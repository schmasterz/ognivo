<?php

declare(strict_types=1);

use App\Events\Host\HostStatusUpdated;
use App\Exceptions\FingerprintMismatchException;
use App\Exceptions\MissingPinException;
use App\Models\Host;
use App\Models\PanelCertificate;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\HostNodeService;
use App\Services\Hypervisor\Contracts\HypervisorDriverInterface;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\IncusConnectionChecker;
use App\Services\Metrics\VmMetricsCollectorService;
use App\Services\PanelCertificateService;
use App\Services\VmReconciliationService;
use App\States\Host\Error;
use App\States\Host\FingerprintMismatch;
use App\States\Host\HostState;
use App\States\Host\NotTrusted;
use App\States\Host\Ok;
use App\States\Host\Unreachable;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('defaults Host status to Ok', function (): void {
    $host = new Host;

    expect($host->status)
        ->toBeInstanceOf(HostState::class)
        ->toBeInstanceOf(Ok::class)
        ->and($host->status->getValue())->toBe('ok');
});

it('exposes all valid HostState morph values without offline ghost state', function (): void {
    expect(HostState::values())->toBe([
        'ok',
        'unreachable',
        'not_trusted',
        'fingerprint_mismatch',
        'error',
    ])->and(HostState::values())->not->toContain('offline');
});

it('allows valid HostState transitions and dispatches HostStatusUpdated', function (string $from, string $to): void {
    Event::fake([HostStatusUpdated::class]);

    $host = Host::factory()->create(['status' => $from]);

    $host->status->transitionTo($to);

    $fresh = $host->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->status)->toBeInstanceOf($to);

    Event::assertDispatched(
        HostStatusUpdated::class,
        fn (HostStatusUpdated $event): bool => $event->hostId === $host->id
            && $event->oldStatus === $from::getMorphClass()
            && $event->newStatus === $to::getMorphClass()
    );
})->with([
    'Ok -> Unreachable' => [Ok::class, Unreachable::class],
    'Ok -> NotTrusted' => [Ok::class, NotTrusted::class],
    'Ok -> FingerprintMismatch' => [Ok::class, FingerprintMismatch::class],
    'Ok -> Error' => [Ok::class, Error::class],
    'Unreachable -> Ok' => [Unreachable::class, Ok::class],
    'Unreachable -> NotTrusted' => [Unreachable::class, NotTrusted::class],
    'Unreachable -> FingerprintMismatch' => [Unreachable::class, FingerprintMismatch::class],
    'Unreachable -> Error' => [Unreachable::class, Error::class],
    'Error -> Ok' => [Error::class, Ok::class],
    'Error -> Unreachable' => [Error::class, Unreachable::class],
    'Error -> NotTrusted' => [Error::class, NotTrusted::class],
    'Error -> FingerprintMismatch' => [Error::class, FingerprintMismatch::class],
    'NotTrusted -> Ok' => [NotTrusted::class, Ok::class],
    'NotTrusted -> FingerprintMismatch' => [NotTrusted::class, FingerprintMismatch::class],
    'NotTrusted -> Error' => [NotTrusted::class, Error::class],
    'FingerprintMismatch -> Ok' => [FingerprintMismatch::class, Ok::class],
    'FingerprintMismatch -> NotTrusted' => [FingerprintMismatch::class, NotTrusted::class],
    'FingerprintMismatch -> Error' => [FingerprintMismatch::class, Error::class],
]);

it('rejects disallowed HostState transitions', function (string $from, string $to): void {
    Event::fake([HostStatusUpdated::class]);

    $host = Host::factory()->create(['status' => $from]);

    expect(fn () => $host->status->transitionTo($to))
        ->toThrow(CouldNotPerformTransition::class);

    expect($host->fresh()?->status)->toBeInstanceOf($from);
    Event::assertNotDispatched(HostStatusUpdated::class);
})->with([
    'Ok -> Ok' => [Ok::class, Ok::class],
    'Unreachable -> Unreachable' => [Unreachable::class, Unreachable::class],
    'Error -> Error' => [Error::class, Error::class],
    'NotTrusted -> NotTrusted' => [NotTrusted::class, NotTrusted::class],
    'NotTrusted -> Unreachable' => [NotTrusted::class, Unreachable::class],
    'FingerprintMismatch -> FingerprintMismatch' => [FingerprintMismatch::class, FingerprintMismatch::class],
    'FingerprintMismatch -> Unreachable' => [FingerprintMismatch::class, Unreachable::class],
]);

