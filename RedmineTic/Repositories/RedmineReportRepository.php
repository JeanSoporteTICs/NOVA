<?php

namespace RedmineTic\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thin wrapper for redmine_tic_reportes table operations that are isolated
 * from catalog lookups and user-hydration.
 *
 * ETAPA B / Lote B5.1: DB<->array mapping (hydrate/payload) now lives here.
 * assignedUserName() resolution stays a facade/Users-domain concern — the
 * caller passes it in as a callback so this class never depends on
 * RedmineUserRepository. parseDate()/parseTime()/databaseDate()/databaseTime()
 * are intentionally duplicated (private, below) rather than shared back with
 * RedmineDataRepository, matching the exact precedent already established by
 * RedmineHoursExtraRepository for the same pair of helpers — those stay in
 * the facade because RedmineHoursExtraRepository/hoursExtraFromDatabase()
 * (HorasExtra domain) also depend on them.
 *
 * The bulk of report CRUD orchestration (saveActiveReportsToDatabase,
 * individual/mass actions) remains in RedmineDataRepository pending B5.2/B5.3.
 */
class RedmineReportRepository
{
    private ?RedmineCatalogRepository $catalogRepoInst = null;
    private ?bool $tableAvailableCache = null;

    public function __construct(
        private string $projectKey,
        private string $projectName,
    ) {}

    /**
     * DB row -> public report array. $assignedNameResolver receives the raw
     * asignado_a value and must return the display name (kept as a callback
     * so this class never depends on RedmineUserRepository directly).
     *
     * @return array<string,mixed>
     */
    public function hydrate(object $row, callable $assignedNameResolver): array
    {
        $estado = (string) ($row->estado ?? '');
        $processedAt = $row->procesado_at ?? null;
        if ($processedAt === null && in_array(strtolower(trim($estado)), ['procesado', 'procesada', 'error', 'archivado'], true)) {
            $processedAt = $row->actualizado_at ?? null;
        }

        return [
            'id' => (string) ($row->id ?? ''),
            'redmine_id' => (string) ($row->redmine_id ?? ''),
            'estado' => $estado,
            'estado_redmine' => (string) ($row->estado_redmine ?? ''),
            'tipo' => (string) ($row->tipo ?? ''),
            'prioridad' => (string) ($row->prioridad ?? ''),
            'categoria' => $this->catalogRepo()->nameById($row->categoria_catalogo_id ?? null) ?: (string) ($row->categoria ?? ''),
            'unidad' => $this->catalogRepo()->nameById($row->unidad_catalogo_id ?? null) ?: (string) ($row->unidad ?? ''),
            'unidad_solicitante_catalogo_id' => (int) ($row->unidad_solicitante_catalogo_id ?? 0),
            'unidad_solicitante' => $this->catalogRepo()->nameById($row->unidad_solicitante_catalogo_id ?? null) ?: (string) ($row->unidad_solicitante ?? ''),
            'solicitante' => (string) ($row->solicitante ?? ''),
            'asunto' => (string) ($row->asunto ?? ''),
            'descripcion' => (string) ($row->descripcion ?? ''),
            'fecha' => $this->databaseDate($row->fecha ?? null),
            'hora' => $this->databaseTime($row->hora ?? null),
            'fecha_inicio' => $this->databaseDate($row->fecha_inicio ?? null),
            'fecha_fin' => $this->databaseDate($row->fecha_fin ?? null),
            'chat_id_telegram' => (string) ($row->chat_id_telegram ?? ''),
            'mensaje' => (string) ($row->mensaje ?? ''),
            'asignado_a' => (string) ($row->asignado_a ?? ''),
            'asignado_nombre' => $assignedNameResolver((string) ($row->asignado_a ?? '')),
            'hora_extra' => !empty($row->hora_extra) ? 'SI' : 'NO',
            'tiempo_estimado' => $row->tiempo_estimado !== null ? (string) $row->tiempo_estimado : '',
            'origen' => (string) ($row->origen ?? ''),
            'procesado_ts' => $processedAt !== null ? (string) $processedAt : '',
            'created_at' => $row->creado_at !== null ? (string) $row->creado_at : '',
        ];
    }

