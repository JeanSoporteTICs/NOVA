<?php

return [
    'user_source' => env('NOVA_USER_SOURCE', 'redmine-mantencion'),
    'session_timeout' => (int) env('NOVA_SESSION_TIMEOUT', 3600),
    'module_admin_roles' => ['admin', 'root', 'gestor', 'administrador'],
];