it('skips disallowed and redundant transitions gracefully in transitionIfAllowed', function (): void {
    Event::fake([HostStatusUpdated::class]);

    $host = Host::factory()->create(['status' => FingerprintMismatch::class]);

    expect($host->status->transitionIfAllowed(Unreachable::class))->toBeFalse()
        ->and($host->status->transitionIfAllowed(FingerprintMismatch::class))->toBeFalse()
        ->and($host->fresh()?->status)->toBeInstanceOf(FingerprintMismatch::class);

    Event::assertNotDispatched(HostStatusUpdated::class);

    expect($host->status->transitionIfAllowed(Ok::class))->toBeTrue()
        ->and($host->fresh()?->status)->toBeInstanceOf(Ok::class);

    Event::assertDispatchedTimes(HostStatusUpdated::class, 1);
});

it('assigns state without persisting when transitionIfAllowed runs on an unsaved Host', function (): void {
    Event::fake([HostStatusUpdated::class]);

    $transientHost = new Host([
        'name' => 'transient-host',
        'endpoint_url' => 'https://192.0.2.50:8443',
        'driver' => 'incus',
    ]);

    expect($transientHost->exists)->toBeFalse()
        ->and($transientHost->status->transitionIfAllowed(NotTrusted::class))->toBeTrue()
        ->and($transientHost->exists)->toBeFalse()
        ->and($transientHost->status)->toBeInstanceOf(NotTrusted::class);

    Event::assertNotDispatched(HostStatusUpdated::class);
});

it('ignores non-Host StateChanged events in HostStateChangedListener', function (): void {
    Event::fake([HostStatusUpdated::class]);

    $user = User::factory()->create();
    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'status' => Stopped::class,
    ]);

    $vm->status->transitionTo(Running::class);

    Event::assertNotDispatched(HostStatusUpdated::class);
});

it('enforces guarded transitions in HostNodeService::updateStatus', function (): void {
    Event::fake([HostStatusUpdated::class]);

    $host = Host::factory()->create(['status' => NotTrusted::class]);

    /** @var HostNodeService $service */
    $service = app(HostNodeService::class);

    expect(fn () => $service->updateStatus($host, 'offline'))
        ->toThrow(ValidationException::class);

    expect(fn () => $service->updateStatus($host, Unreachable::$name))
        ->toThrow(CouldNotPerformTransition::class);

    $service->updateStatus($host, NotTrusted::$name);
    Event::assertNotDispatched(HostStatusUpdated::class);

    $service->updateStatus($host, Ok::$name);
    expect($host->fresh()?->status)->toBeInstanceOf(Ok::class);
    Event::assertDispatched(
        HostStatusUpdated::class,
        fn (HostStatusUpdated $e): bool => $e->hostId === $host->id
            && $e->oldStatus === 'not_trusted'
            && $e->newStatus === 'ok'
    );
});

it('prevents VmReconciliationService from overwriting trust-failure states with unreachable or ok', function (string $trustState): void {
    Event::fake([HostStatusUpdated::class]);

    $host = Host::factory()->create(['status' => $trustState]);

    $mockDriver = Mockery::mock(HypervisorDriverInterface::class);
    $mockDriver->shouldReceive('listProjects')->once()->andThrow(new RuntimeException('Connection timed out'));
    HypervisorManager::fake($mockDriver);

    try {
        /** @var VmReconciliationService $reconciler */
        $reconciler = app(VmReconciliationService::class);
        $reconciler->reconcileHost($host);

        expect($host->fresh()?->status)->toBeInstanceOf($trustState);

        $fakeDriver = new FakeHypervisorDriver;
        HypervisorManager::fake($fakeDriver);
        $reconciler->reconcileHost($host->fresh());

        expect($host->fresh()?->status)->toBeInstanceOf($trustState);
        Event::assertNotDispatched(HostStatusUpdated::class);
    } finally {
        HypervisorManager::resetFake();
    }
})->with([
    'NotTrusted' => [NotTrusted::class],
    'FingerprintMismatch' => [FingerprintMismatch::class],
]);

