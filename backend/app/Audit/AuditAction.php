<?php

declare(strict_types=1);

namespace App\Audit;

enum AuditAction: string
{
    // Auth
    case AuthLoginSuccess = 'auth.login.success';
    case AuthLoginFailed = 'auth.login.failed';
    case AuthLoginThrottled = 'auth.login.throttled';
    case AuthLogout = 'auth.logout';
    case AuthRegister = 'auth.register';
    case AuthPasswordChanged = 'auth.password.changed';
    case AuthEmailChanged = 'auth.email.changed';
    case AuthTokensRevoked = 'auth.tokens.revoked';

    // User
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserRoleChanged = 'user.role.changed';
    case UserStatusChanged = 'user.status.changed';
    case UserDeleted = 'user.deleted';
    case UserQuotaAssigned = 'user.quota.assigned';
    case UserPseudonymised = 'user.pseudonymised';

    // VM
    case VmCreateRequested = 'vm.create.requested';
    case VmStateChanged = 'vm.state.changed';
    case VmActionRequested = 'vm.action.requested';
    case VmActionDenied = 'vm.action.denied';
    case VmDeleteForced = 'vm.delete.forced';
    case VmSnapshotCreated = 'vm.snapshot.created';
    case VmSnapshotRestored = 'vm.snapshot.restored';
    case VmSnapshotDeleted = 'vm.snapshot.deleted';

    // IP & IP Pool
    case IpAllocated = 'ip.allocated';
    case IpReleased = 'ip.released';
    case IpReassigned = 'ip.reassigned';
    case IpPoolCreated = 'ip_pool.created';
    case IpPoolUpdated = 'ip_pool.updated';
    case IpPoolDeleted = 'ip_pool.deleted';
    case IpPoolPreflightRun = 'ip_pool.preflight.run';

    // Host
    case HostCreated = 'host.created';
    case HostUpdated = 'host.updated';
    case HostDeleted = 'host.deleted';
    case HostRetrusted = 'host.retrusted';
    case HostResourcesSynced = 'host.resources.synced';

    // Certificate
    case CertGenerated = 'cert.generated';
    case CertRotated = 'cert.rotated';
    case CertRevoked = 'cert.revoked';
    case CertDownloaded = 'cert.downloaded';

    // Settings & Quota
    case SettingsUpdated = 'settings.updated';
    case QuotaGroupCreated = 'quota.group.created';
    case QuotaGroupUpdated = 'quota.group.updated';
    case QuotaDefaultUpdated = 'quota.default.updated';

    // Console, Impersonation, Audit
    case ConsoleTicketIssued = 'console.ticket.issued';
    case ImpersonationStarted = 'impersonation.started';
    case ImpersonationEnded = 'impersonation.ended';
    case AuditExported = 'audit.exported';

    /**
     * Get the translation key for the action.
     */
    public function translationKey(): string
    {
        return 'audit.'.$this->value;
    }

    /**
     * Get the high-level category of the action (first dotted segment).
     */
    public function category(): string
    {
        return explode('.', $this->value, 2)[0];
    }
}
