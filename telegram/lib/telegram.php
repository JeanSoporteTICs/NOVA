<?php

declare(strict_types=1);

function telegram_storage_path(?string $file = null): string
{
    $base = function_exists('storage_path')
        ? storage_path('app/telegram')
        : dirname(__DIR__, 2) . '/storage/app/telegram';

    return $file === null ? $base : $base . '/' . ltrim($file, '/');
}

function telegram_config_path(): string
{
    return telegram_storage_path('config.json');
}

function telegram_read_config(?string $path = null): array
{
    $path = $path ?: telegram_config_path();
    $config = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
    $config = is_array($config) ? $config : [];

    $envToken = trim((string) getenv('TELEGRAM_BOT_TOKEN'));
    $envChatId = trim((string) getenv('TELEGRAM_CHAT_ID'));
    if ($envToken !== '') {
        $config['bot_token'] = $envToken;
    }
    if ($envChatId !== '') {
        $config['chat_id'] = $envChatId;
    }
    $envProxy = trim((string) getenv('TELEGRAM_PROXY_URL'));
    if ($envProxy !== '') {
        $config['proxy_url'] = $envProxy;
    }

    return $config;
}

function telegram_save_config(array $config, ?string $path = null): bool
{
    $path = $path ?: telegram_config_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $payload = [
        'bot_token' => (string) ($config['bot_token'] ?? ''),
        'chat_id' => (string) ($config['chat_id'] ?? ''),
        'proxy_url' => (string) ($config['proxy_url'] ?? ''),
        'default_parse_mode' => '',
        'updated_at' => date(DATE_ATOM),
    ];

    $written = @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    if ($written === false) {
        return false;
    }

    @chmod($path, 0666);
    return true;
}

function telegram_is_configured(?array $config = null): bool
{
    $config = $config ?? telegram_read_config();
    return trim((string) ($config['bot_token'] ?? '')) !== '' && trim((string) ($config['chat_id'] ?? '')) !== '';
}

function telegram_global_is_configured(?array $config = null): bool
{
    $config = $config ?? telegram_read_config();
    return trim((string) ($config['bot_token'] ?? '')) !== '';
}

function telegram_send_configured_message(string $text, array $options = []): void
{
    $config = telegram_read_config();
    $token = trim((string) ($config['bot_token'] ?? ''));
    $chatId = trim((string) ($options['chat_id'] ?? $config['chat_id'] ?? ''));
    if ($token === '' || $chatId === '') {
        throw new RuntimeException('Telegram no esta configurado.');
    }

    telegram_send_message($token, $chatId, $text, [
        'parse_mode' => '',
    ]);
}

function telegram_queue_configured_message(string $text, array $options = []): string
{
    $config = telegram_read_config();
    $chatId = trim((string) ($options['chat_id'] ?? $config['chat_id'] ?? ''));
    if ($chatId === '') {
        throw new RuntimeException('Telegram no tiene Chat ID destino.');
    }

    return telegram_queue_message([
        'chat_id' => $chatId,
        'text' => $text,
        'source' => (string) ($options['source'] ?? 'nova'),
        'created_at' => date(DATE_ATOM),
    ]);
}

function telegram_outbox_path(?string $file = null): string
{
    $base = telegram_storage_path('outbox');

    return $file === null ? $base : $base . '/' . ltrim($file, '/');
}

function telegram_sent_path(?string $file = null): string
{
    $base = telegram_storage_path('sent');

    return $file === null ? $base : $base . '/' . ltrim($file, '/');
}

function telegram_failed_path(?string $file = null): string
{
    $base = telegram_storage_path('failed');

    return $file === null ? $base : $base . '/' . ltrim($file, '/');
}

function telegram_queue_message(array $message): string
{
    $chatId = trim((string) ($message['chat_id'] ?? ''));
    $text = trim((string) ($message['text'] ?? ''));
    if ($chatId === '' || $text === '') {
        throw new RuntimeException('El mensaje Telegram requiere chat_id y text.');
    }

    $directory = telegram_outbox_path();
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear outbox Telegram.');
    }

    $id = date('YmdHis') . '-' . bin2hex(random_bytes(6));
    $payload = [
        'id' => $id,
        'chat_id' => $chatId,
        'text' => $text,
        'source' => (string) ($message['source'] ?? 'nova'),
        'created_at' => (string) ($message['created_at'] ?? date(DATE_ATOM)),
        'attempts' => 0,
    ];
    $path = telegram_outbox_path($id . '.json');
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    @chmod($path, 0666);

    return $id;
}

