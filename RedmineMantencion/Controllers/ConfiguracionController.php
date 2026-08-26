<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\RedmineMantencion\Services\MantencionCategoriasService;
use App\Modulos\RedmineMantencion\Services\MantencionConfiguracionRolesService;
use App\Modulos\RedmineMantencion\Services\MantencionConfiguracionService;
use App\Modulos\RedmineMantencion\Services\MantencionNextcloudService;
use App\Modulos\RedmineMantencion\Services\MantencionStaleNewReportNotifier;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ConfiguracionController extends Controller
{
    public function __construct(
        private readonly MantencionConfiguracionService $configuracion,
        private readonly MantencionCategoriasService $categorias,
        private readonly MantencionConfiguracionRolesService $roles,
        private readonly MantencionNextcloudService $nextcloud,
        private readonly MantencionStaleNewReportNotifier $reportsNotifier,
    ) {
    }

    /**
     * Configuración. Migrado desde RedmineMantencion/views/Configuracion/configuracion.php.
     */
    public function index(): View|RedirectResponse
    {
        require_once base_path('RedmineMantencion/controllers/auth.php');
        require_once base_path('RedmineMantencion/controllers/maintenance.php');
        require_once base_path('RedmineMantencion/controllers/nextcloud.php');

        $requestedPanel = strtolower(trim((string) ($_GET['panel'] ?? '')));
        $isNextcloudGroupsPanel = $requestedPanel === 'nextcloud';
        if ($isNextcloudGroupsPanel ? !auth_can('integraciones_nextcloud') : (!auth_can('configuracion') && !auth_can('categorias'))) {
            abort(403, $isNextcloudGroupsPanel ? 'No tienes permiso para administrar grupos de Nextcloud.' : 'No tienes permiso para ver Configuración.');
        }
        $requestedPanelPermission = [
            'resumen' => 'cfg_resumen', 'conexion' => 'cfg_conexion', 'proyecto' => 'cfg_proyecto',
            'retencion' => 'cfg_retencion', 'informes' => 'cfg_informes', 'trackers' => 'cfg_trackers', 'prioridades' => 'cfg_prioridades',
            'estados' => 'cfg_estados', 'categorias' => 'cfg_categorias', 'mantencion' => 'cfg_mantencion',
            'nextcloud' => 'integraciones_nextcloud', 'roles' => 'cfg_roles', 'usuarios' => 'cfg_usuarios',
        ];
        if ($requestedPanel !== '' && (!isset($requestedPanelPermission[$requestedPanel]) || !auth_can($requestedPanelPermission[$requestedPanel]))) {
            abort(403, 'No tienes permiso para ver esta sección de Configuración.');
        }

        $maintenanceFlash = handle_maintenance_request();
        $configResult = $this->configuracion->handle();
        if ($configResult instanceof RedirectResponse) {
            return $configResult;
        }
        [$cfg, $flash, $opts] = $configResult;
        $nextcloudResult = $this->nextcloud->handle_nextcloud();
        if ($nextcloudResult instanceof RedirectResponse) {
            return $nextcloudResult;
        }
        [$nextcloudFlash, $nextcloudCfg, $nextcloudGroups] = $nextcloudResult;
        $h = fn ($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
        $role = auth_get_user_role();
        $novaSessionUser = function_exists('session') ? session('nova_user') : null;
        $isNovaRoot = is_array($novaSessionUser)
            && strtolower(trim((string) ($novaSessionUser['role'] ?? 'usuario'))) === 'root';
        $csrf = legacy_csrf_token();
        $maintenanceMode = maintenance_mode_enabled();
        $maintenanceSettings = maintenance_mode_settings();

        $rolesData = auth_load_roles();
        $rolesData = is_array($rolesData) ? $rolesData : [];
        $usuariosData = function_exists('auth_central_users_for_mantencion') ? auth_central_users_for_mantencion() : [];
        if (!is_array($usuariosData)) {
            $usuariosData = [];
        }
        $usuariosSelectableData = array_values(array_filter($usuariosData, static function ($u): bool {
            if (!is_array($u)) {
                return false;
            }
            $estadoUsuario = strtolower(trim((string) ($u['estado'] ?? $u['estado_usuario'] ?? 'activo')));

            return in_array($estadoUsuario, ['activo', 'active'], true);
        }));
        $usuariosIndex = [];
        foreach ($usuariosSelectableData as $u) {
            if (is_array($u) && isset($u['id'])) {
                $usuariosIndex[(string) $u['id']] = $u;
            }
        }

        $ensureRolePermission = function (string $role, string $key, $value) use (&$rolesData): void {
            if (!isset($rolesData[$role]) || !is_array($rolesData[$role])) {
                return;
            }
            if (!array_key_exists($key, $rolesData[$role])) {
                $rolesData[$role][$key] = $value;
            }
        };
        foreach (array_keys($rolesData) as $roleName) {
            $ensureRolePermission((string) $roleName, 'mis_integraciones', true);
            $ensureRolePermission((string) $roleName, 'integraciones_nextcloud', in_array((string) $roleName, ['root', 'gestor'], true));
            $ensureRolePermission((string) $roleName, 'actividad_eliminar', !empty($rolesData[$roleName]['actividad']));
            $ensureRolePermission((string) $roleName, 'actividad_todos', !empty($rolesData[$roleName]['actividad']));
            $ensureRolePermission((string) $roleName, 'horas_extra_editar', !empty($rolesData[$roleName]['horas_extra']));
            foreach (['reportes_editar', 'reportes_eliminar', 'reportes_importar_core'] as $reportPermission) {
                $ensureRolePermission((string) $roleName, $reportPermission, !empty($rolesData[$roleName]['mensajes_acceso']));
            }
            $legacyHistoryActions = !empty($rolesData[$roleName]['historico_acciones']);
            $ensureRolePermission((string) $roleName, 'historico_estado', $legacyHistoryActions);
            $ensureRolePermission((string) $roleName, 'historico_eliminar', $legacyHistoryActions);
            unset($rolesData[$roleName]['horas_extra_eliminar']);
            unset($rolesData[$roleName]['historico_acciones']);
            $baseConfigAccess = !empty($rolesData[$roleName]['configuracion']);
            foreach (['cfg_resumen', 'cfg_informes', 'cfg_categorias', 'cfg_mantencion', 'cfg_nextcloud'] as $configPermission) {
                $ensureRolePermission((string) $roleName, $configPermission, $configPermission === 'cfg_categorias' ? !empty($rolesData[$roleName]['categorias']) : $baseConfigAccess);
            }
        }
        $categoriasData = [];
        $catalogRepo = function_exists('mantencion_catalog_repository') ? mantencion_catalog_repository() : null;
        $categoriasData = $catalogRepo !== null ? $catalogRepo->categorias() : [];
        if (empty($rolesData)) {
            $rolesData = [
                'root' => [
                    'all' => true,
                    'mensajes' => 'todos',
                    'mensajes_acceso' => true,
                    'reportes_editar' => true,
                    'reportes_eliminar' => true,
                    'reportes_importar_core' => true,
                    'horas_extra' => 'todos',
                    'horas_extra_editar' => true,
                    'historico' => true,
                    'historico_scope' => 'todos',
                    'historico_estado' => true,
                    'historico_eliminar' => true,
                    'configuracion' => true,
                    'estadisticas' => true,
                    'usuarios' => true,
                    'categorias' => true,
                    'simulador' => true,
                    'cfg_conexion' => true,
                    'cfg_proyecto' => true,
                    'cfg_retencion' => true,
                    'cfg_informes' => true,
                    'cfg_trackers' => true,
                    'cfg_prioridades' => true,
                    'cfg_estados' => true,
                    'cfg_roles' => true,
                    'cfg_usuarios' => true,
                    'actividad' => true,
                ],
                'gestor' => [
                    'mensajes' => 'asignados',
                    'mensajes_acceso' => true,
                    'reportes_editar' => true,
                    'reportes_eliminar' => true,
                    'reportes_importar_core' => true,
                    'horas_extra' => 'asignados',
                    'horas_extra_editar' => true,
                    'historico' => true,
                    'historico_scope' => 'asignados',
                    'historico_estado' => true,
                    'historico_eliminar' => true,
                    'configuracion' => true,
                    'estadisticas' => true,
                    'usuarios' => true,
                    'categorias' => true,
                    'simulador' => true,
                    'cfg_conexion' => true,
                    'cfg_proyecto' => true,
                    'cfg_retencion' => true,
                    'cfg_informes' => true,
                    'cfg_trackers' => true,
                    'cfg_prioridades' => true,
                    'cfg_estados' => true,
                    'cfg_roles' => true,
                    'cfg_usuarios' => true,
                ],
                'administrador' => [
                    'mensajes' => 'todos',
                    'mensajes_acceso' => true,
                    'reportes_editar' => true,
                    'reportes_eliminar' => true,
                    'reportes_importar_core' => true,
                    'horas_extra' => 'todos',
                    'horas_extra_editar' => true,
                    'historico' => false,
                    'historico_scope' => 'asignados',
                    'historico_estado' => false,
                    'historico_eliminar' => false,
                    'configuracion' => true,
                    'estadisticas' => true,
                    'usuarios' => false,
                    'categorias' => true,
                    'simulador' => true,
                    'cfg_conexion' => true,
                    'cfg_proyecto' => true,
                    'cfg_retencion' => true,
                    'cfg_informes' => true,
                    'cfg_trackers' => true,
                    'cfg_prioridades' => true,
                    'cfg_estados' => true,
                    'cfg_roles' => false,
                    'cfg_usuarios' => false,
                    'actividad' => true,
                ],
                'usuario' => [
                    'mensajes' => 'asignados',
                    'mensajes_acceso' => true,
                    'reportes_editar' => true,
                    'reportes_eliminar' => true,
                    'reportes_importar_core' => true,
                    'horas_extra' => 'asignados',
                    'horas_extra_editar' => false,
                    'historico' => false,
                    'historico_scope' => 'asignados',
                    'historico_estado' => false,
                    'historico_eliminar' => false,
                    'configuracion' => false,
                    'estadisticas' => false,
                    'usuarios' => false,
                    'categorias' => false,
                    'simulador' => true,
                    'cfg_conexion' => false,
                    'cfg_proyecto' => false,
                    'cfg_retencion' => false,
                    'cfg_informes' => false,
                    'cfg_trackers' => false,
                    'cfg_prioridades' => false,
                    'cfg_estados' => false,
                    'cfg_roles' => false,
                    'cfg_usuarios' => false,
                ],
            ];
        }

        $flashRoles = session()->pull('mantencion_roles_flash');
        $flashRolesType = session()->pull('mantencion_roles_flash_type', 'success');
        $flashUsuarios = session()->pull('mantencion_usuarios_flash');
        $flashUsuariosType = session()->pull('mantencion_usuarios_flash_type', 'success');
        $openRolesModal = false;
        $openUsersModal = false;
        $selectedRoleSel = $_POST['role_select']
            ?? session()->pull('mantencion_roles_selected')
            ?? $_GET['role']
            ?? 'gestor';
        $newRoleName = strtolower(trim((string) ($_POST['new_role'] ?? '')));
        $selectedRole = $newRoleName !== '' ? $newRoleName : $selectedRoleSel;
        $selectedUser = (string) ($_POST['user_select'] ?? $_GET['user_id'] ?? '');
        $canManageRoles = auth_can('cfg_roles');
        $canManageUsers = auth_can('cfg_usuarios');
        $baseRoles = ['administrador', 'usuario'];

        if (!isset($rolesData[$selectedRole]) && $newRoleName === '') {
            $selectedRole = (string) (array_key_first($rolesData) ?? 'usuario');
        }
        if (($selectedUser === '' || !isset($usuariosIndex[$selectedUser])) && $usuariosIndex !== []) {
            $selectedUser = (string) array_key_first($usuariosIndex);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = $_POST['action'] ?? '';
            if ($maintenanceMode && $action !== 'maintenance_settings') {
                if (function_exists('maintenance_mode_block_if_enabled')) {
                    maintenance_mode_block_if_enabled();
                }
            }
            if ($action === 'send_reports_now' && auth_can('cfg_informes')) {
                if (function_exists('csrf_validate')) {
                    csrf_validate();
                }
                $result = $this->reportsNotifier->run(true);
                session()->put('mantencion_config_flash', sprintf(
                    'Comprobación Mantención finalizada: %d enviado(s), %d responsable(s) sin pendientes, %d omitido(s) y %d error(es).',
                    (int) ($result['sent'] ?? 0),
                    (int) ($result['empty'] ?? 0),
                    (int) ($result['skipped'] ?? 0),
                    (int) ($result['failed'] ?? 0)
                ));

                return redirect(route('redmine.mantencion.section', [
                    'section' => 'configuracion',
                    'panel' => 'informes',
                ]), 303);
            }
            if ($action === 'load_role' && $canManageRoles) {
                if (function_exists('csrf_validate')) {
                    csrf_validate();
                }
                $selectedRole = trim($_POST['role_select'] ?? $selectedRole);
                $flash = null;
                $flashRoles = null;
                $openRolesModal = true;
            } elseif ($action === 'delete_role' && $canManageRoles) {
                if (function_exists('csrf_validate')) {
                    csrf_validate();
                }
                $roleToDelete = strtolower(trim((string) ($_POST['role_select'] ?? '')));
                $assignedUsers = array_filter($usuariosData, static fn ($user): bool => is_array($user) && strtolower(trim((string) ($user['rol'] ?? ''))) === $roleToDelete);
                if ($roleToDelete === '' || !isset($rolesData[$roleToDelete])) {
                    session()->put('mantencion_roles_flash', 'El rol seleccionado ya no existe.');
                    session()->put('mantencion_roles_flash_type', 'warning');
                } elseif (in_array($roleToDelete, $baseRoles, true)) {
                    session()->put('mantencion_roles_flash', 'Administrador y Usuario son roles base y no se pueden eliminar.');
                    session()->put('mantencion_roles_flash_type', 'warning');
                } elseif ($assignedUsers !== []) {
                    session()->put('mantencion_roles_flash', 'Reasigna los usuarios vinculados antes de eliminar este rol.');
                    session()->put('mantencion_roles_flash_type', 'warning');
                } else {
                    $this->roles->deleteRolePermissions($roleToDelete);
                    unset($rolesData[$roleToDelete]);
                    session()->put('mantencion_roles_flash', 'Rol eliminado correctamente.');
                    session()->put('mantencion_roles_flash_type', 'success');
                }
                session()->put('mantencion_roles_selected', (string) (array_key_first($rolesData) ?? 'usuario'));
                $rolesRedirectUrl = route('redmine.mantencion.section', [
                    'section' => 'configuracion',
                    'panel' => 'roles',
                ]);

                return redirect($rolesRedirectUrl, 303);
            } elseif ($action === 'save_roles' && $canManageRoles) {
                if (function_exists('csrf_validate')) {
                    csrf_validate();
                }
                $selectedRole = $newRoleName !== '' ? $newRoleName : trim($_POST['role_select'] ?? $selectedRole);
                if ($selectedRole !== '' && preg_match('/^[a-z0-9_-]{2,40}$/', $selectedRole)) {
                    if (!isset($rolesData[$selectedRole])) {
                        $rolesData[$selectedRole] = [];
                    }
                    $previousRoleConfig = $rolesData[$selectedRole];
                    if ($selectedRole === 'root') {
                        $rolesData['root'] = [
                            'all' => true,
                            'mensajes' => 'todos',
                            'mensajes_acceso' => true,
                            'reportes_editar' => true,
                            'reportes_eliminar' => true,
                            'reportes_importar_core' => true,
                            'horas_extra' => 'todos',
                            'horas_extra_editar' => true,
                            'historico' => true,
                            'historico_scope' => 'todos',
                            'historico_estado' => true,
                            'historico_eliminar' => true,
                            'configuracion' => true,
                            'estadisticas' => true,
                            'usuarios' => true,
                            'categorias' => true,
                            'simulador' => true,
                            'cfg_conexion' => true,
                            'cfg_proyecto' => true,
                            'cfg_retencion' => true,
                            'cfg_informes' => true,
                            'cfg_trackers' => true,
                            'cfg_prioridades' => true,
                            'cfg_estados' => true,
                            'cfg_roles' => true,
                            'cfg_usuarios' => true,
                            'actividad' => isset($_POST['perm_actividad']),
                            'actividad_eliminar' => isset($_POST['perm_actividad']) && isset($_POST['perm_actividad_eliminar']),
                            'actividad_todos' => isset($_POST['perm_actividad']) && isset($_POST['perm_actividad_todos']),
                        ];
                    } else {
                        $roleCanViewHistorico = isset($_POST['perm_historico']);
                        $rolesData[$selectedRole] = [
                            'mensajes' => $isNovaRoot
                                ? ($_POST['mensajes_scope'] ?? 'asignados')
                                : ($previousRoleConfig['mensajes'] ?? 'asignados'),
                            'mensajes_acceso' => isset($_POST['perm_mensajes']),
                            'reportes_editar' => isset($_POST['perm_mensajes']) && isset($_POST['perm_reportes_editar']),
                            'reportes_eliminar' => isset($_POST['perm_mensajes']) && isset($_POST['perm_reportes_eliminar']),
                            'reportes_importar_core' => isset($_POST['perm_mensajes']) && isset($_POST['perm_reportes_importar_core']),
                            'horas_extra' => isset($_POST['perm_horas_extra'])
                                ? ($isNovaRoot ? ($_POST['horas_scope'] ?? 'asignados') : ($previousRoleConfig['horas_extra'] ?? 'asignados'))
                                : '',
                            'horas_extra_editar' => isset($_POST['perm_horas_extra'])
                                && isset($_POST['perm_horas_extra_editar']),
                            'historico' => $roleCanViewHistorico,
                            'historico_scope' => $isNovaRoot
                                ? ($_POST['historico_scope'] ?? 'asignados')
                                : ($previousRoleConfig['historico_scope'] ?? 'asignados'),
                            'historico_estado' => $roleCanViewHistorico && isset($_POST['perm_historico_estado']),
                            'historico_eliminar' => $roleCanViewHistorico && isset($_POST['perm_historico_eliminar']),
                            'configuracion' => (string) ($_POST['perm_configuracion'] ?? '0') === '1',
                            'estadisticas' => isset($_POST['perm_estadisticas']),
                            'usuarios' => isset($_POST['perm_usuarios']),
                            'categorias' => isset($_POST['perm_categorias']),
                            'simulador' => isset($_POST['perm_simulador']),
                            'cfg_conexion' => isset($_POST['perm_cfg_conexion']),
                            'cfg_proyecto' => isset($_POST['perm_cfg_proyecto']),
                            'cfg_retencion' => isset($_POST['perm_cfg_retencion']),
                            'cfg_informes' => isset($_POST['perm_cfg_informes']),
                            'cfg_trackers' => isset($_POST['perm_cfg_trackers']),
                            'cfg_prioridades' => isset($_POST['perm_cfg_prioridades']),
                            'cfg_estados' => isset($_POST['perm_cfg_estados']),
                            'cfg_roles' => isset($_POST['perm_cfg_roles']),
                            'cfg_usuarios' => isset($_POST['perm_cfg_usuarios']),
                            'actividad' => isset($_POST['perm_actividad']),
                            'actividad_eliminar' => isset($_POST['perm_actividad']) && isset($_POST['perm_actividad_eliminar']),
                            'actividad_todos' => isset($_POST['perm_actividad']) && isset($_POST['perm_actividad_todos']),
                            'mis_integraciones' => isset($_POST['perm_mis_integraciones']),
                            'integraciones_nextcloud' => isset($_POST['perm_integraciones_nextcloud']),
                            'cfg_resumen' => isset($_POST['perm_cfg_resumen']),
                            'cfg_categorias' => isset($_POST['perm_cfg_categorias']),
                            'cfg_mantencion' => isset($_POST['perm_cfg_mantencion']),
                            'cfg_nextcloud' => isset($_POST['perm_cfg_nextcloud']),
                        ];
                    }
                    $this->roles->saveRolePermissions($selectedRole, $rolesData[$selectedRole] ?? []);
                    // PRG: fuerza una lectura fresca desde BD, evita reenvíos del formulario y
                    // conserva el rol seleccionado al volver al panel.
                    session()->put('mantencion_roles_flash', 'Permisos guardados correctamente.');
                    session()->put('mantencion_roles_flash_type', 'success');
                    session()->put('mantencion_roles_selected', $selectedRole);
                    $rolesRedirectUrl = route('redmine.mantencion.section', [
                        'section' => 'configuracion',
                        'panel' => 'roles',
                    ]);

                    return redirect($rolesRedirectUrl, 303);
                }
                session()->put('mantencion_roles_flash', 'El nombre del rol debe tener entre 2 y 40 caracteres y usar solo letras, números, guion o guion bajo.');
                session()->put('mantencion_roles_flash_type', 'warning');
                $rolesRedirectUrl = route('redmine.mantencion.section', [
                    'section' => 'configuracion',
                    'panel' => 'roles',
                ]);

                return redirect($rolesRedirectUrl, 303);
            }
            if ($action === 'load_user_perms' && $canManageUsers) {
                if (function_exists('csrf_validate')) {
                    csrf_validate();
                }
                $selectedUser = trim($_POST['user_select'] ?? $selectedUser);
                $flash = null;
                $flashUsuarios = null;
                $openUsersModal = true;
            } elseif ($action === 'save_user_perms' && $canManageUsers) {
                if (function_exists('csrf_validate')) {
                    csrf_validate();
                }
                $selectedUser = trim($_POST['user_select'] ?? $selectedUser);
                if ($selectedUser !== '' && isset($usuariosIndex[$selectedUser])) {
                    $currentUserRole = (string) ($usuariosIndex[$selectedUser]['rol'] ?? 'usuario');
                    $previousUserConfig = is_array($usuariosIndex[$selectedUser]['permisos'] ?? null)
                        ? $usuariosIndex[$selectedUser]['permisos']
                        : ($rolesData[$currentUserRole] ?? []);
                    $newUserRole = strtolower(trim((string) ($_POST['u_role'] ?? '')));
                    if ($newUserRole !== '' && !isset($rolesData[$newUserRole])) {
                        $newUserRole = '';
                    }
                    if ($newUserRole !== '') {
                        foreach ($usuariosData as &$u) {
                            if ((string) ($u['id'] ?? '') === $selectedUser) {
                                $u['rol'] = $newUserRole;
                                $usuariosIndex[$selectedUser]['rol'] = $newUserRole;
                                break;
                            }
                        }
                        unset($u);
                    }
                    $userCanViewHistorico = isset($_POST['u_perm_historico']);
                    $cfgUser = [
                        'mensajes' => $isNovaRoot
                            ? ($_POST['u_mensajes_scope'] ?? 'asignados')
                            : ($previousUserConfig['mensajes'] ?? 'asignados'),
                        'mensajes_acceso' => isset($_POST['u_perm_mensajes']),
                        'reportes_editar' => isset($_POST['u_perm_mensajes']) && isset($_POST['u_perm_reportes_editar']),
                        'reportes_eliminar' => isset($_POST['u_perm_mensajes']) && isset($_POST['u_perm_reportes_eliminar']),
                        'reportes_importar_core' => isset($_POST['u_perm_mensajes']) && isset($_POST['u_perm_reportes_importar_core']),
                        'horas_extra' => isset($_POST['u_perm_horas_extra'])
                            ? ($isNovaRoot ? ($_POST['u_horas_scope'] ?? 'asignados') : ($previousUserConfig['horas_extra'] ?? 'asignados'))
                            : '',
                        'horas_extra_editar' => isset($_POST['u_perm_horas_extra'])
                            && isset($_POST['u_perm_horas_extra_editar']),
                        'historico' => $userCanViewHistorico,
                        'historico_estado' => $userCanViewHistorico && isset($_POST['u_perm_historico_estado']),
                        'historico_eliminar' => $userCanViewHistorico && isset($_POST['u_perm_historico_eliminar']),
                        'historico_scope' => $isNovaRoot
                            ? ($_POST['u_historico_scope'] ?? 'asignados')
                            : ($previousUserConfig['historico_scope'] ?? 'asignados'),
                        'configuracion' => (string) ($_POST['u_perm_configuracion'] ?? '0') === '1',
                        'estadisticas' => isset($_POST['u_perm_estadisticas']),
                        'usuarios' => isset($_POST['u_perm_usuarios']),
                        'categorias' => isset($_POST['u_perm_categorias']),
                        'simulador' => isset($_POST['u_perm_simulador']),
                        'cfg_conexion' => isset($_POST['u_perm_cfg_conexion']),
                        'cfg_proyecto' => isset($_POST['u_perm_cfg_proyecto']),
                        'cfg_retencion' => isset($_POST['u_perm_cfg_retencion']),
                        'cfg_informes' => isset($_POST['u_perm_cfg_informes']),
                        'cfg_trackers' => isset($_POST['u_perm_cfg_trackers']),
                        'cfg_prioridades' => isset($_POST['u_perm_cfg_prioridades']),
                        'cfg_estados' => isset($_POST['u_perm_cfg_estados']),
                        'cfg_roles' => isset($_POST['u_perm_cfg_roles']),
                        'cfg_usuarios' => isset($_POST['u_perm_cfg_usuarios']),
                        'actividad' => isset($_POST['u_perm_actividad']),
                        'actividad_eliminar' => isset($_POST['u_perm_actividad']) && isset($_POST['u_perm_actividad_eliminar']),
                        'actividad_todos' => isset($_POST['u_perm_actividad']) && isset($_POST['u_perm_actividad_todos']),
                        'mis_integraciones' => isset($_POST['u_perm_mis_integraciones']),
                        'integraciones_nextcloud' => isset($_POST['u_perm_integraciones_nextcloud']),
                        'cfg_resumen' => isset($_POST['u_perm_cfg_resumen']),
                        'cfg_categorias' => isset($_POST['u_perm_cfg_categorias']),
                        'cfg_mantencion' => isset($_POST['u_perm_cfg_mantencion']),
                        'cfg_nextcloud' => isset($_POST['u_perm_cfg_nextcloud']),
                    ];
                    $effectiveUserRole = strtolower($newUserRole !== '' ? $newUserRole : (string) ($usuariosIndex[$selectedUser]['rol'] ?? 'usuario'));
                    if ($effectiveUserRole === 'root') {
                        $cfgUser['all'] = true;
                    }
                    foreach ($usuariosData as &$u) {
                        if ((string) ($u['id'] ?? '') === $selectedUser) {
                            $u['permisos'] = $cfgUser;
                            break;
                        }
                    }
                    unset($u);
                    $usuariosIndex[$selectedUser]['permisos'] = $cfgUser;
                    $usuariosIndex[$selectedUser]['rol'] = $newUserRole !== '' ? $newUserRole : ($usuariosIndex[$selectedUser]['rol'] ?? '');
                    $selUserData = $usuariosIndex[$selectedUser];
                    $selUserRole = $selUserData['rol'] ?? $selUserRole;
                    $selUserPerms = $cfgUser;
                    if ($newUserRole !== '') {
                        $this->roles->saveUserRole($selectedUser, $newUserRole);
                    }
                    $this->roles->saveUserPermissions($selectedUser, $cfgUser);
                    session()->put('mantencion_usuarios_flash', 'Permisos actualizados para el usuario ID ' . $selectedUser);
                    session()->put('mantencion_usuarios_flash_type', 'success');
                    $usersRedirectUrl = route('redmine.mantencion.section', [
                        'section' => 'configuracion',
                        'panel' => 'usuarios',
                        'user_id' => $selectedUser,
                    ]);

                    return redirect($usersRedirectUrl, 303);
                }
            }
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_remote') {
            if (function_exists('csrf_validate')) {
                csrf_validate();
            }
            if (function_exists('maintenance_mode_block_if_enabled')) {
                maintenance_mode_block_if_enabled();
            }
            $res = $this->categorias->syncFromApi();
            $msg = isset($res['error']) ? $res['error'] : ('Categorías sincronizadas (' . ($res['ok'] ?? 0) . ' registros).');
            $configRedirectUrl = function_exists('url') ? url('/redmine-mantencion/app/configuracion') : legacy_app_url('app/configuracion');

            return redirect($configRedirectUrl . '?panel=categorias&synccat=' . urlencode($msg));
        }

        return view('redmine-mantencion.configuracion', get_defined_vars());
    }
}
