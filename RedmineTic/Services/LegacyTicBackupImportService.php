<?php

namespace RedmineTic\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RedmineTic\Repositories\RedmineActivityRepository;
use RuntimeException;

final class LegacyTicBackupImportService
{
    private const ORIGIN = 'legacy-tic-20260725';

    /**
     * @param array<int,int|string> $assigneeIds
     * @return array<string,mixed>
     */
    public function analyze(string $root, array $assigneeIds = [117, 122]): array
    {
        $package = $this->loadPackage($root);
        $assignees = $this->normalizeAssignees($assigneeIds);
        $reports = $this->selectedReports($package['archived'], $assignees);
        $hours = $this->selectedHoursGroups($package['hours'], $assignees);
        $moduleId = $this->moduleId();
        $users = $this->usersByRedmineId($assignees);

        $tickets = array_values(array_unique(array_filter(array_map(
            static fn (array $report): string => trim((string) ($report['redmine_id'] ?? '')),
            $reports
        ))));
        $legacyIds = array_values(array_unique(array_filter(array_map(
            static fn (array $report): string => trim((string) ($report['id'] ?? '')),
            $reports
        ))));
        $existingTickets = $tickets === []
            ? []
            : DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->whereIn('redmine_id', $tickets)
                ->pluck('redmine_id')
                ->map(static fn ($id): string => (string) $id)
                ->all();

        $hourLinks = 0;
        $hourLegacyIds = [];
        $hourDatesByAssignee = [];
        foreach ($hours as $group) {
            foreach ($group['reports'] as $report) {
                $hourLinks++;
                $legacyId = trim((string) ($report['id'] ?? ''));
                if ($legacyId !== '') {
                    $hourLegacyIds[$legacyId] = true;
                }
                $redmineId = trim((string) ($report['asignado_a'] ?? ''));
                $date = trim((string) ($group['fecha'] ?? ''));
                if ($redmineId !== '' && $date !== '') {
                    $hourDatesByAssignee[$redmineId][$date] = true;
                }
            }
        }

        $groupCollisions = 0;
        foreach ($hourDatesByAssignee as $redmineId => $dates) {
            $userId = $users[$redmineId]['id'] ?? null;
            if ($userId === null) {
                continue;
            }
            $groupCollisions += DB::table('horas_extra_grupos')
                ->where('usuario_id', $userId)
                ->whereIn('fecha', array_keys($dates))
                ->count();
        }

        $byAssignee = [];
        foreach ($assignees as $redmineId) {
            $assignedReports = array_values(array_filter(
                $reports,
                static fn (array $report): bool => trim((string) ($report['asignado_a'] ?? '')) === $redmineId
            ));
            $assignedHours = 0;
            $assignedHourReports = [];
            $assignedHourDates = [];
            foreach ($hours as $group) {
                foreach ($group['reports'] as $report) {
                    if (trim((string) ($report['asignado_a'] ?? '')) !== $redmineId) {
                        continue;
                    }
                    $assignedHours++;
                    $assignedHourReports[trim((string) ($report['id'] ?? ''))] = true;
                    $assignedHourDates[trim((string) ($group['fecha'] ?? ''))] = true;
                }
            }

            $byAssignee[$redmineId] = [
                'user' => $users[$redmineId] ?? null,
                'archived_reports' => count($assignedReports),
                'hour_links' => $assignedHours,
                'unique_hour_reports' => count(array_filter(array_keys($assignedHourReports))),
                'hour_dates' => count(array_filter(array_keys($assignedHourDates))),
            ];
        }

        $catalog = $this->catalogSummary($moduleId, $reports);

        return [
            'source' => $package['root'],
            'manifest_type' => $package['manifest_type'],
            'pending_excluded' => $package['pending_count'],
            'assignees' => $byAssignee,
            'selected_reports' => count($reports),
            'unique_legacy_ids' => count($legacyIds),
            'unique_redmine_tickets' => count($tickets),
            'duplicate_legacy_ids' => count($reports) - count($legacyIds),
            'duplicate_redmine_tickets' => count($reports) - count($tickets),
            'existing_ticket_matches' => count(array_unique($existingTickets)),
            'would_insert_reports' => count($reports) - count(array_unique($existingTickets)),
            'selected_hour_groups' => count($hours),
            'selected_hour_links' => $hourLinks,
            'unique_hour_reports' => count($hourLegacyIds),
            'existing_hour_group_collisions' => $groupCollisions,
            'catalogs' => $catalog,
        ];
    }

