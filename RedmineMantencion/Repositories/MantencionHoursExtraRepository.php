<?php

namespace App\Modulos\RedmineMantencion\Repositories;

use App\Modulos\Nova\Repositories\HorasExtraRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestiona a qué grupo del dominio compartido Horas Extra (ver
 * Nova\Repositories\HorasExtraRepository) pertenece cada reporte de
 * Mantención, agrupado por (usuario_id, fecha) y filtrado siempre por
 * origen='mantencion'. Los reportes en sí (asunto, categoria, estado, etc.)
 * siguen viviendo exclusivamente en redmine_mantencion_reportes: este
 * repositorio solo hidrata los reporte_ids que el repositorio compartido
 * le devuelve.
 */
final class MantencionHoursExtraRepository
{
    private const MODULE_KEY = 'redmine-mantencion';
    private const ORIGEN = 'mantencion';

    private ?int $moduleId = null;
    private bool $moduleIdResolved = false;

    public function __construct(
        private readonly MantencionReportRepository $reports,
        private readonly HorasExtraRepository $shared,
    ) {
    }

    public function tableReady(): bool
    {
        try {
            return $this->reports->tableReady()
                && $this->shared->tableReady()
                && Schema::hasTable('redmine_mantencion_reportes');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function groups(): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $grupos = $this->shared->groupsForOrigen(self::ORIGEN);
        if ($grupos === []) {
            return [];
        }

        $todosLosReporteIds = array_values(array_unique(array_merge(...array_map(
            static fn (array $g): array => $g['reporte_ids'],
            $grupos
        ))));

        if ($todosLosReporteIds === []) {
            return [];
        }

        try {
            $rows = DB::table('redmine_mantencion_reportes as r')
                ->leftJoin('categorias as c', 'c.id', '=', 'r.categoria_id')
                ->whereIn('r.id', $todosLosReporteIds)
                ->where('r.estado', 'archivado')
                ->orderByDesc('r.fecha_reporte')
                ->orderByDesc('r.id')
                ->get(['r.*', 'c.nombre as categoria_nombre']);
        } catch (\Throwable) {
            return [];
        }

        $result = [];
        foreach ($grupos as $grupo) {
            $reporteIdsDelGrupo = array_flip($grupo['reporte_ids']);
            $reportesDelGrupo = [];
            // Se itera $rows (ya ordenado por fecha_reporte/id desc) en vez de
            // reporte_ids (sin orden) para conservar el orden de visualizacion previo.
            foreach ($rows as $row) {
                if (!isset($reporteIdsDelGrupo[$row->id])) {
                    continue;
                }
                $message = $this->reports->rowToMessage($row);
                $message['hora_extra'] = '1';
                $message['_fuente'] = 'horas_extra';
                $reportesDelGrupo[] = $message;
            }

            if ($reportesDelGrupo === []) {
                continue;
            }

            $result[] = [
                'fecha' => $grupo['fecha'],
                'hora_inicio' => $this->formatTime($grupo['hora_inicio']),
                'hora_fin' => $this->formatTime($grupo['hora_fin']),
                'reports' => $reportesDelGrupo,
            ];
        }

        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    public function messages(): array
    {
        $messages = [];
        foreach ($this->groups() as $group) {
            foreach (($group['reports'] ?? []) as $report) {
                if (is_array($report)) {
                    $report['fecha'] = $report['fecha'] ?? ($group['fecha'] ?? '');
                    $report['_fuente'] = 'horas_extra';
                    $messages[] = $report;
                }
            }
        }

        return $messages;
    }

    /** @param array<string,mixed> $message */
    public function syncMessage(array $message): void
    {
        if (! $this->tableReady() || ! $this->messageHasHoursExtra($message)) {
            return;
        }

        $reportId = $this->reportIdForMessage($message);
        if ($reportId === null) {
            return;
        }

        $fecha = $this->dateFromMessage($message);
        if ($fecha === null) {
            return;
        }

        $horaInicio = $this->timeFromMessage($message, ['hora_inicio', 'hora']);
        $horaFin = $this->timeFromMessage($message, ['hora_fin', 'hora']);
        $usuarioId = $this->shared->resolveUsuarioId((string) ($message['asignado_a'] ?? $message['id_redmine_asignado'] ?? ''));

        $grupoId = $this->shared->findOrCreateGroup($usuarioId, $fecha, $horaInicio, $horaFin);
        if ($grupoId === null) {
            return;
        }

        // Si el grupo ya existia (p.ej. creado antes por TIC para el mismo usuario+fecha),
        // se fusionan aqui las horas de este mensaje sin pisar valores ya definidos.
        $this->shared->updateGroupTime($grupoId, $horaInicio, $horaFin);
        $this->shared->attachReporte($grupoId, self::ORIGEN, $reportId);
    }

    public function detachMessageId(string $messageId): bool
    {
        $messageId = trim($messageId);
        if (! $this->tableReady() || $messageId === '') {
            return false;
        }

        $reportId = $this->reportIdForMessage(['id' => $messageId, 'fuente_id' => $messageId]);
        if ($reportId === null) {
            return false;
        }

        try {
            $detached = $this->shared->detachReporte(self::ORIGEN, $reportId);

            DB::table('redmine_mantencion_reportes')
                ->where('id', $reportId)
                ->update(['hora_extra' => 0, 'actualizado_at' => now()]);

            return $detached;
        } catch (\Throwable) {
            return false;
        }
    }

    public function updateGroupHours(string $fecha, string $horaInicio, string $horaFin): bool
    {
        if (! $this->tableReady()) {
            return false;
        }

        $fecha = $this->normalizeDate($fecha) ?? '';
        if ($fecha === '') {
            return false;
        }

        $inicio = $this->normalizeTime($horaInicio);
        $fin = $this->normalizeTime($horaFin);
        if ($inicio === null && $fin === null) {
            return false;
        }

        return $this->shared->updateGroupsByOrigenAndFecha(self::ORIGEN, $fecha, $inicio, $fin);
    }

    private function reportIdForMessage(array $message): ?int
    {
        $moduleId = $this->resolveModuleId();
        if ($moduleId === null) {
            return null;
        }

        $fuente = trim((string) ($message['fuente'] ?? ''));
        $fuenteId = trim((string) ($message['fuente_id'] ?? $message['id'] ?? ''));
        if ($fuenteId === '') {
            return null;
        }

        try {
            $query = DB::table('redmine_mantencion_reportes')
                ->where('modulo_id', $moduleId)
                ->where('fuente_id', $fuenteId);
            if ($fuente !== '') {
                $query->where('fuente', $fuente);
            }

            $id = $query->value('id');
            return $id !== null ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateFromMessage(array $message): ?string
    {
        foreach (['fecha_inicio', 'fecha', 'core_fecha_creacion'] as $key) {
            $date = $this->normalizeDate((string) ($message[$key] ?? ''));
            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    /** @param array<int,string> $keys */
    private function timeFromMessage(array $message, array $keys): ?string
    {
        foreach ($keys as $key) {
            $time = $this->normalizeTime((string) ($message[$key] ?? ''));
            if ($time !== null) {
                return $time;
            }
        }

        return null;
    }

    private function messageHasHoursExtra(array $message): bool
    {
        return in_array(strtolower(trim((string) ($message['hora_extra'] ?? ''))), ['1', 'si', 'sí', 's', 'true', 'yes'], true);
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i'] as $format) {
            try {
                return Carbon::createFromFormat($format, substr($value, 0, strlen($format)))->toDateString();
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Cubre "H:i" y "H:i:s" (lo que envía <input type="time"> y lo que
        // guarda la columna TIME) sin depender de recortar el string al largo
        // del propio nombre del formato, que no coincide con el largo real
        // del valor (bug previo: dejaba pasar siempre null para "17:02").
        if (preg_match('/^(\d{1,2}):([0-5]\d)(?::([0-5]\d))?$/', $value, $matches)) {
            $hour = max(0, min(23, (int) $matches[1]));
            $minute = (int) $matches[2];
            $second = isset($matches[3]) ? (int) $matches[3] : 0;

            return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
        }

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->format('H:i:s');
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function formatTime(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }

        try {
            return Carbon::parse((string) $value)->format('H:i');
        } catch (\Throwable) {
            return trim((string) $value);
        }
    }

    private function resolveModuleId(): ?int
    {
        if ($this->moduleIdResolved) {
            return $this->moduleId;
        }

        $this->moduleIdResolved = true;

        try {
            $id = DB::table('modulos_nova')->where('clave_modulo', self::MODULE_KEY)->value('id');
            $this->moduleId = $id !== null ? (int) $id : null;
        } catch (\Throwable) {
            $this->moduleId = null;
        }

        return $this->moduleId;
    }
}
