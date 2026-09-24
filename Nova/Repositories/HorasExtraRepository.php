<?php

namespace App\Modulos\Nova\Repositories;

use Illuminate\Database\QueryException;
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

    /** Group/pivot changes share the caller transaction; no external calls inside it. */
    public function atomic(callable $operation): mixed
    {
        return DB::transaction($operation, DB::transactionLevel() === 0 ? 3 : 1);
    }

    private function write(callable $operation, mixed $failure): mixed
    {
        $nested = DB::transactionLevel() > 0;
        try {
            return $this->atomic($operation);
        } catch (\Throwable $exception) {
            // A surrounding operation must roll back instead of committing a partial result.
            if ($nested) {
                throw $exception;
            }

            return $failure;
        }
    }

    /** Lock owner before groups, then old and destination groups in ascending PK order. */
    public function lockTransition(string $origen, int $reporteId, ?int $usuarioId, ?string $fecha): void
    {
        if ($usuarioId !== null) {
            DB::table('usuarios_nova')->where('id', $usuarioId)->lockForUpdate()->first();
        }
        $ids = DB::table('horas_extra_grupo_reportes')->where('origen', $origen)->where('reporte_id', $reporteId)->pluck('grupo_id')->all();
        if ($fecha !== null) {
            $query = DB::table('horas_extra_grupos')->where('fecha', $fecha);
            $usuarioId !== null ? $query->where('usuario_id', $usuarioId) : $query->whereNull('usuario_id');
            $ids = array_merge($ids, $query->pluck('id')->all());
        }
        if ($ids !== []) {
            DB::table('horas_extra_grupos')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
        }
    }

    public function resolveUsuarioId(?string $redmineId): ?int
    {
        $redmineId = trim((string) $redmineId);
        if ($redmineId === '' || ! $this->tableReady()) {
            return null;
        }

        try {
            $id = DB::table('usuarios_nova')->where('redmine_id', $redmineId)->value('id');

            return $id !== null ? (int) $id : null;
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                throw $exception;
            }

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
        if (! $this->tableReady()) {
            return [];
        }

        try {
            $pivotRows = DB::table('horas_extra_grupo_reportes')
                ->where('origen', $origen)
                ->orderByDesc('actualizado_at')
                ->orderByDesc('grupo_id')
                ->get(['grupo_id', 'reporte_id']);

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
        if (! $this->tableReady() || $fecha === '') {
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
        if (! $this->tableReady() || $fecha === '') {
            return null;
        }

        return $this->write(function () use ($usuarioId, $fecha, $horaInicio, $horaFin): int {
            if ($usuarioId !== null) {
                DB::table('usuarios_nova')->where('id', $usuarioId)->lockForUpdate()->first();
            }
            $query = DB::table('horas_extra_grupos')->where('fecha', $fecha);
            $usuarioId !== null ? $query->where('usuario_id', $usuarioId) : $query->whereNull('usuario_id');
            $id = (clone $query)->lockForUpdate()->value('id');
            if ($id !== null) {
                return (int) $id;
            }
            try {
                return (int) DB::table('horas_extra_grupos')->insertGetId([
                    'usuario_id' => $usuarioId, 'fecha' => $fecha, 'hora_inicio' => $horaInicio, 'hora_fin' => $horaFin,
                    'total_minutos' => $this->minutesDiff($horaInicio, $horaFin), 'creado_at' => now(), 'actualizado_at' => now(),
                ]);
            } catch (QueryException $exception) {
                // Only recover the same non-null identity after a genuine duplicate-key race.
                if ($usuarioId !== null && (int) ($exception->errorInfo[1] ?? 0) === 1062) {
                    $winner = (clone $query)->lockForUpdate()->value('id');
                    if ($winner !== null) {
                        return (int) $winner;
                    }
                }
                throw $exception;
            }
        }, null);
    }

    public function attachReporte(int $grupoId, string $origen, int $reporteId): void
    {
        if (! $this->tableReady()) {
            throw new \RuntimeException('No se pudo guardar el vínculo de horas extra.');
        }
        $this->atomic(function () use ($grupoId, $origen, $reporteId): void {
            if (! DB::table('horas_extra_grupos')->where('id', $grupoId)->lockForUpdate()->first()) {
                throw new \RuntimeException('La jornada fue modificada o eliminada. Recarga antes de continuar.');
            }
            DB::table('horas_extra_grupo_reportes')->updateOrInsert(
                ['grupo_id' => $grupoId, 'origen' => $origen, 'reporte_id' => $reporteId], ['actualizado_at' => now()]
            );
        });
    }

    /** Only the selected origin/report is detached; unrelated empty groups are retained. */
    public function detachReporte(string $origen, int $reporteId): bool
    {
        if (! $this->tableReady()) {
            throw new \RuntimeException('No se pudieron consultar los vínculos de horas extra.');
        }

        return $this->write(function () use ($origen, $reporteId): bool {
            $grupoIds = DB::table('horas_extra_grupo_reportes')->where('origen', $origen)->where('reporte_id', $reporteId)
                ->orderBy('grupo_id')->pluck('grupo_id');
            DB::table('horas_extra_grupos')->whereIn('id', $grupoIds)->orderBy('id')->lockForUpdate()->get(['id']);
            // Re-read current links after waiting for locks, even under REPEATABLE READ.
            $grupoIds = DB::table('horas_extra_grupo_reportes')->where('origen', $origen)->where('reporte_id', $reporteId)
                ->orderBy('grupo_id')->lockForUpdate()->pluck('grupo_id');
            DB::table('horas_extra_grupos')->whereIn('id', $grupoIds)->orderBy('id')->lockForUpdate()->get(['id']);
            $deleted = DB::table('horas_extra_grupo_reportes')->where('origen', $origen)->where('reporte_id', $reporteId)->delete();
            foreach ($grupoIds as $grupoId) {
                $this->deleteIfEmpty((int) $grupoId);
            }

            return $deleted > 0;
        }, false);
    }

    /**
     * Detach several reports using one lock/read/delete sequence. Callers that
     * delete report rows already hold those rows, so no new hours link can be
     * committed for the selected reports while this operation is in flight.
     *
     * @param  iterable<int|string>  $reporteIds
     */
    public function detachReportes(string $origen, iterable $reporteIds): int
    {
        if (! $this->tableReady()) {
            throw new \RuntimeException('No se pudieron consultar los vínculos de horas extra.');
        }

        $reporteIds = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, is_array($reporteIds) ? $reporteIds : iterator_to_array($reporteIds)),
            static fn (int $id): bool => $id > 0
        )));
        if ($reporteIds === []) {
            return 0;
        }

        return $this->write(function () use ($origen, $reporteIds): int {
            $links = DB::table('horas_extra_grupo_reportes')
                ->where('origen', $origen)
                ->whereIn('reporte_id', $reporteIds);
            $grupoIds = (clone $links)->orderBy('grupo_id')->pluck('grupo_id')->unique()->values();
            DB::table('horas_extra_grupos')->whereIn('id', $grupoIds)->orderBy('id')->lockForUpdate()->get(['id']);

            // Re-read after waiting for group locks, matching detachReporte().
            $grupoIds = (clone $links)->orderBy('grupo_id')->lockForUpdate()->pluck('grupo_id')->unique()->values();
            DB::table('horas_extra_grupos')->whereIn('id', $grupoIds)->orderBy('id')->lockForUpdate()->get(['id']);
            $deleted = (clone $links)->delete();
            foreach ($grupoIds as $grupoId) {
                $this->deleteIfEmpty((int) $grupoId);
            }

            return $deleted;
        }, 0);
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
        if (! $this->tableReady() || $fecha === '') {
            Log::warning('HorasExtraRepository::updateGroupsByOrigenAndFecha — tabla no lista o fecha vacia', [
                'origen' => $origen, 'fecha' => $fecha, 'table_ready' => $this->tableReady(),
            ]);

            return false;
        }

        return $this->write(function () use ($origen, $fecha, $horaInicio, $horaFin): bool {
            $grupoIds = DB::table('horas_extra_grupo_reportes as p')
                ->join('horas_extra_grupos as g', 'g.id', '=', 'p.grupo_id')
                ->where('p.origen', $origen)->where('g.fecha', $fecha)->distinct()->orderBy('p.grupo_id')->pluck('p.grupo_id');
            if ($grupoIds->isEmpty()) {
                return false;
            }
            DB::table('horas_extra_grupos')->whereIn('id', $grupoIds)->orderBy('id')->lockForUpdate()->get(['id']);
            foreach ($grupoIds as $grupoId) {
                if (! $this->updateGroupTime((int) $grupoId, $horaInicio, $horaFin)) {
                    throw new \RuntimeException('No se pudo actualizar la jornada completa.');
                }
            }

            return true;
        }, false);
    }

    public function updateGroupTime(int $grupoId, ?string $horaInicio, ?string $horaFin): bool
    {
        if (! $this->tableReady()) {
            Log::warning('HorasExtraRepository::updateGroupTime — tabla no lista', ['grupo_id' => $grupoId]);

            return false;
        }

        return $this->write(function () use ($grupoId, $horaInicio, $horaFin): bool {
            $current = DB::table('horas_extra_grupos')->where('id', $grupoId)->lockForUpdate()->first(['hora_inicio', 'hora_fin']);
            if ($current === null) {
                return false;
            }
            $finalInicio = $horaInicio !== null && trim($horaInicio) !== '' ? $horaInicio : $current->hora_inicio;
            $finalFin = $horaFin !== null && trim($horaFin) !== '' ? $horaFin : $current->hora_fin;
            if ($finalInicio === $current->hora_inicio && $finalFin === $current->hora_fin) {
                return true;
            }

            return DB::table('horas_extra_grupos')->where('id', $grupoId)->update([
                'hora_inicio' => $finalInicio, 'hora_fin' => $finalFin,
                'total_minutos' => $this->minutesDiff($finalInicio, $finalFin), 'actualizado_at' => now(),
            ]) > 0;
        }, false);
    }

    private function deleteIfEmpty(int $grupoId): void
    {
        // The group row remains locked through EXISTS + DELETE. Attach takes that same lock.
        if (! DB::table('horas_extra_grupo_reportes')->where('grupo_id', $grupoId)->lockForUpdate()->first(['id'])) {
            DB::table('horas_extra_grupos')->where('id', $grupoId)->delete();
        }
    }

    private function minutesDiff(?string $horaInicio, ?string $horaFin): ?int
    {
        $horaInicio = trim((string) $horaInicio);
        $horaFin = trim((string) $horaFin);
        if ($horaInicio === '' || $horaFin === '') {
            return null;
        }

        $start = strtotime('1970-01-01 '.$horaInicio);
        $end = strtotime('1970-01-01 '.$horaFin);
        if ($start === false || $end === false) {
            return null;
        }
        if ($end < $start) {
            $end += 86400;
        }

        return (int) round(($end - $start) / 60);
    }
}
