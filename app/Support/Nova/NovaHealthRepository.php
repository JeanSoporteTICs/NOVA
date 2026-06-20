<?php

namespace App\Support\Nova;

use App\Support\Modules\ModuleRegistry;
use App\Support\Nova\NovaSettingsRepository;
use App\Support\RedmineMantencion\MantencionConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        $checks[] = $this->tableCheck('Usuarios NOVA', 'usuarios_nova');
        $checks[] = $this->tableCheck('Permisos de modulos', 'permisos_usuario_modulo');
        $checks[] = $this->settingsCheck();

        foreach ($this->modules->all() as $key => $module) {
            $path = rtrim((string) ($module['path'] ?? ''), DIRECTORY_SEPARATOR);
            $checks[] = [
                'name' => 'Modulo ' . ($module['name'] ?? $key),
                'status' => is_dir($path) ? 'ok' : 'error',
                'detail' => is_dir($path) ? $path : 'No existe: ' . $path,
            ];
        }

        $checks[] = $this->telegramCheck();
        $checks[] = $this->nextcloudCheck();

        return $checks;
    }

    private function settingsCheck(): array
    {
        try {
            $count = DB::table('nova_settings')->count();
            return ['name' => 'Configuracion NOVA', 'status' => 'ok', 'detail' => 'nova_settings OK (' . $count . ' claves)'];
        } catch (\Throwable) {
            return ['name' => 'Configuracion NOVA', 'status' => 'warn', 'detail' => 'Tabla nova_settings no disponible'];
        }
    }

    private function tableCheck(string $name, string $table): array
    {
        try {
            if (!Schema::hasTable($table)) {
                return ['name' => $name, 'status' => 'error', 'detail' => 'Tabla no existe: ' . $table];
            }

            return ['name' => $name, 'status' => 'ok', 'detail' => 'DB OK (' . DB::table($table)->count() . ' registros)'];
        } catch (\Throwable $e) {
            return ['name' => $name, 'status' => 'error', 'detail' => $e->getMessage()];
        }
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
        $url = '';
        try {
            $repo = app(MantencionConfigRepository::class);
            $config = $repo->loadAll() ?? [];
            $url = trim((string) ($config['nextcloud_url'] ?? ''));
        } catch (\Throwable) {
        }

        return [
            'name' => 'Nextcloud',
            'status' => $url !== '' ? 'ok' : 'warn',
            'detail' => $url !== '' ? $url : 'URL no configurada',
        ];
    }
}
