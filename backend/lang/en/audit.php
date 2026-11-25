<?php

declare(strict_types=1);

$actions = [
    'auth.login.success' => 'Successful sign in',
    'auth.login.failed' => 'Failed sign in attempt',
    'auth.login.throttled' => 'Sign in rate limit exceeded',
    'auth.logout' => 'Signed out',
    'auth.register' => 'New user registered',
    'auth.password.changed' => 'Password changed',
    'auth.email.changed' => 'Email address changed',
    'auth.tokens.revoked' => 'API tokens revoked',

    'user.created' => 'User account created',
    'user.updated' => 'User account updated',
    'user.role.changed' => 'User role modified',
    'user.status.changed' => 'User status modified',
    'user.deleted' => 'User account deleted',
    'user.quota.assigned' => 'Custom user quota assigned',
    'user.pseudonymised' => 'User identity pseudonymised',

    'vm.create.requested' => 'Workload creation requested',
    'vm.state.changed' => 'Workload state changed',
    'vm.action.requested' => 'Workload action requested',
    'vm.action.denied' => 'Workload action denied',
    'vm.delete.forced' => 'Workload force deleted',
    'vm.snapshot.created' => 'Snapshot created',
    'vm.snapshot.restored' => 'Snapshot restored',
    'vm.snapshot.deleted' => 'Snapshot deleted',
    'vm.reinstall.requested' => 'Workload reinstall requested',
    'vm.metrics.viewed' => 'Workload metrics viewed (cross-tenant)',

    'ip.allocated' => 'Public IP allocated',
    'ip.released' => 'Public IP released',
    'ip.reassigned' => 'IP address reassigned',
    'ip_pool.created' => 'IP pool created',
    'ip_pool.updated' => 'IP pool updated',
    'ip_pool.deleted' => 'IP pool deleted',
    'ip_pool.preflight.run' => 'Network preflight diagnostics executed',

    'host.created' => 'Compute host registered',
    'host.updated' => 'Compute host updated',
    'host.deleted' => 'Compute host removed',
    'host.retrusted' => 'Compute host trust re-established',
    'host.resources.synced' => 'Compute host resources synchronized',

    'image.created' => 'OS image added to catalog',
    'image.updated' => 'OS image updated',
    'image.deleted' => 'OS image removed from catalog',

    'cert.generated' => 'Client certificate generated',
    'cert.rotated' => 'Client certificate rotated',
    'cert.revoked' => 'Client certificate revoked',
    'cert.downloaded' => 'Client certificate downloaded',

    'settings.updated' => 'System settings updated',
    'quota.group.created' => 'Quota group created',
    'quota.group.updated' => 'Quota group updated',
    'quota.default.updated' => 'Default tier quota updated',

    'console.ticket.issued' => 'Web console access ticket issued',
    'terminal.session.opened' => 'Interactive terminal session opened',
    'terminal.session.closed' => 'Interactive terminal session closed',
    'impersonation.started' => 'Administrator impersonation session started',
    'impersonation.ended' => 'Administrator impersonation session ended',
    'audit.exported' => 'Audit log archive exported',
];

$nested = [];
foreach ($actions as $key => $label) {
    data_set($nested, 'actions.'.$key, $label);
}

return array_merge($actions, $nested);
