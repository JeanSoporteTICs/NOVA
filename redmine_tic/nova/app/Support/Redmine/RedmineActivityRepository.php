<?php

namespace RedmineTic\Support\Redmine;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manages activity log entries for a Redmine TIC module.
 * Table: redmine_tic_activity_logs
 */
class RedmineActivityRepository
{
    public function __construct(
        private string $projectKey,
        private string $projectName,
    ) {}

    /** @return array<int,string> */
    public function activity(): array
    {
        if (!$this->tableAvailable()) {
            return [];
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return [];
        }

        return DB::table('redmine_tic_activity_logs')
            ->where('modulo_id', $moduleId)
            ->orderByDesc('creado_at')
            ->limit(200)
            ->pluck('linea')
            ->filter()
            ->values()
            ->all();
    }

    public function clearActivity(): void
    {
        if (!$this->tableAvailable()) {
            return;
        }

        $moduleId = $this->moduleId();
        if ($moduleId !== null) {
            DB::table('redmine_tic_activity_logs')->where('modulo_id', $moduleId)->delete();
        }
    }

    /** @param array<string,mixed> $context */
    public function append(string $event, array $context = []): void
    {
        $entry = [
            'ts'      => now('America/Santiago')->format('Y-m-d H:i:s'),
            'event'   => $event,
            'context' => $context,
        ];

        if (!$this->tableAvailable()) {
            return;
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return;
        }

        DB::table('redmine_tic_activity_logs')->insert([
            'modulo_id' => $moduleId,
            'evento'    => $event,
            'contexto'  => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'linea'     => json_encode($entry,   JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'creado_at' => now(),
        ]);
    }

    private function tableAvailable(): bool
    {
        try {
            return Schema::hasTable('modulos_nova') && Schema::hasTable('redmine_tic_activity_logs');
        } catch (\Throwable) {
            return false;
        }
    }

    private function moduleId(): ?int
    {
        try {
            $id = DB::table('modulos_nova')->where('clave_modulo', $this->projectKey)->value('id');
            if ($id !== null) {
                return (int) $id;
            }

            DB::table('modulos_nova')->insert([
                'clave_modulo'   => $this->projectKey,
                'nombre'         => $this->projectName,
                'descripcion'    => '',
                'icono'          => '',
                'tipo'           => 'native',
                'ruta'           => $this->projectKey,
                'entrada'        => 'laravel:redmine.native.dashboard',
                'habilitado'     => 1,
                'orden'          => 100,
                'creado_at'      => now(),
                'actualizado_at' => now(),
            ]);

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }
}
