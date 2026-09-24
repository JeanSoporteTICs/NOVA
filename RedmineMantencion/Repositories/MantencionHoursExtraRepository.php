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
    ) {}

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

    /**
     * Metadata includes every public ID and assignee needed for reconciliation;
     * optional DB IDs restrict details without changing group/report ordering.
     *
     * @param  array<int,int>|null  $reportIds
     * @return array<int,array<string,mixed>>
     */
    public function groups(bool $metadataOnly = false, ?array $reportIds = null): array
    {
        return $this->readGroups($metadataOnly, $reportIds);
    }

    /** Candidate projection when IDs are null; otherwise full messages for those IDs. */
    public function statisticsMessages(?array $reportIds = null): array
    {
        $messages = [];
        foreach ($this->readGroups(false, $reportIds, $reportIds === null) as $group) {
            foreach ($group['reports'] as $report) {
                $report['fecha'] = $report['fecha'] ?? $group['fecha'];
                $messages[] = $report;
            }
        }

        return $messages;
    }

    private function readGroups(bool $metadataOnly = false, ?array $reportIds = null, bool $statisticsOnly = false): array
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

        if ($reportIds !== null) {
            $todosLosReporteIds = array_values(array_intersect($todosLosReporteIds, $reportIds));
        }

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
                ->get($statisticsOnly ? MantencionReportRepository::statisticsColumns()
                    : ($metadataOnly ? ['r.id', 'r.fuente_id', 'r.id_redmine_asignado'] : ['r.*', 'c.nombre as categoria_nombre']));
        } catch (\Throwable) {
            return [];
        }

        // Index memberships once, then traverse the SQL order once. Each row
        // is hydrated once even if several jornadas reference the same report.
        $groupsByReport = [];
        foreach ($grupos as $index => $grupo) {
            foreach (array_unique($grupo['reporte_ids']) as $reportId) {
                $groupsByReport[$reportId][] = $index;
            }
        }
        $reportsByGroup = [];
        foreach ($rows as $row) {
            if (! isset($groupsByReport[$row->id])) {
                continue;
            }
            $message = $metadataOnly ? [
                'id' => trim((string) ($row->fuente_id ?? '')) !== '' ? trim((string) $row->fuente_id) : (string) $row->id,
                'asignado_a' => trim((string) ($row->id_redmine_asignado ?? '')),
                '_database_id' => (int) $row->id,
            ] : $this->reports->rowToMessage($row);
            if ($statisticsOnly) {
                $message['_statistics_id'] = (string) $row->id;
            }
            $message['hora_extra'] = '1';
            $message['_fuente'] = 'horas_extra';
            foreach ($groupsByReport[$row->id] as $index) {
                $reportsByGroup[$index][] = $message;
            }
        }

        $result = [];
        foreach ($grupos as $index => $grupo) {
            $reportesDelGrupo = $reportsByGroup[$index] ?? [];
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
    public function syncMessage(array $message): bool
    {
        if (! $this->tableReady()) {
            return false;
        }
        if (! $this->messageHasHoursExtra($message)) {
            return true;
        }
        $fecha = $this->dateFromMessage($message);
        if ($fecha === null) {
            return true;
        } // Preserve the existing no-op for an unparseable date.
        try {
            return $this->shared->atomic(function () use ($message, $fecha): bool {
                $reportId = $this->reportIdForMessage($message);
                if ($reportId === null || ! DB::table('redmine_mantencion_reportes')->where('id', $reportId)->lockForUpdate()->first()) {
                    return false;
                }
                $horaInicio = $this->timeFromMessage($message, ['hora_inicio', 'hora']);
                $horaFin = $this->timeFromMessage($message, ['hora_fin', 'hora']);
                $usuarioId = $this->shared->resolveUsuarioId((string) ($message['asignado_a'] ?? $message['id_redmine_asignado'] ?? ''));
                $this->shared->lockTransition(self::ORIGEN, $reportId, $usuarioId, $fecha);
                $grupoId = $this->shared->findOrCreateGroup($usuarioId, $fecha, $horaInicio, $horaFin);
                if ($grupoId === null || ! $this->shared->updateGroupTime($grupoId, $horaInicio, $horaFin)) {
                    throw new \RuntimeException('No se pudo guardar la jornada de horas extra.');
                }
                // Preserve Mantención's existing links and non-empty incoming-hour precedence.
                $this->shared->attachReporte($grupoId, self::ORIGEN, $reportId);

                return true;
            });
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                throw $exception;
            }

            return false;
        }
    }

    public function detachMessageId(string $messageId): bool
    {
        $messageId = trim($messageId);
        if (! $this->tableReady() || $messageId === '') {
            return false;
        }
        try {
            return $this->shared->atomic(function () use ($messageId): bool {
                $reportId = $this->reportIdForMessage(['id' => $messageId, 'fuente_id' => $messageId]);
                if ($reportId === null || ! DB::table('redmine_mantencion_reportes')->where('id', $reportId)->lockForUpdate()->first()) {
                    return false;
                }
                $this->shared->detachReporte(self::ORIGEN, $reportId);
                DB::table('redmine_mantencion_reportes')->where('id', $reportId)->update(['hora_extra' => 0, 'actualizado_at' => now()]);

                return true;
            });
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                throw $exception;
            }

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
