<?php
// CRUD para estados (status_id) usando configuraciones_modulo + modulo_opciones.
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/maintenance.php';

function est_load_cfg() {
    $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
    $data = $repo !== null ? $repo->loadAll() : [];
    if (!is_array($data)) $data = [];
    if (!isset($data['estados']) || !is_array($data['estados'])) $data['estados'] = [];
    return $data;
}

function est_save_cfg($cfg) {
    $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
    if ($repo !== null) {
        $repo->saveAll($cfg);
    }
}

function handle_estados() {
    $cfg = est_load_cfg();
    $estados = $cfg['estados'] ?? [];
    $flash = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('csrf_validate')) csrf_validate();
        if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $id = trim($_POST['id'] ?? '');
            $nombre = trim($_POST['nombre'] ?? '');
            $def = isset($_POST['default']);
            if ($id !== '' && $nombre !== '') {
                if ($def) {
                    foreach ($estados as &$e) $e['default'] = false;
                    $cfg['status_id'] = $id;
                }
                $estados[] = ['id' => is_numeric($id) ? (int)$id : $id, 'nombre' => $nombre, 'default' => $def];
                $cfg['estados'] = $estados;
                est_save_cfg($cfg);
                $flash = 'Estado creado';
            }
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? '';
            foreach ($estados as &$e) {
                if ((string)$e['id'] === (string)$id) {
                    $e['nombre'] = trim($_POST['nombre'] ?? $e['nombre']);
                    $e['default'] = isset($_POST['default']);
                    break;
                }
            }
            if (isset($_POST['default'])) {
                foreach ($estados as &$e) {
                    $e['default'] = ((string)$e['id'] === (string)$id);
                }
                $cfg['status_id'] = $id;
            }
            $cfg['estados'] = $estados;
            est_save_cfg($cfg);
            $flash = 'Estado actualizado';
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            $estados = array_values(array_filter($estados, fn($e) => (string)$e['id'] !== (string)$id));
            if (isset($cfg['status_id']) && (string)$cfg['status_id'] === (string)$id) {
                $cfg['status_id'] = $estados[0]['id'] ?? null;
                if (!empty($estados)) {
                    $estados[0]['default'] = true;
                }
            }
            $cfg['estados'] = $estados;
            est_save_cfg($cfg);
            $flash = 'Estado eliminado';
        }
    }
    return [$estados, $flash];
}
?>
