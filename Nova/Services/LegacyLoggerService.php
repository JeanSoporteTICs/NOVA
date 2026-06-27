<?php

namespace App\Modulos\Nova\Services;

use Illuminate\Support\Facades\Log;

final class LegacyLoggerService
{
    private string $loggerPath;

    public function __construct()
    {
        $modulePath = rtrim((string) data_get(config('modules.redmine-mantencion', []), 'path', base_path('redmine-mantencion')), DIRECTORY_SEPARATOR);
        $this->loggerPath = $modulePath . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'logger.php';
    }

    public function log(string $event, string $message): void
    {
        if (!is_file($this->loggerPath)) {
            Log::info('[NOVA security] ' . $event . ': ' . $message);
            return;
        }

        require_once $this->loggerPath;
        if (function_exists('log_security_event')) {
            log_security_event($event, $message);
        }
    }
}
