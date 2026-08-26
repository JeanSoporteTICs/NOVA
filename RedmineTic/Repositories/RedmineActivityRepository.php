<?php

namespace RedmineTic\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manages activity log entries for a Redmine TIC module.
 * Table: tic_log
 */
class RedmineActivityRepository
{
    public function __construct(
        private string $projectKey,
        private string $projectName,
    ) {}

    /** @return array<int,string> */
    public function activity(): array
    {
        if (!$this->tableAvailable()) {
            return [];
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return [];
        }

        return DB::table('tic_log')
            ->where('modulo_id', $moduleId)
            ->orderByDesc('creado_at')
            ->limit(200)
            ->pluck('linea')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{entries:array<int,array<string,mixed>>,total:int,page:int,per_page:int,pages:int,events:array<int,string>}
     */
    public function search(array $filters = [], string $viewerId = '', bool $canViewAll = false): array
    {
        $moduleId = $this->moduleId();
        $page = max(1, (int)($filters['page'] ?? 1));
        $requestedPerPage = (int)($filters['per_page'] ?? 50);
        $perPage = in_array($requestedPerPage, [25, 50, 100], true) ? $requestedPerPage : 50;
        if (!$this->tableAvailable() || $moduleId === null) {
            return ['entries' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 1, 'events' => []];
        }

        $hiddenOperationalEvents = ['consulta_datos', 'envio_redmine_http'];
        $query = DB::table('tic_log')->where('modulo_id', $moduleId)->whereNotIn('evento', $hiddenOperationalEvents);
        $event = trim((string)($filters['evento'] ?? ''));
        $search = trim((string)($filters['buscar'] ?? ''));
        $from = trim((string)($filters['desde'] ?? ''));
        $to = trim((string)($filters['hasta'] ?? ''));
        if ($event !== '') $query->where('evento', $event);
        if ($search !== '') {
            $query->where(static function ($nested) use ($search): void {
                $nested->where('evento', 'like', '%' . $search . '%')->orWhere('contexto', 'like', '%' . $search . '%');
            });
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $query->where('creado_at', '>=', $from . ' 00:00:00');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $query->where('creado_at', '<=', $to . ' 23:59:59');

        $scopedEntries = $query->orderByDesc('creado_at')->orderByDesc('id')->get()
            ->map(fn($row): array => $this->operationalEntry($row))
            ->filter(static fn(array $entry): bool => $canViewAll || ($viewerId !== '' && (string)($entry['user_id'] ?? '') === $viewerId))
            ->values();
        $total = $scopedEntries->count();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $pages);
        $events = $scopedEntries->pluck('event')->filter()->unique()->sort()->values()->all();
        $entries = $scopedEntries->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return ['entries' => $entries, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages, 'events' => $events];
    }

    public function clearForUser(string $userId): int
    {
        $moduleId = $this->moduleId();
        if (!$this->tableAvailable() || $moduleId === null || trim($userId) === '') return 0;
        $ids = DB::table('tic_log')->where('modulo_id', $moduleId)->get(['id', 'contexto'])
            ->filter(static function ($row) use ($userId): bool {
                $context = json_decode((string)($row->contexto ?? ''), true);
                return is_array($context) && (string)($context['user_id'] ?? '') === $userId;
            })->pluck('id')->all();
        return $ids === [] ? 0 : DB::table('tic_log')->whereIn('id', $ids)->delete();
    }

    /** @return array<string,mixed> */
    private function operationalEntry(object $row): array
    {
        $event = (string)($row->evento ?? 'evento');
        $context = json_decode((string)($row->contexto ?? ''), true);
        $context = is_array($context) ? $context : [];
        $httpCode = (int)($context['http_code'] ?? 0);
        $declaredResult = strtolower(trim((string)($context['result'] ?? '')));
        $result = in_array($declaredResult, ['success', 'error', 'info'], true)
            ? $declaredResult
            : (str_contains($event, 'error') || str_contains($event, 'fail') || trim((string)($context['error'] ?? '')) !== '' || ($httpCode >= 400)
                ? 'error'
                : ((str_contains($event, '_ok') || str_starts_with($event, 'reporte_') || ($httpCode >= 200 && $httpCode < 300)) ? 'success' : 'info'));
        $labels = [
            'consulta_datos' => 'Consulta de datos', 'recepcion_datos' => 'Reporte recibido',
            'recepcion_telegram' => 'Reporte desde Telegram', 'envio_redmine_http' => 'Comunicación con Redmine',
            'envio_redmine_ok' => 'Envío a Redmine', 'envio_redmine_error' => 'Error al enviar a Redmine',
            'envio_redmine_resumen' => 'Resumen de envío', 'sincronizacion_usuarios_ok' => 'Usuarios sincronizados',
            'reporte_update' => 'Reporte actualizado', 'reporte_delete' => 'Reporte eliminado',
            'reporte_delete_selected' => 'Reportes eliminados', 'reporte_archive_selected' => 'Reportes archivados',
            'reporte_reset_errors' => 'Errores restablecidos', 'reporte_toggle_hours_extra' => 'Hora extra actualizada',
            'reporte_manual_creado' => 'Reporte manual creado',
            'reporte_rapido_creado' => 'Reporte rápido creado',
            'reporte_rapido_telegram_ok' => 'Notificación Telegram enviada',
            'reporte_rapido_telegram_pendiente' => 'Notificación Telegram pendiente',
            'actividad_limpiada' => 'Bitácora vaciada',
            'sincronizacion_usuarios_error' => 'Error al sincronizar usuarios',
            'sincronizacion_categorias_ok' => 'Categorías sincronizadas', 'sincronizacion_categorias_error' => 'Error al sincronizar categorías',
            'sincronizacion_unidades_ok' => 'Unidades sincronizadas', 'sincronizacion_unidades_error' => 'Error al sincronizar unidades',
        ];
        $safeKeys = ['message_id', 'redmine_id', 'http_code', 'asunto', 'categoria', 'unidad', 'attempts', 'success', 'errors', 'total', 'count', 'created', 'updated', 'changed', 'reason'];
        $details = [];
        foreach ($safeKeys as $key) {
            if (!isset($context[$key]) || is_array($context[$key]) || is_object($context[$key]) || trim((string)$context[$key]) === '') continue;
            $details[] = str_replace('_', ' ', ucfirst($key)) . ': ' . mb_strimwidth((string)$context[$key], 0, 180, '…');
        }
        if ($result === 'error' && isset($context['error']) && is_string($context['error'])) {
            $error = preg_replace('/\{.*$/s', '', $context['error']) ?? '';
            if (trim($error) !== '') $details[] = 'Error: ' . mb_strimwidth(trim($error), 0, 220, '…');
        }

        return [
            'ts' => (string)($row->creado_at ?? ''), 'event' => $event,
            'action' => $labels[$event] ?? ucfirst(str_replace('_', ' ', $event)),
            'user_id' => trim((string)($context['user_id'] ?? '')), 'result' => $result,
            'details' => $details !== [] ? implode(' · ', $details) : 'Sin detalles adicionales.',
        ];
    }

    public function clearActivity(): void
    {
        if (!$this->tableAvailable()) {
            return;
        }

        $moduleId = $this->moduleId();
        if ($moduleId !== null) {
            DB::table('tic_log')->where('modulo_id', $moduleId)->delete();
        }
    }

    /** @param array<string,mixed> $context */
    public function append(string $event, array $context = []): void
    {
        $entry = [
            'ts'      => now('America/Santiago')->format('Y-m-d H:i:s'),
            'event'   => $event,
            'context' => $context,
        ];

        if (!$this->tableAvailable()) {
            return;
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return;
        }

        DB::table('tic_log')->insert([
            'modulo_id' => $moduleId,
            'evento'    => $event,
            'contexto'  => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'linea'     => json_encode($entry,   JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'creado_at' => now(),
        ]);
    }

    private function tableAvailable(): bool
    {
        try {
            return Schema::hasTable('modulos_nova') && Schema::hasTable('tic_log');
        } catch (\Throwable) {
            return false;
        }
    }

    private function moduleId(): ?int
    {
        try {
            $id = DB::table('modulos_nova')->where('clave_modulo', $this->projectKey)->value('id');
            if ($id !== null) {
                return (int) $id;
            }

            DB::table('modulos_nova')->insert([
                'clave_modulo'   => $this->projectKey,
                'nombre'         => $this->projectName,
                'descripcion'    => '',
                'icono'          => '',
                'tipo'           => 'native',
                'ruta'           => $this->projectKey,
                'entrada'        => 'laravel:redmine.native.dashboard',
                'habilitado'     => 1,
                'orden'          => 100,
                'creado_at'      => now(),
                'actualizado_at' => now(),
            ]);

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }
}
