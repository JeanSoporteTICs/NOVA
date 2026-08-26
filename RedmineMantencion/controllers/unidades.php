<?php
// CRUD básico para unidades usando data/unidades.json
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/maintenance.php';

$GLOBALS['DATA_FILE'] = 'unidades';

function ensure_uni_file($path) {
   s
}
function load_unidades($path) {
    $repo = function_exists('mantencion_catalog_repository') ? mantencion_catalog_repository() : null;
    $data = $repo !== null ? $repo->unidades() : [];
    if (!is_array($data)) $data = [];
    $changed = false;
    foreach ($data as &$item) {
        if (!isset($item['id'])) { $item['id'] = uniqid('', true); $changed = true; }
        if (!isset($item['nombre'])) { $item['nombre'] = ''; $changed = true; }
    }
    if ($changed) save_unidades($path, $data);
    return $data;
}
function save_unidades($path, $data) {
    $repo = function_exists('mantencion_catalog_repository') ? mantencion_catalog_repository() : null;
    if ($repo !== null) {
        $repo->upsertUnidades(is_array($data) ? $data : []);
    }
}
function handle_unidades() {
    global $DATA_FILE;
    $rows = load_unidades($DATA_FILE);
    $flash = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('csrf_validate')) csrf_validate();
        if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $newRow = [
                'id' => trim($_POST['id'] ?? '') ?: uniqid('', true),
                'nombre' => trim($_POST['nombre'] ?? ''),
            ];
            $rows[] = $newRow;
            // Punctual create: save_unidades()/upsertRows() does a real DB
            // SELECT+UPDATE/INSERT per row it receives, so passing the whole
            // $rows collection here re-wrote every unidad in the table for a
            // single new record. Pass only the new row instead.
            save_unidades($DATA_FILE, [$newRow]);
            $flash = 'Unidad creada';
        } elseif ($action === 'update') {
            $id = $_POST['id'] ?? '';
            $updatedRow = null;
            foreach ($rows as &$r) {
                if ($r['id'] === $id) {
                    $r['nombre'] = trim($_POST['nombre'] ?? $r['nombre']);
                    $updatedRow = $r;
                    break;
                }
            }
            unset($r);
            // Punctual update: same reasoning as 'create' above — only the
            // edited row needs to be upserted, not the whole collection.
            if ($updatedRow !== null) {
                save_unidades($DATA_FILE, [$updatedRow]);
            }
            $flash = 'Unidad actualizada';
        } elseif ($action === 'delete') {
            $id = $_POST['id'] ?? '';
            $repo = function_exists('mantencion_catalog_repository') ? mantencion_catalog_repository() : null;
            if ($repo !== null) {
                $repo->deactivateUnidad((string)$id);
            }
            $rows = load_unidades($DATA_FILE);
            $flash = 'Unidad eliminada';
        }
    }
    return [$rows, $flash];
}
?>
