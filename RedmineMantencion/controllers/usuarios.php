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

$DATA_FILE = '';
$GLOBALS['DATA_FILE'] = $DATA_FILE;

function rut_base($rut) {
    $clean = preg_replace('/[^0-9kK]/', '', $rut ?? '');
    if ($clean === '') return '';
    $clean = strtoupper($clean);
    return strlen($clean) > 1 ? substr($clean, 0, -1) : $clean;
}

function ensure_usr_file($path) {
    // DB-only runtime: usuarios_nova/permisos_usuario_modulo are the source of truth.
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

function usuarios_sort_for_project(array $rows): array {
    usort($rows, static function (array $a, array $b): int {
        $stateA = strtolower(trim((string)($a['estado'] ?? $a['estado_usuario'] ?? 'activo'))) === 'baneado' ? 1 : 0;
        $stateB = strtolower(trim((string)($b['estado'] ?? $b['estado_usuario'] ?? 'activo'))) === 'baneado' ? 1 : 0;
        if ($stateA !== $stateB) {
            return $stateA <=> $stateB;
        }

        $nameA = trim((string)($a['nombre'] ?? '') . ' ' . (string)($a['apellido'] ?? ''));
        $nameB = trim((string)($b['nombre'] ?? '') . ' ' . (string)($b['apellido'] ?? ''));
        return strcasecmp($nameA, $nameB);
    });

    return array_values($rows);
}

function load_usuarios($path) {
    $data = function_exists('auth_central_users_for_mantencion') ? auth_central_users_for_mantencion(false) : [];
    if (!is_array($data)) $data = [];
    foreach ($data as &$item) {
        ensure_user_fields($item);
    }
    unset($item);
    return usuarios_sort_for_project($data);
}

function save_usuarios($path, $data) {
    if (!is_array($data)) {
        return;
    }
    foreach (array_values($data) as $row) {
        if (is_array($row)) {
            usuarios_central_upsert($row);
        }
    }
}

function usuarios_norm_identity(string $value): string {
    return strtolower((string)preg_replace('/[^0-9a-z]/i', '', $value));
}

function usuarios_normalize_status(string $status): string {
    return in_array(strtolower(trim($status)), ['baneado', 'bloqueado', 'inactivo'], true) ? 'baneado' : 'activo';
}

function usuarios_migrate_global_nextcloud_credentials(array &$rows): bool {
    $userId = function_exists('auth_get_user_id') ? (string)auth_get_user_id() : '';
    if ($userId === '') {
        return false;
    }
    $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
    $cfg = $repo !== null ? $repo->loadAll() : [];
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
    if ($repo !== null) {
        $repo->saveAll($cfg);
    }
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

function usuarios_central_integration_external(int $userId, string $type): string {
    if ($userId <= 0 || !class_exists(\Illuminate\Support\Facades\DB::class)) {
        return '';
    }
    try {
        return trim((string)\Illuminate\Support\Facades\DB::table('integraciones_usuario')
            ->where('usuario_id', $userId)
            ->where('tipo', $type)
            ->value('usuario_externo'));
    } catch (\Throwable) {
        return '';
    }
}

function usuarios_central_integration_has_secret(int $userId, string $type): bool {
    if ($userId <= 0 || !class_exists(\Illuminate\Support\Facades\DB::class)) {
        return false;
    }
    try {
        $secret = \Illuminate\Support\Facades\DB::table('integraciones_usuario')
            ->where('usuario_id', $userId)
            ->where('tipo', $type)
            ->value('valor_secreto');

        return trim((string)$secret) !== '';
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Low-level writer: persists $secretValue verbatim (no encryption applied
 * here). Callers are responsible for handing it an already-safe value
 * (either freshly encrypted, or an untouched already-encrypted ciphertext).
 */
function usuarios_central_write_integration(int $userId, string $type, ?string $secretValue, string $externalUser = ''): void {
    if ($userId <= 0 || !class_exists(\Illuminate\Support\Facades\DB::class)) {
        return;
    }
    if (($secretValue === null || $secretValue === '') && $externalUser === '') {
        return;
    }
    $values = [
        'usuario_externo' => $externalUser !== '' ? $externalUser : null,
        'actualizado_at' => now(),
    ];
    if ($secretValue !== null && $secretValue !== '') {
        $values['valor_secreto'] = $secretValue;
    }
    try {
        \Illuminate\Support\Facades\DB::table('integraciones_usuario')->updateOrInsert(
            ['usuario_id' => $userId, 'tipo' => $type],
            $values
        );
    } catch (\Throwable) {
    }
}

function usuarios_central_save_integration(int $userId, string $type, string $secret = '', string $externalUser = ''): void {
    $encrypted = null;
    if ($secret !== '') {
        try {
            $encrypted = \App\Modulos\Nova\Support\SecretValue::encryptSecret($secret);
        } catch (\Throwable) {
            // Never persist plaintext nor touch the previous credential on a real encryption failure.
            return;
        }
    }
    usuarios_central_write_integration($userId, $type, $encrypted, $externalUser);
}

/**
 * Round-trips whatever is currently stored in valor_secreto (used by the
 * admin "usuarios" form, which has no editable password field and only
 * resaves the value it just read). Detects the actual format via
 * SecretValue instead of assuming enc:v1 — an already Laravel-encrypted
 * value is passed through unchanged (no double-encrypt), a decodable
 * legacy value gets re-encrypted, and an invalid value is left untouched.
 */
function usuarios_central_save_integration_encrypted(int $userId, string $type, string $storedSecret = '', string $externalUser = ''): void {
    if ($storedSecret === '') {
        usuarios_central_write_integration($userId, $type, null, $externalUser);
        return;
    }

    $inspection = \App\Modulos\Nova\Support\SecretValue::inspect($storedSecret);
    if ($inspection['status'] === 'invalid') {
        usuarios_central_write_integration($userId, $type, null, $externalUser);
        return;
    }

    if (!$inspection['needs_rewrite']) {
        usuarios_central_write_integration($userId, $type, $storedSecret, $externalUser);
        return;
    }

    $plaintext = \App\Modulos\Nova\Support\SecretValue::decryptSecret($storedSecret);
    usuarios_central_save_integration($userId, $type, (string) $plaintext, $externalUser);
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

function usuarios_central_revoke_access(int $userId, string $moduleKey = 'redmine-mantencion'): bool {
    $moduleId = usuarios_central_module_id($moduleKey);
    if ($userId <= 0 || $moduleId === null || !class_exists(\Illuminate\Support\Facades\DB::class)) {
        return false;
    }
    try {
        return \Illuminate\Support\Facades\DB::table('permisos_usuario_modulo')
            ->where('usuario_id', $userId)
            ->where('modulo_id', $moduleId)
            ->delete() > 0;
    } catch (\Throwable) {
        return false;
    }
}

function usuarios_central_id_for_project_user(array $user): ?int {
    if (!class_exists(\Illuminate\Support\Facades\DB::class)) {
        return null;
    }
    $redmineId = trim((string)($user['redmine_id'] ?? ''));
    if ($redmineId === '' && ctype_digit(trim((string)($user['id'] ?? '')))) {
        $redmineId = trim((string)$user['id']);
    }
    $uuid = trim((string)($user['_nova_user_id'] ?? ''));
    if ($uuid === '' && $redmineId === '' && !ctype_digit(trim((string)($user['id'] ?? '')))) {
        $uuid = trim((string)($user['id'] ?? ''));
    }
    $username = trim((string)($user['rut_sin_dv'] ?? $user['username'] ?? ''));

    try {
        $id = null;
        if ($redmineId !== '') {
            $id = \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('redmine_id', $redmineId)->value('id');
        }
        if (!$id && $uuid !== '') {
            $id = \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('uuid', $uuid)->value('id');
        }
        if (!$id && $username !== '') {
            $id = \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('usuario', $username)->value('id');
        }
        return $id ? (int)$id : null;
    } catch (\Throwable) {
        return null;
    }
}

function usuarios_central_upsert(array $user, string $moduleKey = 'redmine-mantencion'): ?int {
    if (!class_exists(\Illuminate\Support\Facades\DB::class)) {
        return null;
    }
    $redmineId = trim((string)($user['redmine_id'] ?? ''));
    if ($redmineId === '' && ctype_digit(trim((string)($user['id'] ?? '')))) {
        $redmineId = trim((string)$user['id']);
    }
    $uuid = trim((string)($user['_nova_user_id'] ?? ''));
    if ($uuid === '' && $redmineId === '' && !ctype_digit(trim((string)($user['id'] ?? '')))) {
        $uuid = trim((string)($user['id'] ?? ''));
    }
    if ($redmineId === '' && $uuid === '') {
        return null;
    }
    $name = trim((string)($user['nombre'] ?? $user['name'] ?? ''));
    $lastName = trim((string)($user['apellido'] ?? ''));
    if ($lastName === '' && str_contains($name, ' ')) {
        [$name, $lastName] = usuarios_split_name($name);
    }
    $name = $name !== '' ? $name : 'Redmine';
    $lastName = $lastName !== '' ? $lastName : 'Usuario';
    $identityService = app(\App\Modulos\Nova\Services\RedmineIdentityService::class);
    $rawUsername = trim((string)($user['rut_sin_dv'] ?? $user['username'] ?? ''));
    $username = $rawUsername !== '' ? $rawUsername : $redmineId;
    $rut = $identityService->rutFromLogin((string)($user['rut'] ?? ''));
    $incomingStatus = array_key_exists('estado', $user) || array_key_exists('estado_usuario', $user)
        ? (string)($user['estado'] ?? $user['estado_usuario'] ?? '')
        : '';
    $status = $incomingStatus !== ''
        ? usuarios_normalize_status($incomingStatus)
        : '';
    $roleRaw = strtolower(trim((string)($user['rol'] ?? 'usuario')));
    $role = in_array($roleRaw, ['administrador', 'gestor', 'root'], true) ? $roleRaw : 'usuario';
    try {
        $row = null;
        if ($redmineId !== '') {
            $row = \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('redmine_id', $redmineId)->first();
        }
        if (!$row && $uuid !== '') {
            $row = \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('uuid', $uuid)->first();
        }
        if (!$row && $rut !== '') {
            $match = $identityService->centralUserByLogin($rut);
            $row = $match !== null
                ? \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('id', $match['id'])->first()
                : null;
        }
        if (!$row && $rawUsername !== '') {
            $match = $identityService->centralUserByLogin($rawUsername);
            $row = $match !== null
                ? \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('id', $match['id'])->first()
                : null;
        }
        if ($row && !empty($user['_preserve_existing_status'])) {
            $status = '';
        }
        if ($row && $rawUsername === '') {
            $username = trim((string)$row->usuario) ?: $username;
        }
        if ($row && $rut === '') {
            $rut = trim((string)$row->rut);
        }
        if (!$row && $status === '') {
            $status = 'activo';
        }

        $values = [
            'usuario'        => $username,
            'rut'            => $rut !== '' ? $rut : null,
            'nombre'         => $name,
            'apellido'       => $lastName,
            'rol'            => $role,
            'actualizado_at' => now(),
        ];
        if ($redmineId !== '') {
            $values['redmine_id'] = $redmineId;
        }
        if ($status !== '') {
            $values['estado'] = $status;
        }

        if ($row) {
            \Illuminate\Support\Facades\DB::table('usuarios_nova')->where('id', $row->id)->update($values);
            $userId = (int)$row->id;
        } else {
            $values['uuid']      = (string)\Illuminate\Support\Str::uuid();
            $values['password']  = \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(40));
            $values['creado_at'] = now();
            $userId = (int)\Illuminate\Support\Facades\DB::table('usuarios_nova')->insertGetId($values);
        }
        if ($redmineId !== '') {
            $identityService->syncRedmineIdAndIntegrations($userId, $redmineId);
        }
        usuarios_central_save_integration($userId, 'redmine_mantencion', trim((string)($user['api'] ?? '')), $redmineId);
        usuarios_central_save_integration_encrypted($userId, 'core', trim((string)($user['core_pass_enc'] ?? '')), trim((string)($user['core_user'] ?? '')));
        usuarios_central_save_integration_encrypted($userId, 'nextcloud', trim((string)($user['nextcloud_pass_enc'] ?? '')), trim((string)($user['nextcloud_user'] ?? '')));
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

    // Prefetch: one query for integraciones_usuario covering every user in $central,
    // instead of the 4-5 per-user queries (usuarios_central_user_api/_integration_external/
    // _integration_has_secret) this loop used to run — see Fase 4 lote 1.
    $centralIds = [];
    foreach ($central as $user) {
        $id = (int)($user->id ?? 0);
        if ($id > 0) {
            $centralIds[] = $id;
        }
    }
    $integrationsByUser = [];
    if ($centralIds !== []) {
        try {
            $integrationRows = \Illuminate\Support\Facades\DB::table('integraciones_usuario')
                ->whereIn('usuario_id', $centralIds)
                ->whereIn('tipo', ['redmine_mantencion', 'core', 'nextcloud'])
                ->get(['usuario_id', 'tipo', 'usuario_externo', 'valor_secreto']);
            foreach ($integrationRows as $integrationRow) {
                $integrationsByUser[(int)$integrationRow->usuario_id][(string)$integrationRow->tipo] = [
                    'usuario_externo' => (string)($integrationRow->usuario_externo ?? ''),
                    'valor_secreto' => (string)($integrationRow->valor_secreto ?? ''),
                ];
            }
        } catch (\Throwable) {
            $integrationsByUser = [];
        }
    }

    foreach ($central as $user) {
        $redmineId = trim((string)($user->redmine_id ?? ''));
        $rowId = $redmineId !== '' ? $redmineId : trim((string)($user->uuid ?? $user->usuario ?? ''));
        if ($rowId === '') {
            continue;
        }
        $userIntegrations = $integrationsByUser[(int)($user->id ?? 0)] ?? [];
        // usuarios_central_user_api() returned '' immediately when $redmineId === '';
        // preserved here so central-only users keep the exact same 'api' value.
        $apiSecret = $redmineId !== '' ? (string)($userIntegrations['redmine_mantencion']['valor_secreto'] ?? '') : '';
        $coreExternal = trim((string)($userIntegrations['core']['usuario_externo'] ?? ''));
        $coreSecret = trim((string)($userIntegrations['core']['valor_secreto'] ?? ''));
        $nextcloudExternal = trim((string)($userIntegrations['nextcloud']['usuario_externo'] ?? ''));
        $nextcloudSecret = trim((string)($userIntegrations['nextcloud']['valor_secreto'] ?? ''));
        $row = [
            'id' => $rowId,
            'redmine_id' => $redmineId,
            'rut_sin_dv' => trim((string)($user->usuario ?? '')),
            'nombre' => trim((string)($user->nombre ?? '')),
            'apellido' => trim((string)($user->apellido ?? '')),
            'rut' => trim((string)($user->rut ?? '')),
            'numero_celular' => '',
            'estamento' => '',
            'api' => usuarios_central_decrypt_secret($apiSecret),
            'core_user' => trim((string)($user->usuario_core ?? '')) ?: $coreExternal,
            'core_pass_enc' => '',
            'has_core_credentials' => $coreSecret !== '',
            'nextcloud_user' => $nextcloudExternal,
            'nextcloud_pass_enc' => '',
            'has_nextcloud_credentials' => $nextcloudSecret !== '',
            'rol' => trim((string)($user->rol ?? 'usuario')) === 'admin' ? 'administrador' : 'usuario',
            'estado' => trim((string)($user->estado ?? 'activo')),
            'password' => (string)($user->password ?? ''),
            'permisos' => [],
            '_central_only' => $redmineId === '',
        ];
        if (isset($indexed[$rowId])) {
            $rows[$indexed[$rowId]] = array_merge($rows[$indexed[$rowId]], $row);
        } else {
            $rows[] = $row;
            $indexed[$rowId] = count($rows) - 1;
        }
    }
    return $rows;
}

function usuarios_members_url_from_config(): string {
    $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
    $cfg = $repo !== null ? $repo->loadAll() : [];
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

function usuarios_url_with_query(string $url, array $params): string {
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }

    $query = [];
    if (!empty($parts['query'])) {
        parse_str((string)$parts['query'], $query);
    }
    foreach ($params as $key => $value) {
        $query[$key] = $value;
    }

    $rebuilt = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $rebuilt .= ':' . $parts['port'];
    }
    $rebuilt .= (string)($parts['path'] ?? '');
    if ($query !== []) {
        $rebuilt .= '?' . http_build_query($query);
    }
    if (!empty($parts['fragment'])) {
        $rebuilt .= '#' . $parts['fragment'];
    }

    return $rebuilt;
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

function usuarios_redmine_person_identity(array $user, string $apiKey = '', string $membersUrl = ''): array {
    $id = trim((string)($user['id'] ?? ''));
    $detail = $user;
    $login = trim((string)($user['login'] ?? ''));
    $nombre = trim((string)($user['firstname'] ?? $user['first_name'] ?? ''));
    $apellido = trim((string)($user['lastname'] ?? $user['last_name'] ?? ''));

    if ($id !== '' && $apiKey !== '' && $membersUrl !== '' && ($login === '' || $nombre === '' || $apellido === '')) {
        $remoteDetail = usuarios_fetch_redmine_user_detail($id, $apiKey, $membersUrl);
        if ($remoteDetail !== []) {
            $detail = array_merge($user, $remoteDetail);
        }
    }

    [$nombre, $apellido] = usuarios_redmine_person_name($detail, $apiKey, $membersUrl);

    return [
        'id' => $id,
        'nombre' => $nombre,
        'apellido' => $apellido,
        'login' => trim((string)($detail['login'] ?? $login)),
        'mail' => trim((string)($detail['mail'] ?? $detail['email'] ?? '')),
    ];
}

function usuarios_remote_connection(): array {
    $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
    $cfg = $repo !== null ? $repo->loadAll() : [];
    $apiKey = usuarios_user_api_token();
    if ($apiKey === '') {
        return ['error' => 'Falta token API para importar usuarios. Agrega tu API personal en Cuentas conectadas.'];
    }
    $url = usuarios_members_api_url(usuarios_members_url_from_config());
    if ($url === '') {
        return ['error' => 'Falta URL de miembros para importar usuarios.'];
    }

    return ['apiKey' => $apiKey, 'url' => $url];
}

function usuarios_fetch_remote_memberships(): array {
    $connection = usuarios_remote_connection();
    if (isset($connection['error'])) {
        return $connection;
    }

    $apiKey = (string)$connection['apiKey'];
    $url = (string)$connection['url'];
    $memberships = [];
    $offset = 0;
    $limit = 100;
    do {
        $pageUrl = usuarios_url_with_query($url, ['limit' => $limit, 'offset' => $offset]);
        $ch = curl_init($pageUrl);
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
        $page = is_array($json['memberships'] ?? null) ? $json['memberships'] : [];
        $memberships = array_merge($memberships, $page);
        $total = (int)($json['total_count'] ?? count($memberships));
        $offset += $limit;
    } while ($offset < $total);

    if (empty($memberships)) {
        return ['error' => 'La respuesta no contiene memberships validos.'];
    }

    return ['ok' => true, 'memberships' => $memberships, 'apiKey' => $apiKey, 'url' => $url];
}

function usuarios_central_access_status_by_redmine_id(string $redmineId, string $moduleKey = 'redmine-mantencion'): array {
    if ($redmineId === '' || !class_exists(\Illuminate\Support\Facades\DB::class)) {
        return ['exists' => false, 'has_access' => false];
    }
    $moduleId = usuarios_central_module_id($moduleKey);
    try {
        $user = \Illuminate\Support\Facades\DB::table('usuarios_nova')
            ->where('redmine_id', $redmineId)
            ->first(['id']);
        if (!$user) {
            return ['exists' => false, 'has_access' => false];
        }
        $hasAccess = false;
        if ($moduleId !== null) {
            $hasAccess = \Illuminate\Support\Facades\DB::table('permisos_usuario_modulo')
                ->where('usuario_id', (int)$user->id)
                ->where('modulo_id', $moduleId)
                ->where('permitido', 1)
                ->exists();
        }

        return ['exists' => true, 'has_access' => $hasAccess];
    } catch (\Throwable) {
        return ['exists' => false, 'has_access' => false];
    }
}

function usuarios_remote_import_preview(array $rows): array {
    $remote = usuarios_fetch_remote_memberships();
    if (isset($remote['error'])) {
        return $remote;
    }

    $currentAccess = [];
    foreach ($rows as $row) {
        if (is_array($row) && trim((string)($row['id'] ?? '')) !== '') {
            $currentAccess[trim((string)$row['id'])] = true;
        }
    }

    $items = [];
    $identityService = app(\App\Modulos\Nova\Services\RedmineIdentityService::class);
    foreach (($remote['memberships'] ?? []) as $membership) {
        if (!is_array($membership)) {
            continue;
        }
        $user = $membership['user'] ?? null;
        if (!is_array($user)) {
            continue;
        }
        $id = trim((string)($user['id'] ?? ''));
        $identity = usuarios_redmine_person_identity($user, (string)$remote['apiKey'], (string)$remote['url']);
        $nombre = $identity['nombre'];
        $apellido = $identity['apellido'];
        $login = $identity['login'];
        if ($id === '' || ($nombre === '' && $apellido === '')) {
            continue;
        }
        $central = usuarios_central_access_status_by_redmine_id($id);
        $localMatch = $identityService->projectUserIndexByLogin($rows, $login);
        $centralMatch = $identityService->centralUserByLogin($login);
        $previousId = $localMatch !== null
            ? trim((string)($rows[$localMatch]['redmine_id'] ?? $rows[$localMatch]['id'] ?? ''))
            : trim((string)($centralMatch['redmine_id'] ?? ''));
        $changedId = $previousId !== '' && $previousId !== $id;
        $items[] = [
            'id' => $id,
            'nombre' => $nombre !== '' ? $nombre : trim((string)($user['name'] ?? 'Redmine')),
            'apellido' => $apellido,
            'login' => $login,
            'previous_id' => $changedId ? $previousId : '',
            'status' => $changedId
                ? 'changed'
                : (isset($currentAccess[$id]) || $central['has_access'] ? 'current' : ($central['exists'] ? 'revoked' : 'new')),
        ];
    }

    usort($items, static fn (array $a, array $b): int => strcasecmp(trim($a['nombre'] . ' ' . $a['apellido']), trim($b['nombre'] . ' ' . $b['apellido'])));

    return ['ok' => true, 'items' => $items];
}

function usuarios_sync_remote(array &$rows, ?array $selectedIds = null): array {
    global $DATA_FILE;

    $remote = usuarios_fetch_remote_memberships();
    if (isset($remote['error'])) {
        return $remote;
    }
    $memberships = $remote['memberships'] ?? [];
    $apiKey = (string)($remote['apiKey'] ?? '');
    $url = (string)($remote['url'] ?? '');
    $selected = null;
    if (is_array($selectedIds)) {
        $selected = [];
        foreach ($selectedIds as $id) {
            $id = trim((string)$id);
            if ($id !== '') {
                $selected[$id] = true;
            }
        }
        if ($selected === []) {
            return ['error' => 'Selecciona al menos un usuario para importar.'];
        }
    }

    $indexed = [];
    foreach ($rows as $idx => $row) {
        if (is_array($row) && isset($row['id'])) {
            $indexed[(string)$row['id']] = $idx;
        }
    }
    $created = 0;
    $updated = 0;
    $identityService = app(\App\Modulos\Nova\Services\RedmineIdentityService::class);
    foreach ($memberships as $membership) {
        if (!is_array($membership)) {
            continue;
        }
        $user = $membership['user'] ?? null;
        if (!is_array($user)) {
            continue;
        }
        $id = trim((string)($user['id'] ?? ''));
        if ($selected !== null && !isset($selected[$id])) {
            continue;
        }
        $identity = usuarios_redmine_person_identity($user, $apiKey, $url);
        $nombre = $identity['nombre'];
        $apellido = $identity['apellido'];
        $login = $identity['login'];
        if ($id === '' || ($nombre === '' && $apellido === '')) {
            continue;
        }
        $idx = $indexed[$id] ?? $identityService->projectUserIndexByLogin($rows, $login);
        if ($idx !== null) {
            $previousId = trim((string)($rows[$idx]['redmine_id'] ?? $rows[$idx]['id'] ?? ''));
            $rows[$idx]['id'] = $id;
            $rows[$idx]['redmine_id'] = $id;
            $currentName = trim((string)($rows[$idx]['nombre'] ?? ''));
            $currentLastName = trim((string)($rows[$idx]['apellido'] ?? ''));
            if ($currentName !== $nombre || $currentLastName !== $apellido) {
                $rows[$idx]['nombre'] = $nombre;
                $rows[$idx]['apellido'] = $apellido;
            }
            if ($previousId !== '' && $previousId !== $id) {
                unset($indexed[$previousId]);
            }
            $indexed[$id] = $idx;
            $updated++;
            $rows[$idx]['_preserve_existing_status'] = true;
            usuarios_central_upsert($rows[$idx]);
            unset($rows[$idx]['_preserve_existing_status']);
            continue;
        }
        $centralMatch = $identityService->centralUserByLogin($login);
        $newRow = [
            'id' => $id,
            'redmine_id' => $id,
            'rut_sin_dv' => $centralMatch['usuario'] ?? $identityService->accessUsernameFromLogin($login),
            'nombre' => $nombre !== '' ? $nombre : trim((string)($user['name'] ?? 'Redmine')),
            'apellido' => $apellido,
            'rut' => $centralMatch['rut'] ?? $identityService->rutFromLogin($login),
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
            '_preserve_existing_status' => true,
        ];
        if ($centralMatch !== null) {
            $newRow['_nova_user_id'] = $centralMatch['uuid'];
        }
        $rows[] = $newRow;
        usuarios_central_upsert($newRow);
        $indexed[$id] = count($rows) - 1;
        if ($centralMatch !== null) {
            $updated++;
        } else {
            $created++;
        }
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
    $importPreview = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('csrf_validate')) csrf_validate();
        $action = $_POST['action'] ?? '';
        if (function_exists('maintenance_mode_block_if_enabled')) {
            maintenance_mode_block_if_enabled();
        }
        $id_input = sanitize_input($_POST['id_manual'] ?? '');

        if ($action === 'create') {
            if ($id_input !== '' && has_duplicate_id($rows, $id_input)) {
                return [$rows, 'Error: el ID ya existe', $importPreview];
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
            $requiredLast = sanitize_input($_POST['apellido'] ?? '');
            if ($requiredName === '') {
                return [$rows, 'Error: el nombre es obligatorio', $importPreview];
            }
            [$newNombre, $newApellido] = $requiredLast !== ''
                ? [$requiredName, $requiredLast]
                : usuarios_split_name($requiredName);
            if ($newApellido === '') {
                return [$rows, 'Error: el apellido es obligatorio', $importPreview];
            }
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
                'api' => '',
                'core_user' => '',
                'core_pass_enc' => '',
                'nextcloud_user' => '',
                'nextcloud_pass_enc' => '',
                'permisos' => $rolePerms,
            ];
            $rows[] = $newRow;
            // Punctual create: usuarios_central_upsert() already persists this one
            // record. save_usuarios($DATA_FILE, $rows) would loop and re-upsert
            // every user in $rows for no additional effect (same antipattern fixed
            // in dashboard.php's 'update' case) — removed.
            usuarios_central_upsert($newRow);
            usuarios_set_flash('Usuario creado');
            usuarios_redirect_back();
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? '';
            $index = find_user_index($rows, $id);
            if ($index === null) return [$rows, 'Error: usuario no encontrado', $importPreview];
            $current = &$rows[$index];
            $current['rol'] = sanitize_input($_POST['rol'] ?? ($current['rol'] ?? 'usuario'));
            $current['_preserve_existing_status'] = true;
            // Punctual update: usuarios_central_upsert() already persists this one
            // record; save_usuarios($DATA_FILE, $rows) was a redundant full re-upsert
            // of every user (see note in the 'create' branch above) — removed.
            usuarios_central_upsert($current);
            unset($current['_preserve_existing_status']);
            usuarios_set_flash('Rol de proyecto actualizado');
            usuarios_redirect_back();
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            $index = find_user_index($rows, $id);
            if ($index === null) return [$rows, 'Error: usuario no encontrado', $importPreview];
            $centralId = usuarios_central_id_for_project_user($rows[$index]);
            if ($centralId === null || !usuarios_central_revoke_access($centralId)) {
                return [$rows, 'No se pudo quitar el acceso al proyecto', $importPreview];
            }
            usuarios_set_flash('Acceso al proyecto eliminado');
            usuarios_redirect_back();
        } elseif ($action === 'preview_remote') {
            $res = usuarios_remote_import_preview($rows);
            if (isset($res['error'])) {
                return [$rows, $res['error'], $importPreview];
            }
            $importPreview = $res['items'] ?? [];
            $flash = 'Selecciona los usuarios que quieres importar desde Redmine.';
        } elseif ($action === 'sync_remote') {
            $selectedIds = is_array($_POST['remote_user_ids'] ?? null) ? $_POST['remote_user_ids'] : [];
            $res = usuarios_sync_remote($rows, $selectedIds);
            if (isset($res['error'])) {
                return [$rows, $res['error'], $importPreview];
            }
            usuarios_set_flash('Usuarios importados. Nuevos: ' . (int)($res['created'] ?? 0) . ' | actualizados: ' . (int)($res['updated'] ?? 0));
            usuarios_redirect_back();
        }
    }
    return [$rows, $flash, $importPreview];
}
