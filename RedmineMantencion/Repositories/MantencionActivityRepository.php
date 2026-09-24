<?php

namespace App\Modulos\RedmineMantencion\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Activity pagination; the service retains actor interpretation and rendering. */
final class MantencionActivityRepository
{
    public function page(Builder $base, int $page, int $perPage, string $viewerId, bool $canViewAll, callable $hydrate, callable $authorize): array
    {
        return DB::transaction(function () use ($base, $page, $perPage, $viewerId, $canViewAll, $hydrate, $authorize): array {
            $query = clone $base;
            if (! $canViewAll) {
                // Keep PHP's exact JSON decoder, casts and identity rules.
                // Details are needed only when the old event inferred its actor
                // from text. Hydrate/redact visible details after SQL pagination.
                $allowed = $legacy = [];
                foreach ((clone $query)->select(['id', 'contexto'])->cursor() as $row) {
                    $actor = $hydrate($row);
                    if (($actor['user_id'] === '' || $viewerId === '') && $actor['user'] === 'Sistema') {
                        $legacy[] = DB::connection()->getPdo()->quote((string) $row->id);
                    } elseif ($authorize($actor)) {
                        $allowed[] = DB::connection()->getPdo()->quote((string) $row->id);
                    }
                }
                if ($legacy !== []) {
                    foreach ((clone $query)->whereRaw('id IN ('.implode(',', $legacy).')')->select(['id', 'contexto', 'detalle'])->cursor() as $row) {
                        if ($authorize($hydrate($row))) {
                            $allowed[] = DB::connection()->getPdo()->quote((string) $row->id);
                        }
                    }
                }
                $query->whereRaw($allowed === [] ? '1 = 0' : 'id IN ('.implode(',', $allowed).')');
            }

            $total = (clone $query)->count();
            $pages = max(1, (int) ceil($total / $perPage));
            $page = min($page, $pages);

            // Keep first occurrence before PHP's existing loose unique/sort
            // rules, including numeric labels, case and trailing whitespace.
            $choices = (clone $query)->select(['tipo', 'canal', 'registrado_at', 'id'])
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY BINARY tipo, BINARY canal ORDER BY registrado_at DESC, id DESC) AS choice_rank');
            $choices = DB::query()->fromSub($choices, 'activity_choices')->where('choice_rank', 1)
                ->orderByDesc('registrado_at')->orderByDesc('id')->get(['tipo', 'canal']);
            $tags = $choices->map(fn ($row): string => strtoupper(trim((string) ($row->tipo ?? 'LOG'))))
                ->filter()->unique()->sort()->values()->all();
            $channels = $choices->map(fn ($row): string => (string) ($row->canal ?? ''))
                ->filter()->unique()->sort()->values()->all();
            $events = (clone $query)->orderByDesc('registrado_at')->orderByDesc('id')
                ->offset(($page - 1) * $perPage)->limit($perPage)
                ->get(['id', 'tipo', 'canal', 'mensaje_id', 'detalle', 'contexto', 'registrado_at'])
                ->map($hydrate)->all();

            return ['events' => $events, 'total' => $total, 'page' => $page, 'per_page' => $perPage,
                'pages' => $pages, 'tags' => $tags, 'channels' => $channels];
        });
    }
}
