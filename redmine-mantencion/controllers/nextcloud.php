<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/core_credentials.php';
require_once __DIR__ . '/maintenance.php';

$GLOBALS['NEXTCLOUD_CONFIG_FILE'] = __DIR__ . '/../data/configuracion.json';
$GLOBALS['NEXTCLOUD_CREATED_HISTORY_FILE'] = __DIR__ . '/../data/nextcloud_created_history.json';

function nextcloud_set_flash(string $message): void {
    auth_start_session();
    $_SESSION['nextcloud_flash'] = $message;
}

function nextcloud_consume_flash(): ?string {
    auth_start_session();
    $message = $_SESSION['nextcloud_flash'] ?? null;
    unset($_SESSION['nextcloud_flash']);
    return $message;
}

function nextcloud_set_last_import(array $result): void {
    auth_start_session();
    $_SESSION['nextcloud_last_import'] = $result;
}

function nextcloud_consume_last_import(): array {
    auth_start_session();
    $result = $_SESSION['nextcloud_last_import'] ?? [];
    unset($_SESSION['nextcloud_last_import']);
    return is_array($result) ? $result : [];
}

function nextcloud_set_preview(array $preview): void {
    auth_start_session();
    $_SESSION['nextcloud_preview'] = $preview;
}

function nextcloud_consume_preview(): array {
    auth_start_session();
    $preview = $_SESSION['nextcloud_preview'] ?? [];
    unset($_SESSION['nextcloud_preview']);
    return is_array($preview) ? $preview : [];
}

function nextcloud_redirect_back(): void {
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/redmine-mantencion/views/Integraciones/Nextcloud.php'));
    exit;
}

function nextcloud_sanitize(string $value): string {
    return trim(filter_var($value, FILTER_UNSAFE_RAW) ?? '');
}

function nextcloud_normalize_document_path(string $path): string {
    $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $path)), static function (string $part): bool {
        $part = trim($part);
        return $part !== '' && $part !== '.' && $part !== '..';
    }));
    return $parts ? '/' . implode('/', $parts) : '/NOVA/Procedimientos';
}

function nextcloud_config_load(): array {
    global $NEXTCLOUD_CONFIG_FILE;
    $cfg = storage_read_json($NEXTCLOUD_CONFIG_FILE, []);
    return is_array($cfg) ? $cfg : [];
}

function nextcloud_config_save(array $cfg): bool {
    global $NEXTCLOUD_CONFIG_FILE;
    return storage_write_json($NEXTCLOUD_CONFIG_FILE, $cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function nextcloud_config(): array {
    $cfg = nextcloud_config_load();
    $userId = function_exists('auth_get_user_id') ? (string)auth_get_user_id() : '';
    $globalUser = trim((string)($cfg['nextcloud_admin_user'] ?? ''));
    $globalPass = core_credentials_decrypt((string)($cfg['nextcloud_admin_pass_enc'] ?? ''));
    if ($userId !== '' && $globalUser !== '' && $globalPass !== '' && !nextcloud_credentials_has_saved($userId)) {
        nextcloud_credentials_save_for_user($userId, $globalUser, $globalPass);
        $cfg['nextcloud_admin_user'] = '';
        $cfg['nextcloud_admin_pass_enc'] = '';
        nextcloud_config_save($cfg);
    }
    $savedUserCredentials = nextcloud_credentials_for_user($userId);
    $adminUser = trim((string)($savedUserCredentials['user'] ?? ''));
    $adminPass = trim((string)($savedUserCredentials['pass'] ?? ''));
    $proceduresRoot = nextcloud_normalize_document_path((string)($cfg['procedures_nextcloud_root'] ?? '/NOVA/Procedimientos'));
    return [
        'url' => trim((string)($cfg['nextcloud_url'] ?? 'https://www.coresalud.cl/nextcloud')),
        'admin_user' => $adminUser,
        'admin_pass' => $adminPass,
        'default_group' => trim((string)($cfg['nextcloud_default_group'] ?? '')),
        'default_quota' => trim((string)($cfg['nextcloud_default_quota'] ?? '')),
        'default_language' => trim((string)($cfg['nextcloud_default_language'] ?? 'es')),
        'procedures_storage' => in_array(strtolower((string)($cfg['procedures_storage'] ?? 'local')), ['local', 'nextcloud'], true)
            ? strtolower((string)($cfg['procedures_storage'] ?? 'local'))
            : 'local',
        'procedures_nextcloud_root' => $proceduresRoot,
        'has_password' => nextcloud_credentials_has_saved($userId),
        'has_global_password' => false,
    ];
}

function nextcloud_save_config(array $post): bool {
    $cfg = nextcloud_config_load();
    $cfg['nextcloud_url'] = rtrim(nextcloud_sanitize($post['nextcloud_url'] ?? ''), '/');
    $cfg['nextcloud_default_group'] = nextcloud_sanitize($post['nextcloud_default_group'] ?? '');
    $cfg['nextcloud_default_quota'] = nextcloud_sanitize($post['nextcloud_default_quota'] ?? '');
    $cfg['nextcloud_default_language'] = nextcloud_sanitize($post['nextcloud_default_language'] ?? 'es');
    $storage = strtolower(nextcloud_sanitize($post['procedures_storage'] ?? 'local'));
    $cfg['procedures_storage'] = in_array($storage, ['local', 'nextcloud'], true) ? $storage : 'local';
    $cfg['procedures_nextcloud_root'] = nextcloud_normalize_document_path(nextcloud_sanitize($post['procedures_nextcloud_root'] ?? '/NOVA/Procedimientos'));
    $cfg['nextcloud_admin_user'] = '';
    $cfg['nextcloud_admin_pass_enc'] = '';
    return nextcloud_config_save($cfg);
}

function nextcloud_to_utf8(array $row): array {
    return array_map(static function ($value) {
        $value = trim((string)$value);
        if ($value === '') return '';
        if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
            $enc = mb_detect_encoding($value, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
            if ($enc && $enc !== 'UTF-8') {
                return mb_convert_encoding($value, 'UTF-8', $enc);
            }
        }
        return $value;
    }, $row);
}

function nextcloud_header_key(string $value): string {
    $value = strtolower(trim($value));
    $value = str_replace(['á','é','í','ó','ú','ñ','ü',' '], ['a','e','i','o','u','n','u','_'], $value);
    return preg_replace('/[^a-z0-9_]+/', '', $value) ?? '';
}

function nextcloud_column_value(array $row, array $names): string {
    foreach ($names as $name) {
        $key = nextcloud_header_key($name);
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }
    return '';
}

function nextcloud_match_key(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') $value = $converted;
    }
    $value = strtolower($value);
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function nextcloud_match_tokens(string $value): array {
    $value = trim($value);
    if ($value === '') return [];
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') $value = $converted;
    }
    $value = strtolower($value);
    $tokens = preg_split('/[^a-z0-9]+/', $value) ?: [];
    $stopwords = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'a', 'al', 'en', 'por', 'para'];
    $tokens = array_values(array_filter($tokens, static function ($token) use ($stopwords) {
        return $token !== '' && !in_array($token, $stopwords, true);
    }));
    return array_values(array_unique($tokens));
}

