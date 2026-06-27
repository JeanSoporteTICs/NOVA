<?php

namespace App\Modulos\Telegram\Services;

final class TelegramLibrary
{
    public static function load(): void
    {
        $telegramPath = rtrim((string) data_get(config('modules.telegram', []), 'path', base_path('telegram')), DIRECTORY_SEPARATOR);
        require_once $telegramPath . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'telegram.php';
    }
}
