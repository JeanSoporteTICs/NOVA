<?php

namespace App\Modulos\RedmineMantencion\Repositories;

use App\Modulos\RedmineMantencion\Support\CoreReportDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class MantencionReportRepository
{
    private const MODULE_KEY = 'redmine-mantencion';

    private ?int $moduleId = null;

    private bool $moduleIdResolved = false;

    private ?bool $tableReadyCache = null;

    private array $columnAvailableCache = [];

    public function __construct(private readonly MantencionCatalogRepository $catalogs) {}

    public function tableReady(): bool
    {
        if ($this->tableReadyCache !== null) {
            return $this->tableReadyCache;
        }

        try {
            $this->tableReadyCache = Schema::hasTable('modulos_nova')
                && Schema::hasTable('redmine_mantencion_reportes');
        } catch (\Throwable) {
            $this->tableReadyCache = false;
        }

        return $this->tableReadyCache;
    }

    /**
     * Return all fuente_id values that already exist in this module's table.
     *
     * Scoped to a specific fuente when provided (e.g. 'core').
     * Used to build the DB-based duplicate guard in the CORE import loop.
     *
     * @return array<string, true>
     */
    public function getExistingFuenteIds(string $fuente = ''): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $moduleId = $this->resolveModuleId();
        if ($moduleId === null) {
            return [];
        }

        try {
            $query = DB::table('redmine_mantencion_reportes')
                ->where('modulo_id', $moduleId)
                ->whereNotNull('fuente_id');

            if ($fuente !== '') {
                $query->where('fuente', $fuente);
            }

            return $query->pluck('fuente_id')
                ->mapWithKeys(fn (string $id): array => [$id => true])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Return stable CORE request IDs already persisted, regardless of their
     * local workflow status. Used to prevent re-importing processed/archive
     * rows when the legacy fuente_id fingerprint changed.
     *
     * @return array<string,true>
     */
    public function getExistingCoreIds(): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $moduleId = $this->resolveModuleId();
        if ($moduleId === null) {
            return [];
        }

        try {
            return DB::table('redmine_mantencion_reportes')
                ->where('modulo_id', $moduleId)
                ->where('fuente', 'core')
                ->whereNotNull('id_core')
                ->where('id_core', '<>', '')
                ->pluck('id_core')
                ->mapWithKeys(fn (string $id): array => [trim($id) => true])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Physically delete report rows by their fuente_id values.
     *
     * @param  array<int,string>  $fuenteIds
     */
    public function deleteByFuenteIds(array $fuenteIds): int
    {
        if (!$this->tableReady() || empty($fuenteIds)) return 0;
        $moduleId = $this->resolveModuleId();
        if ($moduleId === null) return 0;
        return $this->deleteWithHours(DB::table('redmine_mantencion_reportes')->where('modulo_id', $moduleId)->whereIn('fuente_id', $fuenteIds));
    }

    public function deleteByIds(array $ids): int
    {
        $moduleId = $this->resolveModuleId();
        if (!$this->tableReady() || $moduleId === null || $ids === []) return 0;
        return $this->deleteWithHours(DB::table('redmine_mantencion_reportes')->where('modulo_id', $moduleId)->whereIn('id', $ids));
    }

    private function deleteWithHours(\Illuminate\Database\Query\Builder $query): int
    {
        try {
            $hours = app(\App\Modulos\Nova\Repositories\HorasExtraRepository::class);
            return $hours->atomic(function () use ($query, $hours): int {
                $ids = (clone $query)->orderBy('id')->lockForUpdate()->pluck('id');
                if ($ids->isEmpty()) return 0;
                $hours->detachReportes('mantencion', $ids);
                return (clone $query)->whereIn('id', $ids)->delete();
            });
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) throw $exception;
            return 0;
        }
    }

    public function updateRedmineStatus(string $ticketId, int $statusId, string $statusName): int
    {
        if (! $this->tableReady()) {
            return 0;
        }

        $moduleId = $this->resolveModuleId();
        $ticketId = trim($ticketId);
        if (
            $moduleId === null
            || ! preg_match('/^\d+$/', $ticketId)
            || $statusId <= 0
            || trim($statusName) === ''
        ) {
            return 0;
        }

        try {
            return DB::table('redmine_mantencion_reportes')
                ->where('modulo_id', $moduleId)
                ->where('numero_ticket_redmine', (int) $ticketId)
                ->where(function ($query) use ($statusId, $statusName): void {
                    $query
                        ->whereNull('estado_id')
                        ->orWhere('estado_id', '!=', (string) $statusId)
                        ->orWhereNull('estado_redmine')
                        ->orWhere('estado_redmine', '!=', trim($statusName));
                })
                ->update([
                    'estado_id' => (string) $statusId,
                    'estado_redmine' => trim($statusName),
                    'actualizado_at' => now(),
                ]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @param array<int,array<string,mixed>> $messages */
    public function syncMessages(array $messages, array $config = [], ?array $originalMessages = null): bool
    {
        if (! $this->tableReady()) {
            return false;
        }

        // Resolve each distinct categoria name once for the whole batch instead of
        // once per message — upsertMessage()/payload() would otherwise call
        // categoriaIdPorNombre() (Schema::hasColumn + a query) on every iteration,
        // even though most CORE imports reuse a small, fixed set of categoria names
        // across many messages. See Fase 4 lote 2.
        $categoriaIds = $this->prefetchCategoriaIds($messages);

        $originals = [];
        foreach ($originalMessages ?? [] as $original) {
            $originals[$this->messageKey($original)] = $original;
        }

        try {
            return DB::transaction(function () use ($messages, $originals, $config, $categoriaIds): bool {
                foreach ($messages as $message) {
                    if (! is_array($message)) {
                        continue;
                    }
                    $original = $originals[$this->messageKey($message)] ?? null;
                    $saved = $original !== null
                        ? $this->updateMessage($message, $original, $config, $categoriaIds)
                        : $this->upsertMessage($message, $config, $categoriaIds);
                    if (! $saved) {
                        throw new \RuntimeException('No se pudo guardar el lote de reportes.');
                    }
                }

                return true;
            });
        } catch (\Throwable $exception) {
            Log::warning('Se revirtió el lote de reportes de Mantención.', [
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    public function reserveSend(array $message, float $started): array
    {
        $moduleId = $this->resolveModuleId();
        if (!$moduleId) return ['ok' => false, 'error' => 'Módulo Mantención no disponible.'];
        $sourceId = trim((string) ($message['fuente_id'] ?? $message['id'] ?? ''));
        $query = DB::table('redmine_mantencion_reportes')->where('modulo_id', $moduleId)
            ->where('fuente', trim((string) ($message['fuente'] ?? '')) ?: null)->where('fuente_id', $sourceId);
        $row = (clone $query)->first();
        if (!$row) return ['ok' => false, 'error' => 'Reporte no encontrado.'];
        return app(\App\Repositories\Redmine\SendAttemptRepository::class)->reserve(
            $moduleId, 'mantencion:'.$row->id, $started,
            function () use ($query, $message): bool {
                $current = (clone $query)->lockForUpdate()->first();
                return $current && $current->estado !== 'archivado'
                    && (string) $current->estado === (string) ($message['estado'] ?? '')
                    && (string) ($current->numero_ticket_redmine ?? '') === (string) ($message['redmine_id'] ?? $message['numero_ticket_redmine'] ?? '');
            }
        );
    }

    private function messageKey(array $message): string
    {
        return json_encode([(string) ($message['fuente'] ?? ''), (string) ($message['fuente_id'] ?? $message['id'] ?? '')]);
    }

    /** Update only changed fields; never recreate a report deleted since the read. */
    public function updateMessage(array $message, array $original, array $config = [], ?array $categoriaIds = null): bool
    {
        $moduleId = $this->resolveModuleId();
        if ($moduleId === null || ! $this->tableReady()) {
            return false;
        }
        $sourceId = trim((string) ($original['fuente_id'] ?? $original['id'] ?? ''));
        if ($sourceId === '') {
            return false;
        }
        $categoriaIds ??= [];
        foreach ([$original, $message] as $item) {
            $name = trim((string) ($item['categoria'] ?? $item['core_tipo_solicitud'] ?? ''));
            if ($name !== '' && ! array_key_exists($name, $categoriaIds)) {
                $categoriaIds[$name] = $this->catalogs->categoriaIdPorNombre($name);
            }
        }
        $before = $this->filterColumns($this->payload($moduleId, $original, $config, $categoriaIds));
        if (array_key_exists('_core_detalle_stored', $original) && array_key_exists('core_detalle', $before)) {
            // El detalle puede haberse reconstruido desde descripcion aunque la columna siga NULL.
            $before['core_detalle'] = $original['_core_detalle_stored'];
        }
        $after = $this->filterColumns($this->payload($moduleId, $message, $config, $categoriaIds));
        $changes = [];
        foreach ($after as $column => $value) {
            if (! in_array($column, ['modulo_id', 'fuente', 'fuente_id', 'actualizado_at'], true)
                && ! $this->sameStoredValue($value, $before[$column] ?? null)) {
                $changes[$column] = $value;
            }
        }

        try {
            return DB::transaction(function () use ($moduleId, $original, $sourceId, $changes, $before, $after): bool {
                $query = DB::table('redmine_mantencion_reportes')
                    ->where('modulo_id', $moduleId)
                    ->where('fuente', trim((string) ($original['fuente'] ?? '')) ?: null)
                    ->where('fuente_id', $sourceId);
                $latest = (clone $query)->lockForUpdate()->first();
                if ($latest === null) {
                    return false;
                }
                foreach ($changes as $column => $value) {
                    if (! $this->sameStoredValue($latest->$column ?? null, $before[$column] ?? null)) {
                        return false;
                    }
                }
                // CORE refreshes may only change reports that are still pending.
                if (($original['estado'] ?? '') === 'pendiente' && ($latest->estado ?? '') !== 'pendiente') {
                    return false;
                }
                if ($changes !== []) {
                    DB::table('redmine_mantencion_reportes')->where('id', $latest->id)
                        ->update($changes + ['actualizado_at' => $after['actualizado_at'] ?? now()]);
                }

                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }

    private function sameStoredValue(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        if (is_bool($left)) {
            $left = (int) $left;
        }
        if (is_bool($right)) {
            $right = (int) $right;
        }

        if ((is_int($left) || is_float($left) || is_int($right) || is_float($right)) && is_numeric($left) && is_numeric($right)) {
            return (float) $left === (float) $right;
        }

        return (string) $left === (string) $right;
    }

    /**
     * @param  array<int,array<string,mixed>>  $messages
     * @return array<string,int|null> trimmed categoria name (same casing as payload()) => categoria_id
     */
    private function prefetchCategoriaIds(array $messages): array
    {
        $names = [];
        foreach ($messages as $message) {
            if (! is_array($message)) {
                continue;
            }
            $name = trim((string) ($message['categoria'] ?? $message['core_tipo_solicitud'] ?? ''));
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        $ids = [];
        foreach (array_keys($names) as $name) {
            $ids[$name] = $this->catalogs->categoriaIdPorNombre($name);
        }

        return $ids;
    }

    /** @return array<int,array<string,mixed>> */
    public function activeMessages(?array $reportIds = null, bool $statisticsOnly = false, bool $withDatabaseId = false, bool $withoutDescription = false): array
    {
        return $this->messagesByStatuses(['pendiente', 'procesado', 'error'], $reportIds, $statisticsOnly, false, $withDatabaseId, $withoutDescription);
    }

    /** Minimal scope/state input; retain the database ID independently of fuente_id. */
    public function dashboardCandidates(): array
    {
        return $this->messagesByStatuses(['pendiente', 'procesado', 'error'], null, false, true);
    }

    /**
     * Internal input for selected dashboard POSTs. Unselected, unprocessed
     * rows carry scope/count/identity metadata; every possible writer target and
     * every retention candidate retains its complete original report.
     */
    public function bulkActionMessages(array $publicIds, bool $includeProcessed = true): array
    {
        try {
            return DB::transaction(function () use ($publicIds, $includeProcessed): array {
                $candidates = $this->dashboardCandidates();
                if ($candidates === []) {
                    return $this->activeMessages();
                }
                $selected = array_fill_keys(array_map('trim', $publicIds), true);
                $detailIds = [];
                foreach ($candidates as $candidate) {
                    if (isset($selected[$candidate['id']]) || ($includeProcessed && strtolower($candidate['estado']) === 'procesado')) {
                        $detailIds[] = $candidate['_dashboard_id'];
                    }
                }
                if (count($detailIds) === count($candidates)) {
                    return $this->activeMessages();
                }

                $details = $this->messagesByStatuses(['pendiente', 'procesado', 'error'], $detailIds, false, false, true);
                if (count($details) !== count($detailIds)) {
                    return $this->activeMessages();
                }
                // Public fuente_id values can repeat. Join by the actual DB ID,
                // preserving every occurrence and the original fecha/ID order.
                $details = array_column($details, null, '_dashboard_id');

                return array_map(static function (array $candidate) use ($details): array {
                    $message = $details[$candidate['_dashboard_id']] ?? $candidate;
                    unset($message['_dashboard_id']);

                    return $message;
                }, $candidates);
            });
        } catch (\Throwable) {
            return $this->activeMessages();
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function archivedMessages(?array $reportIds = null, bool $statisticsOnly = false): array
    {
        return $this->messagesByStatuses(['archivado'], $reportIds, $statisticsOnly);
    }

    /** Fields consumed by the existing statistics normalizer/filter, without report text. */
    public static function statisticsColumns(): array
    {
        return ['r.id', 'r.fuente', 'r.fuente_id', 'r.fecha_reporte', 'r.fecha_inicio',
            'r.hora_reporte', 'r.id_redmine_asignado', 'r.asignado_nombre', 'r.unidad_texto',
            'r.estado', 'r.tiempo_estimado', 'c.nombre as categoria_nombre'];
    }

    /**
     * @param  array<int,string>  $statuses
     * @return array<int,array<string,mixed>>
     */
    private function messagesByStatuses(array $statuses, ?array $reportIds = null, bool $statisticsOnly = false, bool $dashboardOnly = false, bool $withDatabaseId = false, bool $withoutDescription = false): array
    {
        if (! $this->tableReady() || $statuses === [] || $reportIds === []) {
            return [];
        }

        $moduleId = $this->resolveModuleId();
        if ($moduleId === null) {
            return [];
        }

        try {
            $columns = $statisticsOnly ? self::statisticsColumns() : ['r.*', 'c.nombre as categoria_nombre'];
            if ($dashboardOnly) {
                $columns = ['r.id', 'r.fuente', 'r.fuente_id', 'r.estado', 'r.id_redmine_asignado', 'r.asignado_nombre', 'r.tiempo_estimado',
                    'r.actualizado_at', 'r.fecha_reporte', 'r.fecha_inicio', 'r.hora_reporte'];
            } elseif ($withoutDescription && ! $statisticsOnly) {
                try {
                    $availableColumns = Schema::getColumnListing('redmine_mantencion_reportes');
                    if ($availableColumns !== []) {
                        $columns = array_map(static fn (string $column): string => 'r.'.$column,
                            array_values(array_diff($availableColumns, ['descripcion'])));
                        $columns[] = 'c.nombre as categoria_nombre';
                    }
                } catch (\Throwable) {
                    // Keep the complete projection on unsupported schema metadata.
                }
            }
            $rows = DB::table('redmine_mantencion_reportes as r')
                ->leftJoin('categorias as c', 'c.id', '=', 'r.categoria_id')
                ->where('r.modulo_id', $moduleId)
                ->whereIn('r.estado', $statuses)
                ->when($reportIds !== null, function ($query) use ($reportIds): void {
                    // Keep BIGINT IDs as strings and avoid the prepared-statement
                    // parameter limit for large filtered result sets.
                    $pdo = DB::connection()->getPdo();
                    $ids = array_map(fn ($id): string => $pdo->quote((string) $id), $reportIds);
                    $query->whereRaw('r.id IN ('.implode(',', $ids).')');
                })
                ->orderByDesc('r.fecha_reporte')
                ->orderByDesc('r.id')
                ->get($columns);

            return $rows->map(function (object $row) use ($statisticsOnly, $dashboardOnly, $withDatabaseId): array {
                $message = $this->rowToMessage($row);
                if ($dashboardOnly) {
                    return array_intersect_key($message, array_flip(['id', 'estado', 'asignado_a', 'asignado_nombre', 'core_usuario_asignado',
                        'procesado_ts', 'fecha', 'fecha_inicio', 'hora', 'fuente', 'fuente_id']))
                        + ['_dashboard_id' => (string) $row->id];
                }
                if ($statisticsOnly) {
                    $message['_statistics_id'] = (string) $row->id;
                }
                if ($withDatabaseId) {
                    $message['_dashboard_id'] = (string) $row->id;
                }

                return $message;
            })->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $message
     * @param  array<string,int|null>|null  $categoriaIds  optional prefetched name=>id map
     *                                                     (see syncMessages()/prefetchCategoriaIds()); when omitted, the categoria
     *                                                     is resolved with its own query as before — safe for standalone calls.
     */
    public function upsertMessage(array $message, array $config = [], ?array $categoriaIds = null): bool
    {
        $moduleId = $this->resolveModuleId();
        if ($moduleId === null || ! $this->tableReady()) {
            return false;
        }

        $fuente = trim((string) ($message['fuente'] ?? ''));
        $fuenteId = trim((string) ($message['fuente_id'] ?? ''));
        if ($fuenteId === '') {
            $fuenteId = trim((string) ($message['id'] ?? ''));
        }
        if ($fuenteId === '') {
            return false;
        }

        $values = $this->filterColumns($this->payload($moduleId, $message, $config, $categoriaIds));

        try {
            $existing = DB::table('redmine_mantencion_reportes')
                ->where('modulo_id', $moduleId)
                ->where('fuente', $fuente !== '' ? $fuente : null)
                ->where('fuente_id', $fuenteId)
                ->first(['id', 'fuente_id']);

            $idCore = $this->idCore($message);
            if ($existing === null && $fuente === 'core' && $idCore !== null) {
                $existing = DB::table('redmine_mantencion_reportes')
                    ->where('modulo_id', $moduleId)
                    ->where('fuente', 'core')
                    ->where('id_core', $idCore)
                    ->first(['id', 'fuente_id']);
            }

            if ($existing !== null) {
                // Keep the original persistence key when an old fingerprint
                // was reconciled through the stable CORE request ID.
                $values['fuente_id'] = trim((string) ($existing->fuente_id ?? '')) ?: $fuenteId;
                DB::table('redmine_mantencion_reportes')->where('id', $existing->id)->update($values);

                return true;
            }

            $values['creado_at'] = now();
            DB::table('redmine_mantencion_reportes')->insert($values);

            return true;
        } catch (\Throwable $exception) {
            Log::error('No se pudo persistir un reporte de Mantencion.', [
                'fuente' => $fuente,
                'fuente_id' => $fuenteId,
                'exception_class' => $exception::class,
                'error_code' => (string) $exception->getCode(),
            ]);

            return false;
        }
    }

    /** @param array<string,mixed> $message */
    public function markArchived(array $message): bool
    {
        $moduleId = $this->resolveModuleId();
        if ($moduleId === null || ! $this->tableReady()) {
            return false;
        }

        $sourceId = trim((string) ($message['fuente_id'] ?? $message['id'] ?? ''));
        if ($sourceId === '') {
            return false;
        }

        // updateMessage() compares only changed columns. Archiving changes
        // estado and actualizado_at; all other payload fields are identical.
        $beforeStatus = trim((string) ($message['estado'] ?? 'pendiente')) ?: 'pendiente';
        try {
            return DB::transaction(function () use ($moduleId, $message, $sourceId, $beforeStatus): bool {
                $row = DB::table('redmine_mantencion_reportes')
                    ->where('modulo_id', $moduleId)
                    ->where('fuente', trim((string) ($message['fuente'] ?? '')) ?: null)
                    ->where('fuente_id', $sourceId)
                    ->lockForUpdate()
                    ->first(['id', 'estado']);
                if ($row === null) {
                    return false;
                }
                if ($beforeStatus !== 'archivado' && ! $this->sameStoredValue($row->estado, $beforeStatus)) {
                    return false;
                }
                if ($beforeStatus !== 'archivado') {
                    DB::table('redmine_mantencion_reportes')->where('id', $row->id)
                        ->update(['estado' => 'archivado', 'actualizado_at' => now()]);
                }

                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    public function rowToMessage(object $row): array
    {
        $fuenteId = trim((string) ($row->fuente_id ?? ''));
        $id = $fuenteId !== '' ? $fuenteId : (string) ($row->id ?? '');
        $fecha = $this->formatDateForLegacy($row->fecha_reporte ?? $row->fecha_inicio ?? null);
        $fechaInicio = $this->formatDateForLegacy($row->fecha_inicio ?? null);
        $fechaFin = $this->formatDateForLegacy($row->fecha_fin ?? null);
        $hora = $this->formatTimeForLegacy($row->hora_reporte ?? null);
        $redmineId = trim((string) ($row->numero_ticket_redmine ?? ''));
        $categoria = trim((string) ($row->categoria_nombre ?? ''));
        $unidad = trim((string) ($row->unidad_texto ?? ''));
        $estadoRedmine = trim((string) ($row->estado_redmine ?? ''));
        $asignadoNombre = trim((string) ($row->asignado_nombre ?? ''));
        $idCore = trim((string) ($row->id_core ?? ''));

        $estado = trim((string) ($row->estado ?? 'pendiente')) ?: 'pendiente';
        $procesadoTs = in_array(strtolower($estado), ['procesado', 'error', 'archivado'], true)
            ? $this->formatDateTimeForLegacy($row->actualizado_at ?? null)
            : '';

        return [
            'id' => $id,
            'fuente' => trim((string) ($row->fuente ?? '')),
            'fuente_id' => $fuenteId,
            'id_core' => $idCore,
            'core_id' => $idCore,
            'core_solicitud_id' => $idCore,
            'proyecto' => trim((string) ($row->proyecto ?? '')),
            'project_id' => trim((string) ($row->project_id ?? '')),
            'tipo' => trim((string) ($row->tipo ?? '')),
            'tipo_id' => trim((string) ($row->tipo_id ?? '')),
            'tracker_id' => trim((string) ($row->tipo_id ?? '')),
            'asunto' => trim((string) ($row->asunto ?? '')),
            'mensaje' => trim((string) ($row->asunto ?? '')),
            'descripcion' => trim((string) ($row->descripcion ?? '')),
            '_core_detalle_stored' => $row->core_detalle ?? null,
            'estado' => $estado,
            'estado_redmine' => $estadoRedmine,
            'core_estado' => $estadoRedmine,
            'status_id' => trim((string) ($row->estado_id ?? '')),
            'prioridad' => trim((string) ($row->prioridad ?? '')),
            'priority_id' => trim((string) ($row->priority_id ?? '')),
            'asignado_a' => trim((string) ($row->id_redmine_asignado ?? '')),
            'id_redmine_asignado' => trim((string) ($row->id_redmine_asignado ?? '')),
            'asignado_nombre' => $asignadoNombre,
            'core_usuario_asignado' => $asignadoNombre,
            'categoria' => $categoria,
            'solicitante' => trim((string) ($row->solicitante ?? '')),
            'anexo' => trim((string) ($row->anexo ?? '')),
            'numero' => trim((string) ($row->anexo ?? '')),
            'unidad' => $unidad,
            'unidad_texto' => $unidad,
            'core_departamento' => $unidad,
            'unidad_solicitante' => $unidad,
            'fecha' => $fecha,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'hora' => $hora,
            'core_fecha_creacion' => trim($fecha.' '.$hora),
            'tiempo_estimado' => $row->tiempo_estimado !== null ? (string) $row->tiempo_estimado : '',
            'correo' => trim((string) ($row->correo ?? '')),
            'core_email' => trim((string) ($row->correo ?? '')),
            'hora_extra' => ((int) ($row->hora_extra ?? 0)) === 1 ? '1' : '0',
            'redmine_id' => $redmineId,
            'numero_ticket_redmine' => $redmineId,
            'procesado_ts' => $procesadoTs,
            'actualizado_at' => $this->formatDateTimeForLegacy($row->actualizado_at ?? null),
        ] + ($fuenteId !== '' && trim((string) ($row->fuente ?? '')) === 'core'
            ? CoreReportDetail::decode($row->core_detalle ?? null)
                + CoreReportDetail::fromDescription((string) ($row->descripcion ?? ''))
            : []);
    }

    /** @return array<string,mixed> */
    /** @param array<string,int|null>|null $categoriaIds see upsertMessage() */
    private function payload(int $moduleId, array $message, array $config, ?array $categoriaIds = null): array
    {
        $fuente = trim((string) ($message['fuente'] ?? ''));
        $fuenteId = trim((string) ($message['fuente_id'] ?? $message['id'] ?? ''));
        $estado = trim((string) ($message['estado'] ?? 'pendiente')) ?: 'pendiente';
        $projectId = trim((string) ($message['project_id'] ?? $config['project_id'] ?? ''));
        $trackerId = trim((string) ($message['tipo_id'] ?? $message['tracker_id'] ?? $config['tracker_id'] ?? ''));
        $statusId = trim((string) ($message['status_id'] ?? $config['status_id'] ?? ''));
        $priorityId = trim((string) ($message['priority_id'] ?? $config['priority_id'] ?? ''));
        $categoriaNombre = trim((string) ($message['categoria'] ?? $message['core_tipo_solicitud'] ?? ''));
        $unidadTexto = $this->unidadTexto($message);
        $redmineId = $this->integerOrNull((string) ($message['redmine_id'] ?? $message['numero_ticket_redmine'] ?? ''));

        return [
            'modulo_id' => $moduleId,
            'fuente' => $fuente !== '' ? $fuente : null,
            'fuente_id' => $fuenteId !== '' ? $fuenteId : null,
            'id_core' => $this->idCore($message),
            'proyecto' => trim((string) ($message['proyecto'] ?? $message['project_name'] ?? $config['project_name'] ?? '')) ?: null,
            'project_id' => $projectId !== '' ? $projectId : null,
            'tipo' => trim((string) ($message['tipo'] ?? $message['core_tipo_solicitud'] ?? '')) ?: null,
            'tipo_id' => $trackerId !== '' ? $trackerId : null,
            'asunto' => trim((string) ($message['asunto'] ?? $message['mensaje'] ?? '')) ?: null,
            'descripcion' => trim((string) ($message['descripcion'] ?? '')) ?: null,
            'core_detalle' => $fuente === 'core' ? CoreReportDetail::encode($message) : null,
            'estado' => $estado,
            'estado_redmine' => trim((string) ($message['estado_redmine'] ?? $message['core_estado'] ?? '')) ?: null,
            'estado_id' => $statusId !== '' ? $statusId : null,
            'prioridad' => trim((string) ($message['prioridad'] ?? '')) ?: null,
            'priority_id' => $priorityId !== '' ? $priorityId : null,
            'id_redmine_asignado' => trim((string) ($message['asignado_a'] ?? $message['id_redmine_asignado'] ?? '')) ?: null,
            'asignado_nombre' => trim((string) ($message['asignado_nombre'] ?? $message['core_usuario_asignado'] ?? '')) ?: null,
            'categoria_id' => $categoriaNombre !== ''
                ? ($categoriaIds !== null
                    ? ($categoriaIds[$categoriaNombre] ?? null)
                    : $this->catalogs->categoriaIdPorNombre($categoriaNombre))
                : null,
            'solicitante' => trim((string) ($message['solicitante'] ?? '')) ?: null,
            'anexo' => trim((string) ($message['anexo'] ?? $message['core_telefono'] ?? $message['core_celular'] ?? $message['numero'] ?? '')) ?: null,
            'unidad_texto' => $unidadTexto !== '' ? $unidadTexto : null,
            'fecha_inicio' => $this->parseDate((string) ($message['fecha_inicio'] ?? $message['fecha'] ?? '')),
            'fecha_fin' => $this->parseDate((string) ($message['fecha_fin'] ?? $message['fecha_inicio'] ?? $message['fecha'] ?? '')),
            'fecha_reporte' => $this->parseDate((string) ($message['fecha'] ?? $message['core_fecha_creacion'] ?? '')),
            'hora_reporte' => $this->parseTime((string) ($message['hora'] ?? $message['core_fecha_creacion'] ?? '')),
            'tiempo_estimado' => $this->decimalOrNull((string) ($message['tiempo_estimado'] ?? '')),
            'correo' => trim((string) ($message['core_email'] ?? $message['correo'] ?? '')) ?: null,
            'hora_extra' => $this->truthy((string) ($message['hora_extra'] ?? '')),
            'numero_ticket_redmine' => $redmineId,
            // En Mantencion, procesado_ts se proyecta desde actualizado_at. Al
            // resincronizar la cola completa (por ejemplo, tras importar CORE),
            // conservar esa fecha evita reiniciar la retencion de reportes que
            // ya estaban procesados o con error.
            'actualizado_at' => $this->workflowTimestamp($estado, $message['procesado_ts'] ?? null),
        ];
    }

    private function workflowTimestamp(string $estado, mixed $procesadoTs): mixed
    {
        if (! in_array(strtolower(trim($estado)), ['procesado', 'error', 'archivado'], true)) {
            return now();
        }

        $value = trim((string) $procesadoTs);
        if ($value === '') {
            return now();
        }

        try {
            return Carbon::parse($value)
                ->setTimezone((string) config('app.timezone', 'UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return now();
        }
    }

    /** @return array<string,mixed> */
    private function filterColumns(array $values): array
    {
        foreach (array_keys($values) as $column) {
            if (! array_key_exists($column, $this->columnAvailableCache)) {
                $this->columnAvailableCache[$column] = Schema::hasColumn('redmine_mantencion_reportes', $column);
            }
            if (! $this->columnAvailableCache[$column]) {
                unset($values[$column]);
            }
        }

        return $values;
    }

    private function unidadTexto(array $message): string
    {
        foreach (['unidad', 'unidad_texto', 'core_departamento', 'unidad_solicitante', 'core_establecimiento'] as $key) {
            $value = trim((string) ($message[$key] ?? ''));
            if ($value !== '' && strtoupper($value) !== 'N/A') {
                return $value;
            }
        }

        return '';
    }

    private function idCore(array $message): ?string
    {
        foreach (['id_core', 'core_id'] as $key) {
            $value = trim((string) ($message[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        if (($message['fuente'] ?? '') === 'core') {
            $value = trim((string) ($message['core_solicitud_id'] ?? $message['fuente_id'] ?? ''));

            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function parseDate(string $value): ?string
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

    private function parseTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['H:i:s', 'H:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i'] as $format) {
            try {
                return Carbon::createFromFormat($format, substr($value, 0, strlen($format)))->format('H:i:s');
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function decimalOrNull(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) ? (float) $value : null;
    }

    private function integerOrNull(string $value): ?int
    {
        $value = trim($value);

        return ctype_digit($value) ? (int) $value : null;
    }

    private function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'si', 's', 'true', 'yes'], true);
    }

    private function formatDateForLegacy(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return trim((string) $value);
        }
    }

    private function formatTimeForLegacy(mixed $value): string
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

    private function formatDateTimeForLegacy(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }

        try {
            return Carbon::parse((string) $value)->toAtomString();
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
