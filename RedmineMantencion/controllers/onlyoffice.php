<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/procedimientos.php';
require_once __DIR__ . '/storage.php';

function onlyoffice_config(): array {
    $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
    if ($repo !== null) {
        $data = $repo->loadAll();
        if (is_array($data) && $data !== []) {
            return $data;
        }
    }
    return [];
}

function onlyoffice_base_url(): string {
    $cfg = onlyoffice_config();
    $configuredUrl = rtrim(trim((string)($cfg['onlyoffice_app_url'] ?? '')), '/');
    if ($configuredUrl !== '') {
        return $configuredUrl;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/redmine-mantencion';
}

function onlyoffice_absolute_url(string $url): string {
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    $base = onlyoffice_base_url();
    $baseParts = parse_url($base);
    $basePath = rtrim((string)($baseParts['path'] ?? ''), '/');
    if ($basePath !== '' && str_starts_with($url, $basePath . '/')) {
        $origin = (string)($baseParts['scheme'] ?? 'http') . '://' . (string)($baseParts['host'] ?? 'localhost');
        if (!empty($baseParts['port'])) {
            $origin .= ':' . (string)$baseParts['port'];
        }
        return $origin . $url;
    }
    return $base . '/' . ltrim($url, '/');
}

function onlyoffice_file_type(array $record): string {
    return procedures_file_extension((string)($record['file_name'] ?? $record['file_original_name'] ?? 'docx'));
}

function onlyoffice_document_type(string $fileType): string {
    if (in_array($fileType, ['xls', 'xlsx'], true)) {
        return 'cell';
    }
    if (in_array($fileType, ['ppt', 'pptx'], true)) {
        return 'slide';
    }
    return 'word';
}

function onlyoffice_document_key(array $record): string {
    $seed = implode('|', [
        (string)($record['id'] ?? ''),
        (string)($record['file_name'] ?? ''),
        (string)($record['updated_at'] ?? ''),
        (string)($record['file_size'] ?? ''),
    ]);
    return substr(preg_replace('/[^a-zA-Z0-9_-]/', '', base64_encode(hash('sha256', $seed, true))) ?? '', 0, 40);
}

function onlyoffice_base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function onlyoffice_jwt_encode(array $payload, string $secret): string {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $segments = [
        onlyoffice_base64url(json_encode($header, JSON_UNESCAPED_SLASHES)),
        onlyoffice_base64url(json_encode($payload, JSON_UNESCAPED_SLASHES)),
    ];
    $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
    $segments[] = onlyoffice_base64url($signature);
    return implode('.', $segments);
}

function onlyoffice_base64url_decode(string $data): string {
    $remainder = strlen($data) % 4;
    if ($remainder > 0) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);
    return is_string($decoded) ? $decoded : '';
}

function onlyoffice_jwt_verify(string $token, string $secret): bool {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    [$header, $payload, $signature] = $parts;
    $expected = onlyoffice_base64url(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
    return hash_equals($expected, $signature);
}

function onlyoffice_request_token(array $payload): string {
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
        return trim($matches[1]);
    }
    return trim((string)($payload['token'] ?? ''));
}

