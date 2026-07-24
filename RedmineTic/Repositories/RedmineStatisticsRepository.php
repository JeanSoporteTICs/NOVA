<?php

namespace RedmineTic\Repositories;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * ETAPA B / Lote B6.1 — pure data aggregation/transformation for the
 * Estadísticas domain, extracted verbatim from RedmineDataRepository's
 * statistics()/redmineApiStatistics() private helper cluster.
 *
 * This repository holds NO HTTP transport (redmineIssuesUrl()/
 * fetchRedmineIssues() stay in the facade, alongside the orchestration in
 * redmineApiStatistics() itself — deciding fetch-vs-cache, calling the
 * shared getRedmineJson() transport, resolving the user's API token, and
 * activity logging all remain facade responsibilities, matching the same
 * transport/transformation split already used for RedmineIssueSenderService
 * in B5.4). It also does not own date-range parsing (statisticsDateRange()/
 * filterReportsByDateRange() stay in the facade, and countsByDate()/
 * buildApiStatisticsFromRows() receive a $dateKeyNormalizer callback)
 * because normalizeDateKey()/parseFlexibleDate() are shared with the Horas
 * Extra and Histórico domains — a real move for them belongs to B6.3
 * (Helpers compartidos), not this lote.
 *
 * KNOWN FINDING (documented, not fixed here — see B6.1 close-out report):
 * redmineApiStatistics() has zero real callers anywhere in the application
 * (nativeSectionData()'s 'estadisticas' branch calls statistics() instead),
 * yet stats.blade.php's markup/JS was built against redmineApiStatistics()'s
 * richer contract (status_options, tracker_options, priority_options,
 * by_priority, by_tracker, category_options, source, fetched, cached,
 * error). This looks like a wiring regression, not intentionally retired
 * functionality — B6.1 moves it intact, unchanged, without touching
 * nativeSectionData() or the view, per explicit decision to not conflate a
 * refactor with a behavior fix.
 */
final class RedmineStatisticsRepository
{
    private ?RedmineConfigRepository $configRepoInst = null;

    public function __construct(
        private string $projectKey,
        private string $projectName,
    ) {
    }

    private function configRepo(): RedmineConfigRepository
    {
        return $this->configRepoInst ??= new RedmineConfigRepository($this->projectKey, $this->projectName);
    }

    // -------------------------------------------------------------------------
    // statistics() — local (DB-backed) aggregation over already-fetched reports
    // -------------------------------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $reports Reports already filtered
     *   by date range by the caller (RedmineDataRepository::statistics()).
     * @param callable $dateKeyNormalizer fn(string $date): string — resolves
     *   to the facade's normalizeDateKey(), shared with other domains.
     * @return array<string,mixed>
     */
    public function statistics(array $reports, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, callable $dateKeyNormalizer): array
    {
        $byDate = $this->countsByDate($reports, $dateKeyNormalizer);
        $byMonth = $this->countsByMonth($reports, $dateKeyNormalizer);

        return [
            'total' => count($reports),
            'by_status' => $this->countsByField($reports, 'estado'),
            'by_category' => $this->countsByField($reports, 'categoria'),
            'by_unit' => $this->countsByField($reports, 'unidad_solicitante'),
            'by_assignee' => $this->countsByField($reports, 'asignado_nombre'),
            'by_date' => $byDate,
            'by_month' => $byMonth,
            'max_daily' => $byDate ? max($byDate) : 0,
            'max_monthly' => $byMonth ? max($byMonth) : 0,
            'filters' => [
                'desde' => $from?->format('d-m-Y') ?? '',
                'hasta' => $to?->format('d-m-Y') ?? '',
            ],
            'updated_at' => now('America/Santiago')->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countsByField(array $rows, string $field): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = trim((string) Arr::get($row, $field, ''));
            $value = $value !== '' ? $value : 'Sin dato';
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countsByDate(array $rows, callable $dateKeyNormalizer): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $date = $dateKeyNormalizer((string) ($row['fecha_inicio'] ?? $row['fecha'] ?? $row['start_date'] ?? $row['due_date'] ?? $row['created_on'] ?? ''));
            if ($date === '') {
                continue;
            }
            $counts[$date] = ($counts[$date] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countsByMonth(array $rows, callable $dateKeyNormalizer): array
    {
        $counts = [];
        foreach ($this->countsByDate($rows, $dateKeyNormalizer) as $date => $total) {
            $month = substr($date, 0, 7);
            if ($month === '') {
                continue;
            }
            $counts[$month] = ($counts[$month] ?? 0) + $total;
        }
        ksort($counts);

        return $counts;
    }

    // -------------------------------------------------------------------------
    // redmineApiStatistics() — pure transformation of already-fetched Redmine
    // API rows, plus filter/option resolution and DB-backed cache. HTTP
    // transport and orchestration stay in the facade (see class docblock).
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function emptyStatistics(array $filters): array
    {
        return [
            'total' => 0,
            'by_status' => [],
            'by_category' => [],
            'category_options' => [],
            'by_unit' => [],
            'by_assignee' => [],
            'by_priority' => [],
            'by_tracker' => [],
            'by_date' => [],
            'by_month' => [],
            'max_daily' => 0,
            'max_monthly' => 0,
            'filters' => $filters,
            'updated_at' => now('America/Santiago')->format('Y-m-d H:i'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function apiStatisticsCache(): array
    {
        $cache = $this->configRepo()->fromDatabase()['estadisticas_api_cache'] ?? [];
        $cache = is_array($cache) ? $cache : [];
        if ((int) ($cache['schema_version'] ?? 0) < 3) {
            return [];
        }

        return is_array($cache['stats'] ?? null) ? $cache['stats'] : [];
    }

    /**
     * @param array<string,mixed> $stats
     */
    public function saveApiStatisticsCache(array $stats): void
    {
        $this->configRepo()->saveToDatabase(['estadisticas_api_cache' => [
            'schema_version' => 3,
            'saved_at' => now('America/Santiago')->format('Y-m-d H:i'),
            'stats' => $this->normalizeApiStatistics($stats),
        ]], ['estadisticas_api_cache' => 'json']);
    }

    /**
     * @param array<string,mixed> $stats
     * @return array<string,mixed>
     */
    public function normalizeApiStatistics(array $stats): array
    {
        $stats['by_unit'] = $this->normalizeRedmineUnitCounts((array) ($stats['by_unit'] ?? []));

        return $stats;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $config
     * @param array<string,mixed> $filters
     * @param callable $dateKeyNormalizer fn(string $date): string
     * @return array<string,mixed>
     */
    public function buildApiStatisticsFromRows(array $rows, array $config, array $filters, bool $cached, callable $dateKeyNormalizer): array
    {
        $categorySelection = $this->normalizeCategorySelection((array) ($filters['category_scope'] ?? []));
        $categoryFilterActive = filter_var($filters['category_filter'] ?? false, FILTER_VALIDATE_BOOL);
        $filteredRows = $categoryFilterActive ? $this->filterRedmineRowsByCategories($rows, $categorySelection) : array_values($rows);
        $byDate = $this->countsByDate($filteredRows, $dateKeyNormalizer);
        $byMonth = $this->countsByMonth($filteredRows, $dateKeyNormalizer);

        $filters['category_scope'] = $categorySelection;
        $filters['category_filter'] = $categoryFilterActive ? '1' : '';

        return [
            'source' => 'redmine-api',
            'fetched' => true,
            'cached' => $cached,
            'error' => '',
            'total' => count($filteredRows),
            'by_status' => $this->countsByRelation($filteredRows, 'status'),
            'by_category' => $this->countsByRelation($filteredRows, 'category'),
            'category_options' => $this->countsByRelation($rows, 'category'),
            'by_unit' => $this->countsByRedmineUnitField($filteredRows, (string) ($config['cf_unidad_solicitante'] ?? $config['cf_unidad'] ?? '')),
            'by_assignee' => $this->countsByRelation($filteredRows, 'assigned_to'),
            'by_priority' => $this->countsByRelation($filteredRows, 'priority'),
            'by_tracker' => $this->countsByRelation($filteredRows, 'tracker'),
            'by_date' => $byDate,
            'by_month' => $byMonth,
            'max_daily' => $byDate ? max($byDate) : 0,
            'max_monthly' => $byMonth ? max($byMonth) : 0,
            'filters' => $filters,
            'raw_rows' => array_values($rows),
            'updated_at' => now('America/Santiago')->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param array<int,mixed> $selection
     * @return array<int,string>
     */
    public function normalizeCategorySelection(array $selection): array
    {
        $normalized = [];
        foreach ($selection as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string> $categorySelection
     * @return array<int,array<string,mixed>>
     */
    private function filterRedmineRowsByCategories(array $rows, array $categorySelection): array
    {
        if ($categorySelection === []) {
            return [];
        }

        $selected = array_fill_keys(array_map(static fn (string $name): string => Str::lower(Str::ascii($name)), $categorySelection), true);

        return array_values(array_filter($rows, function (array $row) use ($selected): bool {
            $category = $this->redmineRelationName($row, 'category');
            $key = Str::lower(Str::ascii($category));

            return isset($selected[$key]);
        }));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function redmineRelationName(array $row, string $field): string
    {
        $value = Arr::get($row, $field . '.name', Arr::get($row, $field . '.value', ''));
        $value = trim((string) $value);

        return $value !== '' ? $value : 'Sin dato';
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countsByRelation(array $rows, string $field): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = Arr::get($row, $field . '.name', Arr::get($row, $field . '.value', ''));
            $value = trim((string) $value);
            $value = $value !== '' ? $value : 'Sin dato';
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        arsort($counts);

        return $counts;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countsByRedmineCustomField(array $rows, string $fieldId): array
    {
        if ($fieldId === '') {
            return [];
        }

        $counts = [];
        foreach ($rows as $row) {
            foreach ((array) ($row['custom_fields'] ?? []) as $field) {
                if (!is_array($field) || (string) ($field['id'] ?? '') !== $fieldId) {
                    continue;
                }
                $value = $field['value'] ?? '';
                if (is_array($value)) {
                    $value = implode(', ', array_filter(array_map('strval', $value)));
                }
                $value = trim((string) $value);
                $value = $value !== '' ? $value : 'Sin dato';
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    private function countsByRedmineUnitField(array $rows, string $fieldId): array
    {
        return $this->normalizeRedmineUnitCounts($this->countsByRedmineCustomField($rows, $fieldId));
    }

    /**
     * @param array<string,int|numeric-string> $counts
     * @return array<string,int>
     */
    private function normalizeRedmineUnitCounts(array $counts): array
    {
        $normalized = [];
        $labels = [];

        foreach ($counts as $name => $count) {
            $label = $this->normalizeRedmineUnitLabel((string) $name);
            if ($label === '' || (int) $count <= 0) {
                continue;
            }

            $key = Str::lower(Str::ascii($label));
            $normalized[$key] = ($normalized[$key] ?? 0) + (int) $count;
            if (!isset($labels[$key]) || $this->labelScore($label) > $this->labelScore($labels[$key])) {
                $labels[$key] = $label;
            }
        }

        $out = [];
        foreach ($normalized as $key => $count) {
            $out[$labels[$key] ?? $key] = $count;
        }
        arsort($out);

        return $out;
    }

    private function normalizeRedmineUnitLabel(string $label): string
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);
        if ($label === '') {
            return '';
        }

        $plain = Str::lower(Str::ascii($label));
        if (in_array($plain, ['sin dato', 'sin datos', 'n/a', 'na', 'null', '-'], true)) {
            return '';
        }

        return $this->titleLabel($label);
    }

    private function titleLabel(string $label): string
    {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);

        return function_exists('mb_convert_case')
            ? mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8')
            : ucwords($lower);
    }

    private function labelScore(string $label): int
    {
        return preg_match('/[^\x00-\x7F]/', $label) ? 2 : 1;
    }

    // -------------------------------------------------------------------------
    // Status/tracker/priority option lists and selection normalization —
    // pure transformations over the $config array, no external dependency.
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $config
     * @return array<int,array{value:string,label:string}>
     */
    public function statusOptions(array $config): array
    {
        $options = [
            ['value' => 'open', 'label' => 'Abiertos'],
            ['value' => 'closed', 'label' => 'Cerrados'],
            ['value' => 'all', 'label' => 'Todos'],
        ];

        foreach ((array) ($config['estados'] ?? []) as $status) {
            if (!is_array($status)) {
                continue;
            }
            $id = trim((string) ($status['id'] ?? ''));
            $label = trim((string) ($status['nombre'] ?? $status['name'] ?? ''));
            if ($id === '' || $label === '') {
                continue;
            }
            $options[] = ['value' => $id, 'label' => $label];
        }

        return $options;
    }

    /**
     * @param array<string,mixed> $config
     * @return array<int,array{value:string,label:string}>
     */
    public function configOptions(array $config, string $key): array
    {
        $options = [['value' => 'all', 'label' => 'Todos']];
        foreach ((array) ($config[$key] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = trim((string) ($item['id'] ?? ''));
            $label = trim((string) ($item['nombre'] ?? $item['name'] ?? ''));
            if ($id === '' || $label === '') {
                continue;
            }
            $options[] = ['value' => $id, 'label' => $label];
        }

        return $options;
    }

    /**
     * @param array<int,array{value:string,label:string}> $statusOptions
     */
    public function normalizeStatusSelection(string $value, array $statusOptions): string
    {
        $value = trim($value);
        $allowed = array_column($statusOptions, 'value');

        return in_array($value, $allowed, true) ? $value : 'open';
    }

    /**
     * @param array<int,array{value:string,label:string}> $options
     */
    public function normalizeOptionSelection(string $value, array $options): string
    {
        $value = trim($value);
        $allowed = array_column($options, 'value');

        return in_array($value, $allowed, true) ? $value : 'all';
    }

    public function statusQueryValue(string $statusSelection): string
    {
        return match ($statusSelection) {
            'all' => '*',
            'closed' => 'c',
            'open' => 'o',
            default => $statusSelection,
        };
    }
}
