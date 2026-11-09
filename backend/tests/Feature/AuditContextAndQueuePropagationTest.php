<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditContext;
use App\Http\Middleware\SetAuditContext;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class AuditContextAndQueuePropagationTest extends TestCase
{
    public function test_audit_context_populates_from_request_and_system(): void
    {
        $context = new AuditContext;
        $user = new User(['id' => 42, 'email' => 'admin@example.com']);
        $user->id = 42;

        $request = Request::create('/api/vms', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.25',
            'HTTP_USER_AGENT' => 'TestBrowser/1.0',
            'HTTP_X_REQUEST_ID' => 'custom-request-id-1234',
        ]);
        $request->setUserResolver(fn () => $user);

        $context->fromRequest($request);

        $this->assertSame('custom-request-id-1234', $context->getRequestId());
        $this->assertSame('198.51.100.25', $context->getIp());
        $this->assertSame('TestBrowser/1.0', $context->getUserAgent());
        $this->assertSame('user', $context->getActorType());
        $this->assertSame(42, $context->getActorId());
        $this->assertSame('admin@example.com', $context->getActorLabel());

        // Now test forSystem()
        $context->forSystem();
        $this->assertSame('system', $context->getActorType());
        $this->assertNull($context->getActorId());
        $this->assertSame('system', $context->getActorLabel());
        $this->assertNotEmpty($context->getRequestId());
    }

    public function test_audit_context_payload_serialization_round_trip(): void
    {
        $context = new AuditContext;
        $payload = [
            'request_id' => 'req-abc-999',
            'ip' => '203.0.113.195',
            'user_agent' => 'Mozilla/5.0 Test',
            'actor_type' => 'user',
            'actor_id' => 77,
            'actor_label' => 'operator@example.com',
            'impersonator_id' => 1,
        ];

        $context->fromPayload($payload);

        $this->assertSame('req-abc-999', $context->getRequestId());
        $this->assertSame('203.0.113.195', $context->getIp());
        $this->assertSame('Mozilla/5.0 Test', $context->getUserAgent());
        $this->assertSame('user', $context->getActorType());
        $this->assertSame(77, $context->getActorId());
        $this->assertSame('operator@example.com', $context->getActorLabel());
        $this->assertSame(1, $context->getImpersonatorId());

        $exported = $context->toPayload();
        $this->assertSame($payload, $exported);
    }

    public function test_queue_payload_includes_context_and_job_restores_it_with_job_actor_type(): void
    {
        $context = app(AuditContext::class);
        $context->setRequestId('req-job-test-777');
        $context->setIp('192.0.2.1');
        $context->setUserAgent('JobCreator/1.0');
        $context->setActorType('user');
        $context->setActorId(101);
        $context->setActorLabel('worker_initiator@example.com');
        $context->setImpersonatorId(null);

        // Test restoring via JobProcessing event
        $jobPayload = [
            'audit_context' => [
                'request_id' => 'req-job-test-777',
                'ip' => '192.0.2.1',
                'user_agent' => 'JobCreator/1.0',
                'actor_type' => 'user',
                'actor_id' => 101,
                'actor_label' => 'worker_initiator@example.com',
                'impersonator_id' => null,
            ],
        ];

        // Reset the singleton in container to clean state
        $freshContext = new AuditContext;
        $this->app->instance(AuditContext::class, $freshContext);

        // Fire the JobProcessing event
        $mockJob = new class($jobPayload)
        {
            public function __construct(private array $payload) {}

            public function payload(): array
            {
                return $this->payload;
            }

            public function getJobId(): string
            {
                return 'job-1';
            }

            public function getConnectionName(): string
            {
                return 'sync';
            }

            public function getQueue(): string
            {
                return 'default';
            }
        };

        Event::dispatch(new JobProcessing('sync', $mockJob));

        $restoredContext = app(AuditContext::class);
        $this->assertSame('job', $restoredContext->getActorType(), "Actor type must become 'job'");
        $this->assertSame(101, $restoredContext->getActorId(), 'Originating actor_id must be preserved');
        $this->assertSame('worker_initiator@example.com', $restoredContext->getActorLabel(), 'Originating actor_label must be preserved');
        $this->assertSame('req-job-test-777', $restoredContext->getRequestId(), 'Originating request_id must be preserved');
    }

    public function test_set_audit_context_middleware_fills_context_from_request(): void
    {
        $request = Request::create('/api/vms', 'GET', [], [], [], [
            'REMOTE_ADDR' => '198.51.100.50',
            'HTTP_USER_AGENT' => 'CustomClient/2.0',
            'HTTP_X_REQUEST_ID' => 'mw-request-uuid-456',
        ]);

        $middleware = app(SetAuditContext::class);
        $context = app(AuditContext::class);

        $middleware->handle($request, function ($req) use ($context) {
            $this->assertSame('mw-request-uuid-456', $context->getRequestId());
            $this->assertSame('198.51.100.50', $context->getIp());
            $this->assertSame('CustomClient/2.0', $context->getUserAgent());

            return response()->json(['status' => 'ok']);
        });
    }
}
