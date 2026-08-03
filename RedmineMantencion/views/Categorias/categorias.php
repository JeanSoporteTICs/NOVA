<?php
// Endpoint mínimo para sincronizar categorías desde la API y volver a configuración.
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine-mantencion/login.php');
require_once __DIR__ . '/../../controllers/categorias.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (function_exists('csrf_validate')) csrf_validate();
    if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
    $action = $_POST['action'] ?? '';
    if ($action === 'sync_remote') {
        $res = sync_categorias_desde_api('');
        if (isset($res['error'])) {
            $msg = $res['error'];
        } else {
            $msg = 'Categorías actualizadas desde API (' . ($res['ok'] ?? 0) . ' registros)';
        }
    } else {
        $msg = 'Acción no válida.';
    }
} else {
    $msg = 'Método no permitido.';
}
$configUrl = function_exists('url') ? url('/redmine-mantencion/app/configuracion') : legacy_app_url('app/configuracion');
header('Location: ' . $configUrl . '?panel=categorias&synccat=' . urlencode($msg));
exit;
