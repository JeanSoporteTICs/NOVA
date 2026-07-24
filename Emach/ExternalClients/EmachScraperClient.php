<?php

namespace App\Modulos\Emach\ExternalClients;

/**
 * Transport-only client for EMACH's clock-in/out web app.
 *
 * EMACH has no real API — this scrapes its legacy web login flow (cookie
 * session, HTML login forms) to fetch "planilla" (attendance) rows. Ported
 * from Emach/lib/client.php's emach_client_* functions, which are left
 * untouched (emach_client_fetch_planilla_rows() now delegates here — see
 * the note on that function — but the rest of that file, and the fully
 * separate/duplicate scraper inside Emach/index.php, are out of scope for
 * this lote). See .claude/knowledge/external-clients-architecture.md.
 *
 * No NOVA config/session/DB knowledge: username/password are passed in by
 * the caller (the Service resolves them from NOVA's own credential storage).
 *
 * fetchPlanillaRows() throws RuntimeException on failure rather than
 * returning ['ok','data','error'] — a deliberate exception to the general
 * client contract. Its 2 existing callers (Nova\UserIntegrationController,
 * telegram/bin/listen.php) both already depend on catching that exception,
 * and the multi-step login flow internally unwinds via exceptions across
 * several nested calls — converting that to array-checking at every step
 * would be a much larger, riskier rewrite than this lote's scope allows.
 */
final class EmachScraperClient
{
    private const BASE_URL = 'http://10.6.206.19/index.php';

