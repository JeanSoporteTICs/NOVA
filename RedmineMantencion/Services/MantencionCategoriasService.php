<?php

namespace App\Modulos\RedmineMantencion\Services;

class MantencionCategoriasService
{
    public function loadCategorias()
    {
        $repo = function_exists('mantencion_catalog_repository') ? mantencion_catalog_repository() : null;
        $data = $repo !== null ? $repo->categorias() : [];
        if (!is_array($data)) {
            $data = [];
        }
        foreach ($data as &$item) {
            if (!isset($item['id'])) {
                $item['id'] = uniqid('', true);
            }
            if (!isset($item['nombre'])) {
                $item['nombre'] = '';
            }
        }

        return $data;
    }

    public function saveCategorias($data)
    {
        $repo = function_exists('mantencion_catalog_repository') ? mantencion_catalog_repository() : null;
        if ($repo !== null) {
            $repo->upsertCategorias(is_array($data) ? $data : []);
        }
    }

    public function apiUrl($platformUrl)
    {
        if (!$platformUrl) {
            return '';
        }
        if (preg_match('#/issues\.json$#', $platformUrl)) {
            return preg_replace('#/issues\.json$#', '/issue_categories.json', $platformUrl);
        }
        $parts = parse_url($platformUrl);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port . '/issue_categories.json';
    }

    public function requestUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#/settings/categories/?$#', $url)) {
            return preg_replace('#/settings/categories/?$#', '/issue_categories.json', $url);
        }

        return $url;
    }

    public function parseHtml($html)
    {
        $cats = [];
        if (!is_string($html) || trim($html) === '') {
            return $cats;
        }
        if (preg_match_all('/<tr\b[^>]*id\s*=\s*"issue-category-([^"]+)"[^>]*>(.*?)<\/tr>/is', $html, $rows, PREG_SET_ORDER)) {
            foreach ($rows as $row) {
                $id = trim(html_entity_decode($row[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $content = $row[2] ?? '';
                $name = '';
                if (preg_match('/<a\b[^>]*>(.*?)<\/a>/is', $content, $nameMatch)) {
                    $name = trim(html_entity_decode(strip_tags($nameMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                } else {
                    $cells = preg_split('/<\/td>/i', $content);
                    if (isset($cells[0])) {
                        $name = trim(html_entity_decode(strip_tags($cells[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    }
                }
                if ($id !== '' && $name !== '') {
                    $cats[] = ['id' => $id, 'nombre' => $name];
                }
            }
        }

        return $cats;
    }

    public function userApiTokenFallback()
    {
        if (!function_exists('auth_get_user_id')) {
            return '';
        }
        $uid = auth_get_user_id();
        if (!$uid) {
            return '';
        }
        if (function_exists('auth_central_redmine_api_token')) {
            $central = auth_central_redmine_api_token($uid);
            if ($central !== '') {
                return $central;
            }
        }

        return '';
    }

    public function syncFromApi()
    {
        $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
        $cfg = $repo !== null ? $repo->loadAll() : [];
        $platformUrl = $cfg['platform_url'] ?? '';
        $apiKey = $this->userApiTokenFallback();
        $url = !empty($cfg['categories_url']) ? $cfg['categories_url'] : $this->apiUrl($platformUrl);
        $url = $this->requestUrl($url);
        if (!$url) {
            return ['error' => 'Falta platform_url o categories_url en configuraci&oacute;n.'];
        }
        if (!$apiKey) {
            return ['error' => 'Falta token de API personal. Agrega tu API en Cuentas conectadas.'];
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

            return ['error' => "No se pudo conectar: $err"];
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            return ['error' => "HTTP $code al consultar issue_categories."];
        }
        $cats = [];
        $json = json_decode($resp, true);
        if (isset($json['issue_categories']) && is_array($json['issue_categories'])) {
            foreach ($json['issue_categories'] as $cat) {
                if (!is_array($cat)) {
                    continue;
                }
                $cats[] = [
                    'id' => (string) ($cat['id'] ?? ''),
                    'nombre' => $cat['name'] ?? '',
                ];
            }
        } else {
            $cats = $this->parseHtml($resp);
        }
        if (empty($cats)) {
            return ['error' => 'La respuesta no contiene categor&iacute;as v&aacute;lidas.'];
        }
        $this->saveCategorias($cats);

        return ['ok' => count($cats)];
    }

    public function handle()
    {
        $cats = $this->loadCategorias();
        $flash = null;
        $error = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (function_exists('csrf_validate')) {
                csrf_validate();
            }
            if (function_exists('maintenance_mode_block_if_enabled')) {
                maintenance_mode_block_if_enabled();
            }
            $action = $_POST['action'] ?? '';
            if ($action === 'sync_remote') {
                $res = $this->syncFromApi();
                if (isset($res['error'])) {
                    $error = $res['error'];
                } else {
                    $flash = 'Categorías actualizadas desde API (' . ($res['ok'] ?? 0) . ' registros)';
                }
            }
            $cats = $this->loadCategorias();
        }

        return [$cats, $flash, $error];
    }
}
