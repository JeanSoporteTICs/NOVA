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
        CURLOPT_CONNECTTIMEOUT => (int) ($options['connect_timeout'] ?? 3),
        CURLOPT_TIMEOUT => (int) ($options['timeout'] ?? 8),
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

/**
 * Transport now lives in App\Modulos\Emach\ExternalClients\EmachScraperClient
 * (Fase 8 lote 2 of the 2026-07 standardization program — see
 * .claude/knowledge/external-clients-architecture.md). This function is kept
 * as a thin wrapper, with the exact same signature and throwing behavior,
 * because it's still called directly by telegram/bin/listen.php. The other
 * emach_client_* functions below are unused now that the client owns this
 * logic, but are left in place rather than deleted — see the architecture
 * doc for why removing them is a separate future step.
 */
function emach_client_fetch_planilla_rows(int $year, int $month, string $username, string $password): array
{
    return (new \App\Modulos\Emach\ExternalClients\EmachScraperClient())->fetchPlanillaRows($year, $month, $username, $password);
}

function emach_client_prime_session(string $cookieFile, string $username, string $password): void
{
    $landingUrl = EMACH_CLIENT_BASE_URL . '/autoconsulta/marcas/';
    $landing = emach_client_curl_request($landingUrl, $cookieFile, [
        'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
    ]);
    if ($landing['error'] !== '') {
        throw new RuntimeException('No se pudo conectar con EMACH: ' . $landing['error']);
    }

    $loggedAsWorker = emach_client_login_trabajador_from_html($landing['body'], $landing['effective_url'] ?: $landingUrl, $cookieFile, $username, $password);
    if (!$loggedAsWorker) {
        emach_client_login_from_html($landing['body'], $landing['effective_url'] ?: $landingUrl, $cookieFile, $username, $password);
    }

    $loginPageUrl = EMACH_CLIENT_BASE_URL . '/site/login';
    $loginPage = emach_client_curl_request($loginPageUrl, $cookieFile, [
        'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
        'referer' => $landingUrl,
    ]);
    if ($loginPage['error'] !== '') {
        throw new RuntimeException('No se pudo conectar con login EMACH: ' . $loginPage['error']);
    }
    if (!$loggedAsWorker) {
        $loggedAsWorker = emach_client_login_trabajador_from_html($loginPage['body'], $loginPage['effective_url'] ?: $loginPageUrl, $cookieFile, $username, $password);
    }
    if (!$loggedAsWorker) {
        emach_client_login_from_html($loginPage['body'], $loginPage['effective_url'] ?: $loginPageUrl, $cookieFile, $username, $password);
    }

    $loginAttempts = [
        [EMACH_CLIENT_BASE_URL . '/cloud/doLoginTrabajador', [
            'csrf_test_name' => '',
            'url' => '/index.php/autoconsulta/marcas/',
            'rut' => $username,
            'pass' => $password,
        ]],
        [EMACH_CLIENT_BASE_URL . '/site/login', [
            'LoginForm[username]' => $username,
            'LoginForm[password]' => $password,
            'LoginForm[rememberMe]' => '0',
            'login' => $username,
            'password' => $password,
            'usuario' => $username,
            'clave' => $password,
            'rut' => $username,
            'run' => $username,
        ]],
        [EMACH_CLIENT_BASE_URL . '/autoconsulta/login', [
            'usuario' => $username,
            'contrasena' => $password,
            'clave' => $password,
            'username' => $username,
            'password' => $password,
            'rut' => $username,
            'run' => $username,
        ]],
        [EMACH_CLIENT_BASE_URL . '/autoconsulta/marcas/', [
            'usuario' => $username,
            'contrasena' => $password,
            'clave' => $password,
            'username' => $username,
            'password' => $password,
            'rut' => $username,
            'run' => $username,
        ]],
        [EMACH_CLIENT_BASE_URL . '/autoconsulta/marcas', [
            'usuario' => $username,
            'contrasena' => $password,
            'clave' => $password,
            'username' => $username,
            'password' => $password,
            'rut' => $username,
            'run' => $username,
        ]],
    ];

    if (!$loggedAsWorker) {
        foreach (array_slice($loginAttempts, 0, 2) as [$loginUrl, $fields]) {
            $attemptResponse = emach_client_curl_request($loginUrl, $cookieFile, [
                'method' => 'POST',
                'fields' => $fields,
                'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
                'referer' => $landingUrl,
            ]);
            if ($attemptResponse['error'] !== '') {
                throw new RuntimeException('No se pudo enviar login EMACH: ' . $attemptResponse['error']);
            }
        }
    }

    $afterLanding = emach_client_curl_request($landingUrl, $cookieFile, [
        'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
        'referer' => EMACH_CLIENT_BASE_URL . '/site/login',
    ]);
    if ($afterLanding['error'] !== '') {
        throw new RuntimeException('No se pudo validar sesion EMACH: ' . $afterLanding['error']);
    }
    if (emach_client_is_login_page($afterLanding['body'])) {
        throw new RuntimeException('Credenciales EMACH incorrectas o no aceptadas. Actualiza tu usuario y contrasena en NOVA; si estan correctas, revisa si EMACH solicita token/captcha adicional.');
    }

    emach_client_curl_request(EMACH_CLIENT_BASE_URL . '/reportes/planilla', $cookieFile, [
        'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
        'referer' => $landingUrl,
    ]);
}