function nextcloud_group_match_score(string $needleRaw, string $groupRaw): int {
    $needle = nextcloud_match_key($needleRaw);
    $group = nextcloud_match_key($groupRaw);
    if ($needle === '' || $group === '') return 0;
    if ($needle === $group) return 100;

    $needleTokens = nextcloud_match_tokens($needleRaw);
    $groupTokens = nextcloud_match_tokens($groupRaw);
    $needleTokenKey = implode('', $needleTokens);
    $groupTokenKey = implode('', $groupTokens);
    if ($needleTokenKey !== '' && $needleTokenKey === $groupTokenKey) return 98;
    if ($needleTokenKey !== '' && $groupTokenKey !== '' && str_contains($groupTokenKey, $needleTokenKey)) return 90;
    if ($needleTokenKey !== '' && $groupTokenKey !== '' && str_contains($needleTokenKey, $groupTokenKey)) return 86;

    if ($needleTokens && $groupTokens) {
        $intersect = array_intersect($needleTokens, $groupTokens);
        $partialMatches = [];
        foreach ($needleTokens as $needleToken) {
            foreach ($groupTokens as $groupToken) {
                if (strlen($needleToken) >= 2 && (str_starts_with($groupToken, $needleToken) || str_contains($groupToken, $needleToken))) {
                    $partialMatches[] = $needleToken;
                    break;
                }
            }
        }
        $needleCoverage = count($intersect) / max(1, count($needleTokens));
        $groupCoverage = count($intersect) / max(1, count($groupTokens));
        $partialCoverage = count(array_unique($partialMatches)) / max(1, count($needleTokens));
        if ($needleCoverage === 1.0 && $groupCoverage === 1.0) return 96;
        if ($needleCoverage === 1.0) return 82;
        if ($partialCoverage === 1.0) return 80;
        if ($partialCoverage >= 0.5 && count($partialMatches) >= 2) return 72;
        if ($groupCoverage === 1.0 && count($intersect) >= 2) return 78;
        if (count($intersect) >= 2) return 68;
    }

    if (str_contains($group, $needle)) return 74;
    if (str_contains($needle, $group)) return 70;

    similar_text($needleTokenKey ?: $needle, $groupTokenKey ?: $group, $percent);
    return $percent >= 84 ? (int)round($percent * 0.65) : 0;
}

function nextcloud_best_group_match(string $raw, array $candidates): string {
    $bestGroup = '';
    $bestScore = 0;
    foreach ($candidates as $group) {
        $group = (string)$group;
        $score = nextcloud_group_match_score($raw, $group);
        if ($score > $bestScore || ($score === $bestScore && $score > 0 && strlen($group) < strlen($bestGroup))) {
            $bestScore = $score;
            $bestGroup = $group;
        }
    }
    return $bestScore >= 68 ? $bestGroup : '';
}

