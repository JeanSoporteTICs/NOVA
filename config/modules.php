<?php

return [
    'redmine_tic' => [
        'name' => 'Redmine TICS',
        'description' => 'Captura, procesa y envia reportes del proyecto Redmine TICS.',
        'icon' => 'bi-kanban',
        'type' => 'native',
        'path' => base_path('redmine_tic'),
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
        'path' => base_path('redmine-mantencion'),
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
    'administracion' => [
        'name' => 'Administracion',
        'description' => 'Configuracion global y usuarios de NOVA.',
        'icon' => 'bi-person-gear',
        'type' => 'native',
        'path' => storage_path('app/nova'),
        'entry' => 'laravel:administracion.index',
        'allowed_static_roots' => [],
        'allowed_php_roots' => [],
    ],
];
