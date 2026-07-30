<?php

return [
    'user_source' => env('NOVA_USER_SOURCE', 'redmine-mantencion'),
    'session_timeout' => (int) env('NOVA_SESSION_TIMEOUT', 3600),
    // Roles allowed to administer NOVA itself.
    'module_admin_roles' => ['admin', 'root'],
    // Only these roles bypass module-specific roles and permissions.
    'module_superuser_roles' => ['root'],
];