    /**
     * @param array<int,int|string> $assigneeIds
     * @return array<string,mixed>
     */
    public function import(string $root, array $assigneeIds = [117, 122]): array
    {
        $summary = $this->analyze($root, $assigneeIds);
        if ($summary['duplicate_legacy_ids'] !== 0 || $summary['duplicate_redmine_tickets'] !== 0) {
            throw new RuntimeException('El respaldo contiene IDs duplicados en la seleccion.');
        }

        $package = $this->loadPackage($root);
        $assignees = $this->normalizeAssignees($assigneeIds);
        $reports = $this->selectedReports($package['archived'], $assignees);
        $hours = $this->selectedHoursGroups($package['hours'], $assignees);
        $moduleId = $this->moduleId();
        $users = $this->usersByRedmineId($assignees);
        foreach ($assignees as $redmineId) {
            if (!isset($users[$redmineId])) {
                throw new RuntimeException("No existe un usuario NOVA para Redmine ID {$redmineId}.");
            }
        }

        return DB::transaction(function () use ($reports, $hours, $moduleId, $users, $summary): array {
            $hourLegacyIds = [];
            foreach ($hours as $group) {
                foreach ($group['reports'] as $report) {
                    $legacyId = trim((string) ($report['id'] ?? ''));
                    if ($legacyId !== '') {
                        $hourLegacyIds[$legacyId] = true;
                    }
                }
            }

            $catalogs = $this->catalogResolver($moduleId);
            $tickets = array_values(array_unique(array_map(
                static fn (array $report): int => (int) $report['redmine_id'],
                $reports
            )));
            $existingByTicket = DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->whereIn('redmine_id', $tickets)
                ->get(['id', 'redmine_id'])
                ->keyBy(static fn (object $row): string => (string) $row->redmine_id);

            $legacyToDatabaseId = [];
            $inserted = 0;
            $skipped = 0;
            foreach ($reports as $report) {
                $legacyId = trim((string) $report['id']);
                $ticket = trim((string) $report['redmine_id']);
                $existing = $existingByTicket->get($ticket);
                if ($existing !== null) {
                    $legacyToDatabaseId[$legacyId] = (int) $existing->id;
                    $skipped++;
                    continue;
                }

                $isHoursExtra = isset($hourLegacyIds[$legacyId]);
                $reportId = (int) DB::table('redmine_tic_reportes')->insertGetId([
                    'modulo_id' => $moduleId,
                    'redmine_id' => (int) $ticket,
                    'estado' => 'archivado',
                    'estado_redmine' => $this->nullableText($report['estado_redmine'] ?? '', 40),
                    'tipo' => $this->nullableText($report['tipo'] ?? '', 40),
                    'prioridad' => $this->nullableText($report['prioridad'] ?? '', 20),
                    'categoria_catalogo_id' => $this->resolveCatalogId($catalogs, $moduleId, 'categoria', $report['categoria'] ?? ''),
                    'unidad_catalogo_id' => $this->resolveCatalogId($catalogs, $moduleId, 'unidad', $report['unidad'] ?? ''),
                    'unidad_solicitante_catalogo_id' => $this->resolveCatalogId($catalogs, $moduleId, 'unidad', $report['unidad_solicitante'] ?? ''),
                    'solicitante' => $this->nullableText($report['solicitante'] ?? '', 255),
                    'asunto' => $this->nullableLongText($report['asunto'] ?? $report['mensaje'] ?? ''),
                    'descripcion' => $this->nullableLongText($report['descripcion'] ?? ''),
                    'fecha' => $this->parseDate($report['fecha'] ?? null),
                    'hora' => $this->parseTime($report['hora'] ?? null),
                    'fecha_inicio' => $this->parseDate($report['fecha_inicio'] ?? $report['fecha'] ?? null),
                    'fecha_fin' => $this->parseDate($report['fecha_fin'] ?? null),
                    'chat_id_telegram' => $this->nullableText($report['numero'] ?? '', 120),
                    'mensaje' => $this->nullableLongText($report['mensaje'] ?? ''),
                    'asignado_a' => trim((string) $report['asignado_a']),
                    'hora_extra' => $isHoursExtra ? 1 : 0,
                    'tiempo_estimado' => $this->decimalOrNull($report['tiempo_estimado'] ?? null),
                    'origen' => self::ORIGIN,
                    'procesado_at' => $this->parseDateTime($report['procesado_ts'] ?? null),
                    'creado_at' => $this->reportCreatedAt($report),
                    'actualizado_at' => $this->parseDateTime($report['_archivado_en'] ?? null) ?? now(),
                ]);
                $legacyToDatabaseId[$legacyId] = $reportId;
                $existingByTicket->put($ticket, (object) ['id' => $reportId, 'redmine_id' => $ticket]);
                $inserted++;
            }

            $groupsCreated = 0;
            $groupsReused = 0;
            $linksCreated = 0;
            $linksExisting = 0;
            foreach ($hours as $legacyGroup) {
                $reportsByAssignee = [];
                foreach ($legacyGroup['reports'] as $report) {
                    $redmineId = trim((string) ($report['asignado_a'] ?? ''));
                    if ($redmineId !== '') {
                        $reportsByAssignee[$redmineId][] = $report;
                    }
                }

                foreach ($reportsByAssignee as $redmineId => $groupReports) {
                    $date = $this->parseDate($legacyGroup['fecha'] ?? null);
                    if ($date === null) {
                        throw new RuntimeException('Un grupo de horas extra seleccionado no tiene fecha valida.');
                    }
                    $userId = (int) $users[$redmineId]['id'];
                    $groupId = DB::table('horas_extra_grupos')
                        ->where('usuario_id', $userId)
                        ->where('fecha', $date)
                        ->value('id');
                    $start = $this->parseTime($legacyGroup['hora_inicio'] ?? null);
                    $end = $this->parseTime($legacyGroup['hora_fin'] ?? null);

                    if ($groupId === null) {
                        $groupId = DB::table('horas_extra_grupos')->insertGetId([
                            'usuario_id' => $userId,
                            'fecha' => $date,
                            'hora_inicio' => $start,
                            'hora_fin' => $end,
                            'total_minutos' => $this->minutesDiff($start, $end),
                            'creado_at' => now(),
                            'actualizado_at' => now(),
                        ]);
                        $groupsCreated++;
                    } else {
                        $groupsReused++;
                        $current = DB::table('horas_extra_grupos')->where('id', $groupId)->first(['hora_inicio', 'hora_fin']);
                        $updates = [];
                        if (($current->hora_inicio ?? null) === null && $start !== null) {
                            $updates['hora_inicio'] = $start;
                        }
                        if (($current->hora_fin ?? null) === null && $end !== null) {
                            $updates['hora_fin'] = $end;
                        }
                        if ($updates !== []) {
                            $finalStart = $updates['hora_inicio'] ?? $current->hora_inicio;
                            $finalEnd = $updates['hora_fin'] ?? $current->hora_fin;
                            $updates['total_minutos'] = $this->minutesDiff($finalStart, $finalEnd);
                            $updates['actualizado_at'] = now();
                            DB::table('horas_extra_grupos')->where('id', $groupId)->update($updates);
                        }
                    }

                    foreach ($groupReports as $report) {
                        $legacyId = trim((string) ($report['id'] ?? ''));
                        $reportId = $legacyToDatabaseId[$legacyId] ?? null;
                        if ($reportId === null) {
                            throw new RuntimeException("No se pudo resolver el reporte legacy {$legacyId} para horas extra.");
                        }
                        $exists = DB::table('horas_extra_grupo_reportes')
                            ->where('grupo_id', $groupId)
                            ->where('origen', 'tic')
                            ->where('reporte_id', $reportId)
                            ->exists();
                        if ($exists) {
                            $linksExisting++;
                        } else {
                            DB::table('horas_extra_grupo_reportes')->insert([
                                'grupo_id' => $groupId,
                                'origen' => 'tic',
                                'reporte_id' => $reportId,
                                'creado_at' => now(),
                                'actualizado_at' => now(),
                            ]);
                            $linksCreated++;
                        }
                        DB::table('redmine_tic_reportes')->where('id', $reportId)->update(['hora_extra' => 1]);
                    }
                }
            }

            (new RedmineActivityRepository('redmine_tic', 'Backlog Soporte TI'))->append(
                'importacion_legacy_tic',
                [
                    'origen' => self::ORIGIN,
                    'reportes_insertados' => $inserted,
                    'reportes_omitidos' => $skipped,
                    'grupos_creados' => $groupsCreated,
                    'grupos_reutilizados' => $groupsReused,
                    'relaciones_creadas' => $linksCreated,
                ],
            );

            return array_merge($summary, [
                'inserted_reports' => $inserted,
                'skipped_existing_reports' => $skipped,
                'created_hour_groups' => $groupsCreated,
                'reused_hour_groups' => $groupsReused,
                'created_hour_links' => $linksCreated,
                'existing_hour_links' => $linksExisting,
            ]);
        }, 3);
    }

