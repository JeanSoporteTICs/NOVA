#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/telegram.php';
require_once dirname(__DIR__, 2) . '/emach/lib/client.php';
telegram_bootstrap_laravel();

$once = in_array('--once', $argv, true);
$diagnose = in_array('--diagnose', $argv, true);
$deleteWebhook = in_array('--delete-webhook', $argv, true);
$sendQueued = in_array('--send-queued', $argv, true);
$config = telegram_read_config();
$token = trim((string) ($config['bot_token'] ?? ''));
$updatesFile = telegram_storage_path('updates.json');

if ($token === '') {
    fwrite(STDERR, "ERROR: Telegram no esta configurado.\n");
    exit(1);
}

if ($diagnose) {
    telegram_print_diagnostics($token);
    exit(0);
}

if ($deleteWebhook) {
    telegram_delete_webhook($token);
    fwrite(STDOUT, "Webhook eliminado. Ahora puedes usar php telegram/bin/listen.php\n");
    exit(0);
}

if ($sendQueued) {
    $result = telegram_process_outbox();
    fwrite(STDOUT, sprintf(
        "Telegram outbox | enviados=%d fallidos=%d%s\n",
        (int) ($result['sent'] ?? 0),
        (int) ($result['failed'] ?? 0),
        (string) ($result['error'] ?? '') !== '' ? ' error=' . $result['error'] : ''
    ));
    exit((string) ($result['error'] ?? '') === '' ? 0 : 1);
}

$state = is_file($updatesFile) ? json_decode((string) file_get_contents($updatesFile), true) : [];
$state = is_array($state) ? $state : [];
$offset = (int) ($state['offset'] ?? 0);

do {
    $outbox = telegram_process_outbox();
    if (((int) ($outbox['sent'] ?? 0)) > 0 || ((int) ($outbox['failed'] ?? 0)) > 0) {
        fwrite(STDOUT, sprintf(
            '[%s] Outbox procesado: enviados=%d fallidos=%d' . PHP_EOL,
            date('Y-m-d H:i:s'),
            (int) ($outbox['sent'] ?? 0),
            (int) ($outbox['failed'] ?? 0)
        ));
    }

    try {
        $updates = telegram_get_updates($token, $offset, $once ? 2 : 25);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, "TIP: ejecuta php telegram/bin/listen.php --diagnose para revisar webhook y cola pendiente." . PHP_EOL);
        exit(1);
    }
    foreach ($updates as $update) {
        $offset = max($offset, ((int) ($update['update_id'] ?? 0)) + 1);
        $message = is_array($update['message'] ?? null) ? $update['message'] : [];
        $incomingChatId = (string) ($message['chat']['id'] ?? '');
        $user = telegram_user_by_chat_id($incomingChatId);
        if ($user === []) {
            continue;
        }
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        telegram_send_message($token, $incomingChatId, telegram_command_reply($text, $user));
        fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] Comando atendido: ' . $text . PHP_EOL);
    }
    telegram_write_state($updatesFile, ['updated_at' => date(DATE_ATOM), 'offset' => $offset]);
    if (!$once && empty($updates)) {
        usleep(250000);
    }
} while (!$once);

function telegram_print_diagnostics(string $token): void
{
    $info = telegram_get_webhook_info($token);
    $url = trim((string) ($info['url'] ?? ''));
    $pending = (int) ($info['pending_update_count'] ?? 0);
    $lastError = trim((string) ($info['last_error_message'] ?? ''));

    fwrite(STDOUT, "Telegram diagnostico\n");
    fwrite(STDOUT, "Webhook: " . ($url !== '' ? 'activo' : 'inactivo') . PHP_EOL);
    if ($url !== '') {
        fwrite(STDOUT, "Webhook URL: " . $url . PHP_EOL);
    }
    fwrite(STDOUT, "Mensajes pendientes: " . $pending . PHP_EOL);
    if ($lastError !== '') {
        fwrite(STDOUT, "Ultimo error webhook: " . $lastError . PHP_EOL);
    }
}

function telegram_command_reply(string $text, array $user): string
{
    $command = strtolower(trim(strtok($text, ' ') ?: $text));
    if (str_contains($command, '@')) {
        $command = strstr($command, '@', true) ?: $command;
    }

    $settings = telegram_command_settings();
    $commandKey = telegram_command_key($command);
    if ($commandKey !== '' && !$settings->commandEnabled($commandKey)) {
        return $settings->message('disabled');
    }

    return match ($command) {
        '/start', '/ayuda', '/help' => telegram_help_reply(),
        '/estado', '/status' => $settings->render('status', ['fecha' => date('d/m/Y H:i:s')]),
        '/emach' => telegram_emach_last_mark_reply($user),
        '/tic', '/reporte' => telegram_redmine_tic_report_reply(telegram_command_arguments($text), $user),
        '/test' => $settings->render('test', ['fecha' => date('d/m/Y H:i:s')]),
        default => $settings->message('unknown'),
    };
}

function telegram_command_settings(): \App\Support\Integrations\TelegramCommandSettingsRepository
{
    static $settings = null;
    if ($settings instanceof \App\Support\Integrations\TelegramCommandSettingsRepository) {
        return $settings;
    }

    return $settings = new \App\Support\Integrations\TelegramCommandSettingsRepository();
}

function telegram_help_reply(): string
{
    return (new \App\Support\Integrations\TelegramCommandCatalog(telegram_command_settings()))->helpText();
}

