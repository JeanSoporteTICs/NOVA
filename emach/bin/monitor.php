#!/usr/bin/env php
<?php

declare(strict_types=1);

const EMACH_BASE_URL = 'http://10.6.206.19/index.php';
const EMACH_COLUMNS = [
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

require_once dirname(__DIR__, 2) . '/telegram/lib/telegram.php';
emach_monitor_bootstrap_laravel();

$options = emach_monitor_options($argv);
$defaultStoragePath = dirname(__DIR__, 2) . '/storage/app/emach';
$config = emach_monitor_read_config((string) ($options['config'] ?? $defaultStoragePath . '/monitor_config.json'));
$telegramConfig = telegram_read_config();
$credentials = emach_monitor_user_credentials();
$telegramToken = trim((string) ($telegramConfig['bot_token'] ?? ''));
$telegramChatId = trim((string) ($telegramConfig['chat_id'] ?? ''));
$interval = max(15, (int) ($options['interval'] ?? getenv('EMACH_INTERVAL') ?: ($config['interval'] ?? 60)));
$slowInterval = max(15, (int) ($options['slow-interval'] ?? getenv('EMACH_SLOW_INTERVAL') ?: ($config['slow_interval'] ?? $interval)));
$scheduleSpec = trim((string) ($options['schedule'] ?? getenv('EMACH_SCHEDULE') ?: ($config['schedule'] ?? '')));
$scheduleWindows = emach_monitor_parse_schedule($scheduleSpec);
$year = max(2020, min(2100, (int) ($options['year'] ?? getenv('EMACH_YEAR') ?: date('Y'))));
$month = max(1, min(12, (int) ($options['month'] ?? getenv('EMACH_MONTH') ?: date('n'))));
$stateFile = (string) ($options['state'] ?? $defaultStoragePath . '/monitor_state.json');
$loop = (bool) ($options['loop'] ?? false);
$dryRun = (bool) ($options['dry-run'] ?? false);
$sendExisting = (bool) ($options['send-existing'] ?? false);
$testWebhook = (bool) ($options['test-webhook'] ?? false);

if ($testWebhook) {
    if ($telegramToken === '' && !$dryRun) {
        emach_monitor_fail('Configura el servicio Telegram central en NOVA para probar envio.');
    }
    emach_send_notifications($telegramToken, $telegramChatId, emach_monitor_sample_mark(), $dryRun);
    emach_monitor_log('Notificacion de prueba enviada.');
    exit(0);
}

if ($credentials === []) {
    emach_monitor_fail('No hay usuarios NOVA con credenciales EMACH guardadas.');
}
if ($telegramToken === '' && !$dryRun) {
    emach_monitor_fail('Configura el servicio Telegram central en NOVA. Usa --dry-run para probar sin enviar.');
}
if (!function_exists('curl_init')) {
    emach_monitor_fail('Extension cURL no disponible.');
}

do {
    $activeInterval = emach_monitor_current_interval($scheduleWindows, $slowInterval, $interval);
    $result = emach_monitor_tick($year, $month, $credentials, $telegramToken, $telegramChatId, $stateFile, $dryRun, $sendExisting);
    emach_monitor_log(sprintf(
        'Periodo %04d-%02d | consultadas=%d nuevas=%d enviadas=%d linea_base=%s proxima=%ds',
        $year,
        $month,
        $result['total'],
        $result['new'],
        $result['sent'],
        $result['baseline'] ? 'si' : 'no',
        $activeInterval
    ));

    $sendExisting = false;
    if ($loop) {
        sleep($activeInterval);
    }
} while ($loop);

exit(0);

function emach_monitor_options(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--loop') {
            $options['loop'] = true;
            continue;
        }
        if ($arg === '--once') {
            $options['loop'] = false;
            continue;
        }
        if ($arg === '--dry-run') {
            $options['dry-run'] = true;
            continue;
        }
        if ($arg === '--send-existing') {
            $options['send-existing'] = true;
            continue;
        }
        if ($arg === '--test-webhook') {
            $options['test-webhook'] = true;
            continue;
        }
        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            [$key, $value] = explode('=', substr($arg, 2), 2);
            $options[$key] = $value;
        }
    }
    return $options;
}

