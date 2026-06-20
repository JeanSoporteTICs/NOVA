<?php

namespace RedmineTic\Support\Redmine;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Pure-computation statistics service — no DB access, no state.
 * Accepts pre-fetched report/issue arrays and returns aggregated counts,
 * date ranges, and chart-ready structures.
 *
 * The cache read/write and public `statistics()` / `redmineApiStatistics()`
 * methods remain in RedmineDataRepository because they also orchestrate
 * file reads, config reads, and activity logging.
 */
class RedmineStatisticsService
{
    // ---- empty/shell builders ----

    /** @return array<string,mixed> */
    public function emptyStatistics(array $filters): array
    {
        return [
            'total'           => 0,
            'by_status'       => [],
            'by_category'     => [],
            'category_options' => [],
            'by_unit'         => [],
            'by_assignee'     => [],
            'by_priority'     => [],
            'by_tracker'      => [],
            'by_date'         => [],
            'by_month'        => [],
            'max_daily'       => 0,
            'max_monthly'     => 0,
            'filters'         => $filters,
            'updated_at'      => now('America/Santiago')->format('Y-m-d H:i'),
        ];
    }

    /**
     * Builds a full statistics payload from raw Redmine API issue rows.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $config
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function buildFromRows(array $rows, array $config, array $filters, bool $cached = false): array
    {
        $categorySelection  = $this->normalizeCategorySelection((array) ($filters['category_scope'] ?? []));
        $categoryFilterActive = filter_var($filters['category_filter'] ?? false, FILTER_VALIDATE_BOOL);
        $filteredRows = $categoryFilterActive
            ? $this->filterRowsByCategories($rows, $categorySelection)
            : array_values($rows);

        $filters['category_scope']  = $categorySelection;
        $filters['category_filter'] = $categoryFilterActive ? '1' : '';

        $byDate  = $this->countsByDate($filteredRows);
        $byMonth = $this->countsByMonth($filteredRows);

        return [
            'source'           => 'redmine-api',
            'fetched'          => true,
            'cached'           => $cached,
            'error'            => '',
            'total'            => count($filteredRows),
            'by_status'        => $this->countsByRelation($filteredRows, 'status'),
            'by_category'      => $this->countsByRelation($filteredRows, 'category'),
            'category_options' => $this->countsByRelation($rows, 'category'),
            'by_unit'          => $this->countsByRedmineUnitField($filteredRows, (string) ($config['cf_unidad_solicitante'] ?? $config['cf_unidad'] ?? '')),
            'by_assignee'      => $this->countsByRelation($filteredRows, 'assigned_to'),
            'by_priority'      => $this->countsByRelation($filteredRows, 'priority'),
            'by_tracker'       => $this->countsByRelation($filteredRows, 'tracker'),
            'by_date'          => $byDate,
            'by_month'         => $byMonth,
            'max_daily'        => $byDate  ? max($byDate)  : 0,
            'max_monthly'      => $byMonth ? max($byMonth) : 0,
            'filters'          => $filters,
            'raw_rows'         => array_values($rows),
            'updated_at'       => now('America/Santiago')->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param array<string,mixed> $stats
     * @return array<string,mixed>
     */
    public function normalizeApiStatistics(array $stats): array
    {
        $stats['by_unit'] = $this->normalizeUnitCounts((array) ($stats['by_unit'] ?? []));

        return $stats;
    }

    // ---- category filter helpers ----