function emach_client_login_trabajador_from_html(string $html, string $pageUrl, string $cookieFile, string $username, string $password): bool
{
    if ($html === '' || !preg_match('/<form\b([^>]*\bid\s*=\s*(["\'])frmTrabajador\2[^>]*)>(.*?)<\/form>/is', $html, $form)) {
        return false;
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

    return $response['error'] === '' && $response['http_code'] >= 200 && $response['http_code'] < 400;
}

function emach_client_login_from_html(string $html, string $pageUrl, string $cookieFile, string $username, string $password): bool
{
    if ($html === '' || stripos($html, '<form') === false) {
        return false;
    }

    preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/is', $html, $forms, PREG_SET_ORDER);
    foreach ($forms as $form) {
        $formAttrs = emach_client_parse_attrs($form[1]);
        $formBody = $form[2];
        if (stripos($formBody, 'password') === false && stripos($formBody, 'contras') === false) {
            continue;
        }

        $fields = [];
        $passwordName = '';
        $usernameName = '';
        preg_match_all('/<input\b([^>]*)>/is', $formBody, $inputs, PREG_SET_ORDER);
        foreach ($inputs as $input) {
            $attrs = emach_client_parse_attrs($input[1]);
            $name = (string) ($attrs['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $type = strtolower((string) ($attrs['type'] ?? 'text'));
            $fields[$name] = (string) ($attrs['value'] ?? '');
            if ($type === 'password') {
                $passwordName = $name;
            } elseif ($usernameName === '' && in_array($type, ['text', 'email', 'number', 'tel', 'search'], true)) {
                $usernameName = $name;
            }
        }

        if ($passwordName === '') {
            continue;
        }
        if ($usernameName === '') {
            foreach (array_keys($fields) as $name) {
                $key = strtolower($name);
                if (str_contains($key, 'user') || str_contains($key, 'login') || str_contains($key, 'rut') || str_contains($key, 'run') || str_contains($key, 'codigo')) {
                    $usernameName = $name;
                    break;
                }
            }
        }
        if ($usernameName === '') {
            continue;
        }

        $fields[$usernameName] = $username;
        $fields[$passwordName] = $password;
        foreach (['login', 'yt0', 'submit', 'ingresar'] as $submitName) {
            if (!isset($fields[$submitName])) {
                $fields[$submitName] = 'Ingresar';
            }
        }

        $action = emach_client_absolute_url((string) ($formAttrs['action'] ?? ''), $pageUrl);
        $response = emach_client_curl_request($action, $cookieFile, [
            'method' => 'POST',
            'fields' => $fields,
            'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
            'referer' => $pageUrl,
        ]);

        if ($response['error'] === '' && $response['http_code'] >= 200 && $response['http_code'] < 400) {
            return true;
        }
    }

    return false;
}

function emach_client_is_login_page(string $html): bool
{
    if ($html === '') {
        return false;
    }

    return stripos($html, 'frmTrabajador') !== false
        || stripos($html, 'id="frmUsuario"') !== false
        || stripos($html, "id='frmUsuario'") !== false;
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
