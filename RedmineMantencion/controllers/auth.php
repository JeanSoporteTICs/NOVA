<?php
// Autenticación Mantención usando usuarios_nova/integraciones_usuario.
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/logger.php';

date_default_timezone_set('America/Santiago');

function legacy_app_url(string $path = ''): string {
    $path = '/' . ltrim($path, '/');

    if (function_exists('url')) {
        return url('/redmine-mantencion' . ($path === '/' ? '' : $path));
    }

    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (str_contains($script, '/public/index.php')) {
        return rtrim($script, '/') . '/redmine-mantencion' . ($path === '/' ? '' : $path);
    }

    return '/redmine-mantencion' . ($path === '/' ? '' : $path);
}

function auth_start_session() {
    if (session_status() === PHP_SESSION_NONE) {
        $params = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function auth_config_timeout() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = 300;
    $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
    if ($repo !== null) {
        $data = $repo->loadAll();
        if (is_array($data) && isset($data['session_timeout'])) {
            $cache = max(60, (int)$data['session_timeout']);
            return $cache;
        }
    }
    return $cache;
}

function auth_touch_activity() {
    auth_start_session();
    $_SESSION['last_activity'] = time();
}

function auth_norm_key($v) {
    // deja solo letras/numeros en minusculas (para rut o id)
    return strtolower(preg_replace('/[^0-9a-z]/i', '', (string)$v));
}

function auth_central_users_for_mantencion(bool $includeModuleAdmins = true): array {
    if (!class_exists(\Illuminate\Support\Facades\DB::class) || !class_exists(\Illuminate\Support\Facades\Schema::class)) {
        return [];
    }

    try {
        if (!\Illuminate\Support\Facades\Schema::hasTable('usuarios_nova')
            || !\Illuminate\Support\Facades\Schema::hasTable('modulos_nova')
            || !\Illuminate\Support\Facades\Schema::hasTable('permisos_usuario_modulo')) {
            return [];
        }

        $moduleId = \Illuminate\Support\Facades\DB::table('modulos_nova')
            ->where('clave_modulo', 'redmine-mantencion')
            ->value('id');
        $moduleRoleColumn = \Illuminate\Support\Facades\Schema::hasColumn('permisos_usuario_modulo', 'rol_modulo')
            ? 'permisos_usuario_modulo.rol_modulo'
            : \Illuminate\Support\Facades\DB::raw('NULL as rol_modulo');

        $selectColumns = [
            'usuarios_nova.id as nova_id',
            'usuarios_nova.uuid',
            'usuarios_nova.usuario',
            'usuarios_nova.rut',
            'usuarios_nova.redmine_id',
            'usuarios_nova.nombre',
            'usuarios_nova.apellido',
            'usuarios_nova.rol',
            'usuarios_nova.estado',
            'usuarios_nova.password',
            'usuarios_nova.usuario_core',
            'usuarios_nova.telegram_id_chat',
            'usuarios_nova.ultimo_login_at',
            'usuarios_nova.creado_at',
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('usuarios_nova', 'email')) {
            $selectColumns[] = 'usuarios_nova.email';
        }

        $rows = \Illuminate\Support\Facades\DB::table('usuarios_nova')
            ->leftJoin('permisos_usuario_modulo', function ($join) use ($moduleId): void {
                $join->on('permisos_usuario_modulo.usuario_id', '=', 'usuarios_nova.id');
                if ($moduleId !== null) {
                    $join->where('permisos_usuario_modulo.modulo_id', '=', (int)$moduleId);
                } else {
                    $join->whereRaw('1 = 0');
                }
            })
            ->distinct()
            ->select(array_merge($selectColumns, [$moduleRoleColumn]))
            ->where(function ($where) use ($moduleId, $includeModuleAdmins): void {
                if ($includeModuleAdmins) {
                    $where->whereIn('usuarios_nova.rol', ['admin', 'administrador', 'root']);
                    if ($moduleId !== null) {
                        $where->orWhere('permisos_usuario_modulo.permitido', 1);
                    }
                    return;
                }

                if ($moduleId !== null) {
                    $where->where('permisos_usuario_modulo.permitido', 1);
                    return;
                }

                $where->whereRaw('1 = 0');
            })
            ->orderBy('usuarios_nova.nombre')
            ->orderBy('usuarios_nova.apellido')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $ids = $rows->pluck('nova_id')->map(fn ($id) => (int)$id)->all();
        $integrations = \Illuminate\Support\Facades\DB::table('integraciones_usuario')
            ->whereIn('usuario_id', $ids)
            ->get()
            ->groupBy('usuario_id');
        $userPermissions = collect();
        if (\Illuminate\Support\Facades\Schema::hasTable('mantencion_permisos_usuario')) {
            $userPermissions = \Illuminate\Support\Facades\DB::table('mantencion_permisos_usuario')
                ->whereIn('usuario_id', $ids)->get()->groupBy('usuario_id');
        }

        return $rows->map(function ($row) use ($integrations, $userPermissions): array {
            $rowIntegrations = $integrations[(int)$row->nova_id] ?? collect();
            $byType = $rowIntegrations->keyBy('tipo');
            $redmine = collect(['redmine', 'redmine_mantencion', 'redmine_tic'])
                ->map(fn (string $type) => $byType[$type] ?? null)
                ->first(fn ($integration) => trim((string)($integration->valor_secreto ?? '')) !== '')
                ?? ($byType['redmine'] ?? $byType['redmine_mantencion'] ?? $byType['redmine_tic'] ?? null);
            $core = $byType['core'] ?? null;
            $nextcloud = $byType['nextcloud'] ?? null;
            $globalRole = strtolower(trim((string)($row->rol ?? 'usuario')));
            $storedModuleRole = strtolower(trim((string)($row->rol_modulo ?? '')));
            $legacyRole = $globalRole === 'root'
                ? 'root'
                : ($storedModuleRole !== ''
                    ? $storedModuleRole
                    : (in_array($globalRole, ['admin', 'administrador'], true) ? 'administrador' : 'usuario'));
            $api = trim((string)($redmine->valor_secreto ?? ''));
            if ($api !== '') {
                try {
                    $api = (string)decrypt($api);
                } catch (\Throwable) {
                }
            }

            $permissions = [];
            foreach ($userPermissions[(int)$row->nova_id] ?? [] as $permissionRow) {
                $value = (string)$permissionRow->valor;
                $permissions[(string)$permissionRow->permiso] = $value === '1' ? true : ($value === '' ? false : $value);
            }

            return [
                'id'              => trim((string)($row->redmine_id ?? '')) ?: trim((string)($row->usuario ?? $row->uuid ?? '')),
                'rut_sin_dv'      => trim((string)($row->usuario ?? '')),
                'nombre'          => trim((string)($row->nombre ?? '')),
                'apellido'        => trim((string)($row->apellido ?? '')),
                'rut'             => trim((string)($row->rut ?? '')),
                'email'           => trim((string)($row->email ?? '')),
                'api'             => $api,
                'password'        => (string)($row->password ?? ''),
                'rol'             => $legacyRole !== '' ? $legacyRole : 'usuario',
                'rol_nova'        => strtolower(trim((string)($row->rol ?? 'usuario'))),
                'estado_usuario'  => trim((string)($row->estado ?? 'activo')) ?: 'activo',
                'estado'          => trim((string)($row->estado ?? 'activo')) ?: 'activo',
                'core_user'       => trim((string)($row->usuario_core ?? '')) ?: trim((string)($core->usuario_externo ?? '')),
                'core_pass_enc'   => (string)($core->valor_secreto ?? ''),
                'nextcloud_user'  => trim((string)($nextcloud->usuario_externo ?? '')),
                'nextcloud_pass_enc' => (string)($nextcloud->valor_secreto ?? ''),
                'telegram_chat_id'=> trim((string)($row->telegram_id_chat ?? '')),
                'permisos'        => $legacyRole === 'root' ? array_merge($permissions, ['all' => true]) : $permissions,
                '_nova_user_id'   => trim((string)($row->uuid ?? '')),
                'ultimo_login_at' => (string)($row->ultimo_login_at ?? ''),
                'creado_at'       => (string)($row->creado_at ?? ''),
            ];
        })->values()->all();
    } catch (\Throwable) {
        return [];
    }
}

function auth_find_user($username) {
    $data = auth_central_users_for_mantencion();
    if (!is_array($data)) return null;
    foreach ($data as $u) {
        if (!is_array($u)) continue;
        // se permite iniciar sesión con id o RUT (con o sin DV)
        $cand = [];
        $cand[] = $u['id'] ?? null;
        $cand[] = $u['rut'] ?? null;
        $cand[] = $u['rut_sin_dv'] ?? null;
        // derivar rut sin dv si viene en rut
        if (!empty($u['rut'])) {
            $clean = auth_norm_key($u['rut']);
            if (strlen($clean) > 1) {
                $cand[] = substr($clean, 0, -1); // sin dv
                $cand[] = $clean; // con dv
            }
        }
        $cand = array_filter($cand, fn($v) => $v !== null && $v !== '');
        $userKey = auth_norm_key($username);
        foreach ($cand as $c) {
            if ($userKey === auth_norm_key($c)) return $u;
        }
    }
    return null;
}

function auth_find_user_by_id($id) {
    $data = auth_central_users_for_mantencion();
    if (!is_array($data)) return null;
    foreach ($data as $u) {
        if (!is_array($u)) continue;
        if ((string)($u['id'] ?? '') === (string)$id) return $u;
    }
    return null;
}

function auth_central_redmine_api_token($redmineId, string $type = 'redmine'): string {
    $redmineId = trim((string)$redmineId);
    if ($redmineId === '' || !class_exists(\Illuminate\Support\Facades\DB::class)) {
        return '';
    }
    try {
        return app(\App\Modulos\Nova\Repositories\UserIntegrationRepository::class)
            ->redmineTokenForRedmineId($redmineId);
    } catch (\Throwable) {
        return '';
    }
}

function auth_login($username, $password) {
    auth_start_session();
    $user = auth_find_user($username);
    if ($user && strtolower(trim((string)($user['estado'] ?? 'activo'))) === 'baneado') {
        log_security_event('LOGIN_BLOCKED', sprintf('Usuario baneado "%s"', $username));
        return false;
    }
    // usamos campo API como contraseña; si existe 'password' también lo aceptamos
    $apiField = $user['api'] ?? null;
    $passField = $user['password'] ?? null;
    $ok = false;
    if ($user) {
        // Si password está hasheado, usamos password_verify; si no, comparamos directo
        if (!empty($passField) && strlen($passField) > 20) {
            $ok = password_verify($password, $passField);
        } elseif ($passField !== null && $passField === $password) {
            $ok = true;
        } elseif ($apiField !== null && $apiField === $password) {
            $ok = true;
        }
    }
    if ($ok) {
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => $user['id'] ?? '',
            'nombre' => trim((string)($user['nombre'] ?? '')),
            'apellido' => trim((string)($user['apellido'] ?? '')),
            'rut' => $user['rut'] ?? '',
            'rol' => $user['rol'] ?? 'usuario',
        ];
        auth_touch_activity();
        log_security_event('LOGIN_SUCCESS', sprintf('User %s (%s)', $_SESSION['user']['nombre'], $username));
        return true;
    }
    log_security_event('LOGIN_FAILURE', sprintf('Intento con "%s"', $username));
    return false;
}

