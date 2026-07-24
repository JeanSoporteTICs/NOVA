<?php

namespace RedmineTic\Repositories;

use App\Modulos\Nova\Repositories\HorasExtraRepository;
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
        if ($date === '' || !$this->tableAvailable()) {
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
        if ($date === '' || !$this->pivotTableAvailable()) {
            return 0;
        }

        $reporteIds = $this->shared()->reporteIdsPorOrigenYFecha(self::ORIGEN, $this->parseDate($date));

        $count = 0;
        foreach ($reporteIds as $reporteId) {
            if ($this->shared()->detachReporte(self::ORIGEN, $reporteId)) {
                $count++;
            }
        }

        return $count;
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

        $date = trim((string) ($report['fecha_inicio'] ?? $report['fecha'] ?? now('America/Santiago')->format('Y-m-d')));
        $dt = date_create($date) ?: now('America/Santiago');
        $targetDate = $dt->format('Y-m-d');

        $reporteId = is_numeric($id) ? (int) $id : 0;
        if ($reporteId <= 0 || !$this->pivotTableAvailable()) {
            return;
        }

        $horaInicio = $this->parseTime($report['hora_inicio'] ?? $report['hora'] ?? null);
        $horaFin = $this->parseTime($report['hora_fin'] ?? $report['hora'] ?? null);
        $usuarioId = $this->shared()->resolveUsuarioId((string) ($report['asignado_a'] ?? ''));

        $grupoId = $this->shared()->findOrCreateGroup($usuarioId, $targetDate, $horaInicio, $horaFin);
        if ($grupoId === null) {
            return;
        }

        // Si el grupo ya existia (p.ej. creado antes por Mantencion para el mismo
        // usuario+fecha), se fusionan aqui las horas de este reporte sin pisar valores ya definidos.
        $this->shared()->updateGroupTime($grupoId, $horaInicio, $horaFin);
        $this->shared()->attachReporte($grupoId, self::ORIGEN, $reporteId);
    }

    public function remove(string $id): void
    {
        if (!$this->pivotTableAvailable() || trim($id) === '') {
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
        $this->shared()->attachReporte($grupoId, self::ORIGEN, $reporteId);
    }

    private function shared(): HorasExtraRepository
    {
        return $this->shared ??= new HorasExtraRepository();
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
}