    /**
     * Public report array -> DB payload ready for insert/update.
     *
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public function payload(int $moduleId, array $report, bool $archived): array
    {
        $estado = $archived ? 'archivado' : (trim((string) ($report['estado'] ?? 'pendiente')) ?: 'pendiente');

        return [
            'modulo_id' => $moduleId,
            'redmine_id' => $this->unsignedIntegerOrNull($report['redmine_id'] ?? null),
            'estado' => $estado,
            'estado_redmine' => trim((string) ($report['estado_redmine'] ?? '')) ?: null,
            'tipo' => trim((string) ($report['tipo'] ?? '')) ?: null,
            'prioridad' => trim((string) ($report['prioridad'] ?? '')) ?: null,
            'categoria_catalogo_id' => $this->catalogRepo()->idForValue('categoria', $report['categoria'] ?? ''),
            'unidad_catalogo_id' => $this->catalogRepo()->idForValue('unidad', $report['unidad'] ?? ''),
            'unidad_solicitante_catalogo_id' => $this->catalogRepo()->idForValue('unidad', $report['unidad_solicitante'] ?? ''),
            'solicitante' => trim((string) ($report['solicitante'] ?? '')) ?: null,
            'asunto' => trim((string) ($report['asunto'] ?? '')) ?: null,
            'descripcion' => trim((string) ($report['descripcion'] ?? '')) ?: null,
            'fecha' => $this->parseDate($report['fecha'] ?? $report['fecha_inicio'] ?? '') ?: null,
            'hora' => $this->parseTime($report['hora'] ?? ''),
            'fecha_inicio' => $this->parseDate($report['fecha_inicio'] ?? $report['fecha'] ?? '') ?: null,
            'fecha_fin' => $this->parseDate($report['fecha_fin'] ?? '') ?: null,
            'chat_id_telegram' => trim((string) ($report['chat_id_telegram'] ?? $report['numero'] ?? '')) ?: null,
            'mensaje' => trim((string) ($report['mensaje'] ?? '')) ?: null,
            'asignado_a' => $this->unsignedIntegerOrNull($report['asignado_a'] ?? null),
            'hora_extra' => $this->isHoursExtraReport($report) ? 1 : 0,
            'tiempo_estimado' => $this->decimalHours($report['tiempo_estimado'] ?? null),
            'origen' => trim((string) ($report['origen'] ?? '')) ?: null,
            'procesado_at' => $this->parseDateTime($report['procesado_ts'] ?? ''),
            'actualizado_at' => now(),
        ];
    }

    /** @param array<string,mixed> $report */
    public function isHoursExtraReport(array $report): bool
    {
        return in_array(strtolower((string) ($report['hora_extra'] ?? '')), ['si', 'sí', 'sÃ­', '1', 'true'], true);
    }

    public function decimalHours($value): ?float
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        if (preg_match('/^(\d{1,3}):([0-5]\d)$/', $value, $matches)) {
            return round((float) $matches[1] + ((float) $matches[2] / 60), 2);
        }

