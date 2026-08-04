<?php

namespace App\Modulos\RedmineMantencion\Repositories;

use Illuminate\Support\Facades\DB;

final class MantencionActivityRepository
{
    /**
     * @param  array{tag?:string,canal?:string,buscar?:string,desde?:string,hasta?:string}  $filters
     * @return array{events:array<int,array<string,string>>,total:int,page:int,per_page:int,pages:int,tags:array<int,string>,channels:array<int,string>}
     */
    public function search(array $filters, int $page, int $perPage, string $viewerName, bool $canViewAll, string $viewerId): array
    {
        $page = max(1, $page);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 50;
        $query = DB::table('mantencion_log')->whereNotIn('tipo', ['CORE_IMPORT_TRACE', 'MOVIMIENTO']);
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
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $like = '%'.$escaped.'%';
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

        $scoped = $query->orderByDesc('registrado_at')->orderByDesc('id')->get()
            ->map(fn (object $row): array => $this->operationalEvent($row))
            ->filter(fn (array $event): bool => $canViewAll || $this->actorMatches(
                (string) ($event['user'] ?? ''),
                $viewerName,
                (string) ($event['user_id'] ?? ''),
                $viewerId,
            ))
            ->values();

        $total = $scoped->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        return [
            'events' => $scoped->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'tags' => $scoped->pluck('tag')->filter()->unique()->sort()->values()->all(),
            'channels' => $scoped->pluck('canal')->filter()->unique()->sort()->values()->all(),
        ];
    }

    public function clearForUser(string $viewerName, string $viewerId): int
    {
        $ids = DB::table('mantencion_log')->get()
            ->map(fn (object $row): array => $this->operationalEvent($row))
            ->filter(fn (array $event): bool => $this->actorMatches(
                (string) ($event['user'] ?? ''),
                $viewerName,
                (string) ($event['user_id'] ?? ''),
                $viewerId,
            ))
            ->pluck('id')
            ->filter()
            ->all();

        return $ids === [] ? 0 : DB::table('mantencion_log')->whereIn('id', $ids)->delete();
    }

    public function record(string $tag, string $details, string $viewerName, string $viewerId): void
    {
        try {
            DB::table('mantencion_log')->insert([
                'canal' => 'seguridad',
                'tipo' => $tag,
                'detalle' => $details,
                'contexto' => json_encode(array_filter([
                    'user_id' => trim($viewerId),
                    'user_name' => trim($viewerName),
                ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'registrado_at' => now(),
            ]);
        } catch (\Throwable) {
            // Operational auditing must never turn an already-completed
            // external operation into an HTTP 500 response.
        }
    }

    public function actorMatches(string $eventUser, string $viewerName, string $eventUserId = '', string $viewerId = ''): bool
    {
        if ($eventUserId !== '' && $viewerId !== '') {
            return $eventUserId === $viewerId;
        }

        $event = trim($this->normalize($eventUser));
        $viewer = trim($this->normalize($viewerName));
        if ($event === '' || $viewer === '' || $event === 'sistema') {
            return false;
        }
        if ($event === $viewer) {
            return true;
        }

        return (explode(' ', $event)[0] ?? '') !== ''
            && (explode(' ', $event)[0] ?? '') === (explode(' ', $viewer)[0] ?? '');
    }

    /** @return array<string,string> */
    private function operationalEvent(object $row): array
    {
        $tag = strtoupper(trim((string) ($row->tipo ?? 'LOG')));
        $details = trim((string) ($row->detalle ?? ''));
        $context = json_decode((string) ($row->contexto ?? ''), true);
        $context = is_array($context) ? $context : [];
        $labels = [
            'LOGIN_SUCCESS' => 'Inicio de sesión', 'LOGIN_FAILURE' => 'Intento de acceso fallido',
            'LOGIN_BLOCKED' => 'Acceso bloqueado', 'LOGOUT' => 'Cierre de sesión',
            'SESSION_TIMEOUT' => 'Sesión expirada', 'SESSION_EXTEND' => 'Sesión extendida',
            'SESSION_EXTEND_FAIL' => 'Error al extender sesión', 'REDMINE_SEND' => 'Envío a Redmine',
            'ENVIO' => 'Comunicación con Redmine', 'CORE_IMPORT' => 'Importación desde CORE',
            'CORE_IMPORT_TRACE' => 'Proceso de importación CORE', 'CORE_IMPORT_FAIL' => 'Error al importar desde CORE',
            'REPORT_UPDATE' => 'Reporte actualizado', 'REPORT_ARCHIVE' => 'Reporte archivado',
            'REPORT_CREATE' => 'Reporte manual creado',
            'REPORT_DELETE' => 'Reporte eliminado', 'REPORT_DELETE_BULK' => 'Reportes eliminados',
            'HORA_EXTRA' => 'Hora extra actualizada', 'MOVIMIENTO' => 'Navegación del módulo',
            'ACTIVITY_CLEAR' => 'Bitácora vaciada',
        ];
        $result = preg_match('/(?:FAIL|FAILURE|ERROR|BLOCKED|TIMEOUT)/', $tag) ? 'error' : 'success';
        if (in_array($tag, ['MOVIMIENTO', 'CORE_IMPORT_TRACE'], true)) {
            $result = 'info';
        }

        $user = trim((string) ($context['user_name'] ?? '')) ?: 'Sistema';
        $userId = trim((string) ($context['user_id'] ?? ''));
        if ($user === 'Sistema' && preg_match('/^NOVA (?:sesion (?:extendida|cerrada) por|User)\s+([^|]+?)(?:\s*\|\s*|$)/ui', $details, $match)) {
            $user = trim($match[1]);
            $details = trim((string) preg_replace('/^NOVA [^|]+(?:\|\s*)?/ui', '', $details));
        } elseif ($user === 'Sistema' && preg_match('/^([^|]+?)\s*\(ID\s*[^)]+\)\s*\|/u', $details, $match)) {
            $user = trim($match[1]);
            $details = trim((string) preg_replace('/^[^|]+\|\s*/u', '', $details));
        } elseif ($user === 'Sistema' && preg_match('/\bUsuario=([^|]+)$/ui', $details, $match)) {
            $user = trim($match[1]);
        }

        $details = preg_replace('/\b(password|contrase(?:ñ|n)a|token|api[_ -]?key)\s*[:=]\s*[^\s|,;]+/iu', '$1: [oculto]', $details) ?? $details;

        return [
            'id' => (string) ($row->id ?? ''),
            'ts' => (string) ($row->registrado_at ?? ''),
            'tag' => $tag,
            'action' => $labels[$tag] ?? ucfirst(strtolower(str_replace('_', ' ', $tag))),
            'user_id' => $userId,
            'user' => $user !== '' ? $user : 'Sistema',
            'result' => $result,
            'details' => mb_strimwidth($details !== '' ? $details : 'Sin detalles adicionales.', 0, 360, '…'),
            'canal' => (string) ($row->canal ?? ''),
            'mensaje_id' => (string) ($row->mensaje_id ?? ''),
        ];
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return preg_replace('/[^a-z0-9]+/', ' ', is_string($ascii) ? $ascii : $value) ?: '';
    }
}