    /**
     * @return array{root:string,manifest_type:string,pending_count:int,archived:array<int,array<string,mixed>>,hours:array<int,array<string,mixed>>}
     */
    private function loadPackage(string $root): array
    {
        $root = realpath($root) ?: '';
        if ($root === '' || !is_dir($root)) {
            throw new RuntimeException('No existe la carpeta del respaldo legacy TIC.');
        }

        $manifestPath = $root . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($manifestPath)) {
            throw new RuntimeException('El respaldo no contiene manifest.json.');
        }
        $manifest = $this->readJson($manifestPath);
        $manifestType = trim((string) ($manifest['type'] ?? ''));
        if (!in_array($manifestType, ['redmine-maintenance-package', 'redmine-tic-maintenance-package'], true)) {
            throw new RuntimeException('El manifiesto no corresponde al formato legacy TIC esperado.');
        }

        $dataRoot = $root . DIRECTORY_SEPARATOR . 'data';
        $archived = $this->readSectionFiles($dataRoot, $manifest['sections']['archivados']['files'] ?? []);
        $hours = $this->readSectionFiles($dataRoot, $manifest['sections']['horas_extras']['files'] ?? []);
        $pendingPath = $dataRoot . DIRECTORY_SEPARATOR . 'mensaje.json';
        $pending = is_file($pendingPath) ? $this->readJson($pendingPath) : [];