function auth_logout() {
    auth_start_session();
    $name = trim((string)($_SESSION['user']['nombre'] ?? ''));
    $id = trim((string)($_SESSION['user']['id'] ?? ''));
    if ($name !== '' || $id !== '') {
        log_security_event('LOGOUT', sprintf('Sesion cerrada por %s (ID %s)', $name !== '' ? $name : 'usuario', $id));
    }
    // limpiar variables y cookie de sesión
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function auth_require_login($redirect = '/redmine-mantencion/login.php') {
    auth_start_session();
    $novaUser = function_exists('session') ? session('nova_user') : null;
    $managedByNova = is_array($novaUser);
    $timeout = auth_config_timeout();
    $last = $_SESSION['last_activity'] ?? 0;
    if (!$managedByNova && $last && (time() - $last) > $timeout) {
        log_security_event('SESSION_TIMEOUT', 'Sesion expirada por inactividad');
        auth_logout();
    }
    if (empty($_SESSION['user'])) {
        header('Location: ' . ($managedByNova || function_exists('route') ? route('login') : $redirect));
        exit;
    }
    $sessionUserId = (string)($_SESSION['user']['id'] ?? '');
    if ($sessionUserId !== '') {
        $sessionUser = auth_find_user_by_id($sessionUserId);
        if ($sessionUser && strtolower(trim((string)($sessionUser['estado'] ?? 'activo'))) === 'baneado') {
            log_security_event('LOGIN_BLOCKED', sprintf('Sesion cerrada por usuario baneado ID %s', $sessionUserId));
            if (!$managedByNova) {
                auth_logout();
            }
            header('Location: ' . ($managedByNova || function_exists('route') ? route('login') : $redirect));
            exit;
        }
    }
    auth_touch_activity();
}

function auth_get_user_role() {
    auth_start_session();
    $sessionUserId = (string)($_SESSION['user']['id'] ?? '');
    if ($sessionUserId !== '') {
        $user = auth_find_user_by_id($sessionUserId);
        if (is_array($user) && trim((string)($user['rol'] ?? '')) !== '') {
            return (string)$user['rol'];
        }
    }

    return $_SESSION['user']['rol'] ?? 'usuario';
}

// ----------------- Roles y permisos -----------------
function auth_apply_role_permission_defaults(array $roles): array {
    foreach ($roles as $name => &$cfg) {
        if (!is_array($cfg)) {
            $cfg = [];
        }
        if (!array_key_exists('procedimientos', $cfg)) {
            $cfg['procedimientos'] = true;
        }
        if (!array_key_exists('mis_integraciones', $cfg)) {
            $cfg['mis_integraciones'] = true;
        }
        if (!array_key_exists('integraciones_nextcloud', $cfg)) {
            $cfg['integraciones_nextcloud'] = in_array((string)$name, ['root', 'gestor'], true);
        }
        if (!array_key_exists('actividad_eliminar', $cfg)) {
            $cfg['actividad_eliminar'] = !empty($cfg['actividad']);
        }
        if (!array_key_exists('actividad_todos', $cfg)) {
            $cfg['actividad_todos'] = !empty($cfg['actividad']);
        }
        if (!array_key_exists('horas_extra_editar', $cfg)) {
            $cfg['horas_extra_editar'] = !empty($cfg['horas_extra']);
        }
        foreach (['reportes_editar', 'reportes_eliminar', 'reportes_importar_core'] as $permission) {
            if (!array_key_exists($permission, $cfg)) {
                $cfg[$permission] = !empty($cfg['mensajes_acceso']);
            }
        }
        $legacyHistoryActions = !empty($cfg['historico_acciones']);
        foreach (['historico_estado', 'historico_eliminar'] as $permission) {
            if (!array_key_exists($permission, $cfg)) {
                $cfg[$permission] = $legacyHistoryActions;
            }
        }
        unset($cfg['horas_extra_eliminar']);
        unset($cfg['historico_acciones']);
        $configAccess = !empty($cfg['configuracion']);
        foreach (['cfg_resumen', 'cfg_categorias', 'cfg_mantencion', 'cfg_nextcloud'] as $permission) {
            if (!array_key_exists($permission, $cfg)) {
                $cfg[$permission] = $permission === 'cfg_categorias' ? !empty($cfg['categorias']) : $configAccess;
            }
        }
        // Permiso retirado: ver Procedimientos habilita sus acciones internas.
        unset($cfg['procedimientos_editar']);
    }
    unset($cfg);
    return $roles;
}

function auth_load_roles(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    // Primary: read from mantencion_permisos_rol (relational table — S30)
    if (class_exists(\Illuminate\Support\Facades\DB::class) && class_exists(\Illuminate\Support\Facades\Schema::class)) {
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('mantencion_permisos_rol')) {
                $rows = \Illuminate\Support\Facades\DB::table('mantencion_permisos_rol')
                    ->get(['rol', 'permiso', 'valor']);
                $roles = [];
                foreach ($rows as $row) {
                    $valor = (string)$row->valor;
                    if ($valor === '1') {
                        $decoded = true;
                    } elseif ($valor === '') {
                        $decoded = false;
                    } else {
                        $decoded = $valor; // 'todos', 'asignados', etc.
                    }
                    $roles[(string)$row->rol][(string)$row->permiso] = $decoded;
                }
                if ($roles !== []) {
                    return $cache = auth_apply_role_permission_defaults($roles);
                }
            }
        } catch (\Throwable) {}
    }

    return $cache = auth_apply_role_permission_defaults([]);
}

