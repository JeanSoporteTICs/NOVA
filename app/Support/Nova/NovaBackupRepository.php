<?php

namespace App\Support\Nova;

final class NovaBackupRepository
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function targets(): array
    {
        return [
            ['key' => 'nova_users', 'label' => 'Usuarios NOVA', 'path' => storage_path('app/nova/users.json')],
            ['key' => 'nova_settings', 'label' => 'Configuracion NOVA', 'path' => storage_path('app/nova/settings.json')],
            ['key' => 'nova_access', 'label' => 'Accesos NOVA', 'path' => storage_path('app/nova/access_overrides.json')],
            ['key' => 'redmine_tic_users', 'label' => 'Usuarios Redmine TICS', 'path' => $this->moduleDataPath('redmine_tic', 'usuarios.json')],
            ['key' => 'redmine_mantencion_users', 'label' => 'Usuarios Redmine Mantencion', 'path' => $this->moduleDataPath('redmine-mantencion', 'usuarios.json')],
            ['key' => 'redmine_tic_config', 'label' => 'Configuracion Redmine TICS', 'path' => $this->moduleDataPath('redmine_tic', 'configuracion.json')],
            ['key' => 'redmine_mantencion_config', 'label' => 'Configuracion Redmine Mantencion', 'path' => $this->moduleDataPath('redmine-mantencion', 'configuracion.json')],
        ];
    }

    public function create(string $key = 'all'): int
    {
        $count = 0;
        foreach ($this->targets() as $target) {
            if ($key !== 'all' && $key !== (string) $target['key']) {
                continue;
            }
            $source = (string) $target['path'];
            if (!is_file($source)) {
                continue;
            }
            $directory = storage_path('app/nova/backups/' . date('Y-m-d'));
            if (!is_dir($directory)) {
                mkdir($directory, 0777, true);
            }
            $name = pathinfo($source, PATHINFO_FILENAME) . '.' . date('His') . '.bak.json';
            if (@copy($source, $directory . DIRECTORY_SEPARATOR . $name)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<int,array<string,string|int>>
     */
    public function recent(): array
    {
        $base = storage_path('app/nova/backups');
        $files = is_dir($base) ? glob($base . '/*/*.bak.json') : [];
        $items = [];
        foreach ($files ?: [] as $file) {
            $items[] = [
                'name' => basename($file),
                'date' => basename(dirname($file)),
                'size' => filesize($file) ?: 0,
                'path' => $file,
                'created_at' => date('d-m-Y H:i:s', filemtime($file) ?: time()),
            ];
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) $b['path'], (string) $a['path']));

        return array_slice($items, 0, 30);
    }

    private function moduleDataPath(string $moduleKey, string $file): string
    {
        $base = rtrim((string) data_get(config("modules.{$moduleKey}", []), 'path', base_path($moduleKey)), DIRECTORY_SEPARATOR);

        return $base . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . $file;
    }
}
