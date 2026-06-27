<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/nextcloud.php';
require_once __DIR__ . '/core_credentials.php';

/**
 * Builds a Nextcloud cfg array for the currently logged-in user's personal
 * credentials (stored in integraciones_usuario, tipo='nextcloud').
 * Returns null when credentials or URL are missing.
 */
function nc_browser_user_cfg(): ?array
{
    $userId = function_exists('auth_get_user_id') ? (string) auth_get_user_id() : '';
    if ($userId === '') {
        return null;
    }
    $creds = nextcloud_credentials_for_user($userId);
    if (trim((string) ($creds['user'] ?? '')) === '' || trim((string) ($creds['pass'] ?? '')) === '') {
        return null;
    }
    $baseCfg = nextcloud_config_load();
    $url     = trim((string) ($baseCfg['nextcloud_url'] ?? ''));
    if ($url === '') {
        return null;
    }
    return [
        'url'        => $url,
        'admin_user' => trim((string) $creds['user']),
        'admin_pass' => trim((string) $creds['pass']),
    ];
}

/** Strips path separators and dangerous characters from a filename. */
function nc_browser_safe_name(string $name): string
{
    $name = trim($name);
    $name = str_replace(["\0", '/', '\\'], '-', $name);
    $name = preg_replace('/[<>:"|?*\x00-\x1F]+/u', '-', $name) ?? $name;
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    $name = trim($name, " .-\t\n\r\0\x0B");
    return $name !== '' ? $name : 'archivo';
}

/**
 * Pings the configured OnlyOffice Document Server via its /healthcheck endpoint.
 * Returns ['available' => bool, 'configured' => bool, 'url' => string].
 * Timeout capped at 5 seconds as required.
 */
function nc_browser_onlyoffice_ping(): array
{
    $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
    $cfg  = ($repo !== null) ? $repo->loadAll() : [];
    $url  = rtrim(trim((string) ($cfg['onlyoffice_app_url'] ?? '')), '/');
    if ($url === '') {
        return ['available' => false, 'configured' => false, 'url' => '', '_perf_ms' => 0];
    }
    if (!empty($cfg['onlyoffice_disabled'])) {
        return ['available' => false, 'configured' => true, 'disabled' => true, 'url' => $url, '_perf_ms' => 0];
    }
    $parsedPath = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    if (preg_match('#/(redmine-mantencion|NOVA|nextcloud)(/|$)#i', $parsedPath)) {
        return ['available' => false, 'configured' => true, 'invalid_url' => true, 'url' => $url, '_perf_ms' => 0];
    }
    $ch = curl_init($url . '/healthcheck');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 1,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_NOBODY         => true,
    ]);
    $_ncT0 = microtime(true);
    curl_exec($ch);
    $_ncMs = (int) round((microtime(true) - $_ncT0) * 1000);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    error_log('[NC_PERF] PING url=' . $url . '/healthcheck ms=' . $_ncMs . ' http=' . $http . ' available=' . ($http >= 200 && $http < 400 ? 'yes' : 'no'));
    return ['available' => ($http >= 200 && $http < 400), 'configured' => true, 'url' => $url, '_perf_ms' => $_ncMs];
}

function nc_browser_nextcloud_users(): array
{
    if (!class_exists(\Illuminate\Support\Facades\DB::class) || !class_exists(\Illuminate\Support\Facades\Schema::class)) {
        return [];
    }

    try {
        if (!\Illuminate\Support\Facades\Schema::hasTable('usuarios_nova') || !\Illuminate\Support\Facades\Schema::hasTable('integraciones_usuario')) {
            return [];
        }

        $currentId = function_exists('auth_get_user_id') ? trim((string) auth_get_user_id()) : '';
        $rows = \Illuminate\Support\Facades\DB::table('integraciones_usuario')
            ->join('usuarios_nova', 'usuarios_nova.id', '=', 'integraciones_usuario.usuario_id')
            ->where('integraciones_usuario.tipo', 'nextcloud')
            ->whereNotNull('integraciones_usuario.usuario_externo')
            ->where('integraciones_usuario.usuario_externo', '<>', '')
            ->whereNotNull('integraciones_usuario.valor_secreto')
            ->where('integraciones_usuario.valor_secreto', '<>', '')
            ->where('usuarios_nova.estado', 'activo')
            ->orderBy('usuarios_nova.nombre')
            ->orderBy('usuarios_nova.apellido')
            ->select([
                'usuarios_nova.uuid',
                'usuarios_nova.usuario',
                'usuarios_nova.redmine_id',
                'usuarios_nova.nombre',
                'usuarios_nova.apellido',
                'integraciones_usuario.usuario_externo',
            ])
            ->get();

        $users = [];
        foreach ($rows as $row) {
            if ($currentId !== '' && in_array($currentId, [
                (string) ($row->uuid ?? ''),
                (string) ($row->usuario ?? ''),
                (string) ($row->redmine_id ?? ''),
            ], true)) {
                continue;
            }

            $external = trim((string) ($row->usuario_externo ?? ''));
            if ($external === '') {
                continue;
            }
            $fullName = trim(trim((string) ($row->nombre ?? '')) . ' ' . trim((string) ($row->apellido ?? '')));
            $users[] = [
                'user' => $external,
                'label' => $fullName !== '' ? $fullName : (trim((string) ($row->usuario ?? '')) ?: $external),
            ];
        }

        return array_values($users);
    } catch (\Throwable) {
        return [];
    }
}

