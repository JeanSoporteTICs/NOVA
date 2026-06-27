<?php

namespace RedmineTic\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB operations for hours-extra groups and pivot table.
 * Tables: redmine_tic_horas_extra_grupos, redmine_tic_horas_extra_grupo_reportes
 *
 * Note: hoursExtraFromDatabase() and hoursExtraData() remain in
 * RedmineDataRepository because they need pre-hydrated report arrays that
 * are produced by the report persistence layer.
 */
class RedmineHoursExtraRepository
{
    public function __construct(
        private string $projectKey,
        private string $projectName,
    ) {}

    public function saveGroup(string $sourceFile, array $payload): void
    {
        $date = trim((string) ($payload['fecha'] ?? ''));
        if ($date === '' || !$this->tableAvailable()) {
            return;
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return;
        }

        DB::table('redmine_tic_horas_extra_grupos')
            ->where('modulo_id', $moduleId)
            ->where('fecha', $this->parseDate($date))
            ->update([
                'hora_inicio'    => $this->parseTime($payload['hora_inicio'] ?? null),
                'hora_fin'       => $this->parseTime($payload['hora_fin'] ?? null),
                'actualizado_at' => now(),
            ]);
    }

    public function deleteGroup(string $sourceFile, string $date): int
    {
        if ($date === '' || !$this->tableAvailable()) {
            return 0;
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return 0;
        }

        return DB::table('redmine_tic_horas_extra_grupos')
            ->where('modulo_id', $moduleId)
            ->where('fecha', $this->parseDate($date))
            ->delete();
    }

    public function syncForReport(array $report): void
    {
        $id = (string) ($report['id'] ?? '');
        if ($id === '' || !$this->tableAvailable()) {
            return;
        }

        $this->remove($id);

        if (!in_array(strtolower((string) ($report['hora_extra'] ?? '')), ['si', 'sí', '1', 'true'], true)) {
            return;
        }

        $date     = trim((string) ($report['fecha_inicio'] ?? $report['fecha'] ?? now('America/Santiago')->format('Y-m-d')));
        $dt       = date_create($date) ?: now('America/Santiago');
        $targetDate = $dt->format('Y-m-d');

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return;
        }

        DB::table('redmine_tic_horas_extra_grupos')->updateOrInsert(
            ['modulo_id' => $moduleId, 'fecha' => $targetDate],
            [
                'hora_inicio'    => $this->parseTime($report['hora_inicio'] ?? $report['hora'] ?? null),
                'hora_fin'       => $this->parseTime($report['hora_fin']   ?? $report['hora'] ?? null),
                'actualizado_at' => now(),
            ]
        );

        if ($this->pivotTableAvailable()) {
            $reporteId = is_numeric($id) ? (int) $id : 0;
            if ($reporteId > 0) {
                $grupoId = (int) DB::table('redmine_tic_horas_extra_grupos')
                    ->where('modulo_id', $moduleId)
                    ->where('fecha', $targetDate)
                    ->value('id');
                if ($grupoId > 0) {
                    try {
                        DB::table('redmine_tic_horas_extra_grupo_reportes')->updateOrInsert(
                            ['grupo_id' => $grupoId, 'reporte_id' => $reporteId],
                            ['creado_at' => now()]
                        );
                    } catch (\Throwable) {
                    }
                }
            }
        }
    }

    public function remove(string $id): void
    {
        if (!$this->tableAvailable() || trim($id) === '') {
            return;
        }

        $moduleId  = $this->moduleId();
        if ($moduleId === null) {
            return;
        }

        $reporteId = is_numeric($id) ? (int) $id : 0;
        if ($reporteId <= 0 || !$this->pivotTableAvailable()) {
            return;
        }

        $grupoIds = DB::table('redmine_tic_horas_extra_grupo_reportes')
            ->where('reporte_id', $reporteId)
            ->pluck('grupo_id')
            ->all();

        DB::table('redmine_tic_horas_extra_grupo_reportes')
            ->where('reporte_id', $reporteId)
            ->delete();

        foreach ($grupoIds as $grupoId) {
            $grupoId = (int) $grupoId;
            $inModule = DB::table('redmine_tic_horas_extra_grupos')
                ->where('id', $grupoId)
                ->where('modulo_id', $moduleId)
                ->exists();
            if (!$inModule) {
                continue;
            }
            $hasReports = DB::table('redmine_tic_horas_extra_grupo_reportes')
                ->where('grupo_id', $grupoId)
                ->exists();
            if (!$hasReports) {
                DB::table('redmine_tic_horas_extra_grupos')->where('id', $grupoId)->delete();
            }
        }
    }

    public function tableAvailable(): bool
    {
        try {
            return Schema::hasTable('modulos_nova') && Schema::hasTable('redmine_tic_horas_extra_grupos');
        } catch (\Throwable) {
            return false;
        }
    }

    public function pivotTableAvailable(): bool
    {
        try {
            return $this->tableAvailable() && Schema::hasTable('redmine_tic_horas_extra_grupo_reportes');
        } catch (\Throwable) {
            return false;
        }
    }

    // ---- small date/time utilities (duplicated from RedmineDataRepository) ----

    private function parseDate(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Exception) {
            return '';
        }
    }

    private function parseTime(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{1,2}:\d{2}(?::\d{2})?$/', $value)) {
            $parts  = explode(':', $value);
            $hour   = max(0, min(23, (int) ($parts[0] ?? 0)));
            $minute = max(0, min(59, (int) ($parts[1] ?? 0)));
            $second = max(0, min(59, (int) ($parts[2] ?? 0)));

            return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
        }

        try {
            return (new \DateTimeImmutable($value))->format('H:i:s');
        } catch (\Exception) {
            return null;
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
