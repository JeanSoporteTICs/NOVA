<?php

namespace App\Modulos\Telegram\ExternalClients;

/**
 * Transport-only client for the Telegram Bot API.
 *
 * Ported from telegram/lib/telegram.php's telegram_send_message() /
 * telegram_get_updates() / telegram_get_webhook_info() / telegram_delete_webhook().
 * Those procedural functions are intentionally left untouched — they're
 * still used directly by telegram/bin/listen.php (the CLI listener daemon)
 * and by telegram_process_outbox()/telegram_send_configured_message(). This
 * class is the path TelegramService uses going forward for the operations
 * it performs itself (sendMessage, deleteWebhook). Migrating listen.php to
 * this client, and retiring the now-duplicated procedural functions, is a
 * separate future step — see .claude/knowledge/external-clients-architecture.md.
 *
 * No NOVA config/session/DB knowledge: callers (Services) resolve the bot
 * token and proxy URL from config and pass them in explicitly.
 *
 * @phpstan-type ApiResult array{ok:bool,data:mixed,error:?string}
 */
final class TelegramApiClient
{
    private const BASE_URL = 'https://api.telegram.org/bot';

    /**
     * @param array{proxy_url?:string} $options
     * @return array{ok:bool,data:mixed,error:?string}
     */
    public function sendMessage(string $botToken, string $chatId, string $text, array $options = []): array
    {
        $fields = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => 'true',
        ];

        $ch = curl_init(self::BASE_URL . $botToken . '/sendMessage');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
        $this->applyProxy($ch, (string) ($options['proxy_url'] ?? ''));
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '' || $httpCode < 200 || $httpCode >= 300) {
            return [
                'ok' => false,
                'data' => null,
                'error' => 'Telegram envio fallo. HTTP ' . $httpCode . ($error !== '' ? ' | ' . $this->friendlyCurlError($error) : ''),
            ];
        }

        $payload = json_decode((string) $body, true);
        if (!is_array($payload) || ($payload['ok'] ?? false) !== true) {
            return ['ok' => false, 'data' => null, 'error' => 'Telegram rechazo el mensaje.'];
        }

        return ['ok' => true, 'data' => $payload['result'] ?? null, 'error' => null];
    }

    /**
     * @return array{ok:bool,data:mixed,error:?string}
     */
    public function getUpdates(string $botToken, int $offset = 0, int $timeout = 25, string $proxyUrl = ''): array
    {
        $url = self::BASE_URL . $botToken . '/getUpdates?' . http_build_query([
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
        $this->applyProxy($ch, $proxyUrl);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '' || $httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'data' => null, 'error' => 'Telegram getUpdates fallo. HTTP ' . $httpCode . $this->errorDetail($body, $error)];
        }

        $payload = json_decode((string) $body, true);
        if (!is_array($payload) || ($payload['ok'] ?? false) !== true || !is_array($payload['result'] ?? null)) {
            return ['ok' => false, 'data' => null, 'error' => 'Telegram getUpdates devolvio respuesta invalida.'];
        }

        return ['ok' => true, 'data' => $payload['result'], 'error' => null];
    }

    /**
     * @return array{ok:bool,data:mixed,error:?string}
     */
    public function getWebhookInfo(string $botToken, string $proxyUrl = ''): array
    {
        $ch = curl_init(self::BASE_URL . $botToken . '/getWebhookInfo');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $this->applyProxy($ch, $proxyUrl);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '' || $httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'data' => null, 'error' => 'Telegram getWebhookInfo fallo. HTTP ' . $httpCode . $this->errorDetail($body, $error)];
        }

        $payload = json_decode((string) $body, true);
        if (!is_array($payload) || ($payload['ok'] ?? false) !== true || !is_array($payload['result'] ?? null)) {
            return ['ok' => false, 'data' => null, 'error' => 'Telegram getWebhookInfo devolvio respuesta invalida.'];
        }

        return ['ok' => true, 'data' => $payload['result'], 'error' => null];
    }

    /**
     * @return array{ok:bool,data:mixed,error:?string}
     */
    public function deleteWebhook(string $botToken, string $proxyUrl = ''): array
    {
        $ch = curl_init(self::BASE_URL . $botToken . '/deleteWebhook');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['drop_pending_updates' => 'false']),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        ]);
        $this->applyProxy($ch, $proxyUrl);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '' || $httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'data' => null, 'error' => 'Telegram deleteWebhook fallo. HTTP ' . $httpCode . $this->errorDetail($body, $error)];
        }

        $payload = json_decode((string) $body, true);
        if (!is_array($payload) || ($payload['ok'] ?? false) !== true) {
            return ['ok' => false, 'data' => null, 'error' => 'Telegram deleteWebhook devolvio respuesta invalida.'];
        }

        return ['ok' => true, 'data' => $payload['result'] ?? null, 'error' => null];
    }

    /**
     * @param resource|\CurlHandle $ch
     */
    private function applyProxy($ch, string $proxyUrl): void
    {
        $proxyUrl = trim($proxyUrl);
        if ($proxyUrl === '') {
            return;
        }

        curl_setopt($ch, CURLOPT_PROXY, $proxyUrl);
    }

    private function friendlyCurlError(string $error): string
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

    private function errorDetail(mixed $body, string $error): string
    {
        if ($error !== '') {
            return ' | ' . $this->friendlyCurlError($error);
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
}
