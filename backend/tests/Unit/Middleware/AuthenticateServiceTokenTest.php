<?php

declare(strict_types=1);

use App\Audit\AuditContext;
use App\Http\Middleware\AuthenticateServiceToken;
use App\Http\Middleware\SetAuditContext;
use App\Models\ServiceToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('authenticates a valid srv_ token, binds it, and updates last_used_at', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-25 10:00:00'));

    try {
        $generated = ServiceToken::generate(
            name: 'backup-daemon',
            abilities: ['vms:read', 'snapshots:create'],
            expiresAt: Carbon::now()->addDays(7),
        );
        $token = $generated['token'];
        $plainTextToken = $generated['plain_text_token'];

        expect($token->last_used_at)->toBeNull();

        $request = Request::create('/api/vms', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plainTextToken,
        ]);

        $middleware = new AuthenticateServiceToken;
        $nextCalled = false;

        $response = $middleware->handle($request, function (Request $req) use (&$nextCalled, $token): Response {
            $nextCalled = true;

            expect($req->attributes->get('service_token'))->toBeInstanceOf(ServiceToken::class)
                ->and($req->attributes->get('service_token')?->is($token))->toBeTrue()
                ->and(app(ServiceToken::class)->is($token))->toBeTrue();

            return response()->json(['ok' => true], 200);
        });

        $token->refresh();

        expect($nextCalled)->toBeTrue()
            ->and($response->getStatusCode())->toBe(200)
            ->and($token->last_used_at)->not->toBeNull()
            ->and($token->last_used_at?->equalTo(Carbon::now()))->toBeTrue();
    } finally {
        Carbon::setTestNow();
    }
});

it('returns 401 for an invalid or malformed srv_ token', function (string $bearerToken): void {
    $request = Request::create('/api/vms', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer '.$bearerToken,
    ]);

    $middleware = new AuthenticateServiceToken;
    $nextCalled = false;

    $response = $middleware->handle($request, function () use (&$nextCalled): Response {
        $nextCalled = true;

        return response()->json(['ok' => true], 200);
    });

    expect($nextCalled)->toBeFalse()
        ->and($response->getStatusCode())->toBe(401)
        ->and($request->attributes->get('service_token'))->toBeNull();
})->with([
    'non-existent secret' => ['srv_'.str_repeat('a', 40)],
    'empty secret after prefix' => ['srv_'],
]);

it('returns 401 for an expired service token without updating last_used_at', function (): void {
    $secret = bin2hex(random_bytes(20));
    $token = ServiceToken::factory()->expired()->create([
        'name' => 'expired-ci-token',
        'token_hash' => hash('sha256', $secret),
        'last_used_at' => null,
    ]);

    $request = Request::create('/api/vms', 'GET', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer srv_'.$secret,
    ]);

    $middleware = new AuthenticateServiceToken;
    $nextCalled = false;

    $response = $middleware->handle($request, function () use (&$nextCalled): Response {
        $nextCalled = true;

        return response()->json(['ok' => true], 200);
    });

    $token->refresh();

    expect($nextCalled)->toBeFalse()
        ->and($response->getStatusCode())->toBe(401)
        ->and($token->last_used_at)->toBeNull();
});

it('passes non-srv_ requests through in optional mode and rejects them in required mode', function (?string $authHeader): void {
    $server = $authHeader !== null ? ['HTTP_AUTHORIZATION' => $authHeader] : [];
    $middleware = new AuthenticateServiceToken;

    $optionalRequest = Request::create('/api/vms', 'GET', [], [], [], $server);
    $optionalNextCalled = false;
    $optionalResponse = $middleware->handle($optionalRequest, function () use (&$optionalNextCalled): Response {
        $optionalNextCalled = true;

        return response()->json(['ok' => true], 200);
    });

    expect($optionalNextCalled)->toBeTrue()
        ->and($optionalResponse->getStatusCode())->toBe(200);

    $requiredRequest = Request::create('/api/vms', 'GET', [], [], [], $server);
    $requiredNextCalled = false;
    $requiredResponse = $middleware->handle($requiredRequest, function () use (&$requiredNextCalled): Response {
        $requiredNextCalled = true;

        return response()->json(['ok' => true], 200);
    }, 'required');

    expect($requiredNextCalled)->toBeFalse()
        ->and($requiredResponse->getStatusCode())->toBe(401);
})->with([
    'missing authorization header' => [null],
    'sanctum bearer token' => ['Bearer 1|sanctum-plain-text-token'],
]);

it('populates AuditContext with system actor metadata when service token authenticates', function (): void {
    $generated = ServiceToken::generate(
        name: 'terraform-automation',
        abilities: ['*'],
    );
    $token = $generated['token'];
    $plainTextToken = $generated['plain_text_token'];

    $auditContext = new AuditContext;
    app()->instance(AuditContext::class, $auditContext);

    $request = Request::create('/api/vms', 'POST', [], [], [], [
        'REMOTE_ADDR' => '10.20.30.40',
        'HTTP_USER_AGENT' => 'Terraform/1.9',
        'HTTP_AUTHORIZATION' => 'Bearer '.$plainTextToken,
    ]);

    $setAuditContext = new SetAuditContext($auditContext);
    $authMiddleware = new AuthenticateServiceToken;

    $response = $setAuditContext->handle($request, function (Request $req) use ($authMiddleware, $auditContext, $token): Response {
        return $authMiddleware->handle($req, function () use ($auditContext, $token): Response {
            expect($auditContext->getActorType())->toBe('system')
                ->and($auditContext->getActorId())->toBe($token->id)
                ->and($auditContext->getActorLabel())->toBe('service_token:terraform-automation')
                ->and($auditContext->getIp())->toBe('10.20.30.40')
                ->and($auditContext->getUserAgent())->toBe('Terraform/1.9');

            return response()->json(['ok' => true], 200);
        });
    });

    expect($response->getStatusCode())->toBe(200)
        ->and($auditContext->getActorType())->toBe('system')
        ->and($auditContext->getActorId())->toBe($token->id)
        ->and($auditContext->getActorLabel())->toBe('service_token:terraform-automation');
});

it('updates AuditContext downstream in SetAuditContext when service_token attribute is attached during request handling', function (): void {
    $token = ServiceToken::factory()->create([
        'name' => 'monitoring-poller',
    ]);

    $auditContext = new AuditContext;
    $setAuditContext = new SetAuditContext($auditContext);
    $request = Request::create('/api/hosts', 'GET');

    $setAuditContext->handle($request, function (Request $req) use ($token): Response {
        $req->attributes->set('service_token', $token);

        return response()->json(['ok' => true], 200);
    });

    expect($auditContext->getActorType())->toBe('system')
        ->and($auditContext->getActorId())->toBe($token->id)
        ->and($auditContext->getActorLabel())->toBe('service_token:monitoring-poller');
});

it('supports AuditContext::forServiceToken directly', function (): void {
    $token = ServiceToken::factory()->create([
        'name' => 'ci-deployer',
    ]);

    $auditContext = (new AuditContext)->forServiceToken($token);

    expect($auditContext->getActorType())->toBe('system')
        ->and($auditContext->getActorId())->toBe($token->id)
        ->and($auditContext->getActorLabel())->toBe('service_token:ci-deployer')
        ->and($auditContext->getImpersonatorId())->toBeNull();
});