function auth_get_role_config(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $roles = auth_load_roles();
    $role = auth_get_user_role();
    return $cache = $roles[$role] ?? [];
}

function auth_get_user_override_permissions(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $userId = auth_get_user_id();
    if ($userId === '') {
        return $cache = [];
    }
    $user = auth_find_user_by_id($userId);
    if (!is_array($user)) {
        return $cache = [];
    }
    return $cache = is_array($user['permisos'] ?? null) ? $user['permisos'] : [];
}

function auth_user_has_all_permissions(): bool {
    if (auth_get_user_role() === 'root') {
        return true;
    }
    $override = auth_get_user_override_permissions();
    if (!empty($override['all'])) {
        return true;
    }
    $roleCfg = auth_get_role_config();
    return !empty($roleCfg['all']);
}

function auth_get_permission_value(string $permiso) {
    $override = auth_get_user_override_permissions();
    if (array_key_exists($permiso, $override)) {
        return $override[$permiso];
    }
    $roleCfg = auth_get_role_config();
    return $roleCfg[$permiso] ?? null;
}

function auth_can($permiso) {
    if (in_array((string)$permiso, ['actividad', 'actividad_eliminar', 'actividad_todos'], true)) {
        $override = auth_get_user_override_permissions();
        if (array_key_exists((string)$permiso, $override)) {
            return !empty($override[(string)$permiso]);
        }
        $roleCfg = auth_get_role_config();
        if (array_key_exists((string)$permiso, $roleCfg)) {
            return !empty($roleCfg[(string)$permiso]);
        }
    }
    if (auth_user_has_all_permissions()) {
        return true;
    }
    $value = auth_get_permission_value($permiso);
    if (is_array($value)) {
        return count($value) > 0;
    }
    return !empty($value);
}

