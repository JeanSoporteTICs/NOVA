<?php

use RedmineTic\Support\DateSupport;

// Frozen pre-P06 hours readers, bound to the real facade to reuse unchanged helpers.
return [
    'groups' => function (): array {
        if (! $this->hoursExtraTableAvailable() || ! $this->reportsTableAvailable() || ! $this->hoursExtraPivotTableAvailable()) {
            return [];
        }

        $grupos = $this->hoursExtraRepo()->groupsForOrigen();
        if ($grupos === []) {
            return [];
        }

        $reports = collect($this->archivedReportsFromDatabase())
            ->keyBy(static fn (array $report): string => (string) ($report['id'] ?? ''));

        return array_map(function (array $grupo) use ($reports): array {
            $reportRows = array_values(array_filter(array_map(
                static fn (int $id): ?array => $reports->get((string) $id),
                $grupo['reporte_ids']
            )));

            return [
                'fecha' => DateSupport::databaseDate($grupo['fecha']),
                'hora_inicio' => DateSupport::databaseTime($grupo['hora_inicio']),
                'hora_fin' => DateSupport::databaseTime($grupo['hora_fin']),
                'reports' => $reportRows,
                '_source_file' => DateSupport::databaseDate($grupo['fecha']),
            ];
        }, $grupos);
    },
    'screen' => function (array $filters, array $user): array {
        $groups = $this->deduplicateHoursGroups((require __DIR__.'/tic_hours_reference.php')['groups']->call($this));
        $userId = (string) ($user['id'] ?? '');
        if ($userId !== '') {
            $groups = array_values(array_filter(array_map(static function (array $group) use ($userId): ?array {
                $reports = array_values(array_filter((array) ($group['reports'] ?? []), static fn (array $report): bool => (string) ($report['asignado_a'] ?? '') === $userId));
                if ($reports === []) {
                    return null;
                }
                $group['reports'] = $reports;

                return $group;
            }, $groups)));
        } else {
            $groups = [];
        }

        $availableYears = [now('America/Santiago')->format('Y') => true];
        foreach ($groups as $group) {
            $date = DateSupport::parseFlexibleDate((string) ($group['fecha'] ?? ''));
            if ($date) {
                $availableYears[$date->format('Y')] = true;
            }
        }
        $availableYears = array_keys($availableYears);
        sort($availableYears);

        $hasExplicitFilters = array_key_exists('filters', $filters) || array_key_exists('mes', $filters) || array_key_exists('anio', $filters);
        $selectedMonth = DateSupport::selectedMonth($filters['mes'] ?? null, $hasExplicitFilters);
        $selectedYear = DateSupport::selectedYear($filters['anio'] ?? null, $hasExplicitFilters);
        $visible = array_values(array_filter($groups, function (array $group) use ($selectedMonth, $selectedYear): bool {
            $date = DateSupport::parseFlexibleDate((string) ($group['fecha'] ?? ''));
            if (! $date) {
                return true;
            }

            return ($selectedMonth === '' || (int) $selectedMonth === (int) $date->format('n'))
                && ($selectedYear === '' || (string) $selectedYear === $date->format('Y'));
        }));
        usort($visible, function (array $a, array $b): int {
            return ((string) (DateSupport::normalizeDateKey((string) ($b['fecha'] ?? '')))) <=> ((string) (DateSupport::normalizeDateKey((string) ($a['fecha'] ?? ''))));
        });

        $totalMinutes = array_reduce($visible, fn (int $carry, array $group): int => $carry + (DateSupport::minutesDiff((string) ($group['hora_inicio'] ?? ''), (string) ($group['hora_fin'] ?? '')) ?? 0), 0);
        $emachSuggestions = $this->emachOvertimeSuggestionsForGroups($visible, $user);

        return [
            'rows' => $visible,
            'hoursMeta' => [
                'months' => DateSupport::monthOptions(),
                'years' => $availableYears,
                'selectedMonth' => $selectedMonth,
                'selectedYear' => $selectedYear,
                'visibleCount' => count($visible),
                'totalCount' => count($groups),
                'totalHours' => DateSupport::formatMinutes($totalMinutes),
                'emachSuggestions' => $emachSuggestions,
            ],
        ];
    },
];
