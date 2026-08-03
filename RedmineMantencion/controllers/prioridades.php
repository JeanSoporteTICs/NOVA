<?php
// CRUD para prioridades usando configuraciones_modulo + modulo_opciones.
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/maintenance.php';

function prio_load_cfg() {
    $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
    $data = $repo !== null ? $repo->loadAll() : [];
    if (!is_array($data)) $data = [];
    if (!isset($data['prioridades']) || !is_array($data['prioridades'])) $data['prioridades'] = [];
    return $data;
}

function prio_save_cfg($cfg) {
    $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
    if ($repo !== null) {
        $repo->saveAll($cfg);
    }
}

function handle_prioridades() {
    $cfg = prio_load_cfg();
    $prioridades = $cfg['prioridades'] ?? [];
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
                    foreach ($prioridades as &$p) $p['default'] = false;
                    $cfg['priority_id'] = $id;
                }
                $prioridades[] = [
                    'id' => is_numeric($id) ? (int)$id : $id,
                    'nombre' => $nombre,
                    'default' => $def
                ];
                $cfg['prioridades'] = $prioridades;
                prio_save_cfg($cfg);
                $flash = 'Prioridad creada';
            }
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? '';
            foreach ($prioridades as &$p) {
                if ((string)$p['id'] === (string)$id) {
                    $p['nombre'] = trim($_POST['nombre'] ?? $p['nombre']);
                    $p['default'] = isset($_POST['default']);
                    break;
                }
            }
            if (isset($_POST['default'])) {
                foreach ($prioridades as &$p) {
                    $p['default'] = ((string)$p['id'] === (string)$id);
                }
                $cfg['priority_id'] = $id;
            }
            $cfg['prioridades'] = $prioridades;
            prio_save_cfg($cfg);
            $flash = 'Prioridad actualizada';
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            $prioridades = array_values(array_filter($prioridades, fn($p) => (string)$p['id'] !== (string)$id));
            if (isset($cfg['priority_id']) && (string)$cfg['priority_id'] === (string)$id) {
                $cfg['priority_id'] = $prioridades[0]['id'] ?? null;
                if (!empty($prioridades)) $prioridades[0]['default'] = true;
            }
            $cfg['prioridades'] = $prioridades;
            prio_save_cfg($cfg);
            $flash = 'Prioridad eliminada';
        }
    }

    return [$prioridades, $flash];
}
?>
