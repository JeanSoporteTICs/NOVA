<?php
// Pre-P06 history filter/pagination reference; synthetic test inputs only.
return static function (array $rows, array $query, array $config): array {
    $normDate = static function ($value): string {
        $value = trim((string) $value);
        if ($value === '') return '';
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof DateTimeImmutable) {
                return $date->format('Y-m-d');
            }
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    };

    $fmtDate = static function ($value) use ($normDate): string {
        $date = $normDate($value);
        if ($date === '') return trim((string) $value) ?: '-';
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $dt ? $dt->format('d-m-Y') : $date;
    };

    $normalizeText = static function ($value): string {
        $value = strtolower(trim((string) $value));
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return is_string($converted) ? $converted : $value;
    };

    $redmineIssueUrl = static function ($redmineId) use ($config): string {
        return \RedmineTic\Support\RedmineUrlSupport::redmineIssueUrl(
            (string) ($config['platform_url'] ?? ''),
            (string) $redmineId
        );
    };

    $sourceValue = static function (array $row): string {
        $origin = strtolower(trim((string) ($row['origen'] ?? '')));
        if ($origin === 'telegram' || trim((string) ($row['chat_id_telegram'] ?? '')) !== '') return 'telegram';
        return 'manual';
    };

    $sourceLabel = static fn (array $row): string => $sourceValue($row) === 'telegram' ? 'Telegram' : 'Manual';
    $sourceIcon = static fn (array $row): string => $sourceValue($row) === 'telegram' ? 'bi-telegram' : 'bi-pencil-square';

    $fDesde = $normDate($query['desde'] ?? '');
    $fHasta = $normDate($query['hasta'] ?? '');
    $fFuente = trim((string) ($query['fuente'] ?? ''));
    $fBusqueda = trim((string) ($query['buscar'] ?? ''));
    $fDescripcion = trim((string) ($query['descripcion'] ?? ''));
    $fCategoria = trim((string) ($query['categoria'] ?? ''));
    $fEstadoRedmine = trim((string) ($query['estado_redmine'] ?? ''));
    $perPageOptions = [25, 50, 100];
    $perPage = (int) ($query['per_page'] ?? 25);
    if (!in_array($perPage, $perPageOptions, true)) $perPage = 25;
    $currentPage = max(1, (int) ($query['page'] ?? 1));

    $redmineStatusOptions = [];
    $redmineFilterStatuses = [];
    foreach ((array) ($config['estados'] ?? []) as $statusOption) {
        if (!is_array($statusOption)) continue;
        $statusId = filter_var($statusOption['id'] ?? null, FILTER_VALIDATE_INT);
        $statusName = trim((string) ($statusOption['nombre'] ?? $statusOption['name'] ?? ''));
        if ($statusId === false || $statusId <= 0 || $statusName === '') continue;
        $redmineStatusOptions[$statusId] = ['id' => $statusId, 'name' => $statusName];
        $redmineFilterStatuses[$statusName] = $statusName;
    }
    $redmineStatusOptions = array_values($redmineStatusOptions);

    $categories = [];
    $filtered = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $date = $normDate($row['fecha_inicio'] ?? $row['fecha'] ?? $row['_history_sort_date'] ?? '');
        $source = $sourceValue($row);
        $category = trim((string) ($row['categoria'] ?? $row['core_categoria'] ?? ''));
        $redmineStatus = trim((string) ($row['estado_redmine'] ?? $row['redmine_estado'] ?? $row['status_name'] ?? ''));
        if ($category !== '') $categories[$category] = $category;
        if ($redmineStatus !== '') $redmineFilterStatuses[$redmineStatus] = $redmineStatus;
        if ($date !== '' && $fDesde !== '' && $date < $fDesde) continue;
        if ($date !== '' && $fHasta !== '' && $date > $fHasta) continue;
        if ($fFuente !== '' && $source !== $fFuente) continue;
        if ($fCategoria !== '' && $category !== $fCategoria) continue;
        if ($fEstadoRedmine !== '' && $normalizeText($redmineStatus) !== $normalizeText($fEstadoRedmine)) continue;
        if ($fBusqueda !== '') {
            $needle = $normalizeText($fBusqueda);
            $haystack = $normalizeText(implode(' ', [
                $row['redmine_id'] ?? '',
                $row['asunto'] ?? '',
                $row['mensaje'] ?? '',
                $row['solicitante'] ?? '',
                $row['unidad_solicitante'] ?? '',
                $row['unidad'] ?? '',
                $row['asignado_nombre'] ?? '',
                $row['asignado_a'] ?? '',
                $category,
                $redmineStatus,
            ]));
            if ($needle !== '' && !str_contains($haystack, $needle)) continue;
        }
        if ($fDescripcion !== '') {
            $descriptionNeedle = $normalizeText($fDescripcion);
            $descriptionText = $normalizeText($row['descripcion'] ?? '');
            if ($descriptionNeedle !== '' && !str_contains($descriptionText, $descriptionNeedle)) continue;
        }
        $row['_history_date_norm'] = $date;
        $filtered[] = $row;
    }
    ksort($categories);
    ksort($redmineFilterStatuses, SORT_NATURAL | SORT_FLAG_CASE);

    $totalFiltered = count($filtered);
    $totalPages = max(1, (int) ceil($totalFiltered / $perPage));
    $currentPage = min($currentPage, $totalPages);
    $pageOffset = ($currentPage - 1) * $perPage;
    $pagedRows = array_slice($filtered, $pageOffset, $perPage);
    $visibleRows = count($pagedRows);
    $hoursRows = count(array_filter($filtered, static fn ($row): bool => is_array($row) && !empty($row['_history_is_hours_extra'])));
    $archivedRows = max(0, $totalFiltered - $hoursRows);

return compact("pagedRows", "totalFiltered", "totalPages", "currentPage", "hoursRows", "categories", "redmineFilterStatuses");
};
