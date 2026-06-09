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
        'name' => 'Redmine TICS',
        'description' => 'Captura, procesa y envia reportes del proyecto Redmine TICS.',
        'icon' => 'bi-kanban',
        'type' => 'native',
        'path' => $modulePath('NOVA_REDMINE_TIC_PATH', 'redmine_tic'),
        'entry' => 'laravel:redmine.native.dashboard',
        'allowed_static_roots' => [
            'assets',
            'data/procedimientos/documentos',
            'data/procedimientos/imagenes',
        ],
        'allowed_php_roots' => [
            '',
            'views',
            'controllers',
        ],
    ],
    'redmine-mantencion' => [
        'name' => 'Redmine Mantencion',
        'description' => 'Gestiona reportes, pendientes, procedimientos e integraciones de mantencion.',
        'icon' => 'bi-tools',
        'type' => 'native',
        'path' => $modulePath('NOVA_REDMINE_MANTENCION_PATH', 'redmine-mantencion'),
        'entry' => 'laravel:redmine.mantencion.dashboard',
        'allowed_static_roots' => [
            'assets',
            'data/procedimientos/documentos',
            'data/procedimientos/imagenes',
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
        'path' => $modulePath('NOVA_EMACH_PATH', 'emach'),
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
