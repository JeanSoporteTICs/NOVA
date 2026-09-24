<?php

namespace RedmineTic\Repositories;

use App\Repositories\Database\SqlText;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RedmineTic\Support\DateSupport;
use RedmineTic\Support\HistoryFilter;

/** Read-only SQL page; authorization and hydration remain owned by the facade. */
final class RedmineHistoryRepository
{
    public function page(int $moduleId, array $filters, callable $resolveName, callable $authorize, callable $hydrate, bool $hasHours): ?array
    {
        return DB::transaction(function () use ($moduleId, $filters, $resolveName, $authorize, $hydrate, $hasHours): ?array {
            $base = DB::table('redmine_tic_reportes as r')->where('r.modulo_id', $moduleId)->where('r.estado', 'archivado');
            // Names are resolved once per distinct assignee, through the same user
            // projection and scope predicate as history(). No report text is read.
            $assignees = (clone $base)->selectRaw("DISTINCT BINARY COALESCE(r.asignado_a, '') AS assignee")->pluck('assignee');
            $names = [];
            $allowed = [];
            $candidates = [];
            foreach ($assignees as $id) {
                $name = $resolveName((string) $id);
                $names[(string) $id] = $name;
                $candidates[] = ['asignado_a' => (string) $id, 'asignado_nombre' => $name];
            }
            foreach ($authorize($candidates) as $candidate) {
                $allowed[] = $this->quote($candidate['asignado_a']);
            }
            if ($allowed === []) {
                return ['rows' => [], 'total' => 0, 'hours' => 0, 'page' => 1, 'pages' => 1, 'categories' => [], 'statuses' => []];
            }

            $date = "COALESCE(CAST(r.fecha_inicio AS CHAR), '')";
            $dateCases = [];
            foreach ((clone $base)->whereNotNull('r.fecha_inicio')
                ->whereRaw("(COALESCE(MONTH($date), 0) = 0 OR COALESCE(DAY($date), 0) = 0 OR DAY($date) > DAY(LAST_DAY($date)))")
                ->selectRaw("DISTINCT $date AS legacy_date")->get() as $exception) {
                $value = $exception->legacy_date;
                $dateCases[] = 'WHEN '.$this->quote((string) $value).' THEN '.$this->quote(HistoryFilter::date(DateSupport::databaseDate($value)));
            }
            if ($dateCases !== []) {
                $date = 'CASE '.$date.' '.implode(' ', $dateCases).' ELSE '.$date.' END';
            }
            $columns = ['r.id', 'r.creado_at', 'r.actualizado_at'];
            $base->select($columns)->selectRaw("$date AS history_date")
                ->selectRaw('ROW_NUMBER() OVER (ORDER BY r.actualizado_at DESC) AS original_order');
            $category = $this->catalog($base, 'category', 'categoria_catalogo_id', $moduleId);
            $base->selectRaw(SqlText::trim($category).' AS history_category')
                ->selectRaw(SqlText::trim('r.estado_redmine').' AS history_status');
            $base->selectRaw("COALESCE(r.asignado_a, '') AS history_assignee");
            $base->selectRaw('CASE WHEN '.SqlText::trim('r.origen')." COLLATE utf8mb4_bin REGEXP '(?-i)^[tT][eE][lL][eE][gG][rR][aA][mM]$' OR ".SqlText::trim('r.chat_id_telegram')." <> '' THEN 'telegram' ELSE 'manual' END AS history_source");
            if ($hasHours) {
                // groupsForOrigen casts report references to PHP integers.
                $base->selectRaw("($date <> '' AND r.id <= ".PHP_INT_MAX." AND EXISTS (SELECT 1 FROM horas_extra_grupo_reportes p INNER JOIN horas_extra_grupos g ON g.id = p.grupo_id WHERE p.origen = 'tic' AND p.reporte_id = r.id)) AS history_hours");
            } else {
                $base->selectRaw('0 AS history_hours');
            }
            if (trim((string) ($filters['buscar'] ?? '')) !== '') {
                $unit = $this->catalog($base, 'unit', 'unidad_catalogo_id', $moduleId);
                $applicantUnit = $this->catalog($base, 'applicant_unit', 'unidad_solicitante_catalogo_id', $moduleId);
                $unit = 'COALESCE(NULLIF(NULLIF('.SqlText::trim('r.unidad_texto').", ''), '0'), $unit)";
                $nameCases = [];
                foreach ($names as $id => $name) {
                    $nameCases[] = 'WHEN '.$this->quote((string) $id).' THEN '.$this->quote($name);
                }
                $name = $nameCases === [] ? "''" : "CASE BINARY COALESCE(r.asignado_a, '') ".implode(' ', $nameCases)." ELSE '' END";
                $fields = ["COALESCE(r.redmine_id, '')", "COALESCE(r.asunto, '')", "COALESCE(r.mensaje, '')", "COALESCE(r.solicitante, '')",
                    $applicantUnit, $unit, $name, "COALESCE(r.asignado_a, '')", SqlText::trim($category), SqlText::trim('r.estado_redmine')];
                $base->selectRaw("CONCAT_WS(' ', ".implode(', ', $fields).') AS history_search');
            }
            if (trim((string) ($filters['descripcion'] ?? '')) !== '') {
                $base->addSelect('r.descripcion');
            }
            $ordered = DB::query()->fromSub($base, 'history')->select('*')
                ->selectRaw('ROW_NUMBER() OVER (ORDER BY history_date DESC, creado_at DESC, original_order) AS history_position');
            $query = DB::query()->fromSub($ordered, 'ordered_history')->whereRaw($allowed === [] ? '1 = 0' : 'BINARY history_assignee IN ('.implode(',', $allowed).')');
            // The old SELECT * has no deterministic tie breaker. Its filesort can
            // order equal timestamps differently with a narrower projection.
            // Retain that exact reader when the final order is ambiguous.
            if ((clone $query)->selectRaw('1')->groupBy('history_date', 'creado_at', 'actualizado_at')->havingRaw('COUNT(*) > 1')->exists()) {
                return null;
            }
            $choices = (clone $query)->selectRaw('BINARY history_category AS choice_category, BINARY history_status AS choice_status, MIN(history_position) AS first_position')
                ->groupBy('choice_category', 'choice_status')
                ->orderBy('first_position')->get();
            $categories = $statuses = [];
            foreach ($choices as $choice) {
                if ($choice->choice_category !== '') {
                    $categories[$choice->choice_category] = $choice->choice_category;
                }
                if ($choice->choice_status !== '') {
                    $statuses[$choice->choice_status] = $choice->choice_status;
                }
            }
            ksort($categories);
            // Statuses are merged with configured options by the view before sorting.
            foreach (['desde' => '>=', 'hasta' => '<='] as $key => $operator) {
                $bound = HistoryFilter::date($filters[$key] ?? '');
                if ($bound !== '') {
                    $query->whereRaw("(history_date = '' OR BINARY history_date $operator BINARY ?)", [$bound]);
                }
            }
            foreach (['fuente' => 'history_source', 'categoria' => 'history_category'] as $key => $column) {
                $value = trim((string) ($filters[$key] ?? ''));
                if ($value !== '') {
                    $query->whereRaw("BINARY $column = BINARY ?", [$value]);
                }
            }
            $this->textFilter($query, 'history_status', trim((string) ($filters['estado_redmine'] ?? '')), true);
            $this->textFilter($query, 'history_search', trim((string) ($filters['buscar'] ?? '')));
            $this->textFilter($query, 'descripcion', trim((string) ($filters['descripcion'] ?? '')));
            $totals = (clone $query)->selectRaw('COUNT(*) AS total, COALESCE(SUM(history_hours), 0) AS hours')->first();
            $perPage = (int) ($filters['per_page'] ?? 25);
            $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;
            $pages = max(1, (int) ceil($totals->total / $perPage));
            $page = min(max(1, (int) ($filters['page'] ?? 1)), $pages);
            $references = (clone $query)->select(['id', 'history_date', 'history_hours'])
                ->orderByDesc('history_date')->orderByDesc('creado_at')->orderBy('original_order')
                ->offset(($page - 1) * $perPage)->limit($perPage)->get();
            $details = $references->isEmpty() ? collect() : DB::table('redmine_tic_reportes')->where('modulo_id', $moduleId)
                ->whereIn('id', $references->pluck('id'))->get()->keyBy('id');
            $rows = [];
            foreach ($references as $reference) {
                $report = $hydrate($details->get($reference->id));
                $report['_history_type'] = $reference->history_hours ? 'Hora extra' : 'Archivado';
                $report['_history_can_delete'] = true;
                $report['_history_sort_date'] = $reference->history_date;
                if ($reference->history_hours) {
                    $report['_history_is_hours_extra'] = true;
                }
                $report['_history_date_norm'] = $reference->history_date;
                $rows[] = $report;
            }

            return ['rows' => $rows, 'total' => (int) $totals->total, 'hours' => (int) $totals->hours,
                'page' => $page, 'pages' => $pages, 'categories' => $categories, 'statuses' => $statuses];
        });
    }

