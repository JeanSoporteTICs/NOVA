<?php

namespace App\Modulos\RedmineMantencion\ExternalClients;

/**
 * Transport-only client for Nextcloud's WebDAV endpoint
 * ({url}/remote.php/dav/files/{admin_user}/...).
 *
 * Ported from RedmineMantencion/controllers/nextcloud.php's
 * nextcloud_webdav_base_url() / nextcloud_webdav_request() /
 * nextcloud_propfind_parse() / nextcloud_list_directory() /
 * nextcloud_path_safe(). Those procedural functions are kept as thin
 * wrappers around this class — they're called from nc_browser.php (the
 * file browser),
 * none of which were touched by this lote (Fase 8 lote 3, 2026-07). See
 * .claude/knowledge/external-clients-architecture.md.
 *
 * The OCS REST API (nextcloud_request()/nextcloud_sharing_request(), used
 * for user provisioning and share management — a different protocol from
 * WebDAV) is intentionally out of scope here; only the WebDAV surface
 * (file operations + directory listing) was requested for this lote.
 *
 * No NOVA config/session/DB knowledge: $cfg (url/admin_user/admin_pass) is
 * passed in by the caller.
 *
 * Deliberate exception to this class not knowing about NOVA: audit logging
 * (nextcloud_log_action()) for WebDAV writes is NOT done here — it stays in
 * the nextcloud_webdav_request() wrapper, both because it's a NOVA-specific
 * concern the client shouldn't own, and because the project's own rule
 * ("never add a new WebDAV write path without calling nextcloud_log_action()")
 * has to keep firing for every one of nc_browser.php's/procedimientos.php's
 * existing call sites without any of them being modified in this lote.
 * Transport-level perf logging (error_log('[NC_PERF] ...')) is kept here,
 * since it's a neutral operational log, not a NOVA audit concern.
 */
final class NextcloudWebdavClient
{
    /**
     * @param array{url?:string,admin_user?:string} $cfg
     */
    public function baseUrl(array $cfg): string
    {
        $base = rtrim((string) ($cfg['url'] ?? ''), '/');
        $user = rawurlencode((string) ($cfg['admin_user'] ?? ''));

        return $base . '/remote.php/dav/files/' . $user;
    }

    /**
     * @param array{url?:string,admin_user?:string,admin_pass?:string} $cfg
     * @param string|null $body
     * @param array<int,string> $headers
     * @return array{ok:bool,http:int,body:string,headers:string,message:string}
     */
    public function request(array $cfg, string $method, string $path, $body = null, array $headers = []): array
    {
        $method = strtoupper($method);
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');
        $url = $this->baseUrl($cfg) . implode('/', array_map('rawurlencode', explode('/', $path)));

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

        $t0 = microtime(true);
        $response = curl_exec($ch);
        $ms = (int) round((microtime(true) - $t0) * 1000);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            error_log('[NC_PERF] WEBDAV ' . $method . ' ' . $url . ' ms=' . $ms . ' CURL_ERROR=' . $err);

            return ['ok' => false, 'http' => 0, 'body' => '', 'headers' => '', 'message' => $err];
        }

        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        error_log('[NC_PERF] WEBDAV ' . $method . ' ' . $url . ' ms=' . $ms . ' http=' . $http);