function emach_monitor_read_config(string $configFile): array
{
    if (!is_file($configFile)) {
        return [];
    }

    $config = json_decode((string) file_get_contents($configFile), true);
    return is_array($config) ? $config : [];
}

function emach_monitor_bootstrap_laravel(): void
{
    if (function_exists('decrypt') && function_exists('storage_path')) {
        return;
    }

    $basePath = dirname(__DIR__, 2);
    $autoload = $basePath . '/vendor/autoload.php';
    $bootstrap = $basePath . '/bootstrap/app.php';
    if (!is_file($autoload) || !is_file($bootstrap)) {
        return;
    }

    require_once $autoload;
    $app = require $bootstrap;
    if (is_object($app) && method_exists($app, 'make') && interface_exists(\Illuminate\Contracts\Console\Kernel::class)) {
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    }
}

/**
 * @return array<int,array{user:string,password:string}>
 */
function emach_monitor_user_credentials(): array
{
    $credentials = [];
    foreach (emach_monitor_nova_users() as $user) {
        $emach = is_array($user['emach_credentials'] ?? null) ? $user['emach_credentials'] : [];
        $emachUser = trim((string) ($emach['user'] ?? ''));
        $password = emach_monitor_decrypt_secret((string) ($emach['password'] ?? ''));
        if ($emachUser === '' || $password === '') {
            continue;
        }
        $credentials[] = [
            'user' => $emachUser,
            'password' => $password,
        ];
    }

    return $credentials;
}

function emach_monitor_decrypt_secret(string $secret): string
{
    if ($secret === '') {
        return '';
    }

    if (function_exists('decrypt')) {
        try {
            return (string) decrypt($secret);
        } catch (Throwable) {
        }
    }

    return $secret;
}

function emach_monitor_parse_schedule(string $spec): array
{
    if ($spec === '') {
        return [];
    }

    $windows = [];
    foreach (explode(',', $spec) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }
        if (!preg_match('/^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})=(\d+)$/', $chunk, $match)) {
            emach_monitor_fail('Horario invalido: ' . $chunk . '. Usa HH:MM-HH:MM=segundos.');
        }

        $start = emach_monitor_minutes((int) $match[1], (int) $match[2]);
        $end = emach_monitor_minutes((int) $match[3], (int) $match[4]);
        $interval = max(15, (int) $match[5]);
        $windows[] = [
            'start' => $start,
            'end' => $end,
            'interval' => $interval,
            'label' => sprintf('%02d:%02d-%02d:%02d', (int) $match[1], (int) $match[2], (int) $match[3], (int) $match[4]),
        ];
    }

    return $windows;
}

function emach_monitor_minutes(int $hour, int $minute): int
{
    if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
        emach_monitor_fail('Hora invalida en EMACH_SCHEDULE.');
    }

    return ($hour * 60) + $minute;
}

function emach_monitor_current_interval(array $windows, int $slowInterval, int $defaultInterval): int
{
    if (empty($windows)) {
        return $defaultInterval;
    }

    $now = ((int) date('G') * 60) + (int) date('i');
    foreach ($windows as $window) {
        $start = (int) $window['start'];
        $end = (int) $window['end'];
        $isInside = $start <= $end
            ? ($now >= $start && $now <= $end)
            : ($now >= $start || $now <= $end);
        if ($isInside) {
            return (int) $window['interval'];
        }
    }

    return $slowInterval;
}

function emach_monitor_tick(
    int $year,
    int $month,
    array $credentials,
    string $telegramToken,
    string $telegramChatId,
    string $stateFile,
    bool $dryRun,
    bool $sendExisting
): array {
    $marks = [];
    foreach ($credentials as $credential) {
        $rows = emach_fetch_planilla_rows($year, $month, (string) $credential['user'], (string) $credential['password']);
        foreach ($rows as $row) {
            $marks[] = emach_normalize_mark($row);
        }
    }
    $state = emach_monitor_read_state($stateFile);
    $known = array_fill_keys($state['fingerprints'] ?? [], true);
    $firstRun = empty($known);
    $newMarks = [];

    foreach ($marks as $mark) {
        $fingerprint = $mark['fingerprint'];
        if (!isset($known[$fingerprint])) {
            $newMarks[] = $mark;
            $known[$fingerprint] = true;
        }
    }

    $shouldSend = !$firstRun || $sendExisting;
    $sent = 0;
    if ($shouldSend) {
        foreach ($newMarks as $mark) {
            emach_send_notifications($telegramToken, $telegramChatId, $mark, $dryRun);
            $sent++;
        }
    }

    $state = [
        'updated_at' => date(DATE_ATOM),
        'year' => $year,
        'month' => $month,
        'fingerprints' => array_keys($known),
    ];
    emach_monitor_write_state($stateFile, $state);

    return [
        'total' => count($marks),
        'new' => count($newMarks),
        'sent' => $sent,
        'baseline' => $firstRun && !$sendExisting,
    ];
}