function onlyoffice_editor_config(array $record, array $cfg, string $mode = 'edit'): array {
    $fileType = onlyoffice_file_type($record);
    $documentUrl = onlyoffice_absolute_url((string)($record['file_url'] ?? ''));
    $callbackUrl = onlyoffice_absolute_url(legacy_app_url('controllers/onlyoffice.php?action=callback&id=' . rawurlencode((string)$record['id'])));
    $sessionUser = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];
    $userId = trim((string)($sessionUser['id'] ?? 'public'));
    $userName = trim((string)($sessionUser['nombre'] ?? 'Invitado'));
    $canEdit = $mode !== 'view' && auth_can('procedimientos_editar');

    $config = [
        'document' => [
            'fileType' => $fileType,
            'key' => onlyoffice_document_key($record),
            'title' => (string)($record['file_original_name'] ?: $record['title']),
            'url' => $documentUrl,
            'permissions' => [
                'edit' => $canEdit,
                'comment' => $canEdit,
                'download' => true,
                'fillForms' => $canEdit,
                'modifyContentControl' => $canEdit,
                'modifyFilter' => $canEdit,
                'print' => true,
                'review' => $canEdit,
            ],
        ],
        'documentType' => onlyoffice_document_type($fileType),
        'editorConfig' => [
            'callbackUrl' => $callbackUrl,
            'coEditing' => [
                'mode' => 'fast',
                'change' => false,
            ],
            'lang' => 'es-CL',
            'region' => 'es-CL',
            'mode' => $canEdit ? 'edit' : 'view',
            'user' => [
                'id' => $userId !== '' ? $userId : 'public',
                'name' => $userName !== '' ? $userName : 'Invitado',
            ],
            'customization' => [
                'autosave' => false,
                'forcesave' => true,
            ],
        ],
        'height' => '100%',
        'width' => '100%',
    ];

    $secret = trim((string)($cfg['onlyoffice_jwt_secret'] ?? ''));
    if ($secret !== '') {
        $config['token'] = onlyoffice_jwt_encode($config, $secret);
    }
    procedures_onlyoffice_log('editor.config', [
        'id' => (string)($record['id'] ?? ''),
        'mode' => $mode,
        'can_edit' => $canEdit ? 'yes' : 'no',
        'document_type' => (string)($config['documentType'] ?? ''),
        'file_type' => $fileType,
        'document_url' => $documentUrl,
        'callback_url' => $callbackUrl,
        'jwt_enabled' => $secret !== '' ? 'yes' : 'no',
        'storage_driver' => (string)($record['storage_driver'] ?? ''),
        'nextcloud_path' => (string)($record['nextcloud_path'] ?? ''),
    ]);
    return $config;
}

function onlyoffice_callback_response(int $error = 0): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $error], JSON_UNESCAPED_SLASHES);
    exit;
}

function onlyoffice_handle_client_log(): void {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = [];
    }
    procedures_onlyoffice_log('client.' . preg_replace('/[^a-z0-9_.-]+/i', '_', (string)($payload['event'] ?? 'log')), [
        'id' => (string)($payload['id'] ?? ''),
        'message' => (string)($payload['message'] ?? ''),
        'code' => (string)($payload['code'] ?? ''),
        'data' => $payload['data'] ?? '',
    ]);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
    exit;
}

