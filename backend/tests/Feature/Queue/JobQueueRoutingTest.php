<?php

declare(strict_types=1);

use App\Jobs\CollectHostMetricsJob;
use App\Jobs\CreateSnapshotJob;
use App\Jobs\DeleteSnapshotJob;
use App\Jobs\DeleteUserWorkloadsJob;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\Enums\QueueName;
use App\Jobs\ExecuteVmActionJob;
use App\Jobs\ProvisionVmJob;
use App\Jobs\PurgeVmMetricsJob;
use App\Jobs\ReinstallVmJob;
use App\Jobs\RestoreSnapshotJob;
use App\Jobs\SuspendUserWorkloadsJob;
use App\Jobs\WarnExpiringPanelCertificateJob;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use App\Models\WebhookDelivery;
use Illuminate\Support\Facades\Queue;

dataset('jobQueueRouting', [
    'ExecuteVmActionJob -> high' => [ExecuteVmActionJob::class, QueueName::High->value],
    'CollectHostMetricsJob -> metrics' => [CollectHostMetricsJob::class, QueueName::Metrics->value],
    'DeliverWebhookJob -> default' => [DeliverWebhookJob::class, QueueName::Default->value],
    'PurgeVmMetricsJob -> default' => [PurgeVmMetricsJob::class, QueueName::Default->value],
    'WarnExpiringPanelCertificateJob -> default' => [WarnExpiringPanelCertificateJob::class, QueueName::Default->value],
    'ProvisionVmJob -> heavy' => [ProvisionVmJob::class, QueueName::Heavy->value],
    'ReinstallVmJob -> heavy' => [ReinstallVmJob::class, QueueName::Heavy->value],
    'CreateSnapshotJob -> heavy' => [CreateSnapshotJob::class, QueueName::Heavy->value],
    'RestoreSnapshotJob -> heavy' => [RestoreSnapshotJob::class, QueueName::Heavy->value],
    'DeleteSnapshotJob -> heavy' => [DeleteSnapshotJob::class, QueueName::Heavy->value],
    'SuspendUserWorkloadsJob -> heavy' => [SuspendUserWorkloadsJob::class, QueueName::Heavy->value],
    'DeleteUserWorkloadsJob -> heavy' => [DeleteUserWorkloadsJob::class, QueueName::Heavy->value],
]);

/**
 * Instantiate the given job class with minimal valid constructor arguments.
 *
 * @param  class-string  $jobClass
 */
function instantiateJobForRoutingTest(string $jobClass): object
{
    return match ($jobClass) {
        ExecuteVmActionJob::class => new ExecuteVmActionJob(new VirtualMachine(), 'start'),
        CollectHostMetricsJob::class => new CollectHostMetricsJob(1),
        DeliverWebhookJob::class => new DeliverWebhookJob(new WebhookDelivery()),
        PurgeVmMetricsJob::class => new PurgeVmMetricsJob(1),
        WarnExpiringPanelCertificateJob::class => new WarnExpiringPanelCertificateJob(),
        ProvisionVmJob::class => new ProvisionVmJob(new VirtualMachine()),
        ReinstallVmJob::class => new ReinstallVmJob(new VirtualMachine()),
        CreateSnapshotJob::class => new CreateSnapshotJob(new VmSnapshot()),
        RestoreSnapshotJob::class => new RestoreSnapshotJob(new VmSnapshot()),
        DeleteSnapshotJob::class => new DeleteSnapshotJob(new VmSnapshot()),
        SuspendUserWorkloadsJob::class => new SuspendUserWorkloadsJob(new User()),
        DeleteUserWorkloadsJob::class => new DeleteUserWorkloadsJob(new User()),
        default => throw new InvalidArgumentException("Unexpected job class: {$jobClass}"),
    };
}

/**
 * Dispatch the given job class via its static dispatch() helper.
 *
 * @param  class-string  $jobClass
 */
function dispatchJobForRoutingTest(string $jobClass): void
{
    match ($jobClass) {
        ExecuteVmActionJob::class => ExecuteVmActionJob::dispatch(new VirtualMachine(), 'start'),
        CollectHostMetricsJob::class => CollectHostMetricsJob::dispatch(1),
        DeliverWebhookJob::class => DeliverWebhookJob::dispatch(new WebhookDelivery()),
        PurgeVmMetricsJob::class => PurgeVmMetricsJob::dispatch(1),
        WarnExpiringPanelCertificateJob::class => WarnExpiringPanelCertificateJob::dispatch(),
        ProvisionVmJob::class => ProvisionVmJob::dispatch(new VirtualMachine()),
        ReinstallVmJob::class => ReinstallVmJob::dispatch(new VirtualMachine()),
        CreateSnapshotJob::class => CreateSnapshotJob::dispatch(new VmSnapshot()),
        RestoreSnapshotJob::class => RestoreSnapshotJob::dispatch(new VmSnapshot()),
        DeleteSnapshotJob::class => DeleteSnapshotJob::dispatch(new VmSnapshot()),
        SuspendUserWorkloadsJob::class => SuspendUserWorkloadsJob::dispatch(new User()),
        DeleteUserWorkloadsJob::class => DeleteUserWorkloadsJob::dispatch(new User()),
        default => throw new InvalidArgumentException("Unexpected job class: {$jobClass}"),
    };
}

test('job assigns expected queue on instantiation', function (
    string $jobClass,
    string $expectedQueue,
): void {
    $job = instantiateJobForRoutingTest($jobClass);

    expect($job)->toBeInstanceOf($jobClass)
        ->and($job->queue)->toBe($expectedQueue);
})->with('jobQueueRouting');

test('job dispatches onto expected queue when queued', function (
    string $jobClass,
    string $expectedQueue,
): void {
    Queue::fake();

    dispatchJobForRoutingTest($jobClass);

    Queue::assertPushedOn($expectedQueue, $jobClass);
})->with('jobQueueRouting');

test('QueueName::values returns all expected queue names', function (): void {
    expect(QueueName::values())->toBe(['high', 'metrics', 'default', 'heavy']);
});
