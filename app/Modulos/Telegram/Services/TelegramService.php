<?php

namespace App\Modulos\Telegram\Services;

use App\Modulos\Telegram\ExternalClients\TelegramApiClient;
use App\Modulos\Telegram\Services\TelegramLibrary;

final class TelegramService
{
    private bool $loaded = false;

    public function __construct(
        private readonly TelegramApiClient $client = new TelegramApiClient()
    ) {
    }

    public function load(): void
    {
        if (!$this->loaded) {
            TelegramLibrary::load();
            $this->loaded = true;
        }
    }

    private function tryLoad(): bool
    {
        try {
            $this->load();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function readConfig(): array
    {
        $this->load();
        return function_exists('telegram_read_config') ? telegram_read_config() : [];
    }

    public function configPath(): string
    {
        $this->load();
        return function_exists('telegram_config_path') ? telegram_config_path() : '';
    }

    public function storagePath(): string
    {
        $this->load();
        return function_exists('telegram_storage_path') ? telegram_storage_path() : '';
    }

    public function isConfigured(?array $config = null): bool
    {
        $this->load();
        if (!function_exists('telegram_global_is_configured')) {
            return false;
        }
        return $config !== null ? telegram_global_is_configured($config) : telegram_global_is_configured();
    }

    public function saveConfig(array $config): bool
    {
        $this->load();
        return function_exists('telegram_save_config') ? telegram_save_config($config) : false;
    }

    public function deleteWebhook(string $token): void
    {
        $this->load();
        // Transport now goes through TelegramApiClient (see ExternalClients/) —
        // telegram_delete_webhook() in telegram/lib/telegram.php is left as-is
        // for its other caller (telegram/bin/listen.php). Preserves the same
        // throwing contract callers (TelegramController/NovaAdministrationController)
        // already depend on.
        $config = function_exists('telegram_read_config') ? telegram_read_config() : [];
        $result = $this->client->deleteWebhook($token, (string) ($config['proxy_url'] ?? ''));
        if (!$result['ok']) {
            throw new \RuntimeException((string) $result['error']);
        }
    }

    public function listenerStatus(): array
    {
        $this->load();
        return function_exists('telegram_listener_status') ? telegram_listener_status() : [];
    }

    public function sendConfiguredMessage(string $text, array $params = []): void
    {
        $this->load();
        if (function_exists('telegram_send_configured_message')) {
            telegram_send_configured_message($text, $params);
        }
    }

    public function notify(string $message): bool
    {
        if (!$this->tryLoad()) {
            return false;
        }
        if (!function_exists('telegram_read_config')) {
            return false;
        }
        $config = telegram_read_config();
        $token  = trim((string) ($config['bot_token'] ?? ''));
        $chatId = trim((string) ($config['chat_id'] ?? ''));
        if ($token === '' || $chatId === '') {
            return false;
        }
        // Transport now goes through TelegramApiClient — see deleteWebhook() note above.
        $result = $this->client->sendMessage($token, $chatId, $message, [
            'proxy_url' => (string) ($config['proxy_url'] ?? ''),
        ]);

        return $result['ok'];
    }

    public function sendToChat(string $chatId, string $message): bool
    {
        if (!$this->tryLoad()) {
            return false;
        }
        if (!function_exists('telegram_read_config')) {
            return false;
        }
        $config = telegram_read_config();
        $token  = trim((string) ($config['bot_token'] ?? ''));
        $chatId = trim($chatId);
        if ($token === '' || $chatId === '') {
            return false;
        }
        $result = $this->client->sendMessage($token, $chatId, $message, [
            'proxy_url' => (string) ($config['proxy_url'] ?? ''),
        ]);

        return $result['ok'];
    }

    /**
     * @return array{name:string,status:string,detail:string}
     */
    public function healthCheck(): array
    {
        $telegramPath = rtrim((string) data_get(config('modules.telegram', []), 'path', base_path('telegram')), DIRECTORY_SEPARATOR);
        $path = $telegramPath . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'telegram.php';
        if (!is_file($path)) {
            return ['name' => 'Telegram', 'status' => 'warn', 'detail' => 'Libreria no encontrada'];
        }
        $this->load();
        if (!function_exists('telegram_read_config')) {
            return ['name' => 'Telegram', 'status' => 'warn', 'detail' => 'Funciones no disponibles'];
        }
        $config = telegram_read_config();
        $token  = trim((string) ($config['bot_token'] ?? ''));

        return [
            'name'   => 'Telegram',
            'status' => $token !== '' ? 'ok' : 'warn',
            'detail' => $token !== '' ? 'Bot configurado' : 'Token pendiente',
        ];
    }
}
