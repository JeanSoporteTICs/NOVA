<?php
// CRUD para trackers usando configuraciones_modulo + modulo_opciones.
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/maintenance.php';

function trk_load_cfg() {
    $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
    $data = $repo !== null ? $repo->loadAll() : [];
    if (!is_array($data)) $data = [];
    if (!isset($data['trackers']) || !is_array($data['trackers'])) $data['trackers'] = [];
    return $data;
}

function trk_save_cfg($cfg) {
    $repo = function_exists('config_mantencion_repository') ? config_mantencion_repository() : null;
    if ($repo !== null) {
        $repo->saveAll($cfg);
    }
}

function handle_trackers() {
    $cfg = trk_load_cfg();
    $trackers = $cfg['trackers'] ?? [];
    $flash = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $id = trim($_POST['id'] ?? '');
            $nombre = trim($_POST['nombre'] ?? '');
            $def = isset($_POST['default']);
            if ($id !== '' && $nombre !== '') {
                if ($def) {
                    foreach ($trackers as &$t) $t['default'] = false;
                    $cfg['tracker_id'] = $id;
                }
                $trackers[] = ['id' => is_numeric($id)?(int)$id:$id, 'nombre' => $nombre, 'default' => $def];
                $cfg['trackers'] = $trackers;
                trk_save_cfg($cfg);
                $flash = 'Tracker creado';
            }
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? '';
            foreach ($trackers as &$t) {
                if ((string)$t['id'] === (string)$id) {
                    $t['nombre'] = trim($_POST['nombre'] ?? $t['nombre']);
                    $t['default'] = isset($_POST['default']);
                    break;
                }
            }
            if (isset($_POST['default'])) {
                foreach ($trackers as &$t) {
                    $t['default'] = ((string)$t['id'] === (string)$id);
                }
                $cfg['tracker_id'] = $id;
            }
            $cfg['trackers'] = $trackers;
            trk_save_cfg($cfg);
            $flash = 'Tracker actualizado';
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            $trackers = array_values(array_filter($trackers, fn($t) => (string)$t['id'] !== (string)$id));
            if (isset($cfg['tracker_id']) && (string)$cfg['tracker_id'] === (string)$id) {
                $cfg['tracker_id'] = $trackers[0]['id'] ?? null;
                if (!empty($trackers)) $trackers[0]['default'] = true;
            }
            $cfg['trackers'] = $trackers;
            trk_save_cfg($cfg);
            $flash = 'Tracker eliminado';
        }
    }
    return [$trackers, $flash];
}
?>
