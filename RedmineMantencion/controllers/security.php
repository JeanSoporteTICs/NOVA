<?php
require_once __DIR__ . '/logger.php';

// El resto de la lógica de seguridad/actividad vive ahora en
// App\Modulos\RedmineMantencion\Services\MantencionSecurityService.
// Esta función se queda global porque MantencionDashboardService (Fase 4)
// la usa para el widget de actividad reciente del Dashboard.
function security_load_events(int $limit = 20): array {
    try { return \Illuminate\Support\Facades\DB::table('mantencion_log')->latest('registrado_at')->limit($limit)->get()->map(static fn($row): array => ['ts'=>(new DateTimeImmutable((string)$row->registrado_at))->format('d-m-Y H:i:s'),'tag'=>(string)($row->tipo??'LOG'),'details'=>(string)($row->detalle??''),'canal'=>(string)($row->canal??'')])->all(); } catch (Throwable) { return []; }
}