        return null;
    }

    public function unsignedIntegerOrNull($value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        $number = (int) $value;

        return $number > 0 ? $number : null;
    }

    public function parseDateTime($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private function catalogRepo(): RedmineCatalogRepository
    {
        return $this->catalogRepoInst ??= new RedmineCatalogRepository($this->projectKey, $this->projectName);
    }

    // ---- small date/time utilities (duplicated from RedmineDataRepository,
    // same precedent as RedmineHoursExtraRepository::parseDate()/parseTime()) ----

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

    private function databaseDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $this->parseDate($value);
    }

    private function databaseTime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $time = $this->parseTime($value);

        return $time !== null ? substr($time, 0, 5) : '';
    }

    public function tableAvailable(): bool
    {
        if ($this->tableAvailableCache !== null) {
            return $this->tableAvailableCache;
        }

        try {
            return $this->tableAvailableCache = Schema::hasTable('modulos_nova') && Schema::hasTable('redmine_tic_reportes');
        } catch (\Throwable) {
            return $this->tableAvailableCache = false;
        }
    }

    /**
     * Converts a report id value to its numeric DB id, or 0 when invalid.
     */
    public function reportDatabaseId($value): int
    {
        $id = trim((string) $value);

        return ctype_digit($id) ? (int) $id : 0;
    }

    /**
     * Finds one active (non-archived) report row by id and hydrates it.
     * $assignedNameResolver is forwarded to hydrate() unchanged.
     *
     * @return array<string,mixed>|null
     */
    public function findActiveById(int $moduleId, string $id, callable $assignedNameResolver): ?array
    {
        if (!$this->tableAvailable() || $moduleId <= 0) {
            return null;
        }

        $reportId = $this->reportDatabaseId($id);
        if ($reportId <= 0) {
            return null;
        }

        try {
            $row = DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->where('id', $reportId)
                ->where(function ($query): void {
                    $query->whereNull('estado')->orWhere('estado', '<>', 'archivado');
                })
                ->first();

            return $row === null ? null : $this->hydrate($row, $assignedNameResolver);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Finds and hydrates every active (non-archived) report matching the
     * given ids — a single WHERE id IN (...) instead of hydrating every
     * active report and filtering in PHP. Ids that don't parse to a
     * positive integer, or that don't match any existing active row, are
     * silently absent from the result (same as the pre-extraction
     * in_array()-based filtering, which never matched them either).
     *
     * @param string[] $ids
     * @return array<int,array<string,mixed>>
     */
    public function findActiveByIds(int $moduleId, array $ids, callable $assignedNameResolver): array
    {
        if (!$this->tableAvailable() || $moduleId <= 0 || $ids === []) {
            return [];
        }

        $reportIds = array_values(array_unique(array_filter(
            array_map(fn ($id): int => $this->reportDatabaseId($id), $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ($reportIds === []) {
            return [];
        }

        try {
            return DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->whereIn('id', $reportIds)
                ->where(function ($query): void {
                    $query->whereNull('estado')->orWhere('estado', '<>', 'archivado');
                })
                ->get()
                ->map(fn ($row): array => $this->hydrate($row, $assignedNameResolver))
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Finds and hydrates every active (non-archived) report whose estado is
     * in $states — a single WHERE estado IN (...) instead of hydrating the
     * entire active set and filtering by state in PHP.
     *
     * @param string[] $states
     * @return array<int,array<string,mixed>>
     */
    public function findActiveByStates(int $moduleId, array $states, callable $assignedNameResolver): array
    {
        if (!$this->tableAvailable() || $moduleId <= 0 || $states === []) {
            return [];
        }

        try {
            return DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->whereIn('estado', $states)
                ->get()
                ->map(fn ($row): array => $this->hydrate($row, $assignedNameResolver))
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Punctual-per-selection DELETE: removes only the active reports whose
     * id is in $ids, via a single WHERE id IN (...) — never touches rows
     * outside the selection. Returns the number of rows actually deleted
     * (ids that don't match any existing active row simply don't count).
     *
     * @param string[] $ids
     */
    public function deleteActiveByIds(int $moduleId, array $ids): int
    {
        if (!$this->tableAvailable() || $moduleId <= 0 || $ids === []) {
            return 0;
        }

        $reportIds = array_values(array_unique(array_filter(
            array_map(fn ($id): int => $this->reportDatabaseId($id), $ids),
            static fn (int $id): bool => $id > 0
        )));
        if ($reportIds === []) {
            return 0;
        }

        try {
            return DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->whereIn('id', $reportIds)
                ->where(function ($query): void {
                    $query->whereNull('estado')->orWhere('estado', '<>', 'archivado');
                })
                ->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Punctual UPDATE of an active report's columns by PK. Returns whether
     * the statement executed without error — matching the pre-extraction
     * behavior, this does NOT check the affected-row count.
     *
     * @param array<string,mixed> $values
     */
    public function updateActiveFields(int $moduleId, string $id, array $values): bool
    {
        if (!$this->tableAvailable() || $moduleId <= 0) {
            return false;
        }

        $reportId = $this->reportDatabaseId($id);
        if ($reportId <= 0) {
            return false;
        }

        try {
            DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->where('id', $reportId)
                ->where(function ($query): void {
                    $query->whereNull('estado')->orWhere('estado', '<>', 'archivado');
                })
                ->update($values);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Punctual UPDATE of only the hora_extra/tiempo_estimado columns.
     */
    public function updateActiveHoursExtraFlag(int $moduleId, string $id, bool $enabled): bool
    {
        if (!$this->tableAvailable() || $moduleId <= 0) {
            return false;
        }

        $reportId = $this->reportDatabaseId($id);
        if ($reportId <= 0) {
            return false;
        }

        try {
            DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->where('id', $reportId)
                ->where(function ($query): void {
                    $query->whereNull('estado')->orWhere('estado', '<>', 'archivado');
                })
                ->update([
                    'hora_extra' => $enabled ? 1 : 0,
                    'tiempo_estimado' => $enabled ? $this->decimalHours('1') : null,
                    'actualizado_at' => now(),
                ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Punctual DELETE of a single active report row by PK.
     */
    public function deleteActiveById(int $moduleId, string $id): int
    {
        if (!$this->tableAvailable() || $moduleId <= 0) {
            return 0;
        }

        $reportId = $this->reportDatabaseId($id);
        if ($reportId <= 0) {
            return 0;
        }

        try {
            return DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->where('id', $reportId)
                ->where(function ($query): void {
                    $query->whereNull('estado')->orWhere('estado', '<>', 'archivado');
                })
                ->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Punctual INSERT of a brand-new report. Returns null on failure (table
     * missing or a DB exception) so the caller can fall back to the input
     * array unchanged without invalidating any cache.
     *
     * @param array<string,mixed> $report
     * @return array<string,mixed>|null
     */
    public function insertReport(int $moduleId, array $report, bool $archived): ?array
    {
        if (!$this->tableAvailable()) {
            return null;
        }

        try {
            $id = (int) DB::table('redmine_tic_reportes')->insertGetId($this->payload($moduleId, $report, $archived));

            return array_merge($report, ['id' => (string) $id]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Punctual upsert-to-archived for one report: UPDATE by PK if it already
     * has a numeric id, INSERT otherwise. Used both by the individual
     * archive-one-report flow and by the maintenance bundle restore (each
     * report is still processed one at a time).
     *
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    public function upsertArchived(int $moduleId, array $report): array
    {
        if (!$this->tableAvailable()) {
            return $report;
        }

        try {
            $payload = $this->payload($moduleId, $report, true);
            $reportId = $this->reportDatabaseId($report['id'] ?? null);
            if ($reportId > 0) {
                DB::table('redmine_tic_reportes')
                    ->where('modulo_id', $moduleId)
                    ->where('id', $reportId)
                    ->update($payload);
            } else {
                $reportId = (int) DB::table('redmine_tic_reportes')->insertGetId($payload);
                $report['id'] = (string) $reportId;
            }
        } catch (\Throwable) {
        }

        return $report;
    }

    /**
     * Hard-deletes a single report row by numeric DB id.
     */
    public function deleteRow(int $moduleId, int $reportId): int
    {
        if (!$this->tableAvailable() || $moduleId <= 0 || $reportId <= 0) {
            return 0;
        }

        try {
            return DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->where('id', $reportId)
                ->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Hard-deletes the single archived report row identified by string $id.
     */
    public function deleteArchived(string $id): int
    {
        if (!$this->tableAvailable() || trim($id) === '') {
            return 0;
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return 0;
        }

        try {
            return DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->where('id', (int) $id)
                ->where('estado', 'archivado')
                ->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function updateArchivedRedmineStatus(string $redmineId, string $statusName): int
    {
        $redmineId = trim($redmineId);
        $statusName = trim($statusName);
        if (
            !$this->tableAvailable()
            || !preg_match('/^\d+$/', $redmineId)
            || $statusName === ''
        ) {
            return 0;
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return 0;
        }

        try {
            return DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->where('redmine_id', (int) $redmineId)
                ->where('estado', 'archivado')
                ->update([
                    'estado_redmine' => $statusName,
                    'actualizado_at' => now(),
                ]);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Hard-deletes all active (non-archived) report rows not in $keepIds.
     */
    public function deleteActiveExcept(int $moduleId, array $keepIds): void
    {
        if (!$this->tableAvailable() || $moduleId <= 0) {
            return;
        }

        try {
            $query = DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->where(function ($q): void {
                    $q->whereNull('estado')->orWhere('estado', '<>', 'archivado');
                });

            if ($keepIds !== []) {
                $query->whereNotIn('id', $keepIds);
            }

            $query->delete();
        } catch (\Throwable) {
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
