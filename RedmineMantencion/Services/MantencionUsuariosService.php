<?php

namespace App\Modulos\RedmineMantencion\Services;

class MantencionUsuariosService
{
    private readonly MantencionUsuariosCentralService $central;
    private readonly MantencionUsuariosStorageService $storage;
    private readonly MantencionUsuariosRedmineSyncService $redmineSync;

    public function __construct(MantencionUsuariosCentralService $central, MantencionUsuariosStorageService $storage, MantencionUsuariosRedmineSyncService $redmineSync)
    {
        $this->central = $central;
        $this->storage = $storage;
        $this->redmineSync = $redmineSync;
    }

    public function handle_usuarios() {
        $rows = $this->storage->load_usuarios('');
        if ($this->storage->usuarios_migrate_global_nextcloud_credentials($rows)) {
            $this->storage->save_usuarios('', $rows);
        }
        $flash = $this->storage->usuarios_consume_flash();
        $importPreview = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (function_exists('csrf_validate')) csrf_validate();
            $action = $_POST['action'] ?? '';
            if (function_exists('maintenance_mode_block_if_enabled')) {
                maintenance_mode_block_if_enabled();
            }
            $id_input = $this->storage->sanitize_input($_POST['id_manual'] ?? '');

            if ($action === 'create') {
                if ($id_input !== '' && $this->storage->has_duplicate_id($rows, $id_input)) {
                    return [$rows, 'Error: el ID ya existe', $importPreview];
                }
                $assignedRole = $this->storage->sanitize_input($_POST['rol'] ?? 'usuario');
                $rolePerms = [];
                if (function_exists('auth_load_roles')) {
                    $roles = auth_load_roles();
                    $cfg = $roles[$assignedRole] ?? [];
                    if (is_array($cfg)) {
                        $rolePerms = $cfg;
                    }
                }
                $requiredName = $this->storage->sanitize_input($_POST['nombre'] ?? '');
                $requiredLast = $this->storage->sanitize_input($_POST['apellido'] ?? '');
                if ($requiredName === '') {
                    return [$rows, 'Error: el nombre es obligatorio', $importPreview];
                }
                [$newNombre, $newApellido] = $requiredLast !== ''
                    ? [$requiredName, $requiredLast]
                    : usuarios_split_name($requiredName);
                if ($newApellido === '') {
                    return [$rows, 'Error: el apellido es obligatorio', $importPreview];
                }
                $newRow = [
                    'id' => $id_input !== '' ? $id_input : uniqid('', true),
                    'rut_sin_dv' => '',
                    'nombre' => $newNombre !== '' ? $newNombre : $requiredName,
                    'apellido' => $newApellido,
                    'rut' => '',
                    'numero_celular' => '',
                    'estamento' => '',
                    'rol' => $assignedRole,
                    'estado' => in_array(($_POST['estado'] ?? 'activo'), ['activo', 'baneado'], true) ? $_POST['estado'] : 'activo',
                    'api' => '',
                    'core_user' => '',
                    'core_pass_enc' => '',
                    'nextcloud_user' => '',
                    'nextcloud_pass_enc' => '',
                    'permisos' => $rolePerms,
                ];
                $rows[] = $newRow;
                // Punctual create: $this->central->usuarios_central_upsert() already persists this one
                // record. $this->storage->save_usuarios($DATA_FILE, $rows) would loop and re-upsert
                // every user in $rows for no additional effect (same antipattern fixed
                // in dashboard.php's 'update' case) — removed.
                $this->central->usuarios_central_upsert($newRow);
                usuarios_set_flash('Usuario creado');
                $this->storage->usuarios_redirect_back();
            } elseif ($action === 'update') {
                $id = $_POST['id'] ?? '';
                $index = $this->storage->find_user_index($rows, $id);
                if ($index === null) return [$rows, 'Error: usuario no encontrado', $importPreview];
                $current = &$rows[$index];
                $current['rol'] = $this->storage->sanitize_input($_POST['rol'] ?? ($current['rol'] ?? 'usuario'));
                $current['_preserve_existing_status'] = true;
                // Punctual update: $this->central->usuarios_central_upsert() already persists this one
                // record; $this->storage->save_usuarios($DATA_FILE, $rows) was a redundant full re-upsert
                // of every user (see note in the 'create' branch above) — removed.
                $this->central->usuarios_central_upsert($current);
                unset($current['_preserve_existing_status']);
                usuarios_set_flash('Rol de proyecto actualizado');
                $this->storage->usuarios_redirect_back();
            } elseif ($action === 'delete') {
                $id = $_POST['id'] ?? '';
                $index = $this->storage->find_user_index($rows, $id);
                if ($index === null) return [$rows, 'Error: usuario no encontrado', $importPreview];
                $centralId = $this->central->usuarios_central_id_for_project_user($rows[$index]);
                if ($centralId === null || !$this->central->usuarios_central_revoke_access($centralId)) {
                    return [$rows, 'No se pudo quitar el acceso al proyecto', $importPreview];
                }
                usuarios_set_flash('Acceso al proyecto eliminado');
                $this->storage->usuarios_redirect_back();
            } elseif ($action === 'preview_remote') {
                $res = $this->redmineSync->usuarios_remote_import_preview($rows);
                if (isset($res['error'])) {
                    return [$rows, $res['error'], $importPreview];
                }
                $importPreview = $res['items'] ?? [];
                $flash = 'Selecciona los usuarios que quieres importar desde Redmine.';
            } elseif ($action === 'sync_remote') {
                $selectedIds = is_array($_POST['remote_user_ids'] ?? null) ? $_POST['remote_user_ids'] : [];
                $res = $this->redmineSync->usuarios_sync_remote($rows, $selectedIds);
                if (isset($res['error'])) {
                    return [$rows, $res['error'], $importPreview];
                }
                usuarios_set_flash('Usuarios importados. Nuevos: ' . (int)($res['created'] ?? 0) . ' | actualizados: ' . (int)($res['updated'] ?? 0));
                $this->storage->usuarios_redirect_back();
            }
        }
        return [$rows, $flash, $importPreview];
    }
}