function telegram_process_outbox(int $limit = 20): array
{
    $config = telegram_read_config();
    $token = trim((string) ($config['bot_token'] ?? ''));
    if ($token === '') {
        return ['sent' => 0, 'failed' => 0, 'error' => 'Telegram no esta configurado.'];
    }

    $files = glob(telegram_outbox_path('*.json')) ?: [];
    sort($files, SORT_NATURAL);
    $sent = 0;
    $failed = 0;

    foreach (array_slice($files, 0, max(1, $limit)) as $file) {
        $payload = json_decode((string) @file_get_contents($file), true);
        if (!is_array($payload)) {
            telegram_move_queue_file($file, telegram_failed_path(basename($file)));
            $failed++;
            continue;
        }

        try {
            telegram_send_message($token, (string) ($payload['chat_id'] ?? ''), (string) ($payload['text'] ?? ''));
            $payload['sent_at'] = date(DATE_ATOM);
            telegram_move_queue_payload($file, telegram_sent_path(basename($file)), $payload);
            $sent++;
        } catch (Throwable $e) {
            $payload['attempts'] = ((int) ($payload['attempts'] ?? 0)) + 1;
            $payload['last_error'] = $e->getMessage();
            $payload['last_attempt_at'] = date(DATE_ATOM);
            if ((int) $payload['attempts'] >= 3) {
                telegram_move_queue_payload($file, telegram_failed_path(basename($file)), $payload);
                $failed++;
            } else {
                file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
            }
        }
    }

    return ['sent' => $sent, 'failed' => $failed, 'error' => ''];
}

function telegram_move_queue_file(string $from, string $to): void
{
    $directory = dirname($to);
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
    @rename($from, $to);
    @chmod($to, 0666);
}

function telegram_move_queue_payload(string $from, string $to, array $payload): void
{
    $directory = dirname($to);
    if (!is_dir($directory)) {
        @mkdir($directory, 0775, true);
    }
    file_put_contents($to, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    @chmod($to, 0666);
    @unlink($from);
}

function telegram_send_message(string $botToken, string $chatId, string $text, array $options = []): void
{
    $fields = [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => 'true',
    ];
    $ch = curl_init('https://api.telegram.org/bot' . $botToken . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
    ]);
    telegram_apply_proxy($ch, (string) ($options['proxy_url'] ?? telegram_read_config()['proxy_url'] ?? ''));
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = (string) curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '' || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Telegram envio fallo. HTTP ' . $httpCode . ($error !== '' ? ' | ' . telegram_friendly_curl_error($error) : ''));
    }

    $payload = json_decode((string) $body, true);
    if (!is_array($payload) || ($payload['ok'] ?? false) !== true) {
        throw new RuntimeException('Telegram rechazo el mensaje.');
    }
}

function telegram_get_updates(string $botToken, int $offset = 0, int $timeout = 25): array
{
    $url = 'https://api.telegram.org/bot' . $botToken . '/getUpdates?' . http_build_query([
        'offset' => $offset,
        'timeout' => $timeout,
        'allowed_updates' => json_encode(['message']),
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => $timeout + 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    telegram_apply_proxy($ch, (string) (telegram_read_config()['proxy_url'] ?? ''));
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = (string) curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '' || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Telegram getUpdates fallo. HTTP ' . $httpCode . telegram_error_detail($body, $error));
    }

    $payload = json_decode((string) $body, true);
    if (!is_array($payload) || ($payload['ok'] ?? false) !== true || !is_array($payload['result'] ?? null)) {
        throw new RuntimeException('Telegram getUpdates devolvio respuesta invalida.');
    }

    return $payload['result'];
}

function telegram_get_webhook_info(string $botToken): array
{
    $ch = curl_init('https://api.telegram.org/bot' . $botToken . '/getWebhookInfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    telegram_apply_proxy($ch, (string) (telegram_read_config()['proxy_url'] ?? ''));
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = (string) curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '' || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Telegram getWebhookInfo fallo. HTTP ' . $httpCode . telegram_error_detail($body, $error));
    }

    $payload = json_decode((string) $body, true);
    if (!is_array($payload) || ($payload['ok'] ?? false) !== true || !is_array($payload['result'] ?? null)) {
        throw new RuntimeException('Telegram getWebhookInfo devolvio respuesta invalida.');
    }

    return $payload['result'];
}

function telegram_delete_webhook(string $botToken): void
{
    $ch = curl_init('https://api.telegram.org/bot' . $botToken . '/deleteWebhook');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['drop_pending_updates' => 'false']),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
    ]);
    telegram_apply_proxy($ch, (string) (telegram_read_config()['proxy_url'] ?? ''));
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = (string) curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '' || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('Telegram deleteWebhook fallo. HTTP ' . $httpCode . telegram_error_detail($body, $error));
    }

    $payload = json_decode((string) $body, true);
    if (!is_array($payload) || ($payload['ok'] ?? false) !== true) {
        throw new RuntimeException('Telegram deleteWebhook devolvio respuesta invalida.');
    }
}