        return [
            'root' => $root,
            'manifest_type' => $manifestType,
            'pending_count' => is_array($pending) ? count($pending) : 0,
            'archived' => $archived,
            'hours' => $hours,
        ];
    }

    /**
     * @param mixed $files
     * @return array<int,array<string,mixed>>
     */
    private function readSectionFiles(string $dataRoot, mixed $files): array
    {
        if (!is_array($files) || $files === []) {
            throw new RuntimeException('El manifiesto no contiene todos los archivos requeridos.');
        }

        $result = [];
        foreach ($files as $relative) {
            $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
            if ($relative === '' || str_contains($relative, '..') || strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'json') {
                throw new RuntimeException('El manifiesto contiene una ruta no permitida.');
            }
            $path = $dataRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path)) {
                throw new RuntimeException("Falta el archivo {$relative}.");
            }
            $rows = $this->readJson($path);
            if (!is_array($rows)) {
                throw new RuntimeException("El archivo {$relative} no contiene una lista JSON.");
            }
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $result[] = $row;
                }
            }
        }

        return $result;
    }

    /** @return array<mixed> */
    private function readJson(string $path): array
    {
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new RuntimeException('JSON invalido en ' . basename($path) . ': ' . $e->getMessage(), 0, $e);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<int,int|string> $assigneeIds @return array<int,string> */
    private function normalizeAssignees(array $assigneeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $assigneeIds
        ), static fn (string $id): bool => ctype_digit($id))));
        if ($ids === []) {
            throw new RuntimeException('Debes indicar al menos un ID Redmine para importar.');
        }

        return $ids;
    }

    /** @param array<int,array<string,mixed>> $reports @param array<int,string> $assignees @return array<int,array<string,mixed>> */
    private function selectedReports(array $reports, array $assignees): array
    {
        return array_values(array_filter($reports, static function (array $report) use ($assignees): bool {
            $assignee = trim((string) ($report['asignado_a'] ?? ''));
            $legacyId = trim((string) ($report['id'] ?? ''));
            $ticket = trim((string) ($report['redmine_id'] ?? ''));

            return in_array($assignee, $assignees, true) && $legacyId !== '' && ctype_digit($ticket);
        }));
    }

    /** @param array<int,array<string,mixed>> $groups @param array<int,string> $assignees @return array<int,array<string,mixed>> */
    private function selectedHoursGroups(array $groups, array $assignees): array
    {
        $selected = [];
        foreach ($groups as $group) {
            $reports = array_values(array_filter(
                is_array($group['reports'] ?? null) ? $group['reports'] : [],
                static fn (array $report): bool => in_array(trim((string) ($report['asignado_a'] ?? '')), $assignees, true)
            ));
            if ($reports === []) {
                continue;
            }
            $group['reports'] = $reports;
            $selected[] = $group;
        }

        return $selected;
    }

    private function moduleId(): int
    {
        foreach (['modulos_nova', 'redmine_tic_reportes', 'catalogos_modulo', 'usuarios_nova', 'horas_extra_grupos', 'horas_extra_grupo_reportes'] as $table) {
            if (!Schema::hasTable($table)) {
                throw new RuntimeException("Falta la tabla requerida {$table}.");
            }
        }

        $id = DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');
        if ($id === null) {
            throw new RuntimeException('No existe el modulo redmine_tic.');
        }

        return (int) $id;
    }

    /**
     * @param array<int,string> $assignees
     * @return array<string,array{id:int,name:string,status:string}>
     */
    private function usersByRedmineId(array $assignees): array
    {
        return DB::table('usuarios_nova')
            ->whereIn('redmine_id', $assignees)
            ->get(['id', 'redmine_id', 'nombre', 'apellido', 'estado'])
            ->mapWithKeys(static fn (object $user): array => [
                (string) $user->redmine_id => [
                    'id' => (int) $user->id,
                    'name' => trim((string) $user->nombre . ' ' . (string) $user->apellido),
                    'status' => (string) $user->estado,
                ],
            ])
            ->all();
    }

    /** @param array<int,array<string,mixed>> $reports @return array<string,int> */
    private function catalogSummary(int $moduleId, array $reports): array
    {
        $active = $this->activeCatalogKeys($moduleId);
        $values = ['categoria' => [], 'unidad' => []];
        foreach ($reports as $report) {
            foreach ([
                'categoria' => [$report['categoria'] ?? ''],
                'unidad' => [$report['unidad'] ?? '', $report['unidad_solicitante'] ?? ''],
            ] as $type => $candidates) {
                foreach ($candidates as $candidate) {
                    $normalized = $this->normalizeCatalogValue((string) $candidate);
                    if ($normalized !== '') {
                        $values[$type][$normalized] = true;
                    }
                }
            }
        }

        $categoryMatches = count(array_intersect_key($values['categoria'], $active['categoria']));
        $unitMatches = count(array_intersect_key($values['unidad'], $active['unidad']));

        return [
            'categories' => count($values['categoria']),
            'categories_active_match' => $categoryMatches,
            'categories_historical_inactive' => count($values['categoria']) - $categoryMatches,
            'units' => count($values['unidad']),
            'units_active_match' => $unitMatches,
            'units_historical_inactive' => count($values['unidad']) - $unitMatches,
        ];
    }

    /** @return array{categoria:array<string,true>,unidad:array<string,true>} */
    private function activeCatalogKeys(int $moduleId): array
    {
        $keys = ['categoria' => [], 'unidad' => []];
        foreach (DB::table('catalogos_modulo')
            ->where('modulo_id', $moduleId)
            ->where('activo', 1)
            ->whereIn('tipo', ['categoria', 'unidad'])
            ->get(['tipo', 'clave_externa', 'nombre']) as $row) {
            foreach ([$row->clave_externa, $row->nombre] as $candidate) {
                $normalized = $this->normalizeCatalogValue((string) $candidate);
                if ($normalized !== '') {
                    $keys[$row->tipo][$normalized] = true;
                }
            }
        }

        return $keys;
    }

    /** @return array{ids:array<string,array<string,int>>,active:array<string,array<string,bool>>} */
    private function catalogResolver(int $moduleId): array
    {
        $resolver = [
            'ids' => ['categoria' => [], 'unidad' => []],
            'active' => ['categoria' => [], 'unidad' => []],
        ];
        $rows = DB::table('catalogos_modulo')
            ->where('modulo_id', $moduleId)
            ->whereIn('tipo', ['categoria', 'unidad'])
            ->orderByDesc('activo')
            ->orderBy('id')
            ->get(['id', 'tipo', 'clave_externa', 'nombre', 'activo']);
        foreach ($rows as $row) {
            foreach ([$row->clave_externa, $row->nombre] as $candidate) {
                $normalized = $this->normalizeCatalogValue((string) $candidate);
                if ($normalized !== '' && !isset($resolver['ids'][$row->tipo][$normalized])) {
                    $resolver['ids'][$row->tipo][$normalized] = (int) $row->id;
                    $resolver['active'][$row->tipo][$normalized] = (bool) $row->activo;
                }
            }
        }

        return $resolver;
    }

    /** @param array{ids:array<string,array<string,int>>,active:array<string,array<string,bool>>} $resolver */
    private function resolveCatalogId(array &$resolver, int $moduleId, string $type, mixed $value): ?int
    {
        $value = trim((string) $value);
        $normalized = $this->normalizeCatalogValue($value);
        if ($normalized === '') {
            return null;
        }
        if (isset($resolver['ids'][$type][$normalized])) {
            return $resolver['ids'][$type][$normalized];
        }

        $externalKey = 'legacy-tic:' . substr(sha1($type . '|' . $normalized), 0, 40);
        DB::table('catalogos_modulo')->updateOrInsert(
            ['modulo_id' => $moduleId, 'tipo' => $type, 'clave_externa' => $externalKey],
            [
                'nombre' => mb_substr($value, 0, 255),
                'predeterminado' => 0,
                'activo' => 0,
                'actualizado_at' => now(),
            ]
        );
        $id = (int) DB::table('catalogos_modulo')
            ->where('modulo_id', $moduleId)
            ->where('tipo', $type)
            ->where('clave_externa', $externalKey)
            ->value('id');
        $resolver['ids'][$type][$normalized] = $id;
        $resolver['active'][$type][$normalized] = false;

        return $id > 0 ? $id : null;
    }

    private function normalizeCatalogValue(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    private function nullableLongText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function parseDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach (['d-m-Y', 'Y-m-d', 'd/m/Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->toDateString();
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function parseTime(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach (['H:i:s', 'H:i'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->format('H:i:s');
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            try {
                return Carbon::createFromTimestamp((int) $value, 'America/Santiago');
            } catch (\Throwable) {
                return null;
            }
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value, 'America/Santiago');
        } catch (\Throwable) {
            return null;
        }
    }

    private function reportCreatedAt(array $report): Carbon
    {
        $date = $this->parseDate($report['fecha'] ?? null);
        $time = $this->parseTime($report['hora'] ?? null) ?? '00:00:00';
        if ($date !== null) {
            try {
                return Carbon::parse($date . ' ' . $time, 'America/Santiago');
            } catch (\Throwable) {
            }
        }

        return $this->parseDateTime($report['procesado_ts'] ?? null) ?? now();
    }

    private function decimalOrNull(mixed $value): ?float
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }
        if (preg_match('/^(\d{1,3}):([0-5]\d)$/', $value, $matches) === 1) {
            return round((float) $matches[1] + ((float) $matches[2] / 60), 2);
        }

        return null;
    }

    private function minutesDiff(?string $start, ?string $end): ?int
    {
        if ($start === null || $end === null) {
            return null;
        }
        $startTimestamp = strtotime('1970-01-01 ' . $start);
        $endTimestamp = strtotime('1970-01-01 ' . $end);
        if ($startTimestamp === false || $endTimestamp === false) {
            return null;
        }
        if ($endTimestamp < $startTimestamp) {
            $endTimestamp += 86400;
        }

        return (int) round(($endTimestamp - $startTimestamp) / 60);
    }
}
