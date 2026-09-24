<?php

use App\Modulos\RedmineMantencion\Services\MantencionSecurityService;
use Illuminate\Support\Facades\DB;

// Original activity selection before SQL scope/pagination.
return static function (array $filters, int $page = 1, int $perPage = 50, string $viewerName = '', bool $canViewAll = false, string $viewerId = ''): array {
    $service = new MantencionSecurityService;
    $page = max(1, $page);
    $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;

    try {
        $hiddenOperationalTags = ['CORE_IMPORT_TRACE', 'MOVIMIENTO'];
        $query = DB::table('mantencion_log')->whereNotIn('tipo', $hiddenOperationalTags);
        $tag = strtoupper(trim((string) ($filters['tag'] ?? '')));
        $channel = strtolower(trim((string) ($filters['canal'] ?? '')));
        $search = trim((string) ($filters['buscar'] ?? ''));
        $from = trim((string) ($filters['desde'] ?? ''));
        $to = trim((string) ($filters['hasta'] ?? ''));

        if ($tag === 'NEXTCLOUD') {
            $query->where('tipo', 'like', 'NEXTCLOUD\_%');
        } elseif ($tag !== '') {
            $query->where('tipo', $tag);
        }
        if ($channel !== '') {
            $query->where('canal', $channel);
        }
        if ($search !== '') {
            $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $like = '%'.$escapedSearch.'%';
            $query->where(static function ($nested) use ($like): void {
                $nested->where('detalle', 'like', $like)
                    ->orWhere('tipo', 'like', $like)
                    ->orWhere('canal', 'like', $like)
                    ->orWhere('mensaje_id', 'like', $like);
            });
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $query->where('registrado_at', '>=', $from.' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $query->where('registrado_at', '<=', $to.' 23:59:59');
        }

        $scopedEvents = $query->orderByDesc('registrado_at')->orderByDesc('id')->get()
            ->map(fn ($row): array => $service->operationalEvent($row))
            ->filter(fn (array $event): bool => $canViewAll || $service->actorMatches((string) ($event['user'] ?? ''), $viewerName, (string) ($event['user_id'] ?? ''), $viewerId))
            ->values();
        $total = $scopedEvents->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $tags = $scopedEvents->pluck('tag')->filter()->unique()->sort()->values()->all();
        $channels = $scopedEvents->pluck('canal')->filter()->unique()->sort()->values()->all();
        $events = $scopedEvents->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return ['events' => $events, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages, 'tags' => $tags, 'channels' => $channels];
    } catch (Throwable) {
        return ['events' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 1, 'tags' => [], 'channels' => []];
    }
};
