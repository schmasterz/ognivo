<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\Cast;
use App\Models\VirtualMachine;
use App\Services\VmReconciliationService;
use Spatie\ModelStates\Events\StateChanged;

final class VmStateChangedListener
{
    public function __construct(
        private readonly AuditLogger $logger,
    ) {}

    public function handle(StateChanged $event): void
    {
        if (! ($event->model instanceof VirtualMachine)) {
            return;
        }

        /** @var VirtualMachine $vm */
        $vm = $event->model;

        $previousState = $event->initialState !== null
            ? $event->initialState->getValue()
            : null;

        $newState = $event->finalState !== null
            ? $event->finalState->getValue()
            : null;

        $ctx = app(AuditContext::class);

        if (VmReconciliationService::isReconciling()) {
            $source = 'reconcile';
        } elseif ($ctx->getActorType() === 'job') {
            $source = 'job';
        } elseif ($ctx->getActorType() === 'system') {
            $source = 'system';
        } else {
            $source = 'user';
        }

        $operationId = $ctx->getOperationId()
            ?? (isset($vm->operation_id) ? Cast::int($vm->operation_id) : null)
            ?? (request()->hasHeader('X-Operation-Id') ? Cast::int(request()->header('X-Operation-Id')) : null);

        $context = [
            'previous_state' => $previousState,
            'new_state' => $newState,
            'source' => $source,
        ];
        if ($operationId !== null) {
            $context['operation_id'] = $operationId;
        }

        $entry = new AuditEntry(
            action: AuditAction::VmStateChanged,
            outcome: AuditOutcome::Success,
            subject: $vm,
            tenantUserId: $vm->user_id,
            visibility: 'tenant',
            context: $context,
            operationId: $operationId,
        );

        $this->logger->record($entry);
    }
}