    /**
     * @param array<int,mixed> $selection
     * @return array<int,string>
     */
    public function normalizeCategorySelection(array $selection): array
    {
        $normalized = [];
        foreach ($selection as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string> $categorySelection
     * @return array<int,array<string,mixed>>
     */
    public function filterRowsByCategories(array $rows, array $categorySelection): array
    {
        if ($categorySelection === []) {
            return [];
        }

        $selected = array_fill_keys(
            array_map(static fn (string $name): string => Str::lower(Str::ascii($name)), $categorySelection),
            true
        );

        return array_values(array_filter($rows, function (array $row) use ($selected): bool {
            $key = Str::lower(Str::ascii($this->relationName($row, 'category')));

            return isset($selected[$key]);
        }));
    }

    // ---- count aggregators ----

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    public function countsByField(array $rows, string $field): array
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
    public function countsByRelation(array $rows, string $field): array
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
    public function countsByRedmineCustomField(array $rows, string $fieldId): array
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
    public function countsByRedmineUnitField(array $rows, string $fieldId): array
    {
        return $this->normalizeUnitCounts($this->countsByRedmineCustomField($rows, $fieldId));
    }

    /**
     * @param array<string,int|numeric-string> $counts
     * @return array<string,int>
     */
    public function normalizeUnitCounts(array $counts): array
    {
        $normalized = [];
        $labels     = [];

        foreach ($counts as $name => $count) {
            $label = $this->normalizeUnitLabel((string) $name);
            if ($label === '' || (int) $count <= 0) {
                continue;
            }

            $key                = Str::lower(Str::ascii($label));
            $normalized[$key]   = ($normalized[$key] ?? 0) + (int) $count;
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

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,int>
     */
    public function countsByDate(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $date = $this->normalizeDateKey((string) ($row['fecha_inicio'] ?? $row['fecha'] ?? $row['start_date'] ?? $row['due_date'] ?? $row['created_on'] ?? ''));
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
    public function countsByMonth(array $rows): array
    {
        $counts = [];
        foreach ($this->countsByDate($rows) as $date => $total) {
            $month = substr($date, 0, 7);
            if ($month === '') {
                continue;
            }
            $counts[$month] = ($counts[$month] ?? 0) + $total;
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param array<int,array<string,mixed>> $reports
     * @param string[] $states
     */
    public function countByState(array $reports, array $states): int
    {
        return count(array_filter($reports, static function (array $report) use ($states): bool {
            $state = strtolower(trim((string) Arr::get($report, 'estado', '')));

            return in_array($state, $states, true);
        }));
    }

    // ---- date range / parsing ----

    /**
     * @param array<string,mixed> $filters
     * @return array{0:?\DateTimeImmutable,1:?\DateTimeImmutable}
     */
    public function dateRange(array $filters): array
    {
        $from = $this->parseFlexibleDate((string) ($filters['desde'] ?? ''));
        $to   = $this->parseFlexibleDate((string) ($filters['hasta'] ?? ''));

        if (!$from && !$to) {
            $today = now('America/Santiago');
            $from  = new \DateTimeImmutable($today->copy()->startOfMonth()->format('Y-m-d'), new \DateTimeZone('America/Santiago'));
            $to    = new \DateTimeImmutable($today->copy()->endOfMonth()->format('Y-m-d'),   new \DateTimeZone('America/Santiago'));
        }

        if ($from && $to && $from > $to) {
            return [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * @param array<int,array<string,mixed>> $reports
     * @return array<int,array<string,mixed>>
     */
    public function filterByDateRange(array $reports, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): array
    {
        if (!$from && !$to) {
            return $reports;
        }

        return array_values(array_filter($reports, function (array $report) use ($from, $to): bool {
            $date = $this->parseFlexibleDate(
                (string) ($report['fecha_inicio'] ?? $report['fecha'] ?? $report['start_date'] ?? $report['due_date'] ?? $report['created_on'] ?? '')
            );
            if (!$date) {
                return false;
            }
            if ($from && $date < $from) {
                return false;
            }
            if ($to && $date > $to) {
                return false;
            }

            return true;
        }));
    }

    public function normalizeDateKey(string $date): string
    {
        $parsed = $this->parseFlexibleDate($date);

        return $parsed ? $parsed->format('Y-m-d') : '';
    }

    public function parseFlexibleDate(string $date): ?\DateTimeImmutable
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $date, new \DateTimeZone('America/Santiago'));
            if ($parsed) {
                return $parsed;
            }
        }

        return null;
    }

    // ---- Redmine API option normalizers ----

    /**
     * @param array<string,mixed> $config
     * @return array<int,array{value:string,label:string}>
     */
    public function statusOptions(array $config): array
    {
        $options = [
            ['value' => 'open',   'label' => 'Abiertos'],
            ['value' => 'closed', 'label' => 'Cerrados'],
            ['value' => 'all',    'label' => 'Todos'],
        ];

        foreach ((array) ($config['estados'] ?? []) as $status) {
            if (!is_array($status)) {
                continue;
            }
            $id    = trim((string) ($status['id'] ?? ''));
            $label = trim((string) ($status['nombre'] ?? $status['name'] ?? ''));
            if ($id !== '' && $label !== '') {
                $options[] = ['value' => $id, 'label' => $label];
            }
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
            $id    = trim((string) ($item['id'] ?? ''));
            $label = trim((string) ($item['nombre'] ?? $item['name'] ?? ''));
            if ($id !== '' && $label !== '') {
                $options[] = ['value' => $id, 'label' => $label];
            }
        }

        return $options;
    }

    /**
     * @param array<int,array{value:string,label:string}> $statusOptions
     */
    public function normalizeStatusSelection(string $value, array $statusOptions): string
    {
        $value   = trim($value);
        $allowed = array_column($statusOptions, 'value');

        return in_array($value, $allowed, true) ? $value : 'open';
    }

    /**
     * @param array<int,array{value:string,label:string}> $options
     */
    public function normalizeOptionSelection(string $value, array $options): string
    {
        $value   = trim($value);
        $allowed = array_column($options, 'value');

        return in_array($value, $allowed, true) ? $value : 'all';
    }

    public function statusQueryValue(string $statusSelection): string
    {
        return match ($statusSelection) {
            'all'    => '*',
            'closed' => 'c',
            'open'   => 'o',
            default  => $statusSelection,
        };
    }

    public function isClosedStatus(string $statusName): bool
    {
        $statusKey = strtolower(trim($statusName));
        foreach (['cerrad', 'closed', 'resuelt', 'resolved', 'finaliz', 'complet', 'terminad'] as $needle) {
            if (str_contains($statusKey, $needle)) {
                return true;
            }
        }

        return false;
    }

    // ---- Redmine row helpers ----

    /** @param array<string,mixed> $row */
    public function relationName(array $row, string $field): string
    {
        $value = Arr::get($row, $field . '.name', Arr::get($row, $field . '.value', ''));
        $value = trim((string) $value);

        return $value !== '' ? $value : 'Sin dato';
    }

    // ---- private helpers ----

    private function normalizeUnitLabel(string $label): string
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
}