function onlyoffice_handle_callback(): void {
    $id = trim((string)($_GET['id'] ?? ''));
    $rawBody = (string)file_get_contents('php://input');
    $payload = json_decode($rawBody, true);
    procedures_onlyoffice_log('callback.request', [
        'id' => $id,
        'body_bytes' => strlen($rawBody),
        'json_ok' => is_array($payload) ? 'yes' : 'no',
        'status' => is_array($payload) ? (string)($payload['status'] ?? '') : '',
    ]);
    if ($id === '' || !is_array($payload)) {
        procedures_onlyoffice_log('callback.reject.invalid_payload', ['id' => $id]);
        onlyoffice_callback_response(1);
    }

    $cfg = onlyoffice_config();
    $secret = trim((string)($cfg['onlyoffice_jwt_secret'] ?? ''));
    if ($secret !== '' && !onlyoffice_jwt_verify(onlyoffice_request_token($payload), $secret)) {
        procedures_onlyoffice_log('callback.reject.jwt', [
            'id' => $id,
            'token_present' => onlyoffice_request_token($payload) !== '' ? 'yes' : 'no',
        ]);
        onlyoffice_callback_response(1);
    }

    $status = (int)($payload['status'] ?? 0);
    if (!in_array($status, [2, 6], true)) {
        procedures_onlyoffice_log('callback.skip_status', ['id' => $id, 'status' => $status]);
        onlyoffice_callback_response(0);
    }

    $downloadUrl = trim((string)($payload['url'] ?? ''));
    $downloadScheme = strtolower((string)parse_url($downloadUrl, PHP_URL_SCHEME));
    if ($downloadUrl === '' || !in_array($downloadScheme, ['http', 'https'], true)) {
        procedures_onlyoffice_log('callback.reject.download_url', [
            'id' => $id,
            'status' => $status,
            'download_url' => $downloadUrl,
        ]);
        onlyoffice_callback_response(1);
    }

    $items = procedures_read_all();
    $record = procedures_find_by_id($items, $id);
    if (!$record || empty($record['file_name'])) {
        procedures_onlyoffice_log('callback.reject.record', [
            'id' => $id,
            'record_found' => $record ? 'yes' : 'no',
        ]);
        onlyoffice_callback_response(1);
    }

    $context = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true]]);
    $downloadT0 = microtime(true);
    $content = @file_get_contents($downloadUrl, false, $context);
    $downloadHeaders = is_array($http_response_header ?? null) ? $http_response_header : [];
    procedures_onlyoffice_log('callback.download_result', [
        'id' => $id,
        'status' => $status,
        'download_url' => $downloadUrl,
        'ok' => (is_string($content) && $content !== '') ? 'yes' : 'no',
        'bytes' => is_string($content) ? strlen($content) : 0,
        'ms' => (int)round((microtime(true) - $downloadT0) * 1000),
        'headers' => implode(' | ', array_slice($downloadHeaders, 0, 4)),
    ]);
    if (!is_string($content) || $content === '') {
        onlyoffice_callback_response(1);
    }

    $storedSize = strlen($content);
    $storedMime = (string)($record['file_mime'] ?? '');
    if (($record['storage_driver'] ?? '') === 'nextcloud') {
        $nextcloudCfg = function_exists('procedures_nextcloud_cfg_for_record')
            ? procedures_nextcloud_cfg_for_record($record)
            : null;
        if ($nextcloudCfg === null) {
            procedures_onlyoffice_log('callback.reject.nextcloud_cfg', ['id' => $id]);
            onlyoffice_callback_response(1);
        }
        $remotePath = trim((string)($record['nextcloud_path'] ?? ''));
        if ($remotePath === '') {
            procedures_onlyoffice_log('callback.reject.nextcloud_path', ['id' => $id]);
            onlyoffice_callback_response(1);
        }
        $uploadT0 = microtime(true);
        $upload = nextcloud_webdav_request($nextcloudCfg, 'PUT', $remotePath, $content, ['Content-Type: ' . ($storedMime !== '' ? $storedMime : 'application/octet-stream')]);
        procedures_onlyoffice_log('callback.nextcloud_upload', [
            'id' => $id,
            'ok' => empty($upload['ok']) ? 'no' : 'yes',
            'http' => (string)($upload['http'] ?? ''),
            'ms' => (int)round((microtime(true) - $uploadT0) * 1000),
            'path' => $remotePath,
            'message' => (string)($upload['message'] ?? ''),
        ]);
        if (empty($upload['ok'])) {
            onlyoffice_callback_response(1);
        }
    } else {
        $target = procedures_documents_dir() . '/' . basename((string)$record['file_name']);
        storage_write_file_locked($target, $content, 0, true);
        $storedSize = filesize($target) ?: $storedSize;
        $storedMime = procedures_detect_file_mime($target, $storedMime);
    }

    foreach ($items as $index => $item) {
        if ((string)($item['id'] ?? '') === $id) {
            $items[$index]['file_size'] = $storedSize;
            $items[$index]['file_mime'] = $storedMime;
            $items[$index]['updated_at'] = date('c');
            if (!empty($item['draft_pending']) && $status === 6) {
                $items[$index]['draft_pending'] = false;
            }
            break;
        }
    }
    procedures_write_all($items);
    procedures_onlyoffice_log('callback.saved', [
        'id' => $id,
        'status' => $status,
        'bytes' => $storedSize,
        'mime' => $storedMime,
    ]);
    onlyoffice_callback_response(0);
}

$onlyofficeAction = (string)($_GET['action'] ?? '');
if ($onlyofficeAction === 'callback') {
    onlyoffice_handle_callback();
}
if ($onlyofficeAction === 'client_log') {
    onlyoffice_handle_client_log();
}
