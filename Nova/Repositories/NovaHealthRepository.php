<?php

namespace App\Modulos\Nova\Repositories;

use App\Modulos\Nova\Repositories\ModuleRegistry;
use App\Modulos\Telegram\Services\TelegramService;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NovaHealthRepository
{
    public function __construct(
        private ModuleRegistry $modules,
        private TelegramService $telegram,
    ) {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function checks(): array
    {
        $checks   = [];
        $checks[] = $this->tableCheck('Usuarios NOVA', 'usuarios_nova');
        $checks[] = $this->tableCheck('Permisos de modulos', 'permisos_usuario_modulo');
        $checks[] = $this->settingsCheck();

        foreach ($this->modules->all() as $key => $module) {
            $path     = rtrim((string) ($module['path'] ?? ''), DIRECTORY_SEPARATOR);
            $checks[] = [
                'name'   => 'Modulo ' . ($module['name'] ?? $key),
                'status' => is_dir($path) ? 'ok' : 'error',
                'detail' => is_dir($path) ? $path : 'No existe: ' . $path,
            ];
        }

        $checks[] = $this->telegram->healthCheck();
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

    private function nextcloudCheck(): array
    {
        $url = '';
        try {
            $repo   = app(MantencionConfigRepository::class);
            $config = $repo->loadAll() ?? [];
            $url    = trim((string) ($config['nextcloud_url'] ?? ''));
        } catch (\Throwable) {
        }

        return [
            'name'   => 'Nextcloud',
            'status' => $url !== '' ? 'ok' : 'warn',
            'detail' => $url !== '' ? $url : 'URL no configurada',
        ];
    }
}