function emach_planilla_urls(int $year, int $month): array
{
    $query = http_build_query([
        'ano' => $year,
        'mes' => $month,
        '_' => (int) round(microtime(true) * 1000),
    ]);

    return [
        EMACH_BASE_URL . '/reportes/getplanilla?' . $query,
        EMACH_BASE_URL . '/autoconsulta/getplanilla?' . $query,
    ];
}

function emach_curl_request(string $url, string $cookieFile, array $options = []): array
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

function emach_fetch_planilla_rows(int $year, int $month, string $username, string $password): array
{
    $cookieFile = tempnam(sys_get_temp_dir(), 'emach-monitor-');
    if (!is_string($cookieFile) || $cookieFile === '') {
        throw new RuntimeException('No se pudo crear cookie temporal.');
    }

    try {
        emach_prime_session($cookieFile, $username, $password);
        foreach (emach_planilla_urls($year, $month) as $url) {
            $referer = str_contains($url, '/reportes/')
                ? EMACH_BASE_URL . '/reportes/planilla'
                : EMACH_BASE_URL . '/autoconsulta/marcas/';
            $response = emach_curl_request($url, $cookieFile, [
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

function emach_prime_session(string $cookieFile, string $username, string $password): void
{
    $landingUrl = EMACH_BASE_URL . '/autoconsulta/marcas/';
    $landing = emach_curl_request($landingUrl, $cookieFile, [
        'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
    ]);
    emach_login_trabajador_from_html($landing['body'], $landing['effective_url'] ?: $landingUrl, $cookieFile, $username, $password);
    emach_curl_request(EMACH_BASE_URL . '/reportes/planilla', $cookieFile, [
        'headers' => ['Accept: text/html,application/xhtml+xml,application/json'],
        'referer' => $landingUrl,
    ]);
}

function emach_login_trabajador_from_html(string $html, string $pageUrl, string $cookieFile, string $username, string $password): void
{
    if ($html === '' || !preg_match('/<form\b([^>]*\bid\s*=\s*(["\'])frmTrabajador\2[^>]*)>(.*?)<\/form>/is', $html, $form)) {
        throw new RuntimeException('No se encontro el formulario de trabajador en EMACH.');
    }

    $fields = [];
    preg_match_all('/<input\b([^>]*)>/is', $form[3], $inputs, PREG_SET_ORDER);
    foreach ($inputs as $input) {
        $attrs = emach_parse_attrs($input[1]);
        $name = (string) ($attrs['name'] ?? '');
        if ($name !== '') {
            $fields[$name] = (string) ($attrs['value'] ?? '');
        }
    }

    $fields['rut'] = $username;
    $fields['pass'] = $password;
    $fields['url'] = $fields['url'] ?? '/index.php/autoconsulta/marcas/';

    $formAttrs = emach_parse_attrs($form[1]);
    $action = emach_absolute_url((string) ($formAttrs['action'] ?? '/index.php/cloud/doLoginTrabajador'), $pageUrl);
    $response = emach_curl_request($action, $cookieFile, [
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

function emach_parse_attrs(string $tag): array
{
    $attrs = [];
    preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/s', $tag, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $attrs[strtolower($match[1])] = html_entity_decode($match[3], ENT_QUOTES, 'UTF-8');
    }
    return $attrs;
}

function emach_absolute_url(string $url, string $baseUrl): string
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

function emach_normalize_mark(array $row): array
{
    $row = array_pad(array_values($row), count(EMACH_COLUMNS), '');
    $mark = [];
    foreach (EMACH_COLUMNS as $index => $key) {
        $mark[$key] = $row[$index] === null ? '' : trim((string) $row[$index]);
    }
    $mark['fingerprint'] = hash('sha256', implode('|', [
        $mark['codigo_enrolamiento'],
        $mark['run'],
        $mark['fecha'],
        $mark['marcas'],
        $mark['tipo'],
        $mark['reloj'],
    ]));
    return $mark;
}

function emach_monitor_sample_mark(): array
{
    return [
        'codigo_enrolamiento' => 'TEST-EMACH',
        'run' => '19006667-3',
        'nombre' => 'PRUEBA NOVA EMACH',
        'fecha' => date('d/m/Y'),
        'marcas' => date('H:i:s'),
        'tipo' => 'ENTRADA',
        'reloj' => 'PRUEBA WEBHOOK',
        'longitud' => '',
        'latitud' => '',
        'precision' => '',
        'fingerprint' => hash('sha256', 'test-webhook|' . microtime(true)),
    ];
}

function emach_notification_payload(array $mark): array
{
    return [
        'event' => 'emach.marcacion_detectada',
        'detected_at' => date(DATE_ATOM),
        'source' => 'NOVA EMACH',
        'mark' => $mark,
        'telegram_text' => sprintf(
            'Nueva marcacion EMACH: %s %s - %s %s (%s)',
            $mark['nombre'],
            $mark['run'],
            $mark['tipo'],
            $mark['marcas'],
            $mark['fecha']
        ),
    ];
}

function emach_send_notifications(string $telegramToken, string $telegramChatId, array $mark, bool $dryRun): void
{
    $payload = emach_notification_payload($mark);

    if ($dryRun) {
        emach_monitor_log('DRY RUN notificacion: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return;
    }

    if ($telegramToken !== '') {
        foreach (emach_telegram_chat_ids_for_mark($mark, $telegramChatId) as $chatId) {
            telegram_queue_configured_message((string) $payload['telegram_text'], [
                'chat_id' => $chatId,
                'source' => 'emach-monitor',
            ]);
        }
    }
}

/**
 * @return array<int,string>
 */
function emach_telegram_chat_ids_for_mark(array $mark, string $fallbackChatId): array
{
    $chatIds = [];
    if ($fallbackChatId !== '') {
        $chatIds[] = $fallbackChatId;
    }

    $markRun = emach_normalize_identity((string) ($mark['run'] ?? ''));
    foreach (emach_monitor_nova_users() as $user) {
        $settings = is_array($user['telegram_settings'] ?? null) ? $user['telegram_settings'] : [];
        $chatId = trim((string) ($settings['chat_id'] ?? ''));
        if ($chatId === '') {
            continue;
        }

        $needles = array_filter(array_map('emach_normalize_identity', [
            $user['rut'] ?? '',
            $user['rut_sin_dv'] ?? '',
            $user['username'] ?? '',
        ]));
        if ($markRun !== '' && in_array($markRun, $needles, true)) {
            $chatIds[] = $chatId;
        }
    }

    return array_values(array_unique($chatIds));
}

/**
 * @return array<int,array<string,mixed>>
 */
function emach_monitor_nova_users(): array
{
    $path = dirname(__DIR__, 2) . '/storage/app/nova/users.json';
    $users = json_decode((string) @file_get_contents($path), true);
    return is_array($users) ? array_values(array_filter($users, 'is_array')) : [];
}

function emach_normalize_identity(string $value): string
{
    return strtolower((string) preg_replace('/[^0-9k]/i', '', $value));
}

function emach_monitor_read_state(string $stateFile): array
{
    if (!is_file($stateFile)) {
        return ['fingerprints' => []];
    }
    $state = json_decode((string) file_get_contents($stateFile), true);
    if (!is_array($state)) {
        return ['fingerprints' => []];
    }
    $state['fingerprints'] = array_values(array_filter($state['fingerprints'] ?? [], 'is_string'));
    return $state;
}

function emach_monitor_write_state(string $stateFile, array $state): void
{
    $directory = dirname($stateFile);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear directorio de estado: ' . $directory);
    }
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
}

function emach_monitor_log(string $message): void
{
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL);
}

function emach_monitor_fail(string $message): never
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit(1);
}
