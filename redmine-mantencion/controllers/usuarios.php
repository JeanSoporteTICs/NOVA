<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/core_credentials.php';
require_once __DIR__ . '/maintenance.php';

function usuarios_set_flash(string $message): void {
    auth_start_session();
    $_SESSION['usuarios_flash'] = $message;
}

function usuarios_consume_flash(): ?string {
    auth_start_session();
    $message = $_SESSION['usuarios_flash'] ?? null;
    unset($_SESSION['usuarios_flash']);
    return $message;
}

function usuarios_redirect_back(): void {
    $location = $_SERVER['REQUEST_URI'] ?? '/redmine-mantencion/views/Usuarios/usuarios.php';
    header('Location: ' . $location);
    exit;
}

$GLOBALS['DATA_FILE'] = __DIR__ . '/../data/usuarios.json';
$GLOBALS['CONFIG_FILE'] = __DIR__ . '/../data/configuracion.json';

function rut_base($rut) {
    $clean = preg_replace('/[^0-9kK]/', '', $rut ?? '');
    if ($clean === '') return '';
    $clean = strtoupper($clean);
    return strlen($clean) > 1 ? substr($clean, 0, -1) : $clean;
}

function ensure_usr_file($path) {
    if (storage_read_json($path, null) === null) {
        storage_write_json($path, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE, false);
    }
}

function usuarios_text_key(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $value = strtr($value, [
        'ÃƒÆ’Ã‚Â¡' => 'á', 'ÃƒÆ’Ã‚Â©' => 'é', 'ÃƒÆ’Ã‚Â­' => 'í', 'ÃƒÆ’Ã‚Â³' => 'ó', 'ÃƒÆ’Ã‚Âº' => 'ú',
        'ÃƒÆ’Ã‚Â±' => 'ñ', 'ÃƒÆ’Ã‚Â‘' => 'Ñ',
        'Ã¡' => 'á', 'Ã©' => 'é', 'Ã­' => 'í', 'Ã³' => 'ó', 'Ãº' => 'ú', 'Ã±' => 'ñ',
    ]);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    return trim((string)$value);
}

function usuarios_strip_trailing_phrase(string $value, string $phrase): string {
    $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';
    $phrase = preg_replace('/\s+/', ' ', trim($phrase)) ?? '';
    if ($value === '' || $phrase === '') {
        return $value;
    }
    $phraseTokens = explode(' ', usuarios_text_key($phrase));
    if ($phraseTokens === ['']) {
        return $value;
    }
    do {
        $tokens = preg_split('/\s+/', $value) ?: [];
        $tail = array_slice($tokens, -count($phraseTokens));
        $tailKey = usuarios_text_key(implode(' ', $tail));
        $phraseKey = implode(' ', $phraseTokens);
        if ($tailKey !== $phraseKey || count($tokens) <= count($phraseTokens)) {
            break;
        }
        $value = implode(' ', array_slice($tokens, 0, -count($phraseTokens)));
    } while (true);

    return trim($value);
}

function usuarios_detect_repeated_suffix(string $fullName): array {
    $fullName = preg_replace('/\s+/', ' ', trim($fullName)) ?? '';
    $tokens = preg_split('/\s+/', $fullName) ?: [];
    $count = count($tokens);
    if ($count < 3) {
        return [$fullName, ''];
    }
    $maxLen = min(4, intdiv($count, 2));
    for ($len = $maxLen; $len >= 1; $len--) {
        $suffix = array_slice($tokens, -$len);
        $prev = array_slice($tokens, -($len * 2), $len);
        if (usuarios_text_key(implode(' ', $suffix)) !== usuarios_text_key(implode(' ', $prev))) {
            continue;
        }
        $nameTokens = $tokens;
        while (count($nameTokens) > $len) {
            $tail = array_slice($nameTokens, -$len);
            if (usuarios_text_key(implode(' ', $tail)) !== usuarios_text_key(implode(' ', $suffix))) {
                break;
            }
            $nameTokens = array_slice($nameTokens, 0, -$len);
        }
        if ($nameTokens !== []) {
            return [implode(' ', $nameTokens), implode(' ', $suffix)];
        }
    }

    return [$fullName, ''];
}