function auth_get_user_id() {
    auth_start_session();
    return $_SESSION['user']['id'] ?? '';
}

// CSRF helpers
function legacy_csrf_token() {
    auth_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_validate() {
    auth_start_session();
    // Acepta token por POST o cabecera X-CSRF-Token (p.ej. AJAX)
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $token = trim($token);
    $sess  = $_SESSION['csrf_token'] ?? '';
    $legacyValid = $token !== '' && $sess !== '' && hash_equals($sess, $token);

    // Las rutas legacy se despachan dentro de Laravel y mantienen dos sesiones:
    // la sesión NOVA y NOVALEGACY. Aceptar también el token Laravel evita falsos
    // negativos cuando el bridge reconstruye la sesión legacy entre GET y POST.
    $laravelSubmitted = trim((string)(
        $_POST['_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_SERVER['HTTP_X_XSRF_TOKEN']
        ?? ''
    ));
    $laravelExpected = function_exists('csrf_token') ? trim((string)csrf_token()) : '';
    $laravelValid = $laravelSubmitted !== ''
        && $laravelExpected !== ''
        && hash_equals($laravelExpected, $laravelSubmitted);

    if (!$legacyValid && !$laravelValid) {
        $expectsJson = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || (string)($_POST['ajax'] ?? '') === '1';
        if ($expectsJson) {
            if (ob_get_level() > 0) {
                ob_clean();
            }
            http_response_code(419);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'ok' => false,
                'message' => 'La validación de seguridad venció. Recarga la página e inténtalo nuevamente.',
                'reason' => 'csrf_validation_failed',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        // Cierra sesión para evitar estados inconsistentes y redirige a login
        auth_logout();
        header('Location: ' . legacy_app_url('login.php?err=csrf'));
        exit;
    }
}

function auth_require_role(array $rolesAllowed, $redirect = '/redmine-mantencion/login.php') {
    auth_require_login($redirect);
    $role = auth_get_user_role();
    // rol gestor hereda permisos de root
    if ($role === 'gestor' && in_array('root', $rolesAllowed, true)) {
        return;
    }
    if (!in_array($role, $rolesAllowed, true)) {
        header('Location: ' . legacy_app_url());
        exit;
    }
}