function nc_browser_office_mime(string $type): string
{
    if (!function_exists('procedures_office_mime')) {
        require_once __DIR__ . '/procedimientos.php';
    }

    return function_exists('procedures_office_mime')
        ? procedures_office_mime($type)
        : match ($type) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        };
}

function nc_browser_blank_office_binary(string $type): string
{
    if (!function_exists('procedures_blank_template_base64')) {
        require_once __DIR__ . '/procedimientos.php';
    }

    $binary = base64_decode(procedures_blank_template_base64($type), true);

    return is_string($binary) ? $binary : '';
}

/** Emits a JSON response and exits. */
function nc_browser_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function nc_browser_cache_prefix(string $userId): string
{
    return 'nova:nc_browser:' . sha1($userId !== '' ? $userId : 'anonymous');
}

function nc_browser_cache_version(string $userId): int
{
    if (!class_exists(\Illuminate\Support\Facades\Cache::class)) {
        return 1;
    }

    try {
        return max(1, (int) \Illuminate\Support\Facades\Cache::get(nc_browser_cache_prefix($userId) . ':version', 1));
    } catch (\Throwable) {
        return 1;
    }
}

function nc_browser_cache_key(string $userId, string $bucket, string $path = ''): string
{
    $version = nc_browser_cache_version($userId);
    return nc_browser_cache_prefix($userId) . ':' . $version . ':' . $bucket . ':' . sha1($path);
}

