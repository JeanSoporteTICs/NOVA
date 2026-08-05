<?php

namespace App\Models;

class SystemHealthModel
{
    public function status(): array
    {
        $status = [
            'ok' => true,
            'checks' => [],
            'warnings' => [],
            'meta' => [
                'checked_at' => (new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago')))->format('c'),
                'php_version' => PHP_VERSION,
            ],
        ];

        $dirs = [
            APP_BASE_PATH . '/data/logs',
        ];

        foreach ($dirs as $dir) {
            $name = basename($dir);
            if (!is_dir($dir)) {
                $status['ok'] = false;
                $status['checks'][$name] = 'missing_dir';
            } elseif (!is_writable($dir)) {
                $status['ok'] = false;
                $status['checks'][$name] = 'not_writable';
            } else {
                $status['checks'][$name] = 'ok';
            }
        }

        $config = require APP_BASE_PATH . '/config/app.php';
        $status['meta']['app_env'] = $config['env'] ?? 'production';
        $extensions = $config['required_extensions'] ?? ['json', 'curl', 'mbstring', 'openssl', 'zip', 'xml'];
        foreach ($extensions as $extension) {
            $key = 'ext_' . $extension;
            if (extension_loaded($extension)) {
                $status['checks'][$key] = 'ok';
            } else {
                $status['checks'][$key] = 'missing';
                $status['ok'] = false;
            }
        }

        $backupRoot = APP_BASE_PATH . '/data/backups';
        if (is_dir($backupRoot) || is_writable(dirname($backupRoot))) {
            $status['checks']['backups'] = 'ok';
        } else {
            $status['checks']['backups'] = 'not_writable';
            $status['ok'] = false;
        }
        if (!empty($config['debug'])) {
            $status['warnings']['app_debug'] = 'APP_DEBUG esta activo; debe quedar apagado en produccion.';
            if (($config['env'] ?? 'production') === 'production') {
                $status['ok'] = false;
            }
        }

        $repo = function_exists('\config_mantencion_repository') ? \config_mantencion_repository() : null;
        $cfg = $repo !== null ? $repo->loadAll() : [];
        if (is_array($cfg)) {
            foreach (['platform_url', 'project_id', 'tracker_id', 'priority_id', 'status_id'] as $key) {
                if (($cfg[$key] ?? '') === '' || $cfg[$key] === null) {
                    $status['checks']['config_' . $key] = 'missing';
                    $status['ok'] = false;
                } else {
                    $status['checks']['config_' . $key] = 'ok';
                }
            }
            unset($status['warnings']['platform_token']);
        }

        $logFile = ini_get('error_log');
        if ($logFile) {
            if (is_writable($logFile) || (!file_exists($logFile) && is_writable(dirname($logFile)))) {
                $status['checks']['error_log'] = 'ok';
            } else {
                $status['checks']['error_log'] = 'not_writable';
                $status['ok'] = false;
            }
        }

        return $status;
    }
}