function usuarios_normalize_person_fields(array &$item): void {
    $nombre = preg_replace('/\s+/', ' ', trim((string)($item['nombre'] ?? ''))) ?? '';
    $apellido = preg_replace('/\s+/', ' ', trim((string)($item['apellido'] ?? ''))) ?? '';
    $nombre = strtr($nombre, [
        'ÃƒÆ’Ã‚Â¡' => 'á', 'ÃƒÆ’Ã‚Â©' => 'é', 'ÃƒÆ’Ã‚Â­' => 'í', 'ÃƒÆ’Ã‚Â³' => 'ó', 'ÃƒÆ’Ã‚Âº' => 'ú',
        'ÃƒÆ’Ã‚Â±' => 'ñ', 'ÃƒÆ’Ã‚Â‘' => 'Ñ',
        'Ã¡' => 'á', 'Ã©' => 'é', 'Ã­' => 'í', 'Ã³' => 'ó', 'Ãº' => 'ú', 'Ã±' => 'ñ',
    ]);
    $apellido = strtr($apellido, [
        'ÃƒÆ’Ã‚Â¡' => 'á', 'ÃƒÆ’Ã‚Â©' => 'é', 'ÃƒÆ’Ã‚Â­' => 'í', 'ÃƒÆ’Ã‚Â³' => 'ó', 'ÃƒÆ’Ã‚Âº' => 'ú',
        'ÃƒÆ’Ã‚Â±' => 'ñ', 'ÃƒÆ’Ã‚Â‘' => 'Ñ',
        'Ã¡' => 'á', 'Ã©' => 'é', 'Ã­' => 'í', 'Ã³' => 'ó', 'Ãº' => 'ú', 'Ã±' => 'ñ',
    ]);
    if ($apellido !== '') {
        [$lastPrefix, $lastSuffix] = usuarios_detect_repeated_suffix($apellido);
        if ($lastSuffix !== '' && strlen($lastSuffix) < strlen($apellido)) {
            $apellido = $lastSuffix;
        }
        $nombre = usuarios_strip_trailing_phrase($nombre, $apellido);
        [$detectedName, $detectedLastName] = usuarios_detect_repeated_suffix($nombre);
        if ($detectedLastName !== '' && strlen($detectedName) < strlen($nombre)) {
            $nombre = $detectedName;
        }
        $tokens = preg_split('/\s+/', $nombre) ?: [];
        while (count($tokens) > 1 && preg_match('/Ã|Â/u', (string)end($tokens)) === 1) {
            array_pop($tokens);
        }
        $nombre = trim(implode(' ', $tokens));
    } else {
        [$detectedName, $detectedLastName] = usuarios_detect_repeated_suffix($nombre);
        $nombre = $detectedName;
        $apellido = $detectedLastName;
    }
    $item['nombre'] = $nombre;
    $item['apellido'] = $apellido;
}

function ensure_user_fields(array &$item) {
    $defaults = [
        'id' => uniqid('', true),
        'rut_sin_dv' => '',
        'nombre' => '',
        'apellido' => '',
        'rut' => '',
        'numero_celular' => '',
        'estamento' => '',
        'api' => '',
        'core_user' => '',
        'core_pass_enc' => '',
        'nextcloud_user' => '',
        'nextcloud_pass_enc' => '',
        'rol' => 'usuario',
        'estado' => 'activo',
        'password' => '',
    ];
    foreach ($defaults as $key => $value) {
        if (!isset($item[$key])) {
            $item[$key] = $value;
        }
    }
    usuarios_normalize_person_fields($item);
    $item['numero_celular'] = '';
    $item['rut_sin_dv'] = '';
    $item['rut'] = '';
    $item['estamento'] = '';
}

function load_usuarios($path) {
    ensure_usr_file($path);
    $data = storage_read_json($path, []);
    if (!is_array($data)) $data = [];
    $changed = false;
    foreach ($data as &$item) {
        $prev = $item;
        ensure_user_fields($item);
        if ($item !== $prev) $changed = true;
    }
    if ($changed) save_usuarios($path, $data);
    return usuarios_merge_central_access($data);
}