function nc_browser_cached(string $key, callable $callback, bool $forceRefresh = false): array
{
    if (!class_exists(\Illuminate\Support\Facades\Cache::class)) {
        return $callback();
    }

    try {
        $cached = $forceRefresh ? null : \Illuminate\Support\Facades\Cache::get($key);
        if (!$forceRefresh && is_array($cached)) {
            $cached['cached'] = true;
            $cached['elapsed_ms'] = 0;
            return $cached;
        }

        $startedAt = microtime(true);
        $data = $callback();
        if (is_array($data)) {
            $data['cached'] = false;
            $data['elapsed_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        }
        if (is_array($data) && !empty($data['ok'])) {
            \Illuminate\Support\Facades\Cache::put($key, $data, 600);
        }
        return $data;
    } catch (\Throwable) {
        return $callback();
    }
}

function nc_browser_invalidate_cache(string $userId): void
{
    if (!class_exists(\Illuminate\Support\Facades\Cache::class)) {
        return;
    }

    try {
        $versionKey = nc_browser_cache_prefix($userId) . ':version';
        $version = max(1, (int) \Illuminate\Support\Facades\Cache::get($versionKey, 1));
        \Illuminate\Support\Facades\Cache::forever($versionKey, $version + 1);
    } catch (\Throwable) {
        // Cache is a speed-up only; never fail the file operation because of it.
    }
}

function nc_browser_json_after_write(array $data, string $userId, int $status = 200): void
{
    if (!empty($data['ok'])) {
        nc_browser_invalidate_cache($userId);
    }
    nc_browser_json($data, $status);
}

/**
 * Main dispatcher for the personal Nextcloud file-browser AJAX endpoint.
 * Called by views/Procedimientos/nc_browser_ajax.php.
 */
function nc_browser_handle(): void
{
    auth_require_login('/redmine-mantencion/login.php');

    if (!auth_can('procedimientos')) {
        nc_browser_json(['ok' => false, 'error' => 'Sin acceso al módulo de procedimientos.'], 403);
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = trim((string) ($_REQUEST['action'] ?? ''));
    $userId = function_exists('auth_get_user_id') ? (string) auth_get_user_id() : '';

    // CSRF protection for all state-changing requests
    if ($method === 'POST' && function_exists('csrf_validate')) {
        csrf_validate();
    }

    // onlyoffice_status does not require Nextcloud credentials.
    if ($action === 'onlyoffice_status') {
        $_ncPing = nc_browser_onlyoffice_ping();
        error_log('[NC_PERF] handle:onlyoffice_status configured=' . ($_ncPing['configured'] ? 'yes' : 'no') . ' available=' . ($_ncPing['available'] ? 'yes' : 'no') . ' ms=' . ($_ncPing['_perf_ms'] ?? '?'));
        nc_browser_json($_ncPing);
    }

    $cfg = nc_browser_user_cfg();
    if ($cfg === null) {
        nc_browser_json([
            'ok'      => false,
            'error'   => 'sin_credenciales',
            'message' => 'Debe configurar sus credenciales de Nextcloud antes de usar Procedimientos.',
        ], 403);
    }

    switch ($action) {

        // ── Read ─────────────────────────────────────────────────────────────

        case 'list':
            $path = nextcloud_path_safe(trim((string) ($_GET['path'] ?? '/')));
            $forceRefresh = (string) ($_GET['refresh'] ?? '') === '1';
            $_ncResult = nc_browser_cached(
                nc_browser_cache_key($userId, 'list', $path),
                static fn (): array => nextcloud_list_directory($cfg, $path),
                $forceRefresh
            );
            error_log('[NC_PERF] handle:list path=' . $path . ' cached=' . (!empty($_ncResult['cached']) ? 'yes' : 'no') . ' elapsed_ms=' . ($_ncResult['elapsed_ms'] ?? '?') . ' items=' . count($_ncResult['items'] ?? []));
            nc_browser_json($_ncResult);

        case 'shares_with_me':
            $forceRefresh = (string) ($_GET['refresh'] ?? '') === '1';
            $_ncResult = nc_browser_cached(
                nc_browser_cache_key($userId, 'shares_with_me'),
                static fn (): array => nextcloud_shares_with_me($cfg),
                $forceRefresh
            );
            error_log('[NC_PERF] handle:shares_with_me cached=' . (!empty($_ncResult['cached']) ? 'yes' : 'no') . ' elapsed_ms=' . ($_ncResult['elapsed_ms'] ?? '?') . ' shares=' . count($_ncResult['shares'] ?? []));
            nc_browser_json($_ncResult);

        case 'share_users':
            nc_browser_json(['ok' => true, 'users' => nc_browser_nextcloud_users()]);

        case 'download':
            $path = nextcloud_path_safe(trim((string) ($_GET['path'] ?? '')));
            if ($path === '/') {
                http_response_code(400);
                exit('Ruta inválida.');
            }
            $res = nextcloud_webdav_request($cfg, 'GET', $path);
            if (empty($res['ok'])) {
                http_response_code(502);
                exit('No se pudo obtener el archivo desde Nextcloud: ' . htmlspecialchars((string) ($res['message'] ?? ''), ENT_QUOTES, 'UTF-8'));
            }
            $fileName = basename($path);
            $mime     = 'application/octet-stream';
            if (preg_match('/(?:^|\r?\n)Content-Type:\s*([^\r\n;]+)/i', (string) ($res['headers'] ?? ''), $m)) {
                $mime = trim($m[1]);
            }
            $inline = in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'], true);
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . strlen((string) ($res['body'] ?? '')));
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $fileName) . '"');
            header('X-Content-Type-Options: nosniff');
            echo (string) ($res['body'] ?? '');
            exit;

        // ── Write ─────────────────────────────────────────────────────────────

        case 'mkdir':
            $dirPath = nextcloud_path_safe(trim((string) ($_POST['path'] ?? '/')));
            $name    = nc_browser_safe_name(trim((string) ($_POST['name'] ?? '')));
            if ($name === '' || $name === 'archivo') {
                nc_browser_json(['ok' => false, 'error' => 'El nombre de la carpeta es obligatorio.'], 422);
            }
            $fullPath = rtrim($dirPath, '/') . '/' . $name;
            $res      = nextcloud_ensure_directory($cfg, $fullPath);
            nc_browser_json_after_write(
                $res['ok']
                    ? ['ok' => true, 'path' => $fullPath]
                    : ['ok' => false, 'error' => ($res['message'] ?? '') ?: 'No se pudo crear la carpeta.'],
                $userId
            );

        case 'rename':
            $fromPath = nextcloud_path_safe(trim((string) ($_POST['path'] ?? '')));
            $newName  = nc_browser_safe_name(trim((string) ($_POST['name'] ?? '')));
            if ($fromPath === '/' || $newName === '' || $newName === 'archivo') {
                nc_browser_json(['ok' => false, 'error' => 'Parámetros inválidos.'], 422);
            }
            $toPath = dirname($fromPath) . '/' . $newName;
            $dest   = nextcloud_webdav_base_url($cfg)
                . implode('/', array_map('rawurlencode', explode('/', '/' . ltrim($toPath, '/'))));
            $res = nextcloud_webdav_request($cfg, 'MOVE', $fromPath, null, [
                'Destination: ' . $dest,
                'Overwrite: T',
            ]);
            nc_browser_json_after_write(
                $res['ok']
                    ? ['ok' => true, 'path' => $toPath]
                    : ['ok' => false, 'error' => ($res['message'] ?? '') ?: 'No se pudo renombrar.'],
                $userId
            );

        case 'transfer':
            $fromPath = nextcloud_path_safe(trim((string) ($_POST['path'] ?? '')));
            $destinationDir = nextcloud_path_safe(trim((string) ($_POST['destination_dir'] ?? '/')));
            $operation = strtolower(trim((string) ($_POST['operation'] ?? 'move')));
            if ($fromPath === '/' || !in_array($operation, ['move', 'copy'], true)) {
                nc_browser_json(['ok' => false, 'error' => 'Parametros invalidos.'], 422);
            }
            if ($operation === 'move' && str_starts_with(rtrim($destinationDir, '/') . '/', rtrim($fromPath, '/') . '/')) {
                nc_browser_json(['ok' => false, 'error' => 'No se puede mover una carpeta dentro de si misma.'], 422);
            }
            $fileName = basename($fromPath);
            $toPath = rtrim($destinationDir, '/') . '/' . $fileName;
            $dest = nextcloud_webdav_base_url($cfg)
                . implode('/', array_map('rawurlencode', explode('/', '/' . ltrim($toPath, '/'))));
            $res = nextcloud_webdav_request($cfg, strtoupper($operation) === 'COPY' ? 'COPY' : 'MOVE', $fromPath, null, [
                'Destination: ' . $dest,
                'Overwrite: T',
            ]);
            nc_browser_json_after_write(
                $res['ok']
                    ? ['ok' => true, 'path' => $toPath, 'operation' => $operation]
                    : ['ok' => false, 'error' => ($res['message'] ?? '') ?: ($operation === 'copy' ? 'No se pudo copiar.' : 'No se pudo mover.')],
                $userId
            );

        case 'delete':
            $path = nextcloud_path_safe(trim((string) ($_POST['path'] ?? '')));
            if ($path === '/') {
                nc_browser_json(['ok' => false, 'error' => 'No se puede eliminar la raíz.'], 422);
            }
            $res = nextcloud_webdav_request($cfg, 'DELETE', $path);
            nc_browser_json_after_write(
                $res['ok']
                    ? ['ok' => true]
                    : ['ok' => false, 'error' => ($res['message'] ?? '') ?: 'No se pudo eliminar.'],
                $userId
            );

        case 'upload':
            $dirPath      = nextcloud_path_safe(trim((string) ($_POST['path'] ?? '/')));
            $uploadedFile = $_FILES['file'] ?? null;
            if (!is_array($uploadedFile) || (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                nc_browser_json(['ok' => false, 'error' => 'No se recibió un archivo válido.'], 422);
            }
            $fileName = nc_browser_safe_name(trim((string) ($uploadedFile['name'] ?? '')));
            $tmpPath  = (string) ($uploadedFile['tmp_name'] ?? '');
            if (!is_uploaded_file($tmpPath)) {
                nc_browser_json(['ok' => false, 'error' => 'Archivo no válido.'], 422);
            }
            $mime = 'application/octet-stream';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo !== false) {
                    $detected = finfo_file($finfo, $tmpPath);
                    if (is_string($detected) && $detected !== '') {
                        $mime = $detected;
                    }
                    finfo_close($finfo);
                }
            }
            if ($mime === 'application/octet-stream') {
                $mime = (string) ($uploadedFile['type'] ?? 'application/octet-stream');
            }
            $binary = @file_get_contents($tmpPath);
            if ($binary === false) {
                nc_browser_json(['ok' => false, 'error' => 'No se pudo leer el archivo subido.'], 500);
            }
            $remotePath = rtrim($dirPath, '/') . '/' . $fileName;
            $res        = nextcloud_webdav_request($cfg, 'PUT', $remotePath, $binary, ['Content-Type: ' . $mime]);
            nc_browser_json_after_write(
                $res['ok']
                    ? ['ok' => true, 'path' => $remotePath, 'name' => $fileName, 'size' => strlen($binary), 'mime' => $mime]
                    : ['ok' => false, 'error' => ($res['message'] ?? '') ?: 'No se pudo subir el archivo.'],
                $userId
            );

        // ── Sharing ────────────────────────────────────────────────────────────

        case 'create_office':
            $dirPath = nextcloud_path_safe(trim((string) ($_POST['path'] ?? '/')));
            $type = strtolower(trim((string) ($_POST['document_type'] ?? 'docx')));
            if (!in_array($type, ['docx', 'xlsx', 'pptx'], true)) {
                nc_browser_json(['ok' => false, 'error' => 'Tipo de documento no valido.'], 422);
            }

            $title = nc_browser_safe_name(trim((string) ($_POST['title'] ?? '')));
            if ($title === 'archivo') {
                $title = match ($type) {
                    'xlsx' => 'Nueva planilla',
                    'pptx' => 'Nueva presentacion',
                    default => 'Nuevo documento',
                };
            }

            $baseName = preg_replace('/\.' . preg_quote($type, '/') . '$/i', '', $title) ?: $title;
            $fileName = $baseName . '.' . $type;
            $binary = nc_browser_blank_office_binary($type);
            if ($binary === '') {
                nc_browser_json(['ok' => false, 'error' => 'No se encontro la plantilla del documento.'], 500);
            }

            $remotePath = rtrim($dirPath, '/') . '/' . $fileName;
            $res = nextcloud_webdav_request($cfg, 'PUT', $remotePath, $binary, ['Content-Type: ' . nc_browser_office_mime($type)]);
            nc_browser_json_after_write(
                $res['ok']
                    ? ['ok' => true, 'path' => $remotePath, 'name' => $fileName]
                    : ['ok' => false, 'error' => ($res['message'] ?? '') ?: 'No se pudo crear el documento.'],
                $userId
            );

        case 'share_link':
            nc_browser_json(['ok' => false, 'error' => 'Los enlaces publicos estan deshabilitados. Comparta solo con usuarios Nextcloud registrados.'], 410);
            $path = nextcloud_path_safe(trim((string) ($_POST['path'] ?? '')));
            if ($path === '/') {
                nc_browser_json(['ok' => false, 'error' => 'No se puede compartir la raíz.'], 422);
            }
            nc_browser_json_after_write(nextcloud_share_create($cfg, $path), $userId);

        case 'share_user':
            $path      = nextcloud_path_safe(trim((string) ($_POST['path'] ?? '')));
            $shareWith = trim((string) ($_POST['share_with'] ?? ''));
            if ($path === '/' || $shareWith === '') {
                nc_browser_json(['ok' => false, 'error' => 'Parámetros inválidos.'], 422);
            }
            $res = nextcloud_sharing_request($cfg, 'POST', '/shares', [
                'path'        => '/' . ltrim($path, '/'),
                'shareType'   => 0,   // user share
                'shareWith'   => $shareWith,
                'permissions' => 17,  // read + reshare
            ]);
            nc_browser_json_after_write(
                $res['ok']
                    ? ['ok' => true, 'data' => $res['data'] ?? null]
                    : ['ok' => false, 'error' => ($res['message'] ?? '') ?: 'No se pudo compartir con el usuario.'],
                $userId
            );

        case 'share_delete':
            $shareId = trim((string) ($_POST['share_id'] ?? ''));
            nc_browser_json_after_write(nextcloud_share_delete($cfg, $shareId), $userId);

        // ── OnlyOffice status (no Nextcloud cfg needed) ───────────────────────
        case 'onlyoffice_status':
            nc_browser_json(nc_browser_onlyoffice_ping());

        default:
            nc_browser_json(['ok' => false, 'error' => 'Acción no reconocida.'], 400);
    }
}
