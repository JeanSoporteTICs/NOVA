<?php

namespace RedmineTic\Support;

use Illuminate\Support\Arr;

/**
 * ETAPA B / Lote B6.3 — small, generic array utilities extracted verbatim
 * from RedmineDataRepository's private helper cluster. No DB, cache,
 * session or network access.
 */
final class ArraySupport
{
    /**
     * @param array<int,array<string,mixed>> $reports
     * @param string[] $states
     */
    public static function countByState(array $reports, array $states): int
    {
        return count(array_filter($reports, static function (array $report) use ($states): bool {
            $state = strtolower(trim((string) Arr::get($report, 'estado', '')));

            return in_array($state, $states, true);
        }));
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function historyRowKey(array $row, string $fallback): string
    {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id !== '') {
            return 'id:' . $id;
        }

        $redmineId = trim((string) ($row['redmine_id'] ?? ''));
        if ($redmineId !== '') {
            return 'redmine:' . $redmineId;
        }

        return 'fallback:' . $fallback;
    }
}