function telegram_listener_pid_path(): string
{
    return telegram_storage_path('listener.pid');
}

function telegram_listener_log_path(): string
{
    return telegram_storage_path('listener.log');
}

function telegram_listener_status(?string $botToken = null): array
{
    $pid = telegram_listener_pid();
    $running = $pid > 0 && telegram_process_is_running($pid);
    if (!$running && $pid > 0) {
        @file_put_contents(telegram_listener_pid_path(), '');
        $pid = 0;
    }
    $webhook = ['available' => false, 'active' => false, 'pending' => null, 'url' => '', 'error' => ''];
    $token = trim((string) ($botToken ?? telegram_read_config()['bot_token'] ?? ''));

    if ($token !== '') {
        try {
            $info = telegram_get_webhook_info($token);
            $webhook = [
                'available' => true,
                'active' => trim((string) ($info['url'] ?? '')) !== '',
                'pending' => (int) ($info['pending_update_count'] ?? 0),
                'url' => trim((string) ($info['url'] ?? '')),
                'error' => trim((string) ($info['last_error_message'] ?? '')),
            ];
        } catch (Throwable $e) {
            $webhook['error'] = $e->getMessage();
        }
    }

    return [
        'running' => $running,
        'pid' => $pid,
        'pid_path' => telegram_listener_pid_path(),
        'log_path' => telegram_listener_log_path(),
        'php_binary' => telegram_listener_php_binary(),
        'queue' => telegram_queue_status(),
        'log_tail' => telegram_listener_log_tail(),
        'webhook' => $webhook,
    ];
}

function telegram_queue_status(): array
{
    return [
        'outbox' => count(glob(telegram_outbox_path('*.json')) ?: []),
        'sent' => count(glob(telegram_sent_path('*.json')) ?: []),
        'failed' => count(glob(telegram_failed_path('*.json')) ?: []),
        'outbox_path' => telegram_outbox_path(),
    ];
}

function telegram_listener_start(): array
{
    $currentPid = telegram_listener_pid();
    if ($currentPid > 0 && telegram_process_is_running($currentPid)) {
        return telegram_listener_status();
    }

    $directory = telegram_storage_path();
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear storage Telegram.');
    }

    $basePath = dirname(__DIR__, 2);
    $php = telegram_listener_php_binary();
    @file_put_contents(
        telegram_listener_log_path(),
        '[' . date('Y-m-d H:i:s') . '] Iniciando listener con ' . $php . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    $process = proc_open(
        [$php, 'telegram/bin/service.php'],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', telegram_listener_log_path(), 'a'],
            2 => ['file', telegram_listener_log_path(), 'a'],
        ],
        $pipes,
        $basePath,
        telegram_listener_environment(),
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        throw new RuntimeException('No se pudo iniciar el listener Telegram.');
    }

    $status = proc_get_status($process);
    $pid = (string) ((int) ($status['pid'] ?? 0));
    if ($pid === '0') {
        @proc_terminate($process);
        throw new RuntimeException('No se pudo obtener el PID del listener Telegram.');
    }

    file_put_contents(telegram_listener_pid_path(), $pid . PHP_EOL, LOCK_EX);
    @chmod(telegram_listener_pid_path(), 0666);
    @chmod(telegram_listener_log_path(), 0666);
    usleep(500000);

    if (!telegram_process_is_running((int) $pid)) {
        @file_put_contents(telegram_listener_pid_path(), '');
        throw new RuntimeException('El listener Telegram intento iniciar pero se detuvo. Revisa el log: ' . telegram_listener_log_path());
    }

    return telegram_listener_status();
}

