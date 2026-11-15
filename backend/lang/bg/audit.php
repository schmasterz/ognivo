<?php

declare(strict_types=1);

$actions = [
    'auth.login.success' => 'Успешен вход в системата',
    'auth.login.failed' => 'Неуспешен опит за вход',
    'auth.login.throttled' => 'Превишено ограничение за опити за вход',
    'auth.logout' => 'Изход от системата',
    'auth.register' => 'Регистрация на нов потребител',
    'auth.password.changed' => 'Сменена парола',
    'auth.email.changed' => 'Променен имейл адрес',
    'auth.tokens.revoked' => 'Анулирани API токени',

    'user.created' => 'Създаден потребителски профил',
    'user.updated' => 'Редактиран потребителски профил',
    'user.role.changed' => 'Променена потребителска роля',
    'user.status.changed' => 'Променен потребителски статус',
    'user.deleted' => 'Изтрит потребителски профил',
    'user.quota.assigned' => 'Зададена индивидуална квота',
    'user.pseudonymised' => 'Псевдонимизирана самоличност на потребител',

    'vm.create.requested' => 'Заявено създаване на инстанция',
    'vm.state.changed' => 'Променено състояние на инстанция',
    'vm.action.requested' => 'Заявено действие върху инстанция',
    'vm.action.denied' => 'Отказано действие върху инстанция',
    'vm.delete.forced' => 'Принудително изтриване на инстанция',
    'vm.snapshot.created' => 'Създадено моментно копие (snapshot)',
    'vm.snapshot.restored' => 'Възстановено моментно копие (snapshot)',
    'vm.snapshot.deleted' => 'Изтрито моментно копие (snapshot)',
    'vm.reinstall.requested' => 'Заявено преинсталиране на инстанция',

    'ip.allocated' => 'Заделен публичен IP адрес',
    'ip.released' => 'Освободен публичен IP адрес',
    'ip.reassigned' => 'Пренасочен IP адрес',
    'ip_pool.created' => 'Създаден IP пул',
    'ip_pool.updated' => 'Редактиран IP пул',
    'ip_pool.deleted' => 'Изтрит IP пул',
    'ip_pool.preflight.run' => 'Изпълнена мрежова диагностика (preflight)',

    'host.created' => 'Регистриран изчислителен възел (host)',
    'host.updated' => 'Редактиран изчислителен възел (host)',
    'host.deleted' => 'Премахнат изчислителен възел (host)',
    'host.retrusted' => 'Подновено доверие към възел (host)',
    'host.resources.synced' => 'Синхронизирани изчислителни ресурси на хост',

    'cert.generated' => 'Генериран клиентски сертификат',
    'cert.rotated' => 'Подменен клиентски сертификат',
    'cert.revoked' => 'Анулиран клиентски сертификат',
    'cert.downloaded' => 'Изтеглен клиентски сертификат',

    'settings.updated' => 'Обновени системни настройки',
    'quota.group.created' => 'Създадена квотна група',
    'quota.group.updated' => 'Редактирана квотна група',
    'quota.default.updated' => 'Обновена квота по подразбиране',

    'console.ticket.issued' => 'Издаден билет за уеб конзола',
    'terminal.session.opened' => 'Interactive terminal session opened',
    'terminal.session.closed' => 'Interactive terminal session closed',
    'impersonation.started' => 'Стартирано администраторско представяне (impersonation)',
    'impersonation.ended' => 'Прекратено администраторско представяне (impersonation)',
    'audit.exported' => 'Експортиран архив с одит лог',
];

$nested = [];
foreach ($actions as $key => $label) {
    data_set($nested, 'actions.'.$key, $label);
}

return array_merge($actions, $nested);