    /**
     * @return array<int,array<int|string,mixed>>
     */
    public function fetchPlanillaRows(int $year, int $month, string $username, string $password): array
    {
        $cookieFile = tempnam(sys_get_temp_dir(), 'emach-client-');
        if (!is_string($cookieFile) || $cookieFile === '') {
            throw new \RuntimeException('No se pudo crear cookie temporal.');
        }

        try {
            $this->primeSession($cookieFile, $username, $password);
            $responses = [];
            foreach ($this->planillaUrls($year, $month) as $url) {
                $referer = str_contains($url, '/reportes/')
                    ? self::BASE_URL . '/reportes/planilla'
                    : self::BASE_URL . '/autoconsulta/marcas/';
                $response = $this->curlRequest($url, $cookieFile, [
                    'headers' => [
                        'Accept: application/json, text/javascript, */*; q=0.01',
                        'X-Requested-With: XMLHttpRequest',
                    ],
                    'referer' => $referer,
                ]);
                $response['requested_url'] = $url;
                $responses[] = $response;
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

        $response = end($responses);
        if (!is_array($response)) {
            throw new \RuntimeException('No se recibio respuesta desde EMACH.');
        }

        $body = (string) ($response['body'] ?? '');
        $httpCode = (int) ($response['http_code'] ?? 0);
        $contentType = (string) ($response['content_type'] ?? '');
        $effectiveUrl = (string) ($response['effective_url'] ?? $response['requested_url'] ?? '');
        $curlError = (string) ($response['error'] ?? '');

        if ($curlError !== '') {
            throw new \RuntimeException($curlError);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException('EMACH respondio HTTP ' . $httpCode . ($effectiveUrl !== '' ? ' | URL final: ' . $effectiveUrl : ''));
        }

        $bodyStart = strtolower(substr(trim($body), 0, 200));
        if (str_contains($bodyStart, '<html') || str_contains($bodyStart, '<!doctype') || str_contains(strtolower($contentType), 'text/html')) {
            throw new \RuntimeException(
                'EMACH devolvio HTML en vez de JSON. Probablemente falta completar el login del servidor o existe un token/captcha adicional. HTTP '
                . $httpCode
                . ($effectiveUrl !== '' ? ' | URL final: ' . $effectiveUrl : '')
                . ($contentType !== '' ? ' | Contenido: ' . $contentType : '')
            );
        }

        throw new \RuntimeException('Respuesta JSON invalida desde EMACH. HTTP ' . $httpCode . ($effectiveUrl !== '' ? ' | URL final: ' . $effectiveUrl : ''));
    }

    /**
     * @return array<int,string>
     */
    private function planillaUrls(int $year, int $month): array
    {
        $query = http_build_query([
            'ano' => $year,
            'mes' => $month,
            '_' => (int) round(microtime(true) * 1000),
        ]);

        return [
            self::BASE_URL . '/reportes/getplanilla?' . $query,
            self::BASE_URL . '/autoconsulta/getplanilla?' . $query,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{body:string,http_code:int,content_type:string,effective_url:string,error:string}
     */
    private function curlRequest(string $url, string $cookieFile, array $options = []): array
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
                if (!array_filter($headers, static fn (string $header): bool => str_starts_with(strtolower($header), 'content-type:'))) {
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

    private function primeSession(string $cookieFile, string $username, string $password): void
    {
        $landingUrl = self::BASE_URL . '/autoconsulta/marcas/';
        $landing = $this->curlRequest($landingUrl, $cookieFile, [
            'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
        ]);
        if ($landing['error'] !== '') {
            throw new \RuntimeException('No se pudo conectar con EMACH: ' . $landing['error']);
        }

        $loggedAsWorker = $this->loginTrabajadorFromHtml($landing['body'], $landing['effective_url'] ?: $landingUrl, $cookieFile, $username, $password);
        if (!$loggedAsWorker) {
            $this->loginFromHtml($landing['body'], $landing['effective_url'] ?: $landingUrl, $cookieFile, $username, $password);
        }

        $loginPageUrl = self::BASE_URL . '/site/login';
        $loginPage = $this->curlRequest($loginPageUrl, $cookieFile, [
            'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
            'referer' => $landingUrl,
        ]);
        if ($loginPage['error'] !== '') {
            throw new \RuntimeException('No se pudo conectar con login EMACH: ' . $loginPage['error']);
        }
        if (!$loggedAsWorker) {
            $loggedAsWorker = $this->loginTrabajadorFromHtml($loginPage['body'], $loginPage['effective_url'] ?: $loginPageUrl, $cookieFile, $username, $password);
        }
        if (!$loggedAsWorker) {
            $this->loginFromHtml($loginPage['body'], $loginPage['effective_url'] ?: $loginPageUrl, $cookieFile, $username, $password);
        }

        // Only the first 2 entries are ever used below (array_slice(...,0,2),
        // faithfully ported from emach_client_prime_session()) — the
        // remaining 3 are dead in the original too, kept here unchanged so
        // this stays a literal port rather than a silent cleanup.
        $loginAttempts = [
            [self::BASE_URL . '/cloud/doLoginTrabajador', [
                'csrf_test_name' => '',
                'url' => '/index.php/autoconsulta/marcas/',
                'rut' => $username,
                'pass' => $password,
            ]],
            [self::BASE_URL . '/site/login', [
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
            [self::BASE_URL . '/autoconsulta/login', [
                'usuario' => $username,
                'contrasena' => $password,
                'clave' => $password,
                'username' => $username,
                'password' => $password,
                'rut' => $username,
                'run' => $username,
            ]],
            [self::BASE_URL . '/autoconsulta/marcas/', [
                'usuario' => $username,
                'contrasena' => $password,
                'clave' => $password,
                'username' => $username,
                'password' => $password,
                'rut' => $username,
                'run' => $username,
            ]],
            [self::BASE_URL . '/autoconsulta/marcas', [
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
                $attemptResponse = $this->curlRequest($loginUrl, $cookieFile, [
                    'method' => 'POST',
                    'fields' => $fields,
                    'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
                    'referer' => $landingUrl,
                ]);
                if ($attemptResponse['error'] !== '') {
                    throw new \RuntimeException('No se pudo enviar login EMACH: ' . $attemptResponse['error']);
                }
            }
        }

        $afterLanding = $this->curlRequest($landingUrl, $cookieFile, [
            'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
            'referer' => self::BASE_URL . '/site/login',
        ]);
        if ($afterLanding['error'] !== '') {
            throw new \RuntimeException('No se pudo validar sesion EMACH: ' . $afterLanding['error']);
        }
        if ($this->isLoginPage($afterLanding['body'])) {
            throw new \RuntimeException('Credenciales EMACH incorrectas o no aceptadas. Actualiza tu usuario y contrasena en NOVA; si estan correctas, revisa si EMACH solicita token/captcha adicional.');
        }

        $this->curlRequest(self::BASE_URL . '/reportes/planilla', $cookieFile, [
            'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
            'referer' => $landingUrl,
        ]);
    }

    private function loginTrabajadorFromHtml(string $html, string $pageUrl, string $cookieFile, string $username, string $password): bool
    {
        if ($html === '' || !preg_match('/<form\b([^>]*\bid\s*=\s*(["\'])frmTrabajador\2[^>]*)>(.*?)<\/form>/is', $html, $form)) {
            return false;
        }

        $fields = [];
        preg_match_all('/<input\b([^>]*)>/is', $form[3], $inputs, PREG_SET_ORDER);
        foreach ($inputs as $input) {
            $attrs = $this->parseAttrs($input[1]);
            $name = (string) ($attrs['name'] ?? '');
            if ($name !== '') {
                $fields[$name] = (string) ($attrs['value'] ?? '');
            }
        }

        $fields['rut'] = $username;
        $fields['pass'] = $password;
        $fields['url'] = $fields['url'] ?? '/index.php/autoconsulta/marcas/';

        $formAttrs = $this->parseAttrs($form[1]);
        $action = $this->absoluteUrl((string) ($formAttrs['action'] ?? '/index.php/cloud/doLoginTrabajador'), $pageUrl);
        $response = $this->curlRequest($action, $cookieFile, [
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

    private function loginFromHtml(string $html, string $pageUrl, string $cookieFile, string $username, string $password): bool
    {
        if ($html === '' || stripos($html, '<form') === false) {
            return false;
        }

        preg_match_all('/<form\b([^>]*)>(.*?)<\/form>/is', $html, $forms, PREG_SET_ORDER);
        foreach ($forms as $form) {
            $formAttrs = $this->parseAttrs($form[1]);
            $formBody = $form[2];
            if (stripos($formBody, 'password') === false && stripos($formBody, 'contras') === false) {
                continue;
            }

            $fields = [];
            $passwordName = '';
            $usernameName = '';
            preg_match_all('/<input\b([^>]*)>/is', $formBody, $inputs, PREG_SET_ORDER);
            foreach ($inputs as $input) {
                $attrs = $this->parseAttrs($input[1]);
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

            $action = $this->absoluteUrl((string) ($formAttrs['action'] ?? ''), $pageUrl);
            $response = $this->curlRequest($action, $cookieFile, [
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

    private function isLoginPage(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        return stripos($html, 'frmTrabajador') !== false
            || stripos($html, 'id="frmUsuario"') !== false
            || stripos($html, "id='frmUsuario'") !== false;
    }

    /**
     * @return array<string,string>
     */
    private function parseAttrs(string $tag): array
    {
        $attrs = [];
        preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/s', $tag, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $attrs[strtolower($match[1])] = html_entity_decode($match[3], ENT_QUOTES, 'UTF-8');
        }

        return $attrs;
    }

    private function absoluteUrl(string $url, string $baseUrl): string
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
}
