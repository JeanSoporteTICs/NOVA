<?php
require_once __DIR__ . '/logger.php';
function security_load_events(int $limit = 20): array {
    try { return \Illuminate\Support\Facades\DB::table('mantencion_log')->latest('registrado_at')->limit($limit)->get()->map(static fn($row): array => ['ts'=>(new DateTimeImmutable((string)$row->registrado_at))->format('d-m-Y H:i:s'),'tag'=>(string)($row->tipo??'LOG'),'details'=>(string)($row->detalle??''),'canal'=>(string)($row->canal??'')])->all(); } catch (Throwable) { return []; }
}
function security_clear_events(): bool {
    try { \Illuminate\Support\Facades\DB::table('mantencion_log')->delete(); return true; } catch (Throwable) { return false; }
}

/**
 * @param array{tag?:string,canal?:string,buscar?:string,desde?:string,hasta?:string} $filters
 * @return array{events:array<int,array<string,string>>,total:int,page:int,per_page:int,pages:int,tags:array<int,string>,channels:array<int,string>}
 */
function security_search_events(array $filters, int $page = 1, int $perPage = 50, string $viewerName = '', bool $canViewAll = false, string $viewerId = ''): array {
    $page = max(1, $page);
    $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;

    try {
        $hiddenOperationalTags = ['CORE_IMPORT_TRACE', 'MOVIMIENTO'];
        $query = \Illuminate\Support\Facades\DB::table('mantencion_log')->whereNotIn('tipo', $hiddenOperationalTags);
        $tag = strtoupper(trim((string)($filters['tag'] ?? '')));
        $channel = strtolower(trim((string)($filters['canal'] ?? '')));
        $search = trim((string)($filters['buscar'] ?? ''));
        $from = trim((string)($filters['desde'] ?? ''));
        $to = trim((string)($filters['hasta'] ?? ''));

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
            $like = '%' . $escapedSearch . '%';
            $query->where(static function ($nested) use ($like): void {
                $nested->where('detalle', 'like', $like)
                    ->orWhere('tipo', 'like', $like)
                    ->orWhere('canal', 'like', $like)
                    ->orWhere('mensaje_id', 'like', $like);
            });
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $query->where('registrado_at', '>=', $from . ' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $query->where('registrado_at', '<=', $to . ' 23:59:59');
        }

        $scopedEvents = $query->orderByDesc('registrado_at')->orderByDesc('id')->get()
            ->map(static fn($row): array => security_operational_event($row))
            ->filter(static fn(array $event): bool => $canViewAll || security_actor_matches((string)($event['user'] ?? ''), $viewerName, (string)($event['user_id'] ?? ''), $viewerId))
            ->values();
        $total = $scopedEvents->count();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $tags = $scopedEvents->pluck('tag')->filter()->unique()->sort()->values()->all();
        $channels = $scopedEvents->pluck('canal')->filter()->unique()->sort()->values()->all();
        $events = $scopedEvents->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return ['events' => $events, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages, 'tags' => $tags, 'channels' => $channels];
    } catch (Throwable) {
        return ['events' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 1, 'tags' => [], 'channels' => []];
    }
}

function security_actor_matches(string $eventUser, string $viewerName, string $eventUserId = '', string $viewerId = ''): bool {
    $normalize = static function (string $value): string {
        $value = strtolower(trim($value));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return preg_replace('/[^a-z0-9]+/', ' ', is_string($ascii) ? $ascii : $value) ?: '';
    };
    if ($eventUserId !== '' && $viewerId !== '') return $eventUserId === $viewerId;
    $event = trim($normalize($eventUser));
    $viewer = trim($normalize($viewerName));
    if ($event === '' || $viewer === '' || $event === 'sistema') return false;
    if ($event === $viewer) return true;
    $eventFirst = explode(' ', $event)[0] ?? '';
    $viewerFirst = explode(' ', $viewer)[0] ?? '';
    return $eventFirst !== '' && $eventFirst === $viewerFirst;
}

