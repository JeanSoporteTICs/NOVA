<?php

namespace RedmineTic\Repositories;

use App\Modulos\Nova\Repositories\HorasExtraRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestiona a qué grupo del dominio compartido Horas Extra (ver
 * Nova\Repositories\HorasExtraRepository) pertenece cada reporte de TIC,
 * filtrando siempre por origen='tic'. Los reportes en sí siguen viviendo
 * exclusivamente en redmine_tic_reportes.
 *
 * Nota: hoursExtraFromDatabase() y hoursExtraData() permanecen en
 * RedmineDataRepository porque necesitan arreglos de reportes ya
 * hidratados que produce la capa de persistencia de reportes.
 */
class RedmineHoursExtraRepository
{
    private const ORIGEN = 'tic';

    private ?HorasExtraRepository $shared = null;

    public function saveGroup(string $sourceFile, array $payload): bool
    {
        $date = trim((string) ($payload['fecha'] ?? ''));
        if ($date === '' || ! $this->tableAvailable()) {
            return false;
        }

        return $this->shared()->updateGroupsByOrigenAndFecha(
            self::ORIGEN,
            $this->parseDate($date),
            $this->parseTime($payload['hora_inicio'] ?? null),
            $this->parseTime($payload['hora_fin'] ?? null),
        );
    }

    /**
     * Legacy: antes eliminaba el grupo completo del módulo para esa fecha.
     * Con tabla compartida, en cambio, desvincula solo los reportes de
     * origen 'tic' de esa fecha; si Mantención todavía tiene reportes en el
     * mismo grupo, el grupo permanece intacto para ese origen.
     */
    public function deleteGroup(string $sourceFile, string $date): int
    {
        if ($date === '' || ! $this->pivotTableAvailable()) {
            return 0;
        }

        try {
            return $this->shared()->atomic(function () use ($date): int {
                $reporteIds = $this->shared()->reporteIdsPorOrigenYFecha(self::ORIGEN, $this->parseDate($date));
                DB::table('redmine_tic_reportes')->whereIn('id', $reporteIds)->orderBy('id')->lockForUpdate()->get(['id']);
                $count = 0;
                foreach ($reporteIds as $reporteId) {
                    if ($this->shared()->detachReporte(self::ORIGEN, $reporteId)) {
                        $count++;
                    }
                }

                return $count;
            });
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                throw $exception;
            }

            return 0;
        }
    }

    public function syncForReport(array $report): bool
    {
        $id = (string) ($report['id'] ?? '');
        if ($id === '' || ! $this->tableAvailable()) {
            return false;
        }
        $reporteId = is_numeric($id) ? (int) $id : 0;
        if ($reporteId <= 0 || ! $this->pivotTableAvailable()) {
            return false;
        }
        try {
            return $this->shared()->atomic(function () use ($report, $reporteId): bool {
                if (! DB::table('redmine_tic_reportes')->where('id', $reporteId)->lockForUpdate()->first()) {
                    return false;
                }
                $enabled = in_array(strtolower((string) ($report['hora_extra'] ?? '')), ['si', 'sí', '1', 'true'], true);
                $date = trim((string) ($report['fecha_inicio'] ?? $report['fecha'] ?? now('America/Santiago')->format('Y-m-d')));
                $dt = date_create($date) ?: now('America/Santiago');
                $targetDate = $dt->format('Y-m-d');
                $horaInicio = $this->parseTime($report['hora_inicio'] ?? $report['hora'] ?? null);
                $horaFin = $this->parseTime($report['hora_fin'] ?? $report['hora'] ?? null);
                $usuarioId = $this->shared()->resolveUsuarioId((string) ($report['asignado_a'] ?? ''));
                $this->shared()->lockTransition(self::ORIGEN, $reporteId, $enabled ? $usuarioId : null, $enabled ? $targetDate : null);
                // Preserve TIC's existing detach/recreate and incoming-hour precedence.
                $this->shared()->detachReporte(self::ORIGEN, $reporteId);
                if (! $enabled) {
                    return true;
                }
                $grupoId = $this->shared()->findOrCreateGroup($usuarioId, $targetDate, $horaInicio, $horaFin);
                if ($grupoId === null || ! $this->shared()->updateGroupTime($grupoId, $horaInicio, $horaFin)) {
                    throw new \RuntimeException('No se pudo guardar la jornada de horas extra.');
                }
                $this->shared()->attachReporte($grupoId, self::ORIGEN, $reporteId);

                return true;
            });
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                throw $exception;
            }

            return false;
        }
    }

    public function remove(string $id): void
    {
        if (! $this->pivotTableAvailable() || trim($id) === '') {
            return;
        }

        $reporteId = is_numeric($id) ? (int) $id : 0;
        if ($reporteId <= 0) {
            return;
        }

        $this->shared()->detachReporte(self::ORIGEN, $reporteId);
    }

    public function tableAvailable(): bool
    {
        return $this->shared()->tableReady();
    }

    public function pivotTableAvailable(): bool
    {
        try {
            return $this->tableAvailable() && Schema::hasTable('horas_extra_grupo_reportes');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Grupos con reportes de origen 'tic', ya listos para que
     * RedmineDataRepository los hidrate contra redmine_tic_reportes.
     *
     * @return array<int,array{grupo_id:int,usuario_id:?int,fecha:string,hora_inicio:?string,hora_fin:?string,total_minutos:?int,reporte_ids:array<int,int>}>
     */
    public function groupsForOrigen(): array
    {
        return $this->shared()->groupsForOrigen(self::ORIGEN);
    }

    public function resolveUsuarioId(?string $redmineId): ?int
    {
        return $this->shared()->resolveUsuarioId($redmineId);
    }

    public function findOrCreateGroup(?int $usuarioId, string $fecha, ?string $horaInicio, ?string $horaFin): ?int
    {
        return $this->shared()->findOrCreateGroup($usuarioId, $fecha, $horaInicio, $horaFin);
    }

    public function updateGroupTime(int $grupoId, ?string $horaInicio, ?string $horaFin): bool
    {
        return $this->shared()->updateGroupTime($grupoId, $horaInicio, $horaFin);
    }

    public function attachReporte(int $grupoId, int $reporteId): void
    {
        $this->shared()->atomic(function () use ($grupoId, $reporteId): void {
            $report = DB::table('redmine_tic_reportes')->where('id', $reporteId)->lockForUpdate()->first(['fecha_inicio', 'fecha']);
            $group = DB::table('horas_extra_grupos')->where('id', $grupoId)->lockForUpdate()->first(['fecha']);
            $date = trim((string) ($report->fecha_inicio ?? $report->fecha ?? ''));
            if ($date === '' || $group === null || $date !== (string) $group->fecha) {
                throw new \RuntimeException('La jornada de horas extra debe coincidir con la fecha de inicio del reporte.');
            }
            $this->shared()->attachReporte($grupoId, self::ORIGEN, $reporteId);
        });
    }

    private function shared(): HorasExtraRepository
    {
        return $this->shared ??= new HorasExtraRepository;
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
            $parts = explode(':', $value);
            $hour = max(0, min(23, (int) ($parts[0] ?? 0)));
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
}
