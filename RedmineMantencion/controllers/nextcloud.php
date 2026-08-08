<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/core_credentials.php';
require_once __DIR__ . '/maintenance.php';

function nextcloud_security_actor(): string {
    $user = mantencion_current_user();
    $name = trim((string)($user['nombre'] ?? ''));
    $id = trim((string)($user['id'] ?? ''));
    if ($name === '' && $id === '') {
        return 'usuario desconocido';
    }
    return trim($name . ($id !== '' ? ' (ID ' . $id . ')' : ''));
}

function nextcloud_log_action(string $tag, string $details): void {
    if (!function_exists('log_security_event')) {
        return;
    }
    $tag = preg_replace('/[^A-Z0-9_]+/', '_', strtoupper($tag)) ?? strtoupper($tag);
    $details = preg_replace('/[\r\n\t]+/', ' ', $details) ?? $details;
    $details = trim($details);
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $suffix = $ip !== '' ? ' | IP ' . $ip : '';
    log_security_event($tag, nextcloud_security_actor() . ' | ' . $details . $suffix);
}

function nextcloud_config_load(): array {
    $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
    if ($repo !== null) {
        $data = $repo->loadAll();
        if (is_array($data) && $data !== []) {
            return $data;
        }
    }
    return [];
}

function nextcloud_request(array $cfg, string $method, string $path, array $payload = [], int $timeoutSeconds = 30): array {
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
    $timeoutSeconds = max(5, min(60, $timeoutSeconds));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_USERPWD => $cfg['admin_user'] . ':' . $cfg['admin_pass'],
        CURLOPT_HTTPHEADER => ['OCS-APIRequest: true', 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeoutSeconds,
    ]);
    if ($pairs) curl_setopt($ch, CURLOPT_POSTFIELDS, implode('&', $pairs));
    $_ncT0 = microtime(true);
    $resp = curl_exec($ch);
    $_ncMs = (int) round((microtime(true) - $_ncT0) * 1000);
    if ($resp === false) {
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        error_log('[NC_PERF] OCS ' . $method . ' ' . $url . ' ms=' . $_ncMs . ' CURL_ERROR=' . $err);
        $message = $errno === CURLE_OPERATION_TIMEDOUT
            ? 'Nextcloud demoró más de ' . $timeoutSeconds . ' segundos en responder. Intenta nuevamente.'
            : 'No fue posible conectar con Nextcloud. Verifica la URL y vuelve a intentarlo.';
        return [
            'ok' => false,
            'http' => 0,
            'statuscode' => 0,
            'message' => $message,
            'timeout' => $errno === CURLE_OPERATION_TIMEDOUT,
            'curl_errno' => $errno,
        ];
    }
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$resp, true);
    $meta = is_array($json) ? ($json['ocs']['meta'] ?? []) : [];
    $statusCode = (int)($meta['statuscode'] ?? 0);
    $message = trim((string)($meta['message'] ?? ''));
    error_log('[NC_PERF] OCS ' . $method . ' ' . $url . ' ms=' . $_ncMs . ' http=' . $http . ' statuscode=' . $statusCode);
    return [
        'ok' => $http < 400 && $statusCode === 100,
        'http' => $http,
        'statuscode' => $statusCode,
        'message' => $message,
        'data' => is_array($json) ? ($json['ocs']['data'] ?? null) : null,
    ];
}

function nextcloud_sharing_request(array $cfg, string $method, string $path, array $payload = []): array {
    $base = rtrim((string)$cfg['url'], '/');
    $path = '/' . ltrim($path, '/');
    $url = $base . '/ocs/v2.php/apps/files_sharing/api/v1' . $path . (str_contains($path, '?') ? '&' : '?') . 'format=json';
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
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($pairs) curl_setopt($ch, CURLOPT_POSTFIELDS, implode('&', $pairs));
    $_ncT0 = microtime(true);
    $resp = curl_exec($ch);
    $_ncMs = (int) round((microtime(true) - $_ncT0) * 1000);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        error_log('[NC_PERF] OCS-SHARING ' . $method . ' ' . $url . ' ms=' . $_ncMs . ' CURL_ERROR=' . $err);
        return ['ok' => false, 'http' => 0, 'statuscode' => 0, 'message' => $err, 'data' => null];
    }
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$resp, true);
    $meta = is_array($json) ? ($json['ocs']['meta'] ?? []) : [];
    $statusCode = (int)($meta['statuscode'] ?? 0);
    $message = trim((string)($meta['message'] ?? ''));
    if ($message === '' && $http >= 400) {
        $message = 'HTTP ' . $http;
    }
    error_log('[NC_PERF] OCS-SHARING ' . $method . ' ' . $url . ' ms=' . $_ncMs . ' http=' . $http . ' statuscode=' . $statusCode);
    return [
        'ok' => $http < 400 && $statusCode === 100,
        'http' => $http,
        'statuscode' => $statusCode,
        'message' => $message,
        'data' => is_array($json) ? ($json['ocs']['data'] ?? null) : null,
    ];
}

