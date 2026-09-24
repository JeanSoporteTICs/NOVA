<?php

namespace App\Modulos\RedmineMantencion\Repositories;

use App\Modulos\Nova\Repositories\HorasExtraRepository;
use App\Modulos\RedmineMantencion\Services\MantencionHistoricoService;
use App\Repositories\Database\SqlText;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only history projection. Legacy normalization remains authoritative. */
final class MantencionHistoryRepository
{
    public function __construct(
        private readonly MantencionReportRepository $reports,
        private readonly HorasExtraRepository $hours,
    ) {}

    public function page(MantencionHistoricoService $history, array $filters, string $userId, array $names, array $statuses, int $page, int $perPage): array
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            try {
                return DB::transaction(fn (): array => $this->readSqlPage(
                    $history, $filters, $userId, $names, $statuses, $page, $perPage
                ));
            } catch (\Throwable) {
                // Keep the compatible PHP filter/page reader on SQL errors.
            }
        }

        return DB::transaction(fn (): array => $this->readPage(
            $history, $filters, $userId, $names, $statuses, $page, $perPage
        ));
    }

    private function readSqlPage(MantencionHistoricoService $history, array $filters, string $userId, array $names, array $statuses, int $page, int $perPage): array
    {
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
        $text = new HistorySqlText;
        $context = $this->candidateContext();
        $columns = ['r.id', 'r.fecha_reporte', 'r.fecha_inicio', 'r.estado'];
        if (($filters['fuente'] ?? '') === '') {
            array_push($columns, 'r.fuente_id', 'r.numero_ticket_redmine', 'r.solicitante');
        }
        if (($filters['buscar'] ?? '') !== '') {
            $columns[] = 'r.solicitante';
        }
        if (($filters['usuario'] ?? '') !== '' || ($filters['scope'] ?? 'asignados') === 'asignados') {
            $columns[] = 'r.id_redmine_asignado';
        }
        if (($filters['scope'] ?? 'asignados') === 'asignados' && $names !== []) {
            $columns[] = 'r.asignado_nombre';
        }
        if (($filters['categoria'] ?? '') !== '') {
            $columns[] = 'c.nombre as categoria_nombre';
        }
        if (($filters['estado_redmine'] ?? '') !== '') {
            array_push($columns, 'r.estado_redmine', 'r.estado_id');
        }
        $base = $this->candidates(($filters['descripcion'] ?? '') !== '', $context, array_values(array_unique($columns)));
        $dateCandidates = $this->candidates(false, $context, ['r.id', 'r.fecha_reporte', 'r.fecha_inicio']);
        $date = 'COALESCE(CAST(fecha_reporte AS CHAR), CAST(fecha_inicio AS CHAR))';
        $exceptions = [];
        foreach ($dateCandidates->whereRaw("$date IS NOT NULL AND (COALESCE(MONTH($date), 0) = 0 OR COALESCE(DAY($date), 0) = 0 OR DAY($date) > DAY(LAST_DAY($date)))")
            ->selectRaw("DISTINCT $date AS legacy_date")->get() as $row) {
            $message = $this->reports->rowToMessage((object) ['fecha_reporte' => $row->legacy_date, 'tiempo_estimado' => null]);
            $exceptions[] = 'WHEN '.$text->quote((string) $row->legacy_date).' THEN '.$text->quote($history->normDate($message['fecha']));
        }
        $dateExpression = $exceptions === [] ? "COALESCE(CAST($date AS CHAR), '')"
            : "CASE CAST($date AS CHAR) ".implode(' ', $exceptions)." ELSE COALESCE(CAST($date AS CHAR), '') END";
        $normalized = (clone $base)->select('*')->selectRaw("$dateExpression AS _fecha_norm");
        $query = DB::query()->fromSub($normalized, 'normalized_history')->where('_fecha_norm', '<>', '')
            ->whereRaw(SqlText::trim('estado')." COLLATE utf8mb4_bin REGEXP '(?-i)^([aA][rR][cC][hH][iI][vV][aA][dD][oO]|[pP][rR][oO][cC][eE][sS][aA][dD][oO])$'");
        foreach (['desde' => '>=', 'hasta' => '<='] as $filter => $operator) {
            if ($filters[$filter] ?? '') {
                $query->whereRaw("BINARY _fecha_norm $operator BINARY ?", [$filters[$filter]]);
            }
        }
        if ($filters['fuente'] ?? '') {
            $query->whereRaw('BINARY _source = BINARY ?', [$filters['fuente']]);
        }
        if (($filters['usuario'] ?? '') !== '') {
            $query->whereRaw('BINARY '.SqlText::trim('id_redmine_asignado').' = BINARY ?', [(string) $filters['usuario']]);
        }
        if (($filters['categoria'] ?? '') !== '') {
            $query->whereRaw('BINARY '.SqlText::asciiLower(SqlText::trim('categoria_nombre')).' = BINARY ?', [$filters['categoria']]);
        }
        if (($filters['estado_redmine'] ?? '') !== '') {
            $fallbacks = [];
            foreach ($statuses as $id => $label) {
                $fallbacks[] = 'WHEN '.(int) $id.' THEN '.$text->quote(trim((string) $label));
            }
            $fallback = $fallbacks === [] ? "''" : 'CASE CAST(estado_id AS SIGNED) '.implode(' ', $fallbacks)." ELSE '' END";
            $status = 'COALESCE(NULLIF('.SqlText::trim('estado_redmine').", ''), $fallback)";
            $needle = dashboard_normalize_text($filters['estado_redmine']);
            $text->apply($query, $status, fn ($sql) => "$sql = ".$text->quote($needle),
                fn ($value) => dashboard_normalize_text($value) === $needle);
        }
        if (($filters['scope'] ?? 'asignados') === 'asignados') {
            $names = array_values(array_filter($names, fn ($name) => dashboard_normalize_text($name) !== ''));
            $nameQuery = null;
            if ($names !== []) {
                $nameQuery = clone $query;
                $text->names($nameQuery, $names);
                $nameQuery->select('id');
            }
            $assigned = SqlText::trim('id_redmine_asignado');
            $query->where(function (Builder $scope) use ($assigned, $userId, $nameQuery): void {
                $scope->whereRaw("$assigned <> '' AND BINARY $assigned = BINARY ?", [$userId]);
                if ($nameQuery !== null) {
                    $scope->orWhereIn('id', $nameQuery);
                }
            });
        }
        $text->contains($query, 'solicitante', (string) ($filters['buscar'] ?? ''));
        if (($filters['descripcion'] ?? '') !== '') {
            $text->contains($query, 'descripcion', $filters['descripcion']);
        }
        // Dedupe follows filtering. A matching hours row survives if its report
        // counterpart was filtered out; report source wins when both remain.
        if (($filters['fuente'] ?? '') === '') {
            $sourceId = SqlText::trim('fuente_id');
            $applicant = SqlText::trim('solicitante');
            $key = "CASE WHEN numero_ticket_redmine IS NOT NULL THEN CONCAT('redmine:', numero_ticket_redmine) WHEN $sourceId <> '' THEN CONCAT('fuente:', $sourceId) ELSE CONCAT('row:', JSON_ARRAY(_fecha_norm, $applicant, '')) END";
            $ranked = (clone $query)->select(['id', '_selection_key', '_source', '_fecha_norm', '_source_order', '_group_order', 'fecha_reporte'])
                ->selectRaw("ROW_NUMBER() OVER (PARTITION BY ($key) COLLATE utf8mb4_bin ORDER BY _source_order, _group_order, fecha_reporte DESC, id DESC) AS history_rank");
            $query = DB::query()->fromSub($ranked, 'deduplicated_history')->where('history_rank', 1);
        }
        $page = max(1, $page);
        $window = (clone $query)->select(['id', '_selection_key', '_source', '_fecha_norm'])
            ->selectRaw('COUNT(*) OVER () AS _total')->orderByDesc('_fecha_norm')->orderBy('_source_order')->orderBy('_group_order')
            ->orderByDesc('fecha_reporte')->orderByDesc('id');
        $references = (clone $window)->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $total = $references->isNotEmpty() ? (int) $references->first()->_total : ($page === 1 ? 0 : (clone $query)->count());
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
            $references = $total === 0 ? collect() : (clone $window)->offset(($page - 1) * $perPage)->limit($perPage)->get();
        }
        $rows = $references->isEmpty() ? collect() : DB::table('redmine_mantencion_reportes as r')
            ->leftJoin('categorias as c', 'c.id', '=', 'r.categoria_id')->whereIn('r.id', $references->pluck('id')->unique())
            ->get(['r.*', 'c.nombre as categoria_nombre'])->keyBy('id');
        $paged = [];
        foreach ($references as $reference) {
            $row = $rows->get($reference->id);
            if ($row === null) {
                continue;
            }
            $message = $this->reports->rowToMessage($row);
            $message['_fuente'] = $reference->_source;
            $message['_fecha_norm'] = $reference->_fecha_norm;
            if ($reference->_source === 'horas_extra') {
                $message['hora_extra'] = '1';
            }
            $paged[] = $message;
        }
        $users = $categories = [];
        $userChoices = $this->candidates(false, $context, ['r.id', 'r.fecha_reporte', 'r.id_redmine_asignado', 'r.asignado_nombre']);
        $categoryChoices = $this->candidates(false, $context, ['r.id', 'r.fecha_reporte', 'c.nombre as categoria_nombre']);
        foreach ($this->selectorRows($userChoices, 'id_redmine_asignado', 'asignado_nombre') as $row) {
            $users[$row->_key] = $row->_label;
        }
        foreach ($this->selectorRows($categoryChoices, 'categoria_nombre', 'categoria_nombre', true) as $row) {
            $categories[strtolower($row->_key)] = $row->_label;
        }
        ksort($users);
        ksort($categories);

        return ['rows' => $paged, 'total' => $total, 'pages' => $pages, 'page' => $page, 'users' => $users, 'categories' => $categories];
    }

    private function selectorRows(Builder $base, string $key, string $label, bool $lowerKey = false): iterable
    {
        // Fixed-width position mirrors source/group/date DESC/id DESC (NULL dates
        // last). MIN preserves the first insertion of a PHP array key; MAX picks
        // its last label, without sorting two full windows of display strings.
        $position = "CONCAT(_source_order, LPAD(_group_order, 20, '0'), IF(fecha_reporte IS NULL, '1', '0'), "
            ."LPAD(COALESCE(99999999 - CAST(DATE_FORMAT(fecha_reporte, '%Y%m%d') AS UNSIGNED), 99999999), 8, '0'), "
            ."LPAD(18446744073709551615 - id, 20, '0'))";

        // Aggregate repeated pairs in SQL; normalize only the distinct selector
        // values in PHP, preserving its byte lowercase and numeric array keys.
        $pairs = (clone $base)->selectRaw("COALESCE($key, '') COLLATE utf8mb4_bin AS _key, COALESCE($label, '') COLLATE utf8mb4_bin AS _label, MIN($position) AS _first, MAX($position) AS _last")
            ->groupBy('_key', '_label')->get();
        $choices = [];
        foreach ($pairs as $pair) {
            $normalized = trim($pair->_key);
            if ($lowerKey) {
                $normalized = strtolower($normalized);
            }
            if (! isset($choices[$normalized])) {
                $choices[$normalized] = ['first' => $pair->_first, 'last' => $pair->_last, 'label' => trim($pair->_label)];
            } else {
                if (strcmp($pair->_first, $choices[$normalized]['first']) < 0) {
                    $choices[$normalized]['first'] = $pair->_first;
                }
                if (strcmp($pair->_last, $choices[$normalized]['last']) > 0) {
                    $choices[$normalized]['last'] = $pair->_last;
                    $choices[$normalized]['label'] = trim($pair->_label);
                }
            }
        }
        uasort($choices, fn ($a, $b) => strcmp($a['first'], $b['first']));
        foreach ($choices as $key => $choice) {
            yield (object) ['_key' => $key, '_label' => $choice['label']];
        }
    }

    private function readPage(MantencionHistoricoService $history, array $filters, string $userId, array $names, array $statuses, int $page, int $perPage): array
    {
        if (! in_array($perPage, [25, 50, 100], true)) {
            $perPage = 25;
        }
        $query = $this->candidates(($filters['descripcion'] ?? '') !== '');
        $items = [];
        $sqlDateOrder = true;
        foreach ((clone $query)->orderBy('_source_order')->orderBy('_group_order')->orderByDesc('fecha_reporte')->orderByDesc('id')->get() as $row) {
            $message = $this->reports->rowToMessage($row);
            $sqlDateOrder = $sqlDateOrder && $message['fecha'] === (string) ($row->fecha_reporte ?? $row->fecha_inicio ?? '');
            $message['_fuente'] = $row->_source;
            $message['_selection_key'] = $row->_selection_key;
            $items[] = array_intersect_key($message, array_flip(['id', 'fuente_id', 'fecha', 'fecha_inicio',
                'estado', 'estado_redmine', 'status_id', 'redmine_id', 'asignado_a', 'asignado_nombre',
                'core_usuario_asignado', 'solicitante', 'categoria', 'descripcion', '_fuente', '_selection_key']));
        }
        // Text transliteration, token-based name scope and stable deduplication are
        // deliberately shared with the old screen, not approximated by DB collation.
        $filtered = $history->filterRows($items, $filters, $userId, $names, $statuses);
        $keys = array_column($filtered, '_selection_key');
        $dates = array_column($filtered, '_fecha_norm', '_selection_key');
        unset($filtered);
        $total = count($keys);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $offset = ($page - 1) * $perPage;
        $pageKeys = array_slice($keys, $offset, $perPage);
        if ($keys !== [] && $sqlDateOrder && count($keys) < 30000) {
            // The PHP predicate supplies only authorized, deduplicated keys. SQL
            // applies the same stable date/source/group/row order and page window.
            $references = (clone $query)->whereIn('_selection_key', $keys)
                ->orderByRaw('COALESCE(fecha_reporte, fecha_inicio) DESC')
                ->orderBy('_source_order')->orderBy('_group_order')->orderByDesc('fecha_reporte')->orderByDesc('id')
                ->offset($offset)->limit($perPage)->get(['id', '_selection_key', '_source']);
            $pageKeys = $references->pluck('_selection_key')->all();
        } else {
            // Legacy zero/invalid dates may be normalized by Carbon. Preserve that
            // order, and avoid the driver's placeholder limit for very large sets.
            $references = $pageKeys === [] ? collect() : (clone $query)->whereIn('_selection_key', $pageKeys)
                ->limit($perPage)->get(['id', '_selection_key', '_source']);
        }
        $rows = $references->isEmpty() ? collect() : DB::table('redmine_mantencion_reportes as r')
            ->leftJoin('categorias as c', 'c.id', '=', 'r.categoria_id')->whereIn('r.id', $references->pluck('id')->unique())
            ->get(['r.*', 'c.nombre as categoria_nombre'])->keyBy('id');
        $byKey = $references->keyBy('_selection_key');
        $paged = [];
        foreach ($pageKeys as $key) {
            $reference = $byKey->get($key);
            $row = $reference ? $rows->get($reference->id) : null;
            if (! $row) {
                continue;
            }
            $message = $this->reports->rowToMessage($row);
            $message['_fuente'] = $reference->_source;
            $message['_fecha_norm'] = $dates[$key];
            if ($reference->_source === 'horas_extra') {
                $message['hora_extra'] = '1';
            }
            $paged[] = $message;
        }
        // Selector choices retain the previous whole-history order and overwrite rules.
        $users = $categories = [];
        foreach ($items as $item) {
            $users[(string) $item['asignado_a']] = $item['asignado_nombre'];
            $categories[strtolower($item['categoria'])] = $item['categoria'];
        }
        ksort($users);
        ksort($categories);

        return ['rows' => $paged, 'total' => $total, 'pages' => $pages, 'page' => $page, 'users' => $users, 'categories' => $categories];
    }

    private function candidateContext(): array
    {
        $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'redmine-mantencion')->value('id');
        $groups = $this->hours->groupsForOrigen('mantencion');

        return ['module_id' => $moduleId, 'group_ids' => array_column($groups, 'grupo_id')];
    }

    private function candidates(bool $withDescription, ?array $context = null, ?array $columns = null): Builder
    {
        $context ??= $this->candidateContext();
        $moduleId = $context['module_id'];
        $columns ??= ['r.id', 'r.fuente_id', 'r.fecha_reporte', 'r.fecha_inicio', 'r.estado', 'r.estado_redmine', 'r.estado_id',
            'r.numero_ticket_redmine', 'r.id_redmine_asignado', 'r.asignado_nombre', 'r.solicitante', 'c.nombre as categoria_nombre'];
        if ($withDescription) {
            $columns[] = 'r.descripcion';
        }
        $base = DB::table('redmine_mantencion_reportes as r')
            ->where('r.estado', 'archivado')->select($columns)->selectRaw('NULL as tiempo_estimado');
        if (in_array('c.nombre as categoria_nombre', $columns, true)) {
            $base->leftJoin('categorias as c', 'c.id', '=', 'r.categoria_id');
        }
        $reports = (clone $base)->where('r.modulo_id', $moduleId)
            ->selectRaw("'reportes' as _source, 0 as _source_order, 0 as _group_order, CONCAT('r:', r.id) as _selection_key");
        // Reuse the existing ordering of groups, including equal-date ties and NULL owners.
        $ids = $context['group_ids'];
        if ($ids !== []) {
            $order = 'CASE p.grupo_id '.implode(' ', array_map(static fn (int $i): string => 'WHEN ? THEN '.$i, array_keys($ids))).' END';
            $hours = (clone $base)->join('horas_extra_grupo_reportes as p', 'p.reporte_id', '=', 'r.id')
                ->where('p.origen', 'mantencion')->whereIn('p.grupo_id', $ids)
                ->selectRaw("'horas_extra' as _source, 1 as _source_order, $order as _group_order, CONCAT('h:', p.grupo_id, ':', r.id) as _selection_key", $ids);
            // Match the existing hours repository: its source selection is independent
            // of the report-module filter; the same user scope is applied afterwards.
            $reports->unionAll($hours);
        }

        return DB::query()->fromSub($reports, 'history_candidates');
    }
}