function telegram_listener_stop(): array
{
    $pid = telegram_listener_pid();
    if ($pid <= 0 || !telegram_process_is_running($pid)) {
        @file_put_contents(telegram_listener_pid_path(), '');
        return telegram_listener_status();
    }

    if (function_exists('posix_kill')) {
        @posix_kill($pid, SIGTERM);
    } else {
        @exec('kill ' . (int) $pid);
    }

    usleep(350000);
    if (telegram_process_is_running($pid)) {
        if (function_exists('posix_kill')) {
            @posix_kill($pid, SIGKILL);
        } else {
            @exec('kill -9 ' . (int) $pid);
        }
    }

    @file_put_contents(telegram_listener_pid_path(), '');
    return telegram_listener_status();
}

function telegram_listener_clear_log(): void
{
    $path = telegram_listener_log_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('No se pudo crear storage Telegram.');
    }

    file_put_contents($path, '[' . date('Y-m-d H:i:s') . '] Log Telegram limpiado' . PHP_EOL, LOCK_EX);
    @chmod($path, 0666);
}

function telegram_listener_pid(): int
{
    $path = telegram_listener_pid_path();
    if (!is_file($path)) {
        return 0;
    }

    return (int) trim((string) @file_get_contents($path));
}

function telegram_process_is_running(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    $cmdlinePath = '/proc/' . $pid . '/cmdline';
    if (is_file($cmdlinePath)) {
        $cmdline = str_replace("\0", ' ', (string) @file_get_contents($cmdlinePath));
        return str_contains($cmdline, 'telegram/bin/service.php') || str_contains($cmdline, 'telegram/bin/listen.php');
    }
    if (function_exists('posix_kill') && @posix_kill($pid, 0)) {
        return true;
    }

    return false;
}

function telegram_listener_php_binary(): string
{
    foreach (['/usr/bin/php', '/bin/php', PHP_BINARY ?: 'php'] as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate !== '' && is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return 'php';
}

function telegram_listener_environment(): array
{
    $environment = [];
    foreach ($_SERVER as $key => $value) {
        if (!is_string($key) || !is_scalar($value)) {
            continue;
        }
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) {
            continue;
        }
        $environment[$key] = (string) $value;
    }

    unset(
        $environment['LD_LIBRARY_PATH'],
        $environment['LD_PRELOAD'],
        $environment['DYLD_LIBRARY_PATH'],
        $environment['DYLD_INSERT_LIBRARIES']
    );

    $environment['PATH'] = '/usr/local/sbin:/usr/local/bin:/usr/bin:/bin';
    $environment['PWD'] = dirname(__DIR__, 2);
    $environment['HOME'] = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp');

    return $environment;
}

function telegram_listener_log_tail(int $lines = 10): string
{
    $path = telegram_listener_log_path();
    if (!is_file($path)) {
        return '';
    }

    $content = (string) @file_get_contents($path);
    if ($content === '') {
        return '';
    }

    $rows = preg_split('/\R/', trim($content)) ?: [];
    return implode(PHP_EOL, array_slice($rows, -$lines));
}

function telegram_apply_proxy($curlHandle, string $proxyUrl): void
{
    $proxyUrl = trim($proxyUrl);
    if ($proxyUrl === '') {
        return;
    }

    curl_setopt($curlHandle, CURLOPT_PROXY, $proxyUrl);
}

function telegram_friendly_curl_error(string $error): string
{
    $lower = strtolower($error);
    if (str_contains($lower, 'timed out') || str_contains($lower, 'could not connect')) {
        return $error . '. Revisa salida a internet o proxy hacia api.telegram.org:443.';
    }
    if (str_contains($lower, 'could not resolve')) {
        return $error . '. Revisa DNS o proxy.';
    }
    return $error;
}

function telegram_error_detail(mixed $body, string $error): string
{
    if ($error !== '') {
        return ' | ' . telegram_friendly_curl_error($error);
    }

    $payload = json_decode((string) $body, true);
    $description = is_array($payload) ? trim((string) ($payload['description'] ?? '')) : '';
    if ($description === '') {
        return '';
    }

    if (str_contains($description, 'terminated by other getUpdates request')) {
        return ' | Hay otro listener usando este bot. Deten el otro proceso antes de iniciar este.';
    }
    if (str_contains($description, 'webhook is active')) {
        return ' | Hay un webhook activo. Elimina el webhook antes de usar el listener por consola.';
    }

    return ' | ' . $description;
}