function nextcloud_group_suggestions(string $raw, array $candidates, int $limit = 3): array {
    $items = [];
    foreach ($candidates as $group) {
        $group = (string)$group;
        $score = nextcloud_group_match_score($raw, $group);
        if ($score > 0) {
            $items[] = ['group' => $group, 'score' => $score];
        }
    }
    usort($items, static function ($a, $b) {
        return ($b['score'] <=> $a['score']) ?: (strlen($a['group']) <=> strlen($b['group'])) ?: strnatcasecmp($a['group'], $b['group']);
    });
    return array_values(array_map(static fn($item) => $item['group'], array_slice($items, 0, $limit)));
}

function nextcloud_normalize_userid(string $userid): string {
    $userid = strtoupper(trim($userid));
    if ($userid === '') return '';
    $userid = str_replace(['.', ' '], '', $userid);
    if (str_contains($userid, '-')) {
        [$body] = array_pad(explode('-', $userid, 2), 2, '');
        return preg_replace('/\D+/', '', $body) ?: '';
    }
    if (preg_match('/^\d{7,8}[0-9K]$/', $userid) && strlen($userid) >= 9) {
        return substr($userid, 0, -1);
    }
    return preg_replace('/\D+/', '', $userid) ?: '';
}

function nextcloud_generate_password(string $displayName, string $userid): string {
    $parts = preg_split('/\s+/', trim($displayName)) ?: [];
    $name = $parts[0] ?? 'Usuario';
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if (is_string($converted) && $converted !== '') $name = $converted;
    }
    $name = preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'Usuario';
    $rut = strtoupper(str_replace('.', '', trim($userid)));
    if (str_contains($rut, '-')) {
        [$body, $digit] = array_pad(explode('-', $rut, 2), 2, '');
    } else {
        $body = substr($rut, 0, -1);
        $digit = substr($rut, -1);
    }
    $body = preg_replace('/\D+/', '', $body) ?: '';
    $digit = preg_replace('/[^0-9K]/', '', $digit) ?: '';
    if (strlen($body) < 4 || $digit === '') {
        $rawDigits = preg_replace('/\D+/', '', $userid) ?: '0000';
        $body = str_pad($rawDigits, 4, '0');
        $digit = substr($rawDigits, -1) ?: '0';
    }
    return substr($body, 0, 4) . ucfirst(strtolower($name)) . strtoupper($digit) . '!' . date('y');
}

function nextcloud_match_groups(string $raw, array $candidates, string $defaultGroup = ''): array {
    $parts = array_values(array_filter(array_map('trim', preg_split('/[,;|]+/', $raw) ?: [])));
    $matched = [];
    foreach ($parts as $part) {
        $match = nextcloud_best_group_match($part, $candidates);
        if ($match !== '') $matched[] = $match;
    }
    $matched = array_values(array_unique($matched));
    if (!$matched && $defaultGroup !== '') $matched[] = $defaultGroup;
    return $matched;
}

function nextcloud_normalize_row(array $row, array $defaults): ?array {
    $rawUserid = nextcloud_column_value($row, ['userid', 'usuario', 'nombre_de_usuario', 'user', 'id', 'rut']);
    $userid = nextcloud_normalize_userid($rawUserid);
    $display = nextcloud_column_value($row, ['displayName', 'displayname', 'nombre_a_desplegar', 'nombre', 'nombre_completo', 'name']);
    $email = nextcloud_column_value($row, ['email', 'correo_electronico', 'correo', 'mail']);
    $password = nextcloud_column_value($row, ['password', 'contrasena', 'contraseña', 'clave']);
    $groupsRaw = nextcloud_column_value($row, ['servicio', 'nombre_del_servicio', 'service', 'groups', 'grupos', 'grupo']);
    $language = nextcloud_column_value($row, ['language', 'idioma']);
    if ($userid === '') {
        return null;
    }
    $lowerUser = strtolower($rawUserid);
    if (str_contains($lowerUser, 'rut sin') || str_contains($lowerUser, 'ej:')) {
        return null;
    }
    $groups = [];
    if ($groupsRaw !== '') {
        $groups = array_values(array_filter(array_map('trim', preg_split('/[,;|]+/', $groupsRaw) ?: [])));
    } elseif (($defaults['default_group'] ?? '') !== '') {
        $groups = [$defaults['default_group']];
    }
    return [
        'userid' => $userid,
        'raw_userid' => $rawUserid,
        'userid_normalized' => $userid !== $rawUserid,
        'password' => $password,
        'displayName' => $display !== '' ? $display : $userid,
        'email' => $email,
        'groups' => $groups,
        'group_source' => $groupsRaw,
        'quota' => (string)($defaults['default_quota'] ?? ''),
        'language' => $language !== '' ? $language : (string)($defaults['default_language'] ?? 'es'),
    ];
}

