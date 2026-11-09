<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;

final class AuditLockoutListener
{
    public function __construct(
        private readonly AuditLogger $logger,
    ) {}

    public function handle(Lockout $event): void
    {
        $request = $event->request;
        $rawEmail = $request->input('email');
        $attemptedEmail = is_string($rawEmail) ? strtolower(trim($rawEmail)) : '';

        $user = $attemptedEmail !== '' ? User::where('email', $attemptedEmail)->first() : null;

        $entry = new AuditEntry(
            action: AuditAction::AuthLoginThrottled,
            outcome: AuditOutcome::Denied,
            subject: $user,
            tenantUserId: $user?->id,
            visibility: $user !== null ? 'tenant' : 'admin',
            pii: $attemptedEmail !== '' ? ['attempted_email' => $attemptedEmail] : [],
        );

        $this->logger->recordIndependent($entry);
    }
}