function telegram_command_key(string $command): string
{
    return match ($command) {
        '/start', '/ayuda', '/help' => 'help',
        '/estado', '/status' => 'status',
        '/emach' => 'emach',
        '/tic', '/reporte' => 'tic',
        '/test' => 'test',
        default => '',
    };
}

function telegram_command_arguments(string $text): string
{
    $parts = preg_split('/\s+/', trim($text), 2);
    return trim((string) ($parts[1] ?? ''));
}

function telegram_redmine_tic_report_reply(string $text, array $user): string
{
    if (!class_exists(\RedmineTic\Support\Redmine\RedmineDataRepository::class)) {
        return telegram_command_settings()->message('tic_unavailable');
    }

    try {
        $redmine = new \RedmineTic\Support\Redmine\RedmineDataRepository();
        $result = $redmine->forProject('redmine_tic')->createTelegramReport($text, $user);
    } catch (Throwable $e) {
        return telegram_command_settings()->render('tic_error', ['error' => $e->getMessage()]);
    }

    if (!($result['ok'] ?? false)) {
        return (string) ($result['error'] ?? 'No pude crear el reporte TIC.');
    }

    $report = is_array($result['report'] ?? null) ? $result['report'] : [];
    return telegram_command_settings()->render('tic_success', [
        'asunto' => (string) ($report['asunto'] ?? '-'),
        'categoria' => (string) ($report['categoria'] ?? '-'),
        'unidad' => (string) ($report['unidad_solicitante'] ?? '-'),
    ]);
}

function telegram_bootstrap_laravel(): void
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

function telegram_storage_users_path(): string
{
    return function_exists('storage_path')
        ? storage_path('app/nova/users.json')
        : dirname(__DIR__, 2) . '/storage/app/nova/users.json';
}

function telegram_user_by_chat_id(string $chatId): array
{
    $users = json_decode((string) @file_get_contents(telegram_storage_users_path()), true);
    if (is_array($users)) {
        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }
            $settings = is_array($user['telegram_settings'] ?? null) ? $user['telegram_settings'] : [];
            if ((string) ($settings['chat_id'] ?? '') === $chatId) {
                return $user;
            }
        }
    }

    return telegram_redmine_tic_user_by_chat_id($chatId);
}

function telegram_redmine_tic_users_path(): string
{
    return dirname(__DIR__, 2) . '/redmine_tic/data/usuarios.json';
}

function telegram_redmine_tic_user_by_chat_id(string $chatId): array
{
    $users = json_decode((string) @file_get_contents(telegram_redmine_tic_users_path()), true);
    if (!is_array($users)) {
        return [];
    }

    foreach ($users as $user) {
        if (!is_array($user)) {
            continue;
        }
        $candidate = (string) ($user['telegram_chat_id'] ?? ($user['telegram_settings']['chat_id'] ?? ''));
        if ($candidate !== $chatId) {
            continue;
        }

        return [
            'id' => (string) ($user['id'] ?? ''),
            'name' => (string) ($user['nombre'] ?? ''),
            'apellido' => (string) ($user['apellido'] ?? ''),
            'username' => (string) ($user['rut'] ?? $user['id'] ?? ''),
            'telegram_settings' => ['chat_id' => $chatId],
            'redmine_tic_user' => $user,
        ];
    }

    return [];
}

function telegram_emach_last_mark_reply(array $user): string
{
    $credentials = telegram_emach_credentials($user);
    if ($credentials['user'] === '' || $credentials['password'] === '') {
        return telegram_command_settings()->message('emach_missing_credentials');
    }

    try {
        $rows = emach_client_fetch_planilla_rows((int) date('Y'), (int) date('n'), $credentials['user'], $credentials['password']);
    } catch (Throwable $e) {
        return telegram_command_settings()->render('emach_error', ['error' => $e->getMessage()]);
    }

    if ($rows === []) {
        return telegram_command_settings()->message('emach_empty');
    }

    $marks = array_map('emach_client_normalize_mark', $rows);
    usort($marks, static function (array $left, array $right): int {
        return strcmp(telegram_emach_mark_sort_key($right), telegram_emach_mark_sort_key($left));
    });

    $mark = $marks[0];
    return telegram_command_settings()->render('emach_success', [
        'fecha' => $mark['fecha'] ?: '-',
        'hora' => $mark['marcas'] ?: '-',
        'tipo' => $mark['tipo'] ?: '-',
        'reloj' => $mark['reloj'] ?: '-',
    ]);
}

function telegram_emach_credentials(array $user): array
{
    $credentials = is_array($user['emach_credentials'] ?? null) ? $user['emach_credentials'] : [];
    return [
        'user' => trim((string) ($credentials['user'] ?? '')),
        'password' => telegram_decrypt_secret((string) ($credentials['password'] ?? '')),
    ];
}

function telegram_decrypt_secret(string $secret): string
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

function telegram_emach_mark_sort_key(array $mark): string
{
    $date = DateTime::createFromFormat('d/m/Y H:i:s', trim((string) ($mark['fecha'] ?? '')) . ' ' . trim((string) ($mark['marcas'] ?? '')));
    if ($date instanceof DateTime) {
        return $date->format('YmdHis');
    }
    return (string) ($mark['fecha'] ?? '') . ' ' . (string) ($mark['marcas'] ?? '');
}

function telegram_write_state(string $path, array $state): void
{
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }
    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
    @chmod($path, 0666);
}
