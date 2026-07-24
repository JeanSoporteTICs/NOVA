<?php

namespace App\Modulos\Nova\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Fuente de verdad única del dominio compartido "Horas Extra" en NOVA.
 *
 * Gestiona únicamente horas_extra_grupos / horas_extra_grupo_reportes: el
 * bloque diario (usuario_id + fecha) y qué reportes de qué origen
 * (mantencion|tic) cuelgan de él. NO conoce reportes, catálogos, permisos ni
 * reglas de negocio de ningún módulo — eso lo resuelve cada módulo con su
 * propio repositorio, que hidrata los reporte_ids devueltos aquí contra su
 * propia tabla de reportes.
 */
final class HorasExtraRepository
{
    public function tableReady(): bool
    {
        try {
            return Schema::hasTable('horas_extra_grupos')
                && Schema::hasTable('horas_extra_grupo_reportes')
                && Schema::hasTable('usuarios_nova');
        } catch (\Throwable) {
            return false;
        }
    }

    public function resolveUsuarioId(?string $redmineId): ?int
    {
        $redmineId = trim((string) $redmineId);
        if ($redmineId === '' || !$this->tableReady()) {
            return null;
        }

        try {
            $id = DB::table('usuarios_nova')->where('redmine_id', $redmineId)->value('id');

            return $id !== null ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Grupos que tienen al menos un reporte del origen dado, con la lista de
     * reporte_id de ESE origen (nunca del otro) para que el módulo dueño los
     * hidrate contra su propia tabla de reportes.
     *
     * @return array<int,array{grupo_id:int,usuario_id:?int,fecha:string,hora_inicio:?string,hora_fin:?string,total_minutos:?int,reporte_ids:array<int,int>}>
     */
    public function groupsForOrigen(string $origen): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        try {
            $pivotRows = DB::table('horas_extra_grupo_reportes')
                ->where('origen', $origen)
                ->orderByDesc('actualizado_at')
                ->orderByDesc('grupo_id')
                ->get(['grupo_id', 'reporte_id'])
                ->unique('reporte_id')
                ->values();

            if ($pivotRows->isEmpty()) {
                return [];
            }

            $grupoIds = $pivotRows->pluck('grupo_id')->unique()->values();
            $grupos = DB::table('horas_extra_grupos')
                ->whereIn('id', $grupoIds)
                ->orderByDesc('fecha')
                ->get(['id', 'usuario_id', 'fecha', 'hora_inicio', 'hora_fin', 'total_minutos']);

            $reportesPorGrupo = $pivotRows->groupBy('grupo_id');

            return $grupos->map(static function (object $g) use ($reportesPorGrupo): array {
                $reportes = $reportesPorGrupo->get($g->id) ?? collect();

                return [
                    'grupo_id' => (int) $g->id,
                    'usuario_id' => $g->usuario_id !== null ? (int) $g->usuario_id : null,
                    'fecha' => (string) $g->fecha,
                    'hora_inicio' => $g->hora_inicio,
                    'hora_fin' => $g->hora_fin,
                    'total_minutos' => $g->total_minutos !== null ? (int) $g->total_minutos : null,
                    'reporte_ids' => $reportes->pluck('reporte_id')->map(static fn ($v): int => (int) $v)->values()->all(),
                ];
            })->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * IDs de reporte de un origen dado que caen en una fecha, sin importar
     * a qué usuario pertenezca el grupo. Útil para operaciones legacy
     * "por fecha" que no conocen un usuario específico.
     *
     * @return array<int,int>
     */
    public function reporteIdsPorOrigenYFecha(string $origen, string $fecha): array
    {
        $fecha = trim($fecha);
        if (!$this->tableReady() || $fecha === '') {
            return [];
        }

        try {
            return DB::table('horas_extra_grupo_reportes as p')
                ->join('horas_extra_grupos as g', 'g.id', '=', 'p.grupo_id')
                ->where('p.origen', $origen)
                ->where('g.fecha', $fecha)
                ->pluck('p.reporte_id')
                ->map(static fn ($v): int => (int) $v)
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function findOrCreateGroup(?int $usuarioId, string $fecha, ?string $horaInicio = null, ?string $horaFin = null): ?int
    {
        $fecha = trim($fecha);
        if (!$this->tableReady() || $fecha === '') {
            return null;
        }

        try {
            $query = DB::table('horas_extra_grupos')->where('fecha', $fecha);
            $usuarioId !== null ? $query->where('usuario_id', $usuarioId) : $query->whereNull('usuario_id');
            $id = $query->value('id');
            if ($id !== null) {
                return (int) $id;
            }

            return (int) DB::table('horas_extra_grupos')->insertGetId([
                'usuario_id' => $usuarioId,
                'fecha' => $fecha,
                'hora_inicio' => $horaInicio,
                'hora_fin' => $horaFin,
                'total_minutos' => $this->minutesDiff($horaInicio, $horaFin),
                'creado_at' => now(),
                'actualizado_at' => now(),
            ]);
        } catch (\Throwable) {
            return null;
        }
    }

    public function attachReporte(int $grupoId, string $origen, int $reporteId): void
    {
        if (!$this->tableReady()) {
            return;
        }

        try {
            $previousGroupIds = DB::table('horas_extra_grupo_reportes')
                ->where('origen', $origen)
                ->where('reporte_id', $reporteId)
                ->where('grupo_id', '<>', $grupoId)
                ->pluck('grupo_id');

            if ($previousGroupIds->isNotEmpty()) {
                DB::table('horas_extra_grupo_reportes')
                    ->where('origen', $origen)
                    ->where('reporte_id', $reporteId)
                    ->where('grupo_id', '<>', $grupoId)
                    ->delete();
            }

            DB::table('horas_extra_grupo_reportes')->updateOrInsert(
                ['grupo_id' => $grupoId, 'origen' => $origen, 'reporte_id' => $reporteId],
                ['actualizado_at' => now()],
            );

            foreach ($previousGroupIds as $previousGroupId) {
                $this->deleteIfEmpty((int) $previousGroupId);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Quita un reporte de su grupo (por origen, para no tocar reportes del
     * otro módulo con el mismo reporte_id numérico). Si el grupo queda sin
     * ningún reporte de ningún origen, se elimina.
     */
    public function detachReporte(string $origen, int $reporteId): bool
    {
        if (!$this->tableReady()) {
            return false;
        }

        try {
            $grupoIds = DB::table('horas_extra_grupo_reportes')
                ->where('origen', $origen)
                ->where('reporte_id', $reporteId)
                ->pluck('grupo_id');

            $deleted = DB::table('horas_extra_grupo_reportes')
                ->where('origen', $origen)
                ->where('reporte_id', $reporteId)
                ->delete();

            foreach ($grupoIds as $grupoId) {
                $this->deleteIfEmpty((int) $grupoId);
            }

            return $deleted > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Compatibilidad con el flujo legacy de edición "por fecha" (las vistas
     * originales de Mantención/TIC no conocen un usuario específico, solo
     * una fecha): actualiza todos los grupos que tengan al menos un reporte
     * de $origen en $fecha. En la inmensa mayoría de los casos es un único
     * grupo; si hay varios usuarios con horas ese día para ese origen, se
     * actualizan todos con el mismo horario indicado (mismo comportamiento
     * "a nivel de fecha" que ya tenían las tablas separadas).
     */
    public function updateGroupsByOrigenAndFecha(string $origen, string $fecha, ?string $horaInicio, ?string $horaFin): bool
    {
        $fecha = trim($fecha);
        if (!$this->tableReady() || $fecha === '') {
            Log::warning('HorasExtraRepository::updateGroupsByOrigenAndFecha — tabla no lista o fecha vacia', [
                'origen' => $origen, 'fecha' => $fecha, 'table_ready' => $this->tableReady(),
            ]);

            return false;
        }

        try {
            $grupoIds = DB::table('horas_extra_grupo_reportes as p')
                ->join('horas_extra_grupos as g', 'g.id', '=', 'p.grupo_id')
                ->where('p.origen', $origen)
                ->where('g.fecha', $fecha)
                ->distinct()
                ->pluck('p.grupo_id');

            if ($grupoIds->isEmpty()) {
                Log::warning('HorasExtraRepository::updateGroupsByOrigenAndFecha — no se encontro ningun grupo para ese origen+fecha', [
                    'origen' => $origen, 'fecha' => $fecha,
                ]);

                return false;
            }

            $updated = 0;
            foreach ($grupoIds as $grupoId) {
                if ($this->updateGroupTime((int) $grupoId, $horaInicio, $horaFin)) {
                    $updated++;
                }
            }

            return $updated > 0;
        } catch (\Throwable $e) {
            Log::error('HorasExtraRepository::updateGroupsByOrigenAndFecha — excepcion', [
                'origen' => $origen, 'fecha' => $fecha, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function updateGroupTime(int $grupoId, ?string $horaInicio, ?string $horaFin): bool
    {
        if (!$this->tableReady()) {
            Log::warning('HorasExtraRepository::updateGroupTime — tabla no lista', ['grupo_id' => $grupoId]);

            return false;
        }

        try {
            $current = DB::table('horas_extra_grupos')->where('id', $grupoId)->first(['hora_inicio', 'hora_fin']);
            if ($current === null) {
                Log::warning('HorasExtraRepository::updateGroupTime — grupo_id no existe', ['grupo_id' => $grupoId]);

                return false;
            }

            $finalInicio = $horaInicio !== null && trim($horaInicio) !== '' ? $horaInicio : $current->hora_inicio;
            $finalFin = $horaFin !== null && trim($horaFin) !== '' ? $horaFin : $current->hora_fin;

            if ($finalInicio === $current->hora_inicio && $finalFin === $current->hora_fin) {
                // Nada que cambiar: el grupo ya tiene exactamente estos valores.
                // No es un fallo — se evita el UPDATE innecesario y se reporta éxito.
                return true;
            }

            return DB::table('horas_extra_grupos')->where('id', $grupoId)->update([
                'hora_inicio' => $finalInicio,
                'hora_fin' => $finalFin,
                'total_minutos' => $this->minutesDiff($finalInicio, $finalFin),
                'actualizado_at' => now(),
            ]) > 0;
        } catch (\Throwable $e) {
            Log::error('HorasExtraRepository::updateGroupTime — excepcion', [
                'grupo_id' => $grupoId, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function deleteIfEmpty(int $grupoId): void
    {
        try {
            $hasAny = DB::table('horas_extra_grupo_reportes')->where('grupo_id', $grupoId)->exists();
            if (!$hasAny) {
                DB::table('horas_extra_grupos')->where('id', $grupoId)->delete();
            }
        } catch (\Throwable) {
        }
    }

    private function minutesDiff(?string $horaInicio, ?string $horaFin): ?int
    {
        $horaInicio = trim((string) $horaInicio);
        $horaFin = trim((string) $horaFin);
        if ($horaInicio === '' || $horaFin === '') {
            return null;
        }

        $start = strtotime('1970-01-01 ' . $horaInicio);
        $end = strtotime('1970-01-01 ' . $horaFin);
        if ($start === false || $end === false) {
            return null;
        }
        if ($end < $start) {
            $end += 86400;
        }

        return (int) round(($end - $start) / 60);
    }
}