        return [
            'ok' => $http >= 200 && $http < 300,
            'http' => $http,
            'headers' => substr((string) $response, 0, $headerSize),
            'body' => substr((string) $response, $headerSize),
            'message' => $http >= 400 ? 'HTTP ' . $http : '',
        ];
    }

    /**
     * @return array<int,array{name:string,path:string,type:string,mime:string,size:int,last_modified:string,etag:string,file_id:string,permissions:string}>
     */
    public function propfindParse(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadXML($xml);
        libxml_use_internal_errors($prev);

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('d', 'DAV:');
        $xpath->registerNamespace('oc', 'http://owncloud.org/ns');

        $responses = $xpath->query('//d:response');
        if (!$responses || $responses->length === 0) {
            return [];
        }

        $items = [];
        $skippedRoot = false;

        foreach ($responses as $response) {
            if (!$skippedRoot) {
                $skippedRoot = true;
                continue;
            }

            $href = urldecode(trim((string) ($xpath->evaluate('string(d:href)', $response))));

            // Extract the user-relative path from the DAV href.
            // href looks like: /[basepath]/remote.php/dav/files/USER/actual/path
            $pathPart = '';
            if (preg_match('#/remote\.php/dav/files/[^/]+(/.*)?$#', $href, $m)) {
                $pathPart = rtrim((string) ($m[1] ?? ''), '/');
            }
            if ($pathPart === '') {
                $pathPart = '/' . ltrim($href, '/');
            }
            if ($pathPart === '') {
                $pathPart = '/';
            }

            $isDir = $xpath->evaluate('count(d:propstat/d:prop/d:resourcetype/d:collection)', $response) > 0;
            $displayName = trim((string) ($xpath->evaluate('string(d:propstat/d:prop/d:displayname)', $response)));
            if ($displayName === '') {
                $displayName = basename($pathPart);
            }

            $items[] = [
                'name' => $displayName,
                'path' => $pathPart,
                'type' => $isDir ? 'dir' : 'file',
                'mime' => $isDir ? 'httpd/unix-directory' : trim((string) ($xpath->evaluate('string(d:propstat/d:prop/d:getcontenttype)', $response))),
                'size' => (int) ($xpath->evaluate('number(d:propstat/d:prop/d:getcontentlength)', $response)),
                'last_modified' => trim((string) ($xpath->evaluate('string(d:propstat/d:prop/d:getlastmodified)', $response))),
                'etag' => trim((string) ($xpath->evaluate('string(d:propstat/d:prop/d:getetag)', $response))),
                'file_id' => trim((string) ($xpath->evaluate('string(d:propstat/d:prop/oc:fileid)', $response))),
                'permissions' => trim((string) ($xpath->evaluate('string(d:propstat/d:prop/oc:permissions)', $response))),
            ];
        }

        usort($items, static function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }

            return strnatcasecmp((string) $a['name'], (string) $b['name']);
        });

        return $items;
    }

    /**
     * @param array{url?:string,admin_user?:string,admin_pass?:string} $cfg
     * @return array{ok:bool,path?:string,items?:array<int,mixed>,error?:string,http?:int,timeout?:bool}
     */
    public function listDirectory(array $cfg, string $path): array
    {
        $path = $this->pathSafe($path);

        $propfindBody = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><d:displayname/><d:getcontenttype/><d:getcontentlength/><d:getlastmodified/><d:getetag/><d:resourcetype/><oc:fileid/><oc:permissions/></d:prop></d:propfind>';

        $pathSegments = '/' . ltrim($path, '/');
        $url = $this->baseUrl($cfg)
            . implode('/', array_map('rawurlencode', explode('/', $pathSegments)));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PROPFIND',
            CURLOPT_USERPWD => $cfg['admin_user'] . ':' . $cfg['admin_pass'],
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/xml; charset=utf-8',
                'Depth: 1',
                'Accept: application/xml',
            ],
            CURLOPT_POSTFIELDS => $propfindBody,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 5,
        ]);

        $t0 = microtime(true);
        $resp = curl_exec($ch);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if ($resp === false) {
            $errno = curl_errno($ch);
            $err = curl_error($ch);
            curl_close($ch);
            error_log('[NC_PERF] PROPFIND depth=1 path=' . $path . ' url=' . $url . ' ms=' . $ms . ' CURL_ERRNO=' . $errno . ' ' . $err);
            if ($errno === 28) {
                return [
                    'ok' => false,
                    'error' => 'Nextcloud no respondio a tiempo. Intente nuevamente o revise la conexion del servidor.',
                    'timeout' => true,
                ];
            }

            return ['ok' => false, 'error' => $err];
        }
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http !== 207) {
            error_log('[NC_PERF] PROPFIND depth=1 path=' . $path . ' url=' . $url . ' ms=' . $ms . ' http=' . $http);
            $hint = match ($http) {
                401 => ' — credenciales inválidas',
                403 => ' — sin permiso',
                404 => ' — ruta no encontrada',
                default => '',
            };

            return ['ok' => false, 'error' => 'HTTP ' . $http . $hint, 'http' => $http];
        }

        $items = $this->propfindParse((string) $resp);
        error_log('[NC_PERF] PROPFIND depth=1 path=' . $path . ' url=' . $url . ' ms=' . $ms . ' http=' . $http . ' items=' . count($items));

        return ['ok' => true, 'path' => $path, 'items' => $items];
    }

    public function pathSafe(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $parts = array_values(array_filter(
            explode('/', $path),
            static fn (string $p): bool => $p !== '' && $p !== '.' && $p !== '..'
        ));

        return '/' . implode('/', $parts);
    }
}
