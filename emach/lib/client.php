<?php

declare(strict_types=1);

const EMACH_CLIENT_BASE_URL = 'http://10.6.206.19/index.php';
const EMACH_CLIENT_COLUMNS = [
    'codigo_enrolamiento',
    'run',
    'nombre',
    'fecha',
    'marcas',
    'tipo',
    'reloj',
    'longitud',
    'latitud',
    'precision',
];

function emach_client_planilla_urls(int $year, int $month): array
{
    $query = http_build_query([
        'ano' => $year,
        'mes' => $month,
        '_' => (int) round(microtime(true) * 1000),
    ]);

    return [
        EMACH_CLIENT_BASE_URL . '/reportes/getplanilla?' . $query,
        EMACH_CLIENT_BASE_URL . '/autoconsulta/getplanilla?' . $query,
    ];
}

function emach_client_curl_request(string $url, string $cookieFile, array $options = []): array
{
    $headers = $options['headers'] ?? ['Accept: application/json'];
    if (!empty($options['referer'])) {
        $headers[] = 'Referer: ' . $options['referer'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0 Safari/537.36',
    ]);

    if (($options['method'] ?? 'GET') === 'POST') {
        $fields = $options['fields'] ?? [];
        if (is_array($fields)) {
            $fields = http_build_query($fields);
            if (!array_filter($headers, static fn(string $header): bool => str_starts_with(strtolower($header), 'content-type:'))) {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
    }

    $body = curl_exec($ch);
    $response = [
        'body' => $body === false ? '' : (string) $body,
        'http_code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'content_type' => (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
        'effective_url' => (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        'error' => (string) curl_error($ch),
    ];
    curl_close($ch);

    return $response;
}

function emach_client_fetch_planilla_rows(int $year, int $month, string $username, string $password): array
{
    $cookieFile = tempnam(sys_get_temp_dir(), 'emach-client-');
    if (!is_string($cookieFile) || $cookieFile === '') {
        throw new RuntimeException('No se pudo crear cookie temporal.');
    }

    try {
        emach_client_prime_session($cookieFile, $username, $password);
        foreach (emach_client_planilla_urls($year, $month) as $url) {
            $referer = str_contains($url, '/reportes/')
                ? EMACH_CLIENT_BASE_URL . '/reportes/planilla'
                : EMACH_CLIENT_BASE_URL . '/autoconsulta/marcas/';
            $response = emach_client_curl_request($url, $cookieFile, [
                'headers' => [
                    'Accept: application/json, text/javascript, */*; q=0.01',
                    'X-Requested-With: XMLHttpRequest',
                ],
                'referer' => $referer,
            ]);
            if ($response['error'] !== '') {
                continue;
            }
            $payload = json_decode($response['body'], true);
            if (is_array($payload) && is_array($payload['data'] ?? null)) {
                return array_values(array_filter($payload['data'], 'is_array'));
            }
        }
    } finally {
        @unlink($cookieFile);
    }

    throw new RuntimeException('EMACH no devolvio JSON de planilla.');
}

function emach_client_prime_session(string $cookieFile, string $username, string $password): void
{
    $landingUrl = EMACH_CLIENT_BASE_URL . '/autoconsulta/marcas/';
    $landing = emach_client_curl_request($landingUrl, $cookieFile, [
        'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
    ]);
    emach_client_login_trabajador_from_html($landing['body'], $landing['effective_url'] ?: $landingUrl, $cookieFile, $username, $password);
    emach_client_curl_request(EMACH_CLIENT_BASE_URL . '/reportes/planilla', $cookieFile, [
        'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
        'referer' => $landingUrl,
    ]);
}

function emach_client_login_trabajador_from_html(string $html, string $pageUrl, string $cookieFile, string $username, string $password): void
{
    if ($html === '' || !preg_match('/<form\b([^>]*\bid\s*=\s*(["\'])frmTrabajador\2[^>]*)>(.*?)<\/form>/is', $html, $form)) {
        throw new RuntimeException('No se encontro el formulario de trabajador en EMACH.');
    }

    $fields = [];
    preg_match_all('/<input\b([^>]*)>/is', $form[3], $inputs, PREG_SET_ORDER);
    foreach ($inputs as $input) {
        $attrs = emach_client_parse_attrs($input[1]);
        $name = (string) ($attrs['name'] ?? '');
        if ($name !== '') {
            $fields[$name] = (string) ($attrs['value'] ?? '');
        }
    }

    $fields['rut'] = $username;
    $fields['pass'] = $password;
    $fields['url'] = $fields['url'] ?? '/index.php/autoconsulta/marcas/';

    $formAttrs = emach_client_parse_attrs($form[1]);
    $action = emach_client_absolute_url((string) ($formAttrs['action'] ?? '/index.php/cloud/doLoginTrabajador'), $pageUrl);
    $response = emach_client_curl_request($action, $cookieFile, [
        'method' => 'POST',
        'fields' => $fields,
        'headers' => [
            'Accept: text/html,application/xhtml+xml,application/json',
            'Origin: http://10.6.206.19',
        ],
        'referer' => $pageUrl,
    ]);

    if ($response['error'] !== '' || $response['http_code'] < 200 || $response['http_code'] >= 400) {
        throw new RuntimeException('Login trabajador EMACH fallo. HTTP ' . $response['http_code']);
    }
}

function emach_client_parse_attrs(string $tag): array
{
    $attrs = [];
    preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/s', $tag, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $attrs[strtolower($match[1])] = html_entity_decode($match[3], ENT_QUOTES, 'UTF-8');
    }
    return $attrs;
}

function emach_client_absolute_url(string $url, string $baseUrl): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
    if ($url === '') {
        return $baseUrl;
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    $parts = parse_url($baseUrl);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return $url;
    }
    $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    if (str_starts_with($url, '/')) {
        return $origin . $url;
    }
    $path = (string) ($parts['path'] ?? '/');
    $directory = rtrim(substr($path, 0, (int) strrpos($path, '/') + 1), '/');
    return $origin . $directory . '/' . ltrim($url, '/');
}

function emach_client_normalize_mark(array $row): array
{
    $row = array_pad(array_values($row), count(EMACH_CLIENT_COLUMNS), '');
    $mark = [];
    foreach (EMACH_CLIENT_COLUMNS as $index => $key) {
        $mark[$key] = $row[$index] === null ? '' : trim((string) $row[$index]);
    }
    return $mark;
}