function security_clear_user_events(string $viewerName, string $viewerId = ''): int {
    try {
        $ids = \Illuminate\Support\Facades\DB::table('mantencion_log')->get()
            ->map(static fn($row): array => security_operational_event($row))
            ->filter(static fn(array $event): bool => security_actor_matches((string)($event['user'] ?? ''), $viewerName, (string)($event['user_id'] ?? ''), $viewerId))
            ->pluck('id')->filter()->all();
        return $ids === [] ? 0 : \Illuminate\Support\Facades\DB::table('mantencion_log')->whereIn('id', $ids)->delete();
    } catch (Throwable) {
        return 0;
    }
}

/** @return array<string,string> */
function security_operational_event(object $row): array {
    $tag = strtoupper(trim((string)($row->tipo ?? 'LOG')));
    $details = trim((string)($row->detalle ?? ''));
    $context = json_decode((string)($row->contexto ?? ''), true);
    $context = is_array($context) ? $context : [];
    $labels = [
        'LOGIN_SUCCESS' => 'Inicio de sesión', 'LOGIN_FAILURE' => 'Intento de acceso fallido',
        'LOGIN_BLOCKED' => 'Acceso bloqueado', 'LOGOUT' => 'Cierre de sesión',
        'SESSION_TIMEOUT' => 'Sesión expirada', 'SESSION_EXTEND' => 'Sesión extendida',
        'SESSION_EXTEND_FAIL' => 'Error al extender sesión', 'REDMINE_SEND' => 'Envío a Redmine',
        'ENVIO' => 'Comunicación con Redmine', 'CORE_IMPORT' => 'Importación desde CORE',
        'CORE_IMPORT_TRACE' => 'Proceso de importación CORE', 'CORE_IMPORT_FAIL' => 'Error al importar desde CORE',
        'REPORT_UPDATE' => 'Reporte actualizado', 'REPORT_ARCHIVE' => 'Reporte archivado',
        'REPORT_DELETE' => 'Reporte eliminado', 'REPORT_DELETE_BULK' => 'Reportes eliminados',
        'HORA_EXTRA' => 'Hora extra actualizada', 'MOVIMIENTO' => 'Navegación del módulo',
        'ACTIVITY_CLEAR' => 'Bitácora vaciada',
    ];
    $result = preg_match('/(?:FAIL|FAILURE|ERROR|BLOCKED|TIMEOUT)/', $tag) ? 'error' : 'success';
    if (in_array($tag, ['MOVIMIENTO', 'CORE_IMPORT_TRACE'], true)) $result = 'info';
    $user = 'Sistema';
    $userId = trim((string)($context['user_id'] ?? ''));
    if (trim((string)($context['user_name'] ?? '')) !== '') $user = trim((string)$context['user_name']);
    if ($user === 'Sistema' && preg_match('/^NOVA (?:sesion (?:extendida|cerrada) por|User)\s+([^|]+?)(?:\s*\|\s*|$)/ui', $details, $match)) {
        $user = trim($match[1]);
        $details = trim((string)preg_replace('/^NOVA [^|]+(?:\|\s*)?/ui', '', $details));
    } elseif ($user === 'Sistema' && preg_match('/^([^|]+?)\s*\(ID\s*[^)]+\)\s*\|/u', $details, $match)) {
        $user = trim($match[1]);
        $details = trim((string)preg_replace('/^[^|]+\|\s*/u', '', $details));
    } elseif ($user === 'Sistema' && preg_match('/\bUsuario=([^|]+)$/ui', $details, $match)) {
        $user = trim($match[1]);
    }
    $details = preg_replace('/\b(password|contrase(?:ñ|n)a|token|api[_ -]?key)\s*[:=]\s*[^\s|,;]+/iu', '$1: [oculto]', $details) ?? $details;
    $details = mb_strimwidth($details, 0, 360, '…');

    return [
        'id' => (string)($row->id ?? ''), 'ts' => (string)($row->registrado_at ?? ''), 'tag' => $tag,
        'action' => $labels[$tag] ?? ucfirst(strtolower(str_replace('_', ' ', $tag))), 'user_id' => $userId,
        'user' => $user !== '' ? $user : 'Sistema', 'result' => $result,
        'details' => $details !== '' ? $details : 'Sin detalles adicionales.',
        'canal' => (string)($row->canal ?? ''), 'mensaje_id' => (string)($row->mensaje_id ?? ''),
    ];
}