function save_usuarios($path, $data) {
    storage_write_json($path, is_array($data) ? array_values($data) : [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function usuarios_norm_identity(string $value): string {
    return strtolower((string)preg_replace('/[^0-9a-z]/i', '', $value));
}

function usuarios_normalize_status(string $status): string {
    return in_array(strtolower(trim($status)), ['baneado', 'bloqueado', 'inactivo'], true) ? 'baneado' : 'activo';
}

function usuarios_migrate_global_nextcloud_credentials(array &$rows): bool {
    global $CONFIG_FILE;
    $userId = function_exists('auth_get_user_id') ? (string)auth_get_user_id() : '';
    if ($userId === '') {
        return false;
    }
    $cfg = storage_read_json($CONFIG_FILE, []);
    if (!is_array($cfg)) {
        return false;
    }
    $globalUser = trim((string)($cfg['nextcloud_admin_user'] ?? ''));
    $globalPassEnc = trim((string)($cfg['nextcloud_admin_pass_enc'] ?? ''));
    if ($globalUser === '' || $globalPassEnc === '') {
        return false;
    }
    $changed = false;
    foreach ($rows as &$row) {
        if (!is_array($row) || (string)($row['id'] ?? '') !== $userId) {
            continue;
        }
        if (trim((string)($row['nextcloud_user'] ?? '')) === '' && trim((string)($row['nextcloud_pass_enc'] ?? '')) === '') {
            $row['nextcloud_user'] = $globalUser;
            $row['nextcloud_pass_enc'] = $globalPassEnc;
            unset($row['nextcloud_pass']);
            $changed = true;
        }
        break;
    }
    unset($row);
    $cfg['nextcloud_admin_user'] = '';
    $cfg['nextcloud_admin_pass_enc'] = '';
    storage_write_json($CONFIG_FILE, $cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return $changed;
}

function find_user_index(array $rows, string $id): ?int {
    foreach ($rows as $idx => $row) {
        if ((string)($row['id'] ?? '') === (string)$id) return $idx;
    }
    return null;
}

function has_duplicate_id(array $rows, string $id): bool {
    foreach ($rows as $row) {
        if ((string)($row['id'] ?? '') === (string)$id) return true;
    }
    return false;
}

function has_duplicate_rut(array $rows, string $rutBase, string $excludeId = ''): bool {
    if ($rutBase === '') return false;
    foreach ($rows as $row) {
        if ($excludeId !== '' && (string)($row['id'] ?? '') === (string)$excludeId) {
            continue;
        }
        $rutExist = preg_replace('/[^0-9kK]/', '', $row['rut'] ?? '');
        if (rut_base($rutExist) === $rutBase) {
            return true;
        }
    }
    return false;
}

function sanitize_input(string $value): string {
    return trim(filter_var($value, FILTER_UNSAFE_RAW) ?? '');
}

function format_rut_value(string $rut): string {
    $clean = preg_replace('/[^0-9kK]/', '', $rut ?? '');
    if ($clean === '') return '';
    $clean = strtoupper($clean);
    if (strlen($clean) < 2) return $clean;
    $body = substr($clean, 0, -1);
    $dv = substr($clean, -1);
    $body = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $body);
    return $body . '-' . $dv;
}

function usuarios_user_api_token(): string {
    if (!function_exists('auth_get_user_id')) {
        return '';
    }
    $userId = auth_get_user_id();
    if ($userId === '') {
        return '';
    }
    if (function_exists('auth_central_redmine_api_token')) {
        $central = auth_central_redmine_api_token($userId, 'redmine_mantencion');
        if ($central !== '') {
            return $central;
        }
    }
    global $DATA_FILE;
    $users = storage_read_json($DATA_FILE, []);
    if (!is_array($users)) {
        return '';
    }
    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        if ((string)($user['id'] ?? '') === (string)$userId) {
            return trim((string)($user['api'] ?? ''));
        }
    }
    return '';
}

function usuarios_central_module_id(string $moduleKey = 'redmine-mantencion'): ?int {
    if (!class_exists(\Illuminate\Support\Facades\DB::class)) {
        return null;
    }
    try {
        $id = \Illuminate\Support\Facades\DB::table('modulos_nova')->where('clave_modulo', $moduleKey)->value('id');
        return $id !== null ? (int)$id : null;
    } catch (\Throwable) {
        return null;
    }
}

function usuarios_central_decrypt_secret(string $secret): string {
    if ($secret === '') {
        return '';
    }
    try {
        return (string)decrypt($secret);
    } catch (\Throwable) {
        return $secret;
    }
}

function usuarios_central_user_api(string $redmineId, string $type = 'redmine_mantencion'): string {
    $redmineId = trim($redmineId);
    if ($redmineId === '' || !class_exists(\Illuminate\Support\Facades\DB::class)) {
        return '';
    }
    try {
        $secret = (string)\Illuminate\Support\Facades\DB::table('usuarios_nova')
            ->join('integraciones_usuario', 'integraciones_usuario.usuario_id', '=', 'usuarios_nova.id')
            ->where('usuarios_nova.redmine_id', $redmineId)
            ->where('integraciones_usuario.tipo', $type)
            ->value('integraciones_usuario.valor_secreto');
        return usuarios_central_decrypt_secret($secret);
    } catch (\Throwable) {
        return '';
    }
}

function usuarios_central_save_integration(int $userId, string $type, string $secret = '', string $externalUser = ''): void {
    if ($userId <= 0 || !class_exists(\Illuminate\Support\Facades\DB::class)) {
        return;
    }
    if ($secret === '' && $externalUser === '') {
        return;
    }
    $values = [
        'usuario_externo' => $externalUser !== '' ? $externalUser : null,
        'actualizado_at' => now(),
    ];
    if ($secret !== '') {
        try {
            $values['valor_secreto'] = encrypt($secret);
        } catch (\Throwable) {
            $values['valor_secreto'] = $secret;
        }
    }
    try {
        \Illuminate\Support\Facades\DB::table('integraciones_usuario')->updateOrInsert(
            ['usuario_id' => $userId, 'tipo' => $type],
            $values
        );
    } catch (\Throwable) {
    }
}

function usuarios_central_grant_access(int $userId, string $moduleKey = 'redmine-mantencion'): void {
    $moduleId = usuarios_central_module_id($moduleKey);
    if ($userId <= 0 || $moduleId === null || !class_exists(\Illuminate\Support\Facades\DB::class)) {
        return;
    }
    try {
        \Illuminate\Support\Facades\DB::table('permisos_usuario_modulo')->updateOrInsert(
            ['usuario_id' => $userId, 'modulo_id' => $moduleId],
            ['permitido' => 1, 'actualizado_at' => now()]
        );
    } catch (\Throwable) {
    }
}

function usuarios_central_upsert(array $user, string $moduleKey = 'redmine-mantencion'): ?int {
    if (!class_exists(\App\Models\NovaUser::class)) {
        return null;
    }
    $redmineId = trim((string)($user['id'] ?? $user['redmine_id'] ?? ''));
    if ($redmineId === '') {
        return null;
    }
    $name = trim((string)($user['nombre'] ?? $user['name'] ?? ''));
    $lastName = trim((string)($user['apellido'] ?? ''));
    if ($lastName === '' && str_contains($name, ' ')) {
        [$name, $lastName] = usuarios_split_name($name);
    }
    $name = $name !== '' ? $name : 'Redmine';
    $lastName = $lastName !== '' ? $lastName : 'Usuario';
    $rawUsername = trim((string)($user['rut_sin_dv'] ?? $user['username'] ?? ''));
    $username = $rawUsername !== '' ? $rawUsername : $redmineId;
    $rut = trim((string)($user['rut'] ?? ''));
    $status = in_array(strtolower(trim((string)($user['estado'] ?? $user['estado_usuario'] ?? 'activo'))), ['baneado', 'bloqueado', 'inactivo'], true) ? 'baneado' : 'activo';
    $role = in_array(strtolower(trim((string)($user['rol'] ?? 'usuario'))), ['admin', 'administrador', 'gestor', 'root'], true) ? 'admin' : 'usuario';
    try {
        $model = \App\Models\NovaUser::query()->where('redmine_id', $redmineId)->first();
        if (!$model && $rut !== '') {
            $model = \App\Models\NovaUser::query()->where('rut', $rut)->first();
        }
        if (!$model && $username !== '') {
            $model = \App\Models\NovaUser::query()->where('usuario', $username)->first();
        }
        if ($model && $rawUsername === '') {
            $username = trim((string)$model->usuario) ?: $username;
        }
        if (!$model) {
            $model = new \App\Models\NovaUser();
            $model->uuid = (string)\Illuminate\Support\Str::uuid();
            $model->password = \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(40));
        }
        $model->usuario = $username;
        $model->rut = $rut !== '' ? $rut : null;
        $model->redmine_id = $redmineId;
        $model->nombre = $name;
        $model->apellido = $lastName;
        $model->rol = $role;
        $model->estado = $status;
        $model->save();
        $userId = (int)$model->id;
        usuarios_central_save_integration($userId, 'redmine_mantencion', trim((string)($user['api'] ?? '')), $redmineId);
        usuarios_central_grant_access($userId, $moduleKey);
        return $userId;
    } catch (\Throwable) {
        return null;
    }
}

function usuarios_merge_central_access(array $rows, string $moduleKey = 'redmine-mantencion'): array {
    $moduleId = usuarios_central_module_id($moduleKey);
    if ($moduleId === null || !class_exists(\Illuminate\Support\Facades\DB::class)) {
        return $rows;
    }
    $indexed = [];
    foreach ($rows as $idx => $row) {
        if (is_array($row) && trim((string)($row['id'] ?? '')) !== '') {
            $indexed[trim((string)$row['id'])] = $idx;
        }
    }
    try {
        $central = \Illuminate\Support\Facades\DB::table('usuarios_nova')
            ->join('permisos_usuario_modulo', 'permisos_usuario_modulo.usuario_id', '=', 'usuarios_nova.id')
            ->where('permisos_usuario_modulo.modulo_id', $moduleId)
            ->where('permisos_usuario_modulo.permitido', 1)
            ->select('usuarios_nova.*')
            ->get();
    } catch (\Throwable) {
        return $rows;
    }
    foreach ($central as $user) {
        $redmineId = trim((string)($user->redmine_id ?? ''));
        if ($redmineId === '') {
            continue;
        }
        $row = [
            'id' => $redmineId,
            'rut_sin_dv' => trim((string)($user->usuario ?? '')),
            'nombre' => trim((string)($user->nombre ?? '')),
            'apellido' => trim((string)($user->apellido ?? '')),
            'rut' => trim((string)($user->rut ?? '')),
            'numero_celular' => '',
            'estamento' => '',
            'api' => usuarios_central_user_api($redmineId, 'redmine_mantencion'),
            'core_user' => trim((string)($user->usuario_core ?? '')),
            'core_pass_enc' => '',
            'nextcloud_user' => '',
            'nextcloud_pass_enc' => '',
            'rol' => trim((string)($user->rol ?? 'usuario')) === 'admin' ? 'administrador' : 'usuario',
            'estado' => trim((string)($user->estado ?? 'activo')),
            'password' => (string)($user->password ?? ''),
            'permisos' => [],
        ];
        if (isset($indexed[$redmineId])) {
            $rows[$indexed[$redmineId]] = array_merge($rows[$indexed[$redmineId]], $row);
        } else {
            $rows[] = $row;
            $indexed[$redmineId] = count($rows) - 1;
        }
    }
    return $rows;
}

function usuarios_members_url_from_config(): string {
    global $CONFIG_FILE;
    $cfg = storage_read_json($CONFIG_FILE, []);
    if (is_array($cfg)) {
        $custom = trim((string)($cfg['users_members_url'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        $platformUrl = trim((string)($cfg['platform_url'] ?? ''));
        if ($platformUrl !== '' && preg_match('#/issues\.json$#', $platformUrl)) {
            return preg_replace('#/issues\.json$#', '/settings/members', $platformUrl);
        }
    }
    return 'https://coresalud.cl/gp/projects/backlog-mantencion-ti/settings/members';
}

function usuarios_members_api_url(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#/settings/members/?$#', $url)) {
        return preg_replace('#/settings/members/?$#', '/memberships.json', $url);
    }
    if (preg_match('#/issues\.json$#', $url)) {
        return preg_replace('#/issues\.json$#', '/memberships.json', $url);
    }
    return $url;
}

function usuarios_split_name(string $fullName): array {
    $fullName = trim($fullName);
    if ($fullName === '') {
        return ['', ''];
    }
    [$cleanName, $detectedLastName] = usuarios_detect_repeated_suffix($fullName);
    if ($detectedLastName !== '') {
        return [$cleanName, $detectedLastName];
    }
    $parts = preg_split('/\s+/', $fullName);
    if (!$parts || count($parts) === 1) {
        return [$fullName, ''];
    }
    $lastNameLength = count($parts) >= 3 ? 2 : 1;
    $lastName = implode(' ', array_slice($parts, -$lastNameLength));
    $firstName = implode(' ', array_slice($parts, 0, -$lastNameLength));
    return [trim($firstName), trim($lastName)];
}

function usuarios_redmine_user_api_url(string $membersUrl, string $userId): string {
    $parts = parse_url($membersUrl);
    if (!$parts || empty($parts['scheme']) || empty($parts['host']) || $userId === '') {
        return '';
    }

    $path = (string)($parts['path'] ?? '');
    $prefix = preg_replace('#/projects/.*$#', '', $path);
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    return $parts['scheme'] . '://' . $parts['host'] . $port . rtrim((string)$prefix, '/') . '/users/' . rawurlencode($userId) . '.json';
}

function usuarios_fetch_redmine_user_detail(string $userId, string $apiKey, string $membersUrl): array {
    static $cache = [];

    if ($userId === '' || $apiKey === '') {
        return [];
    }

    if (array_key_exists($userId, $cache)) {
        return $cache[$userId];
    }

    $url = usuarios_redmine_user_api_url($membersUrl, $userId);
    if ($url === '') {
        return $cache[$userId] = [];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-Redmine-API-Key: ' . $apiKey,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $code >= 400) {
        return $cache[$userId] = [];
    }

    $json = json_decode((string)$resp, true);
    $detail = is_array($json['user'] ?? null) ? $json['user'] : [];

    $detailNombre = trim((string)($detail['firstname'] ?? $detail['first_name'] ?? ''));
    $detailApellido = trim((string)($detail['lastname'] ?? $detail['last_name'] ?? ''));

    if ($detailNombre === '' || $detailApellido === '') {
        $htmlDetail = usuarios_fetch_redmine_user_edit_detail($userId, $apiKey, $membersUrl);
        foreach (['firstname', 'lastname'] as $key) {
            if (trim((string)($detail[$key] ?? '')) === '' && trim((string)($htmlDetail[$key] ?? '')) !== '') {
                $detail[$key] = $htmlDetail[$key];
            }
        }
    }

    return $cache[$userId] = $detail;
}

function usuarios_fetch_redmine_user_edit_detail(string $userId, string $apiKey, string $membersUrl): array {
    $apiUrl = usuarios_redmine_user_api_url($membersUrl, $userId);
    if ($apiUrl === '') {
        return [];
    }

    $url = preg_replace('#/users/([^/]+)\.json$#', '/users/$1/edit', $apiUrl);
    if (!is_string($url) || $url === $apiUrl) {
        return [];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-Redmine-API-Key: ' . $apiKey,
            'Accept: text/html',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $html = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html === false || $code >= 400) {
        return [];
    }

    return [
        'firstname' => usuarios_html_input_value((string)$html, 'user[firstname]'),
        'lastname' => usuarios_html_input_value((string)$html, 'user[lastname]'),
    ];
}

function usuarios_html_input_value(string $html, string $name): string {
    if ($html === '' || $name === '') {
        return '';
    }

    if (!preg_match_all('/<input\b[^>]*>/i', $html, $matches)) {
        return '';
    }

    foreach ($matches[0] as $tag) {
        if (usuarios_html_attr_value($tag, 'name') !== $name) {
            continue;
        }

        return html_entity_decode(usuarios_html_attr_value($tag, 'value'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return '';
}

function usuarios_html_attr_value(string $tag, string $attribute): string {
    $quoted = '/\b' . preg_quote($attribute, '/') . '\s*=\s*([\'"])(.*?)\1/i';
    if (preg_match($quoted, $tag, $match)) {
        return (string)$match[2];
    }

    $plain = '/\b' . preg_quote($attribute, '/') . '\s*=\s*([^\s>]+)/i';
    if (preg_match($plain, $tag, $match)) {
        return trim((string)$match[1], "\"'");
    }

    return '';
}

function usuarios_redmine_person_name(array $user, string $apiKey = '', string $membersUrl = ''): array {
    $id = trim((string)($user['id'] ?? ''));
    $nombre = trim((string)($user['firstname'] ?? $user['first_name'] ?? ''));
    $apellido = trim((string)($user['lastname'] ?? $user['last_name'] ?? ''));

    if ($nombre !== '' && $apellido !== '') {
        return [$nombre, $apellido];
    }

    if ($id !== '' && $apiKey !== '' && $membersUrl !== '') {
        $detail = usuarios_fetch_redmine_user_detail($id, $apiKey, $membersUrl);
        $detailNombre = trim((string)($detail['firstname'] ?? $detail['first_name'] ?? ''));
        $detailApellido = trim((string)($detail['lastname'] ?? $detail['last_name'] ?? ''));

        if ($detailNombre !== '') {
            $nombre = $detailNombre;
        }
        if ($detailApellido !== '') {
            $apellido = $detailApellido;
        }
    }

    if ($nombre !== '' && $apellido !== '') {
        return [$nombre, $apellido];
    }

    $fullName = trim((string)($user['name'] ?? ''));
    [$splitNombre, $splitApellido] = usuarios_split_name($fullName);

    return [
        $nombre !== '' ? $nombre : $splitNombre,
        $apellido !== '' ? $apellido : $splitApellido,
    ];
}

function usuarios_sync_remote(array &$rows): array {
    global $CONFIG_FILE, $DATA_FILE;
    $cfg = storage_read_json($CONFIG_FILE, []);
    $apiKey = is_array($cfg) ? trim((string)($cfg['platform_token'] ?? '')) : '';
    if ($apiKey === '') {
        $apiKey = usuarios_user_api_token();
    }
    if ($apiKey === '') {
        return ['error' => 'Falta token API para importar usuarios. Configura el Token API en Configuracion > Conexion API o agrega la API al usuario actual en Usuarios.'];
    }
    $url = usuarios_members_api_url(usuarios_members_url_from_config());
    if ($url === '') {
        return ['error' => 'Falta URL de miembros para importar usuarios.'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-Redmine-API-Key: ' . $apiKey,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['error' => 'No se pudo conectar para importar usuarios: ' . $err];
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 400) {
        return ['error' => 'HTTP ' . $code . ' al consultar members.'];
    }
    $json = json_decode($resp, true);
    $memberships = is_array($json['memberships'] ?? null) ? $json['memberships'] : [];
    if (empty($memberships)) {
        return ['error' => 'La respuesta no contiene memberships validos.'];
    }
    $indexed = [];
    foreach ($rows as $idx => $row) {
        if (is_array($row) && isset($row['id'])) {
            $indexed[(string)$row['id']] = $idx;
        }
    }
    $created = 0;
    $updated = 0;
    foreach ($memberships as $membership) {
        if (!is_array($membership)) {
            continue;
        }
        $user = $membership['user'] ?? null;
        if (!is_array($user)) {
            continue;
        }
        $id = trim((string)($user['id'] ?? ''));
        [$nombre, $apellido] = usuarios_redmine_person_name($user, $apiKey, $url);
        if ($id === '' || ($nombre === '' && $apellido === '')) {
            continue;
        }
        if (isset($indexed[$id])) {
            $idx = $indexed[$id];
            $currentName = trim((string)($rows[$idx]['nombre'] ?? ''));
            $currentLastName = trim((string)($rows[$idx]['apellido'] ?? ''));
            if ($currentName !== $nombre || $currentLastName !== $apellido) {
                $rows[$idx]['nombre'] = $nombre;
                $rows[$idx]['apellido'] = $apellido;
                $rows[$idx]['rut_sin_dv'] = '';
                $rows[$idx]['rut'] = '';
                $rows[$idx]['estamento'] = '';
                $updated++;
            }
            usuarios_central_upsert($rows[$idx]);
            continue;
        }
        $newRow = [
            'id' => $id,
            'rut_sin_dv' => '',
            'nombre' => $nombre !== '' ? $nombre : trim((string)($user['name'] ?? 'Redmine')),
            'apellido' => $apellido,
            'rut' => '',
            'numero_celular' => '',
            'estamento' => '',
                'api' => '',
                'core_user' => '',
                'core_pass_enc' => '',
                'nextcloud_user' => '',
                'nextcloud_pass_enc' => '',
                'rol' => 'usuario',
                'estado' => 'baneado',
            'password' => '',
            'permisos' => [],
        ];
        $rows[] = $newRow;
        usuarios_central_upsert($newRow);
        $indexed[$id] = count($rows) - 1;
        $created++;
    }
    save_usuarios($DATA_FILE, $rows);
    return ['ok' => true, 'created' => $created, 'updated' => $updated];
}

function handle_usuarios() {
    global $DATA_FILE;
    $rows = load_usuarios($DATA_FILE);
    if (usuarios_migrate_global_nextcloud_credentials($rows)) {
        save_usuarios($DATA_FILE, $rows);
    }
    $flash = usuarios_consume_flash();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('csrf_validate')) csrf_validate();
        $action = $_POST['action'] ?? '';
        if (function_exists('maintenance_mode_block_if_enabled')) {
            maintenance_mode_block_if_enabled();
        }
        $id_input = sanitize_input($_POST['id_manual'] ?? '');

        if ($action === 'create') {
            if ($id_input !== '' && has_duplicate_id($rows, $id_input)) {
                return [$rows, 'Error: el ID ya existe'];
            }
            $assignedRole = sanitize_input($_POST['rol'] ?? 'usuario');
            $rolePerms = [];
            if (function_exists('auth_load_roles')) {
                $roles = auth_load_roles();
                $cfg = $roles[$assignedRole] ?? [];
                if (is_array($cfg)) {
                    $rolePerms = $cfg;
                }
            }
            $requiredName = sanitize_input($_POST['nombre'] ?? '');
            if ($requiredName === '') {
                return [$rows, 'Error: el nombre es obligatorio'];
            }
            [$newNombre, $newApellido] = usuarios_split_name($requiredName);
            $newRow = [
                'id' => $id_input !== '' ? $id_input : uniqid('', true),
                'rut_sin_dv' => '',
                'nombre' => $newNombre !== '' ? $newNombre : $requiredName,
                'apellido' => $newApellido,
                'rut' => '',
                'numero_celular' => '',
                'estamento' => '',
                'rol' => $assignedRole,
                'estado' => in_array(($_POST['estado'] ?? 'activo'), ['activo', 'baneado'], true) ? $_POST['estado'] : 'activo',
                'api' => sanitize_input($_POST['api'] ?? ''),
                'core_user' => sanitize_input($_POST['core_user'] ?? ''),
                'core_pass_enc' => core_credentials_encrypt(sanitize_input($_POST['core_pass'] ?? '')),
                'nextcloud_user' => sanitize_input($_POST['nextcloud_user'] ?? ''),
                'nextcloud_pass_enc' => core_credentials_encrypt(sanitize_input($_POST['nextcloud_pass'] ?? '')),
                'permisos' => $rolePerms,
            ];
            $rows[] = $newRow;
            usuarios_central_upsert($newRow);
            save_usuarios($DATA_FILE, $rows);
            usuarios_set_flash('Usuario creado');
            usuarios_redirect_back();
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? '';
            $index = find_user_index($rows, $id);
            if ($index === null) return [$rows, 'Error: usuario no encontrado'];
            $current = &$rows[$index];
            $requiredNameUp = sanitize_input($_POST['nombre'] ?? $current['nombre']);
            if ($requiredNameUp === '') {
                return [$rows, 'Error: el nombre es obligatorio'];
            }
            [$upNombre, $upApellido] = usuarios_split_name($requiredNameUp);
            $current['rut_sin_dv'] = '';
            $current['nombre'] = $upNombre !== '' ? $upNombre : $requiredNameUp;
            $current['apellido'] = $upApellido;
            $current['rut'] = '';
            $current['numero_celular'] = '';
            $current['estamento'] = '';
            $current['rol'] = sanitize_input($_POST['rol'] ?? ($current['rol'] ?? 'usuario'));
            $postedEstado = sanitize_input($_POST['estado'] ?? ($current['estado'] ?? 'activo'));
            $current['estado'] = in_array($postedEstado, ['activo', 'baneado'], true) ? $postedEstado : 'activo';
            $postedApi = sanitize_input($_POST['api'] ?? '');
            if ($postedApi !== '') {
                $current['api'] = $postedApi;
            }
            usuarios_central_upsert($current);
            $postedCoreUser = sanitize_input($_POST['core_user'] ?? '');
            $postedCorePass = sanitize_input($_POST['core_pass'] ?? '');
            if (!empty($_POST['core_clear_credentials'])) {
                $current['core_user'] = '';
                $current['core_pass_enc'] = '';
                unset($current['core_pass']);
            } else {
                if ($postedCoreUser !== '') {
                    $current['core_user'] = $postedCoreUser;
                }
                if ($postedCorePass !== '') {
                    $current['core_pass_enc'] = core_credentials_encrypt($postedCorePass);
                    unset($current['core_pass']);
                }
            }
            $postedNextcloudUser = sanitize_input($_POST['nextcloud_user'] ?? '');
            $postedNextcloudPass = sanitize_input($_POST['nextcloud_pass'] ?? '');
            if (!empty($_POST['nextcloud_clear_credentials'])) {
                $current['nextcloud_user'] = '';
                $current['nextcloud_pass_enc'] = '';
                unset($current['nextcloud_pass']);
            } else {
                if ($postedNextcloudUser !== '') {
                    $current['nextcloud_user'] = $postedNextcloudUser;
                }
                if ($postedNextcloudPass !== '') {
                    $current['nextcloud_pass_enc'] = core_credentials_encrypt($postedNextcloudPass);
                    unset($current['nextcloud_pass']);
                }
            }
            save_usuarios($DATA_FILE, $rows);
            usuarios_set_flash('Usuario actualizado');
            usuarios_redirect_back();
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            $rows = array_values(array_filter($rows, fn($r) => (string)($r['id'] ?? '') !== (string)$id));
            save_usuarios($DATA_FILE, $rows);
            usuarios_set_flash('Usuario eliminado');
            usuarios_redirect_back();
        } elseif ($action === 'sync_remote') {
            $res = usuarios_sync_remote($rows);
            if (isset($res['error'])) {
                return [$rows, $res['error']];
            }
            usuarios_set_flash('Usuarios importados. Nuevos: ' . (int)($res['created'] ?? 0) . ' | actualizados: ' . (int)($res['updated'] ?? 0));
            usuarios_redirect_back();
        }
    }
    return [$rows, $flash];
}