function nextcloud_parse_csv(string $path, array $defaults): array {
    $fh = fopen($path, 'rb');
    if (!$fh) return ['error' => 'No se pudo leer el archivo.'];
    $header = null;
    $rows = [];
    while (($line = fgetcsv($fh, 0, ';')) !== false) {
        if (count($line) <= 1) {
            $line = str_getcsv((string)implode('', $line), ',');
        }
        $line = nextcloud_to_utf8($line);
        if ($header === null) {
            $header = array_map('nextcloud_header_key', $line);
            continue;
        }
        if (!$header || count(array_filter($line, fn($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }
        $assoc = [];
        foreach ($header as $idx => $key) {
            if ($key !== '') $assoc[$key] = $line[$idx] ?? '';
        }
        $normalized = nextcloud_normalize_row($assoc, $defaults);
        if ($normalized) $rows[] = $normalized;
    }
    fclose($fh);
    return ['rows' => $rows];
}

function nextcloud_xlsx_shared_strings(string $xml): array {
    preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $xml, $items);
    $strings = [];
    foreach ($items[1] ?? [] as $item) {
        preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $item, $texts);
        $strings[] = html_entity_decode(implode('', $texts[1] ?? []), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
    return $strings;
}

function nextcloud_xlsx_col_index(string $cellRef): int {
    preg_match('/^[A-Z]+/i', $cellRef, $m);
    $letters = strtoupper($m[0] ?? 'A');
    $idx = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $idx = ($idx * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $idx - 1);
}

function nextcloud_parse_xlsx(string $path, array $defaults): array {
    if (!class_exists('ZipArchive')) {
        return ['error' => 'Para leer XLSX debes habilitar la extensión ZIP de PHP. Mientras tanto exporta el Excel como CSV.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['error' => 'No se pudo abrir el archivo XLSX.'];
    }
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    $shared = $sharedXml !== false ? nextcloud_xlsx_shared_strings($sharedXml) : [];
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false) return ['error' => 'El XLSX no contiene una hoja principal válida.'];
    preg_match_all('/<row\b[^>]*>(.*?)<\/row>/s', $sheet, $matches);
    $matrix = [];
    foreach ($matches[1] ?? [] as $rowXml) {
        preg_match_all('/<c\b([^>]*)>(.*?)<\/c>/s', $rowXml, $cells, PREG_SET_ORDER);
        $row = [];
        foreach ($cells as $cell) {
            $attrs = $cell[1] ?? '';
            $body = $cell[2] ?? '';
            preg_match('/\br="([^"]+)"/', $attrs, $refMatch);
            $idx = nextcloud_xlsx_col_index($refMatch[1] ?? 'A');
            preg_match('/<v>(.*?)<\/v>/s', $body, $valueMatch);
            $value = html_entity_decode($valueMatch[1] ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (str_contains($attrs, ' t="s"')) {
                $value = $shared[(int)$value] ?? '';
            } elseif (str_contains($attrs, ' t="inlineStr"')) {
                preg_match('/<t\b[^>]*>(.*?)<\/t>/s', $body, $inlineMatch);
                $value = html_entity_decode($inlineMatch[1] ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            $row[$idx] = trim((string)$value);
        }
        if ($row) {
            ksort($row);
            $matrix[] = $row;
        }
    }
    if (!$matrix) return ['rows' => []];
    $header = array_map('nextcloud_header_key', array_values($matrix[0]));
    $rows = [];
    foreach (array_slice($matrix, 1) as $line) {
        $assoc = [];
        foreach ($header as $idx => $key) {
            if ($key !== '') $assoc[$key] = $line[$idx] ?? '';
        }
        $normalized = nextcloud_normalize_row($assoc, $defaults);
        if ($normalized) $rows[] = $normalized;
    }
    return ['rows' => $rows];
}

function nextcloud_request(array $cfg, string $method, string $path, array $payload = []): array {
    $base = rtrim((string)$cfg['url'], '/');
    $url = $base . '/ocs/v1.php/cloud' . $path . (str_contains($path, '?') ? '&' : '?') . 'format=json';
    $pairs = [];
    foreach ($payload as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $item) {
                $pairs[] = rawurlencode($key . '[]') . '=' . rawurlencode((string)$item);
            }
        } elseif ($value !== '') {
            $pairs[] = rawurlencode($key) . '=' . rawurlencode((string)$value);
        }
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => $cfg['admin_user'] . ':' . $cfg['admin_pass'],
        CURLOPT_HTTPHEADER => ['OCS-APIRequest: true', 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($pairs) curl_setopt($ch, CURLOPT_POSTFIELDS, implode('&', $pairs));
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'statuscode' => 0, 'message' => $err];
    }
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$resp, true);
    $meta = is_array($json) ? ($json['ocs']['meta'] ?? []) : [];
    $statusCode = (int)($meta['statuscode'] ?? 0);
    $message = trim((string)($meta['message'] ?? ''));
    return [
        'ok' => $http < 400 && $statusCode === 100,
        'http' => $http,
        'statuscode' => $statusCode,
        'message' => $message,
        'data' => is_array($json) ? ($json['ocs']['data'] ?? null) : null,
    ];
}

function nextcloud_webdav_base_url(array $cfg): string {
    $base = rtrim((string)($cfg['url'] ?? ''), '/');
    $user = rawurlencode((string)($cfg['admin_user'] ?? ''));
    return $base . '/remote.php/dav/files/' . $user;
}

function nextcloud_webdav_request(array $cfg, string $method, string $path, $body = null, array $headers = []): array {
    $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
    $url = nextcloud_webdav_base_url($cfg) . implode('/', array_map('rawurlencode', explode('/', $path)));
    $ch = curl_init($url);
    $requestHeaders = array_merge(['Accept: application/xml, application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => $cfg['admin_user'] . ':' . $cfg['admin_pass'],
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'http' => 0, 'body' => '', 'headers' => '', 'message' => $err];
    }
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'ok' => $http >= 200 && $http < 300,
        'http' => $http,
        'headers' => substr((string)$response, 0, $headerSize),
        'body' => substr((string)$response, $headerSize),
        'message' => $http >= 400 ? 'HTTP ' . $http : '',
    ];
}

function nextcloud_ensure_directory(array $cfg, string $path): array {
    $path = '/' . trim(str_replace('\\', '/', $path), '/');
    if ($path === '/') {
        return ['ok' => true];
    }
    $parts = array_values(array_filter(explode('/', trim($path, '/'))));
    $current = '';
    foreach ($parts as $part) {
        $current .= '/' . $part;
        $res = nextcloud_webdav_request($cfg, 'MKCOL', $current);
        if (!$res['ok'] && !in_array((int)($res['http'] ?? 0), [405, 409], true)) {
            return $res;
        }
    }
    return ['ok' => true];
}

function nextcloud_share_create(array $cfg, string $path, bool $publicUpload = false): array {
    $res = nextcloud_request($cfg, 'POST', '/shares', [
        'path' => '/' . ltrim($path, '/'),
        'shareType' => 3,
        'permissions' => $publicUpload ? 15 : 1,
    ]);
    if (!$res['ok']) {
        return ['ok' => false, 'error' => (($res['message'] ?? '') ?: 'No se pudo crear enlace compartido.')];
    }
    $data = is_array($res['data'] ?? null) ? $res['data'] : [];
    return [
        'ok' => true,
        'id' => (string)($data['id'] ?? ''),
        'url' => (string)($data['url'] ?? ''),
        'token' => (string)($data['token'] ?? ''),
    ];
}

function nextcloud_share_delete(array $cfg, string $shareId): array {
    $shareId = trim($shareId);
    if ($shareId === '') {
        return ['ok' => true];
    }
    $res = nextcloud_request($cfg, 'DELETE', '/shares/' . rawurlencode($shareId));
    return $res['ok'] ? ['ok' => true] : ['ok' => false, 'error' => (($res['message'] ?? '') ?: 'No se pudo eliminar enlace compartido.')];
}

function nextcloud_groups_from_response($data): array {
    $groups = [];
    if (is_array($data['groups'] ?? null)) {
        $source = $data['groups'];
    } elseif (is_array($data)) {
        $source = $data;
    } else {
        $source = [];
    }
    foreach ($source as $key => $value) {
        if (is_string($value)) {
            $groups[] = $value;
        } elseif (is_array($value)) {
            foreach ($value as $nested) {
                if (is_string($nested)) $groups[] = $nested;
            }
        } elseif (is_string($key) && $key !== 'groups') {
            $groups[] = $key;
        }
    }
    $groups = array_values(array_unique(array_filter(array_map('trim', $groups))));
    natcasesort($groups);
    return array_values($groups);
}

function nextcloud_fetch_groups(string $search = ''): array {
    $cfg = nextcloud_config();
    if ($cfg['url'] === '' || $cfg['admin_user'] === '' || $cfg['admin_pass'] === '') {
        return ['error' => 'Configura URL, usuario administrador y contraseña de aplicación de Nextcloud.'];
    }
    $query = '?limit=500';
    if ($search !== '') {
        $query .= '&search=' . rawurlencode($search);
    }
    $res = nextcloud_request($cfg, 'GET', '/groups' . $query);
    if (!$res['ok']) {
        return ['error' => (($res['message'] ?? '') ?: 'HTTP ' . ($res['http'] ?? 0))];
    }
    return ['groups' => nextcloud_groups_from_response($res['data'] ?? [])];
}

function nextcloud_save_cached_groups(array $groups): void {
    $cfg = nextcloud_config_load();
    $cfg['nextcloud_cached_groups'] = array_values($groups);
    $cfg['nextcloud_cached_groups_at'] = (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('c');
    nextcloud_config_save($cfg);
}

function nextcloud_cached_groups(): array {
    $cfg = nextcloud_config_load();
    $groups = $cfg['nextcloud_cached_groups'] ?? [];
    return is_array($groups) ? array_values(array_filter(array_map('strval', $groups))) : [];
}

function nextcloud_user_result_snapshot(array $user, string $status, string $message = ''): array {
    return [
        'userid' => (string)($user['userid'] ?? ''),
        'displayName' => (string)($user['displayName'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'group' => (string)(($user['groups'][0] ?? '')),
        'password' => (string)($user['password'] ?? ''),
        'status' => $status,
        'message' => $message,
    ];
}

function nextcloud_user_exists(array $cfg, string $userid): array {
    $userid = trim($userid);
    if ($userid === '') {
        return ['exists' => false];
    }
    $res = nextcloud_request($cfg, 'GET', '/users/' . rawurlencode($userid));
    if (!empty($res['ok'])) {
        return ['exists' => true];
    }
    $http = (int)($res['http'] ?? 0);
    $statusCode = (int)($res['statuscode'] ?? 0);
    $message = strtolower((string)($res['message'] ?? ''));
    if ($http === 404 || $statusCode === 404 || str_contains($message, 'not exist') || str_contains($message, 'not found')) {
        return ['exists' => false];
    }
    return ['exists' => null, 'error' => (($res['message'] ?? '') ?: 'HTTP ' . $http)];
}

function nextcloud_created_history_load(): array {
    global $NEXTCLOUD_CREATED_HISTORY_FILE;
    $items = storage_read_json($NEXTCLOUD_CREATED_HISTORY_FILE, []);
    $items = is_array($items) ? $items : [];
    $cutoff = time() - 86400;
    $items = array_values(array_filter($items, static function ($item) use ($cutoff) {
        $createdAt = strtotime((string)($item['created_at'] ?? ''));
        return $createdAt !== false && $createdAt >= $cutoff;
    }));
    storage_write_json($NEXTCLOUD_CREATED_HISTORY_FILE, $items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return $items;
}

function nextcloud_created_history_clear(): bool {
    global $NEXTCLOUD_CREATED_HISTORY_FILE;
    return storage_write_json($NEXTCLOUD_CREATED_HISTORY_FILE, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

function nextcloud_created_history_save_batch(array $createdUsers, array $existingUsers = [], array $failedUsers = [], array $resultUsers = []): ?array {
    global $NEXTCLOUD_CREATED_HISTORY_FILE;
    if (!$createdUsers && !$existingUsers && !$failedUsers && !$resultUsers) return null;
    $items = nextcloud_created_history_load();
    $batch = [
        'id' => bin2hex(random_bytes(6)),
        'created_at' => (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('c'),
        'expires_at' => (new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->modify('+24 hours')->format('c'),
        'users' => array_values($createdUsers),
        'created_users' => array_values($createdUsers),
        'existing_users' => array_values($existingUsers),
        'failed_users' => array_values($failedUsers),
        'result_users' => array_values($resultUsers),
    ];
    array_unshift($items, $batch);
    storage_write_json($NEXTCLOUD_CREATED_HISTORY_FILE, $items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return $batch;
}

function nextcloud_prepare_users(array $file, array $options = []): array {
    $cfg = nextcloud_config();
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => 'Debes seleccionar un archivo CSV o XLSX.'];
    }
    $name = strtolower((string)($file['name'] ?? ''));
    $tmp = (string)($file['tmp_name'] ?? '');
    $parsed = str_ends_with($name, '.xlsx') ? nextcloud_parse_xlsx($tmp, $cfg) : nextcloud_parse_csv($tmp, $cfg);
    if (isset($parsed['error'])) return ['error' => $parsed['error']];
    $users = $parsed['rows'] ?? [];
    if (!$users) return ['error' => 'El archivo no contiene usuarios válidos.'];
    $cachedGroups = nextcloud_cached_groups();
    $preparedUsers = [];
    $seenUsers = [];
    foreach ($users as $user) {
        $user['password'] = nextcloud_generate_password((string)$user['displayName'], (string)$user['userid']);
        $user['groups'] = nextcloud_match_groups((string)($user['group_source'] ?? ''), $cachedGroups, '');
        $user['group_match_found'] = !empty($user['groups']);
        $user['group_suggestions'] = empty($user['groups']) ? nextcloud_group_suggestions((string)($user['group_source'] ?? ''), $cachedGroups, 3) : [];
        $user['email_valid'] = filter_var((string)$user['email'], FILTER_VALIDATE_EMAIL) !== false;
        $userKey = (string)($user['userid'] ?? '');
        $user['duplicate_in_file'] = $userKey !== '' && isset($seenUsers[$userKey]);
        if ($userKey !== '') {
            $seenUsers[$userKey] = true;
        }
        $preparedUsers[] = $user;
    }
    return ['ok' => true, 'users' => $preparedUsers, 'total' => count($preparedUsers)];
}

function nextcloud_import_prepared_users(array $users, array $runtimeCredentials = []): array {
    $cfg = nextcloud_config();
    $runtimeUser = trim((string)($runtimeCredentials['user'] ?? ''));
    $runtimePass = trim((string)($runtimeCredentials['pass'] ?? ''));
    if ($runtimeUser !== '') {
        $cfg['admin_user'] = $runtimeUser;
    }
    if ($runtimePass !== '') {
        $cfg['admin_pass'] = $runtimePass;
    }
    if ($cfg['url'] === '' || $cfg['admin_user'] === '' || $cfg['admin_pass'] === '') {
        return ['error' => 'Configura URL, usuario administrador y contraseña de aplicación de Nextcloud.'];
    }
    $created = 0;
    $exists = 0;
    $failed = [];
    $failedUsers = [];
    $existingUsers = [];
    $createdUsers = [];
    $resultUsers = [];
    $seenUsers = [];
    foreach ($users as $user) {
        $user['userid'] = nextcloud_normalize_userid((string)($user['userid'] ?? ''));
        if (($user['userid'] ?? '') === '') {
            $message = 'RUT inválido o vacío';
            $failed[] = 'usuario: ' . $message;
            $failedUser = nextcloud_user_result_snapshot($user, 'failed', $message);
            $failedUsers[] = $failedUser;
            $resultUsers[] = $failedUser;
            continue;
        }
        if (isset($seenUsers[(string)$user['userid']])) {
            $message = 'RUT duplicado en la planilla';
            $failed[] = ($user['userid'] ?? 'usuario') . ': ' . $message;
            $failedUser = nextcloud_user_result_snapshot($user, 'failed', $message);
            $failedUsers[] = $failedUser;
            $resultUsers[] = $failedUser;
            continue;
        }
        $seenUsers[(string)$user['userid']] = true;
        if (filter_var((string)($user['email'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
            $message = 'correo inválido';
            $failed[] = ($user['userid'] ?? 'usuario') . ': ' . $message;
            $failedUser = nextcloud_user_result_snapshot($user, 'failed', $message);
            $failedUsers[] = $failedUser;
            $resultUsers[] = $failedUser;
            continue;
        }
        if (empty($user['groups'])) {
            $message = 'sin grupo válido';
            $failed[] = ($user['userid'] ?? 'usuario') . ': ' . $message;
            $failedUser = nextcloud_user_result_snapshot($user, 'failed', $message);
            $failedUsers[] = $failedUser;
            $resultUsers[] = $failedUser;
            continue;
        }
        $existsCheck = nextcloud_user_exists($cfg, (string)$user['userid']);
        if (($existsCheck['exists'] ?? false) === true) {
            $exists++;
            $existingUser = nextcloud_user_result_snapshot($user, 'existing', 'No se creó porque ya existe en Nextcloud.');
            $existingUsers[] = $existingUser;
            $resultUsers[] = $existingUser;
            continue;
        }
        if (array_key_exists('error', $existsCheck)) {
            $message = 'no se pudo validar existencia: ' . (string)$existsCheck['error'];
            $failed[] = ($user['userid'] ?? 'usuario') . ': ' . $message;
            $failedUser = nextcloud_user_result_snapshot($user, 'failed', $message);
            $failedUsers[] = $failedUser;
            $resultUsers[] = $failedUser;
            continue;
        }
        $res = nextcloud_request($cfg, 'POST', '/users', [
            'userid' => $user['userid'],
            'password' => $user['password'],
            'displayName' => $user['displayName'],
            'email' => $user['email'],
            'groups' => $user['groups'],
            'quota' => $user['quota'],
            'language' => $user['language'],
        ]);
        if ($res['ok']) {
            $created++;
            $createdUser = nextcloud_user_result_snapshot($user, 'created', 'Creado correctamente.');
            $createdUsers[] = $createdUser;
            $resultUsers[] = $createdUser;
        } elseif ((int)($res['statuscode'] ?? 0) === 102) {
            $exists++;
            $existingUser = nextcloud_user_result_snapshot($user, 'existing', 'No se creó porque ya existe en Nextcloud.');
            $existingUsers[] = $existingUser;
            $resultUsers[] = $existingUser;
        } else {
            $message = (($res['message'] ?? '') ?: 'HTTP ' . ($res['http'] ?? 0));
            $failed[] = $user['userid'] . ': ' . $message;
            $failedUser = nextcloud_user_result_snapshot($user, 'failed', $message);
            $failedUsers[] = $failedUser;
            $resultUsers[] = $failedUser;
        }
    }
    $batch = nextcloud_created_history_save_batch($createdUsers, $existingUsers, $failedUsers, $resultUsers);
    return [
        'ok' => true,
        'created' => $created,
        'exists' => $exists,
        'created_users' => $createdUsers,
        'created_batch' => $batch,
        'existing_users' => $existingUsers,
        'failed_users' => $failedUsers,
        'result_users' => $resultUsers,
        'failed' => $failed,
        'total' => count($users),
    ];
}

function nextcloud_users_from_post(array $rows): array {
    $allowedGroups = nextcloud_cached_groups();
    $users = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $group = trim((string)($row['group'] ?? ''));
        $groups = ($group !== '' && in_array($group, $allowedGroups, true)) ? [$group] : [];
        $users[] = [
            'userid' => trim((string)($row['userid'] ?? '')),
            'password' => trim((string)($row['password'] ?? '')),
            'displayName' => trim((string)($row['displayName'] ?? '')),
            'email' => trim((string)($row['email'] ?? '')),
            'groups' => array_values(array_unique(array_filter($groups))),
            'quota' => trim((string)($row['quota'] ?? '')),
            'language' => trim((string)($row['language'] ?? 'es')),
        ];
    }
    return array_values(array_filter($users, fn($user) => $user['userid'] !== '' && $user['password'] !== ''));
}

function nextcloud_import_file(array $file, array $options = []): array {
    $prepared = nextcloud_prepare_users($file, $options);
    if (isset($prepared['error'])) return $prepared;
    return nextcloud_import_prepared_users($prepared['users'] ?? []);
}

function handle_nextcloud(): array {
    $flash = nextcloud_consume_flash();
    $lastImport = nextcloud_consume_last_import();
    $preview = nextcloud_consume_preview();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('csrf_validate')) csrf_validate();
        if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
        $action = $_POST['action'] ?? '';
        if ($action === 'save_nextcloud_config') {
            nextcloud_save_config($_POST);
            nextcloud_set_flash('Configuración de Nextcloud guardada');
            nextcloud_redirect_back();
        }
        if ($action === 'fetch_nextcloud_groups') {
            $res = nextcloud_fetch_groups('');
            if (isset($res['error'])) return [$res['error'], nextcloud_config(), nextcloud_cached_groups()];
            nextcloud_save_cached_groups($res['groups'] ?? []);
            nextcloud_set_flash('Grupos consultados: ' . count($res['groups'] ?? []));
            nextcloud_redirect_back();
        }
        if ($action === 'import_nextcloud_users') {
            $res = nextcloud_prepare_users($_FILES['nextcloud_file'] ?? [], $_POST);
            if (isset($res['error'])) return [$res['error'], nextcloud_config(), nextcloud_cached_groups(), $lastImport, $preview];
            nextcloud_set_preview($res);
            nextcloud_redirect_back();
        }
        if ($action === 'confirm_nextcloud_import') {
            $payload = json_decode((string)($_POST['prepared_users'] ?? ''), true);
            $users = nextcloud_users_from_post((array)($_POST['users'] ?? []));
            if (!$users) {
                $users = is_array($payload['users'] ?? null) ? $payload['users'] : [];
            }
            if (!$users) return ['No hay usuarios preparados para importar.', nextcloud_config(), nextcloud_cached_groups(), $lastImport, $preview];
            $runtimeUser = trim((string)($_POST['nextcloud_runtime_user'] ?? ''));
            $runtimePass = trim((string)($_POST['nextcloud_runtime_pass'] ?? ''));
            $rememberNextcloud = !empty($_POST['nextcloud_remember_credentials']);
            if ($runtimeUser === '' || $runtimePass === '') {
                $savedCredentials = nextcloud_credentials_for_user(function_exists('auth_get_user_id') ? (string)auth_get_user_id() : '');
                if ($runtimeUser === '') {
                    $runtimeUser = trim((string)($savedCredentials['user'] ?? ''));
                }
                if ($runtimePass === '') {
                    $runtimePass = trim((string)($savedCredentials['pass'] ?? ''));
                }
            }
            $res = nextcloud_import_prepared_users($users, [
                'user' => $runtimeUser,
                'pass' => $runtimePass,
            ]);
            if (isset($res['error'])) return [$res['error'], nextcloud_config(), nextcloud_cached_groups(), $lastImport, $preview];
            if ($rememberNextcloud && $runtimeUser !== '' && $runtimePass !== '') {
                nextcloud_credentials_save_for_user(function_exists('auth_get_user_id') ? (string)auth_get_user_id() : '', $runtimeUser, $runtimePass);
            }
            $msg = 'Importación Nextcloud completada. Creados: ' . (int)($res['created'] ?? 0) . ' | existentes: ' . (int)($res['exists'] ?? 0);
            $failed = $res['failed'] ?? [];
            if (is_array($failed) && $failed) {
                $msg .= ' | errores: ' . count($failed) . ' (' . implode(' / ', array_slice($failed, 0, 3)) . ')';
            }
            nextcloud_set_last_import($res);
            nextcloud_set_flash($msg);
            nextcloud_redirect_back();
        }
    }
    return [$flash, nextcloud_config(), nextcloud_cached_groups(), $lastImport, $preview];
}
