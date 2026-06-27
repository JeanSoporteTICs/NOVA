<?php

namespace App\Repositories\Nova;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NovaBackupRepository
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function targets(): array
    {
        return [
            ['key' => 'nova_settings', 'label' => 'Configuracion NOVA', 'type' => 'db_table', 'table' => 'nova_settings'],
        ];
    }

    public function create(string $key = 'all'): int
    {
        $count = 0;
        foreach ($this->targets() as $target) {
            if ($key !== 'all' && $key !== (string) $target['key']) {
                continue;
            }
            if (($target['type'] ?? '') === 'db_table') {
                if ($this->backupDbTable((string) $target['table'], (string) $target['key'])) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * @return array<int,array<string,string|int>>
     */
    public function recent(): array
    {
        $base  = storage_path('app/nova/backups');
        $files = is_dir($base) ? glob($base . '/*/*.bak.json') : [];
        $items = [];
        foreach ($files ?: [] as $file) {
            $items[] = [
                'name'       => basename($file),
                'date'       => basename(dirname($file)),
                'size'       => filesize($file) ?: 0,
                'path'       => $file,
                'created_at' => date('d-m-Y H:i:s', filemtime($file) ?: time()),
            ];
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) $b['path'], (string) $a['path']));

        return array_slice($items, 0, 30);
    }

    private function backupDbTable(string $table, string $key): bool
    {
        try {
            if (!Schema::hasTable($table)) {
                return false;
            }
            $rows      = DB::table($table)->get()->map(static fn ($row) => (array) $row)->all();
            $json      = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            $directory = storage_path('app/nova/backups/' . date('Y-m-d'));
            if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
                return false;
            }
            $name    = $key . '.' . date('His') . '.bak.json';
            $written = @file_put_contents($directory . DIRECTORY_SEPARATOR . $name, $json, LOCK_EX);
            return $written !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