/**
 * Transport now lives in App\Modulos\RedmineMantencion\ExternalClients\NextcloudWebdavClient
 * (Fase 8 lote 3 of the 2026-07 standardization program — see
 * .claude/knowledge/external-clients-architecture.md). This function stays
 * as a thin wrapper — same signature, same return shape — because it's
 * still called directly by nc_browser.php, procedimientos.php and
 * Other Nextcloud flows are not touched by this lote. Audit logging
 * (nextcloud_log_action()) for WebDAV writes stays here rather than moving
 * into the client, per this module's own rule that every WebDAV write path
 * must call it — see .claude/skills/09-nextcloud/SKILL.md.
 */
function nextcloud_webdav_base_url(array $cfg): string {
    return (new \App\Modulos\RedmineMantencion\ExternalClients\NextcloudWebdavClient())->baseUrl($cfg);
}

function nextcloud_webdav_request(array $cfg, string $method, string $path, $body = null, array $headers = []): array {
    $method = strtoupper($method);
    $normalizedPath = '/' . ltrim(str_replace('\\', '/', $path), '/');
    $result = (new \App\Modulos\RedmineMantencion\ExternalClients\NextcloudWebdavClient())->request($cfg, $method, $path, $body, $headers);

    if (!in_array($method, ['PUT', 'DELETE', 'MOVE', 'COPY', 'MKCOL'], true) || !function_exists('nextcloud_log_action')) {
        return $result;
    }

    // http === 0 only happens on the client's curl-failure branch (a real
    // HTTP response, even 4xx/5xx, always has a non-zero status) — same
    // discriminator the original inline code used implicitly via early return.
    if ($result['http'] === 0) {
        nextcloud_log_action(
            $method === 'PUT' ? 'NEXTCLOUD_WEBDAV_WRITE' : 'NEXTCLOUD_' . ($method === 'MKCOL' ? 'MKDIR' : $method),
            'FAIL | WebDAV ' . $method . ' | path ' . $normalizedPath . ' | error ' . $result['message']
        );

        return $result;
    }

    $tag = match ($method) {
        'PUT' => 'NEXTCLOUD_WEBDAV_WRITE',
        'DELETE' => 'NEXTCLOUD_DELETE',
        'MOVE' => 'NEXTCLOUD_MOVE',
        'COPY' => 'NEXTCLOUD_COPY',
        'MKCOL' => 'NEXTCLOUD_MKDIR',
        default => 'NEXTCLOUD_WEBDAV',
    };
    $destination = '';
    foreach ($headers as $header) {
        if (stripos((string)$header, 'Destination:') === 0) {
            $destination = trim(substr((string)$header, strlen('Destination:')));
            break;
        }
    }
    nextcloud_log_action(
        $tag,
        ($result['ok'] ? 'OK' : 'FAIL')
            . ' | WebDAV ' . $method
            . ' | path ' . $normalizedPath
            . ($destination !== '' ? ' | destino ' . $destination : '')
            . ($body !== null ? ' | bytes ' . strlen((string)$body) : '')
            . ' | http ' . $result['http']
    );

    return $result;
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
    $res = nextcloud_sharing_request($cfg, 'POST', '/shares', [
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
    $res = nextcloud_sharing_request($cfg, 'DELETE', '/shares/' . rawurlencode($shareId));
    return $res['ok'] ? ['ok' => true] : ['ok' => false, 'error' => (($res['message'] ?? '') ?: 'No se pudo eliminar enlace compartido.')];
}

function nextcloud_path_safe(string $path): string
{
    return (new \App\Modulos\RedmineMantencion\ExternalClients\NextcloudWebdavClient())->pathSafe($path);
}

function nextcloud_propfind_parse(string $xml): array
{
    return (new \App\Modulos\RedmineMantencion\ExternalClients\NextcloudWebdavClient())->propfindParse($xml);
}

function nextcloud_list_directory(array $cfg, string $path): array
{
    return (new \App\Modulos\RedmineMantencion\ExternalClients\NextcloudWebdavClient())->listDirectory($cfg, $path);
}

function nextcloud_shares_with_me(array $cfg): array
{
    $res = nextcloud_sharing_request($cfg, 'GET', '/shares?shared_with_me=true');
    if (!$res['ok']) {
        return ['ok' => false, 'error' => (($res['message'] ?? '') ?: 'HTTP ' . ($res['http'] ?? 0))];
    }
    $data   = is_array($res['data'] ?? null) ? $res['data'] : [];
    $shares = [];
    foreach ($data as $share) {
        if (!is_array($share)) {
            continue;
        }
        $shares[] = [
            'id'                  => (string) ($share['id'] ?? ''),
            'path'                => (string) ($share['path'] ?? ''),
            'name'                => basename((string) ($share['file_target'] ?? $share['path'] ?? '')),
            'share_type'          => (int) ($share['share_type'] ?? 0),
            'uid_owner'           => (string) ($share['uid_owner'] ?? ''),
            'displayname_owner'   => (string) ($share['displayname_owner'] ?? ''),
            'permissions'         => (int) ($share['permissions'] ?? 0),
            'stime'               => (int) ($share['stime'] ?? 0),
            'item_type'           => (string) ($share['item_type'] ?? 'file'),
            'mimetype'            => (string) ($share['mimetype'] ?? ''),
            'size'                => (int) ($share['size'] ?? 0),
        ];
    }
    return ['ok' => true, 'shares' => $shares];
}