it('recovers Unreachable to Ok in VmMetricsCollectorService without overwriting trust-failure states', function (): void {
    Event::fake([HostStatusUpdated::class]);

    config([
        'metrics.enabled' => true,
        'metrics.interval_seconds' => 60,
    ]);

    $fakeDriver = new FakeHypervisorDriver;
    HypervisorManager::fake($fakeDriver);

    try {
        /** @var VmMetricsCollectorService $collector */
        $collector = app(VmMetricsCollectorService::class);

        $unreachableHost = Host::factory()->create(['driver' => 'fake', 'status' => Unreachable::class]);
        $collector->collectForHost($unreachableHost, 1795560000);

        expect($unreachableHost->fresh()?->status)->toBeInstanceOf(Ok::class);
        Event::assertDispatched(
            HostStatusUpdated::class,
            fn (HostStatusUpdated $e): bool => $e->hostId === $unreachableHost->id
                && $e->oldStatus === 'unreachable'
                && $e->newStatus === 'ok'
        );

        foreach ([NotTrusted::class, FingerprintMismatch::class] as $trustState) {
            $trustHost = Host::factory()->create(['driver' => 'fake', 'status' => $trustState]);
            $collector->collectForHost($trustHost, 1795560060);
            expect($trustHost->fresh()?->status)->toBeInstanceOf($trustState);

            $collector->recordHostFailure($trustHost->fresh(), new MissingPinException('Missing pin'), 5, 1795560120);
            $collector->recordHostFailure($trustHost->fresh(), new FingerprintMismatchException('', '', 'Mismatch', 90), 5, 1795560180);
        }
    } finally {
        HypervisorManager::resetFake();
    }
});

it('recovers trust states to Ok via IncusConnectionChecker without masking them as Unreachable on network blips', function (): void {
    Event::fake([HostStatusUpdated::class]);

    /** @var PanelCertificateService $certService */
    $certService = app(PanelCertificateService::class);
    $certService->generate(365);

    /** @var PanelCertificate $cert */
    $cert = $certService->getActiveCertificate();
    expect($cert)->not->toBeNull();

    $host = Host::factory()->create([
        'driver' => 'incus',
        'endpoint_url' => 'https://incus-guard-check.internal:8443',
        'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('1', 32)),
        'status' => FingerprintMismatch::class,
    ]);

    $attempt = 0;
    Http::fake([
        'https://incus-guard-check.internal:8443/1.0' => static function () use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                throw new ConnectionException('Connection timed out');
            }

            return Http::response([
                'metadata' => [
                    'auth' => 'trusted',
                    'environment' => [
                        'server' => 'incus',
                        'server_version' => '6.0.0',
                    ],
                ],
            ], 200);
        },
    ]);

    /** @var IncusConnectionChecker $checker */
    $checker = app(IncusConnectionChecker::class);
    $checker->check($host);

    expect($host->fresh()?->status)->toBeInstanceOf(FingerprintMismatch::class);
    Event::assertNotDispatched(HostStatusUpdated::class);

    $checker->check($host->fresh());

    expect($host->fresh()?->status)->toBeInstanceOf(Ok::class);
    Event::assertDispatched(
        HostStatusUpdated::class,
        fn (HostStatusUpdated $e): bool => $e->hostId === $host->id
            && $e->oldStatus === 'fingerprint_mismatch'
            && $e->newStatus === 'ok'
    );
});
