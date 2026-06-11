<?php

namespace App\Support\Nova;

use App\Support\Modules\ModuleRegistry;
use App\Support\RedmineMantencion\RedmineMantencionStorageRepository;

final class NovaHealthRepository
{
    public function __construct(private ModuleRegistry $modules)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function checks(): array
    {
        $checks = [];
        $checks[] = $this->fileCheck('Usuarios NOVA', storage_path('app/nova/users.json'));
        $checks[] = $this->fileCheck('Configuracion NOVA', storage_path('app/nova/settings.json'), false);

        foreach ($this->modules->all() as $key => $module) {
            $path = rtrim((string) ($module['path'] ?? ''), DIRECTORY_SEPARATOR);
            $checks[] = [
                'name' => 'Modulo ' . ($module['name'] ?? $key),
                'status' => is_dir($path) ? 'ok' : 'error',
                'detail' => is_dir($path) ? $path : 'No existe: ' . $path,
            ];
            if ($key === 'redmine-mantencion' && class_exists(RedmineMantencionStorageRepository::class)) {
                try {
                    $users = app(RedmineMantencionStorageRepository::class)->readJson('usuarios.json');
                    $checks[] = [
                        'name' => 'Usuarios ' . ($module['name'] ?? $key),
                        'status' => is_array($users) ? 'ok' : 'error',
                        'detail' => is_array($users) ? 'DB OK' : 'No disponible en DB',
                    ];
                } catch (\Throwable $e) {
                    $checks[] = ['name' => 'Usuarios ' . ($module['name'] ?? $key), 'status' => 'error', 'detail' => $e->getMessage()];
                }
                continue;
            }
            $userFile = $path . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'usuarios.json';
            if (is_file($userFile)) {
                $checks[] = $this->fileCheck('Usuarios ' . ($module['name'] ?? $key), $userFile);
            }
        }

        $checks[] = $this->telegramCheck();
        $checks[] = $this->nextcloudCheck();

        return $checks;
    }

    private function fileCheck(string $name, string $path, bool $required = true): array
    {
        if (!is_file($path)) {
            return ['name' => $name, 'status' => $required ? 'error' : 'warn', 'detail' => 'No existe: ' . $path];
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['name' => $name, 'status' => 'error', 'detail' => 'JSON invalido: ' . json_last_error_msg()];
        }

        return ['name' => $name, 'status' => is_writable($path) ? 'ok' : 'warn', 'detail' => is_writable($path) ? 'OK' : 'Sin permisos de escritura'];
    }

    private function telegramCheck(): array
    {
        $telegramPath = rtrim((string) data_get(config('modules.telegram', []), 'path', base_path('telegram')), DIRECTORY_SEPARATOR);
        $path = $telegramPath . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'telegram.php';
        if (!is_file($path)) {
            return ['name' => 'Telegram', 'status' => 'warn', 'detail' => 'Libreria no encontrada'];
        }
        require_once $path;
        if (!function_exists('telegram_read_config')) {
            return ['name' => 'Telegram', 'status' => 'warn', 'detail' => 'Funciones no disponibles'];
        }
        $config = telegram_read_config();

        return [
            'name' => 'Telegram',
            'status' => trim((string) ($config['bot_token'] ?? '')) !== '' ? 'ok' : 'warn',
            'detail' => trim((string) ($config['bot_token'] ?? '')) !== '' ? 'Bot configurado' : 'Token pendiente',
        ];
    }

    private function nextcloudCheck(): array
    {
        $config = [];
        if (class_exists(RedmineMantencionStorageRepository::class)) {
            try {
                $config = app(RedmineMantencionStorageRepository::class)->readJson('configuracion.json') ?: [];
            } catch (\Throwable) {
                $config = [];
            }
        }
        $url = trim((string) ($config['nextcloud_url'] ?? ''));

        return [
            'name' => 'Nextcloud',
            'status' => $url !== '' ? 'ok' : 'warn',
            'detail' => $url !== '' ? $url : 'URL no configurada',
        ];
    }
}