    private function quote(string $value): string
    {
        return DB::connection()->getPdo()->quote($value);
    }

    private function catalog(Builder $query, string $alias, string $column, int $moduleId): string
    {
        $query->leftJoin("catalogos_modulo as $alias", function ($join) use ($alias, $column, $moduleId): void {
            $join->on("$alias.id", '=', "r.$column")->where("$alias.modulo_id", $moduleId)
                ->whereRaw(SqlText::trim("$alias.tipo")." <> ''");
        });

        // hydrate() uses PHP ?:, so a catalog label '0' falls back to empty.
        return 'COALESCE(NULLIF('.SqlText::trim("$alias.nombre").", '0'), '')";
    }

    private function textFilter(Builder $query, string $column, string $needle, bool $equals = false): void
    {
        if ($needle === '') {
            return;
        }
        $needle = HistoryFilter::text($needle);
        if (! $equals && $needle === '') {
            return;
        }
        $nonAscii = "COALESCE($column, '') COLLATE utf8mb4_bin REGEXP ".$this->quote('(?-i)[^\x00-\x7f]');
        $ids = [];
        foreach ((clone $query)->whereRaw($nonAscii)->select(['id', $column])->cursor() as $row) {
            $value = HistoryFilter::text($row->$column);
            if ($equals ? $value === $needle : str_contains($value, $needle)) {
                $ids[] = $this->quote((string) $row->id);
            }
        }
        $normalized = 'LOWER('.SqlText::trim($column).') COLLATE utf8mb4_bin';
        $value = $this->quote($needle);
        $predicate = $equals ? "BINARY $normalized = BINARY $value" : "LOCATE(BINARY $value, BINARY $normalized) > 0";
        $query->where(function ($where) use ($nonAscii, $predicate, $ids): void {
            $where->whereRaw("NOT ($nonAscii) AND ($predicate)");
            if ($ids !== []) {
                $where->orWhereRaw('id IN ('.implode(',', $ids).')');
            }
        });
    }
}
