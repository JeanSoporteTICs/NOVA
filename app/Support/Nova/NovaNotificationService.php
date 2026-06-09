<?php

namespace App\Support\Nova;

final class NovaNotificationService
{
    public function notify(string $message): bool
    {
        $settings = json_decode((string) @file_get_contents(storage_path('app/nova/settings.json')), true);
        if (!is_array($settings) || empty($settings['notification_enabled'])) {
            return false;
        }

        $telegramPath = rtrim((string) data_get(config('modules.telegram', []), 'path', base_path('telegram')), DIRECTORY_SEPARATOR);
        $path = $telegramPath . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'telegram.php';
        if (!is_file($path)) {
            return false;
        }

        require_once $path;
        if (!function_exists('telegram_read_config') || !function_exists('telegram_send_message')) {
            return false;
        }

        $config = telegram_read_config();
        $token = trim((string) ($config['bot_token'] ?? ''));
        $chatId = trim((string) ($config['chat_id'] ?? ''));
        if ($token === '' || $chatId === '') {
            return false;
        }

        try {
            telegram_send_message($token, $chatId, '[NOVA] ' . $message);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
