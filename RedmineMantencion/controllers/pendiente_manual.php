<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/dashboard.php';

// El resto de la lógica de Pendiente Manual vive ahora en
// App\Modulos\RedmineMantencion\Services\MantencionPendientesService.
// Esta función se queda global porque maintenance.php la invoca de forma
// condicional (function_exists) para mostrar el aviso de modo mantención
// en esta pantalla específica, sin depender de qué controller está activo.
function manual_pending_flash_set(string $message): void {
    session()->put('mantencion_manual_pending_flash', $message);
}
