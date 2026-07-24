<?php

$modulePath = static function (string $envKey, string $defaultRelative): string {
    $configured = trim((string) env($envKey, ''));
    $path = $configured !== '' ? $configured : $defaultRelative;
    $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    $isWindowsAbsolute = (bool) preg_match('/^[A-Za-z]:' . preg_quote(DIRECTORY_SEPARATOR, '/') . '/', $normalized);
    $isUnixAbsolute = str_starts_with($normalized, DIRECTORY_SEPARATOR);

    return $isWindowsAbsolute || $isUnixAbsolute ? $normalized : base_path($normalized);
};

return [
    'redmine_tic' => [
        'name' => 'Backlog Soporte TI',
        'description' => 'Captura, procesa y envia reportes del proyecto Backlog Soporte TI.',
        'icon' => 'bi-kanban',
        'type' => 'native',
        'path' => $modulePath('NOVA_REDMINE_TIC_PATH', 'redmine_tic'),
        'entry' => 'laravel:redmine.native.dashboard',
        'allowed_static_roots' => [
            'assets',
        ],
        'allowed_php_roots' => [
            '',
            'views',
            'controllers',
        ],
    ],
    'redmine-mantencion' => [
        'name' => 'Redmine Mantencion',
        'description' => 'Gestiona reportes, pendientes e integraciones de mantencion.',
        'icon' => 'bi-tools',
        'type' => 'native',
        'path' => $modulePath('NOVA_REDMINE_MANTENCION_PATH', 'RedmineMantencion'),
        'entry' => 'laravel:redmine.mantencion.dashboard',
        'allowed_static_roots' => [
            'assets',
        ],
        'allowed_php_roots' => [
            '',
            'views',
            'controllers',
        ],
    ],
    'emach' => [
        'name' => 'EMACH',
        'description' => 'Nuevo proyecto integrado a NOVA.',
        'icon' => 'bi-heart-pulse',
        'type' => 'legacy',
        'path' => $modulePath('NOVA_EMACH_PATH', 'Emach'),
        'entry' => 'index.php',
        'allowed_static_roots' => [
            'assets',
        ],
        'allowed_php_roots' => [
            '',
            'views',
        ],
    ],
    'telegram' => [
        'name' => 'Telegram',
        'description' => 'Centraliza mensajes y comandos de Telegram para los proyectos NOVA.',
        'icon' => 'bi-telegram',
        'type' => 'native',
        'path' => $modulePath('NOVA_TELEGRAM_PATH', 'telegram'),
        'entry' => 'laravel:telegram.index',
        'allowed_static_roots' => [],
        'allowed_php_roots' => [],
    ],
    'procedimientos' => [
        'name' => 'Procedimientos',
        'description' => 'Gestiona y edita documentos almacenados en Nextcloud.',
        'icon' => 'bi-journal-richtext',
        'type' => 'native',
        'path' => $modulePath('NOVA_PROCEDIMIENTOS_PATH', 'Procedimientos'),
        'entry' => 'laravel:procedimientos.index',
        'allowed_static_roots' => [],
        'allowed_php_roots' => [],
    ],
    'horas-extra' => [
        'name' => 'Horas Extra',
        'description' => 'Consulta y gestiona las horas extra consolidadas de los módulos Redmine.',
        'icon' => 'bi-clock-history',
        'type' => 'native',
        'path' => $modulePath('NOVA_HOURS_EXTRA_PATH', 'Nova'),
        'entry' => 'laravel:horas-extra.index',
        'allowed_static_roots' => [],
        'allowed_php_roots' => [],
    ],
    'integraciones' => [
        'name' => 'Mis integraciones',
        'description' => 'Administra las credenciales personales utilizadas por los módulos NOVA.',
        'icon' => 'bi-person-lock',
        'type' => 'native',
        'path' => $modulePath('NOVA_INTEGRATIONS_PATH', 'Nova'),
        'entry' => 'laravel:integrations.nova',
        'show_on_home' => false,
        'allowed_static_roots' => [],
        'allowed_php_roots' => [],
    ],
    'administracion' => [
        'name' => 'Administracion',
        'description' => 'Configuracion global y usuarios de NOVA.',
        'icon' => 'bi-person-gear',
        'type' => 'native',
        'path' => $modulePath('NOVA_ADMIN_STORAGE_PATH', 'storage/app/nova'),
        'entry' => 'laravel:administracion.index',
        'allowed_static_roots' => [],
        'allowed_php_roots' => [],
    ],
];
