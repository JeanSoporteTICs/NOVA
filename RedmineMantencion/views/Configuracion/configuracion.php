<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine-mantencion/login.php');
$requestedPanel = strtolower(trim((string)($_GET['panel'] ?? '')));
$isNextcloudGroupsPanel = $requestedPanel === 'nextcloud';
if ($isNextcloudGroupsPanel ? !auth_can('integraciones_nextcloud') : (!auth_can('configuracion') && !auth_can('categorias'))) {
  http_response_code(403);
  exit($isNextcloudGroupsPanel ? 'No tienes permiso para administrar grupos de Nextcloud.' : 'No tienes permiso para ver Configuración.');
}
$requestedPanelPermission = [
  'resumen' => 'cfg_resumen', 'conexion' => 'cfg_conexion', 'proyecto' => 'cfg_proyecto',
  'retencion' => 'cfg_retencion', 'trackers' => 'cfg_trackers', 'prioridades' => 'cfg_prioridades',
  'estados' => 'cfg_estados', 'categorias' => 'cfg_categorias', 'mantencion' => 'cfg_mantencion',
  'nextcloud' => 'integraciones_nextcloud', 'roles' => 'cfg_roles', 'usuarios' => 'cfg_usuarios',
];
if ($requestedPanel !== '' && (!isset($requestedPanelPermission[$requestedPanel]) || !auth_can($requestedPanelPermission[$requestedPanel]))) {
  http_response_code(403);
  exit('No tienes permiso para ver esta sección de Configuración.');
}
require_once __DIR__ . '/../../controllers/configuracion.php';
require_once __DIR__ . '/../../controllers/categorias.php';
require_once __DIR__ . '/../../controllers/maintenance.php';
require_once __DIR__ . '/../../controllers/nextcloud.php';
$maintenanceFlash = handle_maintenance_request();
[$cfg, $flash, $opts] = handle_configuracion();
[$nextcloudFlash, $nextcloudCfg, $nextcloudGroups] = handle_nextcloud();
$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$role = auth_get_user_role();
$csrf = legacy_csrf_token();
$maintenanceMode = maintenance_mode_enabled();
$maintenanceSettings = maintenance_mode_settings();
if ($_SERVER['REQUEST_METHOD'] === 'GET' && strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/redmine-mantencion/views/Configuracion/configuracion.php') !== false) {
  $configRedirectUrl = ($_SERVER['SCRIPT_NAME'] ?? '/nova/public/index.php') . '/redmine-mantencion/app/configuracion';
  $query = [];
  if (isset($_GET['panel'])) $query['panel'] = (string)$_GET['panel'];
  if (isset($_GET['synccat'])) $query['synccat'] = (string)$_GET['synccat'];
  header('Location: ' . $configRedirectUrl . ($query ? '?' . http_build_query($query) : ''));
  exit;
}

$rolesData = auth_load_roles();
$rolesData = is_array($rolesData) ? $rolesData : [];
$usuariosData = function_exists('auth_central_users_for_mantencion') ? auth_central_users_for_mantencion() : [];
if (!is_array($usuariosData)) $usuariosData = [];
$usuariosSelectableData = array_values(array_filter($usuariosData, static function ($u): bool {
  if (!is_array($u)) return false;
  $estadoUsuario = strtolower(trim((string)($u['estado'] ?? $u['estado_usuario'] ?? 'activo')));
  return in_array($estadoUsuario, ['activo', 'active'], true);
}));
$usuariosIndex = [];
foreach ($usuariosSelectableData as $u) {
  if (is_array($u) && isset($u['id'])) {
    $usuariosIndex[(string)$u['id']] = $u;
  }
}

$saveRolePermissions = function (string $role, array $permissions): void {
  if (!class_exists(\Illuminate\Support\Facades\DB::class)) return;
  \Illuminate\Support\Facades\DB::transaction(function () use ($role, $permissions): void {
    \Illuminate\Support\Facades\DB::table('mantencion_permisos_rol')->where('rol', $role)->delete();
    foreach ($permissions as $permission => $value) {
      \Illuminate\Support\Facades\DB::table('mantencion_permisos_rol')->insert([
        'rol' => $role,
        'permiso' => (string)$permission,
        'valor' => is_bool($value) ? ($value ? '1' : '') : (string)$value,
      ]);
    }
  });
};

$saveUserRole = function (string $userId, string $role): void {
  if ($userId === '' || $role === '' || !class_exists(\Illuminate\Support\Facades\DB::class)) return;
  \Illuminate\Support\Facades\DB::table('usuarios_nova')
    ->where(function ($query) use ($userId): void {
      $query->where('redmine_id', $userId)
        ->orWhere('uuid', $userId)
        ->orWhere('usuario', $userId);
    })
    ->update(['rol' => $role, 'actualizado_at' => now()]);
};

$saveUserPermissions = function (string $userId, array $permissions): void {
  if ($userId === '' || !class_exists(\Illuminate\Support\Facades\DB::class)
      || !\Illuminate\Support\Facades\Schema::hasTable('mantencion_permisos_usuario')) return;
  $novaId = (int)\Illuminate\Support\Facades\DB::table('usuarios_nova')
    ->where(function ($query) use ($userId): void {
      $query->where('redmine_id', $userId)->orWhere('uuid', $userId)->orWhere('usuario', $userId);
    })->value('id');
  if ($novaId <= 0) return;
  \Illuminate\Support\Facades\DB::transaction(function () use ($novaId, $permissions): void {
    \Illuminate\Support\Facades\DB::table('mantencion_permisos_usuario')->where('usuario_id', $novaId)->delete();
    foreach ($permissions as $permission => $value) {
      \Illuminate\Support\Facades\DB::table('mantencion_permisos_usuario')->insert([
        'usuario_id' => $novaId,
        'permiso' => (string)$permission,
        'valor' => is_bool($value) ? ($value ? '1' : '') : (string)$value,
        'actualizado_at' => now(),
      ]);
    }
  });
};

$ensureRolePermission = function (string $role, string $key, $value) use (&$rolesData): void {
  if (!isset($rolesData[$role]) || !is_array($rolesData[$role])) return;
  if (!array_key_exists($key, $rolesData[$role])) {
    $rolesData[$role][$key] = $value;
  }
};
foreach (array_keys($rolesData) as $roleName) {
  $ensureRolePermission((string)$roleName, 'mis_integraciones', true);
  $ensureRolePermission((string)$roleName, 'integraciones_nextcloud', in_array((string)$roleName, ['root', 'gestor'], true));
  $ensureRolePermission((string)$roleName, 'actividad_eliminar', !empty($rolesData[$roleName]['actividad']));
  $ensureRolePermission((string)$roleName, 'actividad_todos', !empty($rolesData[$roleName]['actividad']));
  $baseConfigAccess = !empty($rolesData[$roleName]['configuracion']);
  foreach (['cfg_resumen', 'cfg_categorias', 'cfg_mantencion', 'cfg_nextcloud'] as $configPermission) {
    $ensureRolePermission((string)$roleName, $configPermission, $configPermission === 'cfg_categorias' ? !empty($rolesData[$roleName]['categorias']) : $baseConfigAccess);
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
      'horas_extra' => 'todos',
      'historico' => true,
      'historico_scope' => 'todos',
      'historico_acciones' => true,
      'configuracion' => true,
      'estadisticas' => true,
      'usuarios' => true,
      'categorias' => true,
      'simulador' => true,
      'cfg_conexion' => true,
      'cfg_proyecto' => true,
      'cfg_retencion' => true,
        'cfg_trackers' => true,
        'cfg_prioridades' => true,
        'cfg_estados' => true,
        'cfg_roles' => true,
        'cfg_usuarios' => true,
        'actividad' => true,
      'actividad' => true,
    ],
    'gestor' => [
      'mensajes' => 'asignados',
      'mensajes_acceso' => true,
      'horas_extra' => 'asignados',
      'historico' => true,
      'historico_scope' => 'asignados',
      'historico_acciones' => true,
      'configuracion' => true,
      'estadisticas' => true,
      'usuarios' => true,
      'categorias' => true,
      'simulador' => true,
      'cfg_conexion' => true,
      'cfg_proyecto' => true,
      'cfg_retencion' => true,
      'cfg_trackers' => true,
      'cfg_prioridades' => true,
      'cfg_estados' => true,
      'cfg_roles' => true,
      'cfg_usuarios' => true,
    ],
    'administrador' => [
      'mensajes' => 'todos',
      'mensajes_acceso' => true,
      'horas_extra' => 'todos',
      'historico' => false,
      'historico_scope' => 'asignados',
      'historico_acciones' => false,
      'configuracion' => true,
      'estadisticas' => true,
      'usuarios' => false,
      'categorias' => true,
      'simulador' => true,
      'cfg_conexion' => true,
      'cfg_proyecto' => true,
      'cfg_retencion' => true,
      'cfg_trackers' => true,
      'cfg_prioridades' => true,
      'cfg_estados' => true,
      'cfg_roles' => false,
      'cfg_usuarios' => false,
      'actividad' => false,
      'actividad' => true,
    ],
    'usuario' => [
      'mensajes' => 'asignados',
      'mensajes_acceso' => true,
      'horas_extra' => 'asignados',
      'historico' => false,
      'historico_scope' => 'asignados',
      'historico_acciones' => false,
      'configuracion' => false,
      'estadisticas' => false,
      'usuarios' => false,
      'categorias' => false,
      'simulador' => true,
      'cfg_conexion' => false,
      'cfg_proyecto' => false,
      'cfg_retencion' => false,
      'cfg_trackers' => false,
      'cfg_prioridades' => false,
      'cfg_estados' => false,
      'cfg_roles' => false,
      'cfg_usuarios' => false,
    ],
  ];
}

$flashRoles = $_SESSION['mantencion_roles_flash'] ?? null;
unset($_SESSION['mantencion_roles_flash']);
$flashUsuarios = null;
$openRolesModal = false;
$openUsersModal = false;
$selectedRoleSel = $_POST['role_select']
  ?? $_SESSION['mantencion_roles_selected']
  ?? $_GET['role']
  ?? 'gestor';
unset($_SESSION['mantencion_roles_selected']);
$newRoleName = trim($_POST['new_role'] ?? '');
$selectedRole = $newRoleName !== '' ? $newRoleName : $selectedRoleSel;
$selectedUser = $_POST['user_select'] ?? '';
$canManageRoles = auth_can('cfg_roles');
$canManageUsers = auth_can('cfg_usuarios');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  if ($maintenanceMode && $action !== 'maintenance_settings') {
    if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
  }
  if ($action === 'load_role' && $canManageRoles) {
    if (function_exists('csrf_validate')) csrf_validate();
    $selectedRole = trim($_POST['role_select'] ?? $selectedRole);
    $flash = null;
    $flashRoles = null;
    $openRolesModal = true;
  } elseif ($action === 'save_roles' && $canManageRoles) {
    if (function_exists('csrf_validate')) csrf_validate();
    $selectedRole = $newRoleName !== '' ? $newRoleName : trim($_POST['role_select'] ?? $selectedRole);
    if ($selectedRole !== '') {
      if (!isset($rolesData[$selectedRole])) $rolesData[$selectedRole] = [];
      if ($selectedRole === 'root') {
        $rolesData['root'] = [
          'all' => true,
          'mensajes' => 'todos',
          'mensajes_acceso' => true,
          'horas_extra' => 'todos',
          'historico' => true,
          'historico_scope' => ($_POST['historico_scope'] ?? 'todos'),
          'historico_acciones' => isset($_POST['perm_historico_acciones']),
          'configuracion' => true,
          'estadisticas' => true,
          'usuarios' => true,
          'categorias' => true,
          'simulador' => true,
          'cfg_conexion' => true,
          'cfg_proyecto' => true,
          'cfg_retencion' => true,
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
          'mensajes' => ($_POST['mensajes_scope'] ?? 'asignados'),
          'mensajes_acceso' => isset($_POST['perm_mensajes']),
          'horas_extra' => isset($_POST['perm_horas_extra']) ? ($_POST['horas_scope'] ?? 'asignados') : '',
          'historico' => $roleCanViewHistorico,
          'historico_scope' => ($_POST['historico_scope'] ?? 'asignados'),
          'historico_acciones' => $roleCanViewHistorico && isset($_POST['perm_historico_acciones']),
          'configuracion' => (string)($_POST['perm_configuracion'] ?? '0') === '1',
          'estadisticas' => isset($_POST['perm_estadisticas']),
          'usuarios' => isset($_POST['perm_usuarios']),
          'categorias' => isset($_POST['perm_categorias']),
          'simulador' => isset($_POST['perm_simulador']),
          'cfg_conexion' => isset($_POST['perm_cfg_conexion']),
          'cfg_proyecto' => isset($_POST['perm_cfg_proyecto']),
          'cfg_retencion' => isset($_POST['perm_cfg_retencion']),
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
      $saveRolePermissions($selectedRole, $rolesData[$selectedRole] ?? []);
      // PRG: fuerza una lectura fresca desde BD, evita reenvíos del formulario y
      // conserva el rol seleccionado al volver al panel.
      $_SESSION['mantencion_roles_flash'] = 'Permisos guardados correctamente.';
      $_SESSION['mantencion_roles_selected'] = $selectedRole;
      $rolesRedirectUrl = ($_SERVER['SCRIPT_NAME'] ?? '/nova/public/index.php')
        . '/redmine-mantencion/app/configuracion?panel=roles';
      header('Location: ' . $rolesRedirectUrl, true, 303);
      exit;
    }
  }
  if ($action === 'load_user_perms' && $canManageUsers) {
    if (function_exists('csrf_validate')) csrf_validate();
    $selectedUser = trim($_POST['user_select'] ?? $selectedUser);
    $flash = null;
    $flashUsuarios = null;
    $openUsersModal = true;
  } elseif ($action === 'save_user_perms' && $canManageUsers) {
    if (function_exists('csrf_validate')) csrf_validate();
    $selectedUser = trim($_POST['user_select'] ?? $selectedUser);
    if ($selectedUser !== '' && isset($usuariosIndex[$selectedUser])) {
      $newUserRole = trim($_POST['u_role'] ?? '');
      if ($newUserRole !== '') {
        foreach ($usuariosData as &$u) {
          if ((string)($u['id'] ?? '') === $selectedUser) {
            $u['rol'] = $newUserRole;
            $usuariosIndex[$selectedUser]['rol'] = $newUserRole;
            break;
          }
        }
        unset($u);
      }
      $userCanViewHistorico = isset($_POST['u_perm_historico']);
      $cfgUser = [
        'mensajes' => ($_POST['u_mensajes_scope'] ?? 'asignados'),
        'mensajes_acceso' => isset($_POST['u_perm_mensajes']),
        'horas_extra' => isset($_POST['u_perm_horas_extra']) ? ($_POST['u_horas_scope'] ?? 'asignados') : '',
        'historico' => $userCanViewHistorico,
        'historico_acciones' => $userCanViewHistorico && isset($_POST['u_perm_historico_acciones']),
        'historico_scope' => ($_POST['u_historico_scope'] ?? 'asignados'),
        'configuracion' => (string)($_POST['u_perm_configuracion'] ?? '0') === '1',
        'estadisticas' => isset($_POST['u_perm_estadisticas']),
        'usuarios' => isset($_POST['u_perm_usuarios']),
        'categorias' => isset($_POST['u_perm_categorias']),
        'simulador' => isset($_POST['u_perm_simulador']),
        'cfg_conexion' => isset($_POST['u_perm_cfg_conexion']),
        'cfg_proyecto' => isset($_POST['u_perm_cfg_proyecto']),
        'cfg_retencion' => isset($_POST['u_perm_cfg_retencion']),
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
      $effectiveUserRole = strtolower($newUserRole !== '' ? $newUserRole : (string)($usuariosIndex[$selectedUser]['rol'] ?? 'usuario'));
      if ($effectiveUserRole === 'root') $cfgUser['all'] = true;
      foreach ($usuariosData as &$u) {
        if ((string)($u['id'] ?? '') === $selectedUser) {
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
        $saveUserRole($selectedUser, $newUserRole);
      }
      $saveUserPermissions($selectedUser, $cfgUser);
      $flashUsuarios = 'Permisos actualizados para el usuario ID ' . $h($selectedUser);
      $openUsersModal = true;
    }
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_remote') {
  if (function_exists('csrf_validate')) csrf_validate();
  if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
  $res = sync_categorias_desde_api('');
  $msg = isset($res['error']) ? $res['error'] : ('Categorías sincronizadas (' . ($res['ok'] ?? 0) . ' registros).');
  $configRedirectUrl = ($_SERVER['SCRIPT_NAME'] ?? '/nova/public/index.php') . '/redmine-mantencion/app/configuracion';
  header('Location: ' . $configRedirectUrl . '?panel=categorias&synccat=' . urlencode($msg));
  exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Configuración'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
<?php $configuracionCssVersion = @filemtime(__DIR__ . '/../../assets/css/configuracion.css') ?: time(); ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/configuracion.css?v=<?= (int)$configuracionCssVersion ?>">
</head>
<body class="bg-light">
<?php $activeNav = $isNextcloudGroupsPanel ? 'integraciones_nextcloud_grupos' : 'configuracion'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
<div class="container-fluid py-4">
  <?php
    $heroIcon = $isNextcloudGroupsPanel ? 'bi-people' : 'bi-gear-wide-connected';
    $heroTitle = $isNextcloudGroupsPanel ? 'Grupos de Nextcloud' : 'Configuración de Redmine';
    $heroSubtitle = $isNextcloudGroupsPanel
      ? 'Consulta y administra los grupos utilizados por la integración Nextcloud.'
      : 'Administra conexión, proyecto, tiempos y listas maestras.';
    $heroExtras = '';
    $configPanels = [
      'resumen' => ['label' => 'Resumen', 'icon' => 'bi-speedometer2'],
      'conexion' => ['label' => 'Conexión', 'icon' => 'bi-plug'],
      'proyecto' => ['label' => 'Proyecto', 'icon' => 'bi-kanban'],
      'retencion' => ['label' => 'Retención', 'icon' => 'bi-stopwatch'],
      'trackers' => ['label' => 'Trackers', 'icon' => 'bi-diagram-3'],
      'prioridades' => ['label' => 'Prioridades', 'icon' => 'bi-exclamation-triangle'],
      'estados' => ['label' => 'Estados', 'icon' => 'bi-kanban'],
      'categorias' => ['label' => 'Categorías', 'icon' => 'bi-tags'],
      'mantencion' => ['label' => 'Mantención', 'icon' => 'bi-tools'],
      'roles' => ['label' => 'Roles y permisos', 'icon' => 'bi-shield-check'],
      'usuarios' => ['label' => 'Usuarios y permisos', 'icon' => 'bi-person-lock'],
    ];
    $configPanelPermissions = [
      'resumen' => 'cfg_resumen', 'conexion' => 'cfg_conexion', 'proyecto' => 'cfg_proyecto',
      'retencion' => 'cfg_retencion', 'trackers' => 'cfg_trackers', 'prioridades' => 'cfg_prioridades',
      'estados' => 'cfg_estados', 'categorias' => 'cfg_categorias', 'mantencion' => 'cfg_mantencion',
      'nextcloud' => 'integraciones_nextcloud', 'roles' => 'cfg_roles', 'usuarios' => 'cfg_usuarios',
    ];
    if ($isNextcloudGroupsPanel) {
      $configPanels = ['nextcloud' => ['label' => 'Grupos', 'icon' => 'bi-people']];
    }
    $configPanels = array_filter($configPanels, static fn($meta, $key) => auth_can($configPanelPermissions[$key] ?? 'configuracion'), ARRAY_FILTER_USE_BOTH);
    $requestedConfigPanel = isset($_GET['panel']) ? strtolower((string)$_GET['panel']) : '';
    $activeConfigPanel = $requestedConfigPanel !== '' ? $requestedConfigPanel : (string)(array_key_first($configPanels) ?? '');
    if (!empty($openRolesModal) && isset($configPanels['roles'])) $activeConfigPanel = 'roles';
    if (!empty($openUsersModal) && isset($configPanels['usuarios'])) $activeConfigPanel = 'usuarios';
    if (isset($_GET['synccat']) && isset($configPanels['categorias'])) $activeConfigPanel = 'categorias';
    if (isset($_POST['opt_type']) && isset($configPanels[$_POST['opt_type']])) $activeConfigPanel = (string)$_POST['opt_type'];
    if (!isset($configPanels[$activeConfigPanel])) {
      http_response_code(403);
      exit('No tienes permiso para ver esta sección de Configuración.');
    }
    $configBaseUrl = ($_SERVER['SCRIPT_NAME'] ?? '/nova/public/index.php') . '/redmine-mantencion/app/configuracion';
    $configPanelUrl = fn($panel) => $configBaseUrl . '?panel=' . rawurlencode((string)$panel);
    include __DIR__ . '/../partials/hero.php'; ?>
<?php if ($flash): ?><div data-nova-flash="success" data-nova-flash-message="<?= $h($flash) ?>" hidden></div><?php endif; ?>
<?php if ($flashRoles): ?><div data-nova-flash="success" data-nova-flash-message="<?= $h($flashRoles) ?>" hidden></div><?php endif; ?>
<?php if ($maintenanceFlash): ?><div data-nova-flash="info" data-nova-flash-message="<?= $h($maintenanceFlash) ?>" hidden></div><?php endif; ?>
<?php if (!empty($nextcloudFlash)): ?><div data-nova-flash="nextcloud" data-nova-flash-message="<?= $h($nextcloudFlash) ?>" hidden></div><?php endif; ?>
<?php
  ?>
  <div class="rm-config-shell rm-maint-config-shell <?= $isNextcloudGroupsPanel ? 'is-standalone' : '' ?>">
    <?php if (!$isNextcloudGroupsPanel): ?>
    <aside class="rm-config-rail">
      <div class="rm-config-rail-head">
        <span><i class="bi bi-gear-wide-connected"></i></span>
        <div>
          <small>Redmine Mantención</small>
          <strong>Configuración</strong>
        </div>
      </div>
      <nav class="rm-config-nav" aria-label="Opciones de configuración">
        <?php foreach ($configPanels as $panelKey => $panelMeta): ?>
          <a class="rm-config-nav-link <?= $activeConfigPanel === $panelKey ? 'active' : '' ?>"
             href="<?= $h($configPanelUrl($panelKey)) ?>"
             <?= $activeConfigPanel === $panelKey ? 'aria-current="page"' : '' ?>>
            <i class="bi <?= $h($panelMeta['icon']) ?>"></i>
            <span><?= $h($panelMeta['label']) ?></span>
            <i class="bi bi-chevron-right rm-config-nav-chevron"></i>
          </a>
        <?php endforeach; ?>
      </nav>
 <!-- Los cambios afectan solo al módulo Mantención     <p class="rm-config-rail-help"><i class="bi bi-info-circle"></i> .</p> -->
    </aside>
    <?php endif; ?>
    <main class="rm-config-content">

  <?php if ($activeConfigPanel === 'resumen'): ?>
  <section class="rm-config-summary">
    <div class="rm-config-summary-kpis">
      <article class="rm-summary-kpi">
        <span class="is-blue"><i class="bi bi-kanban"></i></span>
        <div><small>Proyecto</small><strong><?= $h($cfg['project_id'] ?? '-') ?></strong></div>
      </article>
      <article class="rm-summary-kpi">
        <span class="is-cyan"><i class="bi bi-tags"></i></span>
        <div><small>Categorias</small><strong><?= $h(is_array($categoriasData) ? count($categoriasData) : 0) ?></strong></div>
      </article>
      <article class="rm-summary-kpi">
        <span class="is-green"><i class="bi bi-diagram-3"></i></span>
        <div><small>Trackers</small><strong><?= $h(is_array($opts['trackers'] ?? null) ? count($opts['trackers']) : 0) ?></strong></div>
      </article>
      <article class="rm-summary-kpi">
        <span class="<?= $maintenanceMode ? 'is-orange' : 'is-slate' ?>"><i class="bi bi-tools"></i></span>
        <div><small>Mantencion</small><strong><?= $maintenanceMode ? 'Activa' : 'Inactiva' ?></strong></div>
      </article>
    </div>

    <div class="rm-config-summary-grid">
      <article class="rm-summary-card">
        <div class="rm-summary-card-head">
          <span><i class="bi bi-hdd-network"></i></span>
          <div>
            <h2>Conexion Redmine</h2>
            <p>Endpoints usados para enviar y sincronizar datos.</p>
          </div>
        </div>
        <div class="rm-summary-list">
          <div><span>Proyecto</span><strong><?= $h($cfg['project_name'] ?? '-') ?></strong></div>
          <div><span>URL issues</span><strong><?= $h($cfg['platform_url'] ?? '-') ?></strong></div>
          <div><span>URL categorias</span><strong><?= $h($cfg['categories_url'] ?? '-') ?></strong></div>
        </div>
      </article>

      <article class="rm-summary-card">
        <div class="rm-summary-card-head">
          <span><i class="bi bi-sliders"></i></span>
          <div>
            <h2>Operacion</h2>
            <p>Parametros locales activos del modulo.</p>
          </div>
        </div>
        <div class="rm-summary-operation-grid">
          <div><span>Retencion</span><strong><?= $h($cfg['retencion_horas'] ?? 24) ?> hora(s)</strong></div>
          <div><span>Sesion</span><strong>NOVA global</strong></div>
          <div><span>Roles</span><strong><?= $h(is_array($rolesData ?? null) ? count($rolesData) : 0) ?> perfil(es)</strong></div>
          <div><span>Documentos</span><strong>Nextcloud</strong></div>
        </div>
      </article>
    </div>
  </section>

  <?php endif; ?>

<!-- Modal Nextcloud -->
<div class="rm-config-view-panel <?= $activeConfigPanel === 'nextcloud' ? 'is-active' : '' ?>" id="nextcloudConfigModal" tabindex="-1" aria-hidden="true">
  <div class="rm-panel-shell rm-panel-xl">
    <div class="rm-panel-card">
      <div class="rm-panel-head">
        <div>
          <h5 class="rm-panel-title mb-0">Configuraci&oacute;n Nextcloud</h5>
          <div class="text-muted small">URL, valores por defecto y grupos disponibles para creaci&oacute;n masiva.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="rm-panel-body">
        <div class="row g-3">
          <div class="col-lg-5">
            <form method="post" class="h-100 p-3 border rounded-4 bg-white">
              <input type="hidden" name="action" value="save_nextcloud_config">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
              <h6 class="fw-bold mb-3"><i class="bi bi-sliders text-primary"></i> Parámetros</h6>
              <div class="mb-3">
                <label class="form-label">URL Nextcloud</label>
                <input name="nextcloud_url" class="form-control" value="<?= $h($nextcloudCfg['url'] ?? 'https://www.coresalud.cl/nextcloud') ?>" placeholder="https://www.coresalud.cl/nextcloud" required>
              </div>
              <!-- <div class="nova-alert-card is-nextcloud py-2 small">
                <i class="bi bi-cloud-fill"></i>
                <span>Las credenciales de Nextcloud se administran desde <strong>Cuentas conectadas</strong>, junto a las credenciales CORE de cada usuario.</span>
              </div> -->
              <div class="mb-3">
                <label class="form-label">Grupo por defecto</label>
                <?php if (!empty($nextcloudGroups)): ?>
                  <select name="nextcloud_default_group" class="form-select">
                    <option value="">Sin grupo por defecto</option>
                    <?php foreach ($nextcloudGroups as $group): ?>
                      <option value="<?= $h($group) ?>" <?= (string)($nextcloudCfg['default_group'] ?? '') === (string)$group ? 'selected' : '' ?>><?= $h($group) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <input name="nextcloud_default_group" class="form-control" value="<?= $h($nextcloudCfg['default_group'] ?? '') ?>" placeholder="Consulta grupos o escribe uno manualmente">
                <?php endif; ?>
              </div>
              <div class="row g-2">
                <div class="col-md-6">
                  <label class="form-label">Cuota por defecto</label>
                  <?php $quotaDefault = (string)($nextcloudCfg['default_quota'] ?? ''); ?>
                  <select name="nextcloud_default_quota" class="form-select">
                    <option value="" <?= $quotaDefault === '' ? 'selected' : '' ?>>Predeterminada</option>
                    <option value="none" <?= $quotaDefault === 'none' ? 'selected' : '' ?>>Ilimitado</option>
                    <option value="1 GB" <?= $quotaDefault === '1 GB' ? 'selected' : '' ?>>1 GB</option>
                    <option value="5 GB" <?= $quotaDefault === '5 GB' ? 'selected' : '' ?>>5 GB</option>
                    <option value="10 GB" <?= $quotaDefault === '10 GB' ? 'selected' : '' ?>>10 GB</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Idioma</label>
                  <input name="nextcloud_default_language" class="form-control" value="<?= $h($nextcloudCfg['default_language'] ?? 'es') ?>" placeholder="es">
                </div>
              </div>
              <button class="btn-nova btn-nova-primary w-100 mt-3" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>>
                <i class="bi bi-save"></i> Guardar configuración
              </button>
            </form>
          </div>
          <div class="col-lg-7">
            <div class="h-100 p-3 border rounded-4 bg-white">
              <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                  <h6 class="fw-bold mb-1"><i class="bi bi-folder2-open text-info"></i> Grupos consultados</h6>
                  <div class="text-muted small">Se almacenan para buscar coincidencias al cargar el Excel.</div>
                </div>
                <form method="post" class="m-0">
                  <input type="hidden" name="action" value="fetch_nextcloud_groups">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                  <button class="btn-nova btn-nova-info" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>>
                    <i class="bi bi-arrow-repeat"></i> Consultar grupos
                  </button>
                </form>
                <?php if (!empty($nextcloudGroups)): ?>
                  <form method="post" class="m-0" data-app-confirm="¿Eliminar todos los grupos guardados?">
                    <input type="hidden" name="action" value="clear_nextcloud_groups">
                    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                    <button class="btn-nova btn-nova-danger" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>>
                      <i class="bi bi-trash3"></i> Eliminar guardados
                    </button>
                  </form>
                <?php endif; ?>
              </div>
              <?php if (!empty($nextcloudGroups)): ?>
                <div class="border rounded-4 p-3 bg-light" style="max-height:360px;overflow:auto;">
                  <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($nextcloudGroups as $group): ?>
                      <span class="badge rounded-pill text-bg-info-subtle text-info border border-info-subtle"><i class="bi bi-folder2"></i> <?= $h($group) ?></span>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="text-muted small mt-2">Total: <?= count($nextcloudGroups) ?> grupos.</div>
              <?php else: ?>
                <div class="text-muted small border rounded-4 p-3 bg-light">Sin grupos consultados.</div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="rm-panel-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Mantención -->
<div class="rm-config-view-panel <?= $activeConfigPanel === 'mantencion' ? 'is-active' : '' ?>" id="maintenanceModal" tabindex="-1" aria-hidden="true">
  <div class="rm-panel-shell rm-panel-lg">
    <div class="rm-panel-card">
      <div class="rm-panel-head">
        <div>
          <h5 class="rm-panel-title mb-0">Mantenci&oacute;n</h5>
          <div class="text-muted small">Controla el modo de solo lectura de la plataforma.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="rm-panel-body">
        <form method="post" class="p-3 border rounded-4 bg-white mb-3">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" value="maintenance_settings">
          <div class="row g-3 align-items-end">
            <div class="col-md-5">
              <label class="form-label">Estado de mantenci&oacute;n</label>
              <div class="form-check form-switch maintenance-mode-switch">
                <input class="form-check-input" type="checkbox" role="switch" name="maintenance_mode" value="1" id="maintenance-mode-check" <?= !empty($maintenanceSettings['enabled']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="maintenance-mode-check">Mantenci&oacute;n activa</label>
              </div>
            </div>
            <div class="col-md-5">
              <label class="form-label">Hora estimada de t&eacute;rmino</label>
              <input type="datetime-local" name="maintenance_until" class="form-control" value="<?= $h($maintenanceSettings['until'] ?? '') ?>">
            </div>
            <div class="col-md-2">
              <button class="btn-nova btn-nova-primary w-100" type="submit">Guardar</button>
            </div>
          </div>
          <div class="form-text mt-2">Cuando est&aacute; activa, la plataforma queda en solo lectura y solo este modal permite cambiar la mantenci&oacute;n.</div>
        </form>
      </div>
      <div class="rm-panel-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<?php $renderOptionsTable = function($id, $title, $items, $type, $h) use ($csrf, $activeConfigPanel) { ?>
<div class="rm-config-view-panel <?= $activeConfigPanel === $type ? 'is-active' : '' ?>" id="<?= $h($id) ?>" tabindex="-1" aria-hidden="true">
  <div class="rm-panel-shell rm-panel-xl">
    <div class="rm-panel-card">
      <div class="rm-panel-head">
        <h5 class="rm-panel-title"><?= $h($title) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="rm-panel-body">
        <form method="post" class="row g-2 mb-3">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <input type="hidden" name="opt_type" value="<?= $h($type) ?>">
          <input type="hidden" name="opt_action" value="create">
          <div class="col-md-3"><input name="opt_id" class="form-control form-control-sm" placeholder="ID" required></div>
          <div class="col-md-7"><input name="opt_nombre" class="form-control form-control-sm" placeholder="Nombre" required></div>
          <div class="col-md-2 d-flex align-items-center gap-2">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="opt_default" id="optdef-<?= $h($type) ?>">
              <label class="form-check-label" for="optdef-<?= $h($type) ?>">Default</label>
            </div>
            <button class="btn-nova btn-nova-success">Agregar</button>
          </div>
        </form>
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light"><tr><th>ID</th><th>Nombre</th><th>Default</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ($items as $index => $o): ?>
              <?php $rowFormId = $type . '-edit-' . $index . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($o['id'] ?? '')); ?>
              <?php $deleteFormId = $type . '-delete-' . $index . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', (string)($o['id'] ?? '')); ?>
              <tr>
                  <td style="width:120px">
                    <input form="<?= $h($rowFormId) ?>" name="opt_id" class="form-control form-control-sm" value="<?= $h($o['id']) ?>">
                  </td>
                  <td>
                    <input form="<?= $h($rowFormId) ?>" name="opt_nombre" class="form-control form-control-sm" value="<?= $h($o['nombre']) ?>">
                  </td>
                  <td class="text-center">
                    <input form="<?= $h($rowFormId) ?>" class="form-check-input" type="checkbox" name="opt_default" <?= !empty($o['default']) ? 'checked' : '' ?>>
                  </td>
                  <td class="d-flex gap-2">
                    <form method="post" id="<?= $h($rowFormId) ?>" class="m-0">
                      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                      <input type="hidden" name="opt_type" value="<?= $h($type) ?>">
                      <input type="hidden" name="opt_action" value="update">
                      <input type="hidden" name="opt_id_original" value="<?= $h($o['id']) ?>">
                      <button class="btn-action btn-action-edit" type="submit" title="Guardar" aria-label="Guardar"><i class="bi bi-check-lg"></i></button>
                    </form>
                    <form method="post" id="<?= $h($deleteFormId) ?>" data-app-confirm="Eliminar?" class="m-0">
                      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                      <input type="hidden" name="opt_type" value="<?= $h($type) ?>">
                      <input type="hidden" name="opt_action" value="delete">
                      <input type="hidden" name="opt_id_original" value="<?= $h($o['id']) ?>">
                      <button class="btn-action btn-action-delete" type="submit" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash"></i></button>
                    </form>
                  </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<?php }; ?>

<!-- Modales base -->
<?php $renderOptionsTable('trackersModal', 'Trackers', $opts['trackers'], 'trackers', $h); ?>
<?php $renderOptionsTable('prioridadesModal', 'Prioridades', $opts['prioridades'], 'prioridades', $h); ?>
<?php $renderOptionsTable('estadosModal', 'Estados', $opts['estados'], 'estados', $h); ?>

<!-- Modal Categorías -->
<div class="rm-config-view-panel <?= $activeConfigPanel === 'categorias' ? 'is-active' : '' ?>" id="categoriasModal" tabindex="-1" aria-hidden="true">
  <div class="rm-panel-shell rm-panel-xl">
    <div class="rm-panel-card">
      <div class="rm-panel-head">
        <div>
          <h5 class="rm-panel-title mb-0">Categor&iacute;as (solo lectura)</h5>
          <div class="text-muted small">Sincronizadas desde Redmine. Soporta `issue_categories.json` y `settings/categories`.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="rm-panel-body rm-panel-body-tight">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
          <div class="d-flex align-items-center gap-2 flex-grow-1" style="min-width:260px;">
            <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width:32px;height:32px;"><i class="bi bi-search"></i></div>
            <input id="cat-filter" class="form-control" placeholder="Buscar categor&iacute;a (ID o nombre)">
            <span class="badge bg-light text-dark border">Total: <?= $h(is_array($categoriasData) ? count($categoriasData) : 0) ?></span>
          </div>
          <div class="d-flex gap-2">
            <form action="<?= $h($configPanelUrl('categorias')) ?>" method="post" class="m-0 d-inline" id="sync-cat-form">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action" value="sync_remote">
              <button class="btn-nova btn-nova-info btn-icon" type="submit" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>><i class="bi bi-arrow-repeat"></i> Actualizar desde API</button>
            </form>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-striped align-middle" id="cat-table">
            <thead class="table-light"><tr><th style="width:120px;">ID</th><th>Nombre</th></tr></thead>
            <tbody>
              <?php if ($categoriasData): foreach ($categoriasData as $c): ?>
                <tr>
                  <td class="text-muted"><?= $h($c['id'] ?? '') ?></td>
                  <td><?= $h($c['nombre'] ?? '') ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="2" class="text-center text-muted">A&uacute;n no hay datos sincronizados.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="rm-panel-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal Conexión -->
<div class="rm-config-view-panel <?= $activeConfigPanel === 'conexion' ? 'is-active' : '' ?>" id="ConexionModal" tabindex="-1" aria-hidden="true">
  <div class="rm-panel-shell rm-panel-lg">
    <div class="rm-panel-card">
      <div class="rm-panel-head">
        <div>
          <h5 class="rm-panel-title mb-0">Conexión API</h5>
          <div class="text-muted small">Conexiones externas y campos técnicos</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar" onclick="(function(){var el=document.getElementById('rolesModal');if(!el)return;el.classList.remove('show');el.style.display='none';el.setAttribute('aria-hidden','true');var bd=document.getElementById('backdrop-roles');if(bd)bd.remove();document.body.classList.remove('modal-open');})();"></button>
      </div>
      <div class="rm-panel-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <div class="col-12"><h6 class="fw-bold mb-0">CORE</h6></div>
          <div class="col-12">
            <label class="form-label">URL administrador CORE</label>
            <input name="core_admin_url" class="form-control" value="<?= $h($cfg['core_admin_url'] ?? 'https://www.hbvaldivia.cl/core/solicitudes/administrador') ?>">
          </div>
          <div class="col-12">
            <label class="form-label">URL histórico CORE</label>
            <input name="core_historico_url" class="form-control" value="<?= $h($cfg['core_historico_url'] ?? 'https://www.hbvaldivia.cl/core/solicitudes/administrador/obtener_solicitudes_historicas') ?>">
          </div>
          <div class="col-12"><hr class="my-1"><h6 class="fw-bold mb-0">Redmine</h6></div>
          <div class="col-12">
            <label class="form-label">URL issues.json</label>
            <input name="platform_url" class="form-control" value="<?= $h($cfg['platform_url'] ?? '') ?>" required>
          </div>
          <div class="col-12">
            <label class="form-label">URL categor&iacute;as (opcional)</label>
            <input name="categories_url" class="form-control" value="<?= $h($cfg['categories_url'] ?? '') ?>" placeholder="Ej: https://tu-host/projects/xxx/settings/categories">
          </div>
          <div class="col-12">
            <div class="nova-alert-card is-info mb-0">
              <i class="bi bi-person-lock"></i>
              La API de Redmine es personal por usuario y se configura en Cuentas conectadas.
            </div>
          </div>
          <div class="col-12"><hr class="my-1"><h6 class="fw-bold mb-0">Campos personalizados</h6></div>
          <div class="col-md-4">
            <label class="form-label">CF Solicitante (ID)</label>
            <input name="cf_solicitante" class="form-control" value="<?= $h($cfg['cf_solicitante'] ?? '') ?>" placeholder="Ej: 3">
          </div>
          <div class="col-md-4">
            <label class="form-label">CF Unidad (ID)</label>
            <input name="cf_unidad" class="form-control" value="<?= $h($cfg['cf_unidad'] ?? '') ?>" placeholder="Ej: 5">
          </div>
          <div class="col-md-4">
            <label class="form-label">CF Unidad solicitante (ID)</label>
            <input name="cf_unidad_solicitante" class="form-control" value="<?= $h($cfg['cf_unidad_solicitante'] ?? '') ?>" placeholder="Ej: 11">
          </div>
          <div class="col-md-4">
            <label class="form-label">CF Horas extra (ID)</label>
            <input name="cf_hora_extra" class="form-control" value="<?= $h($cfg['cf_hora_extra'] ?? '') ?>" placeholder="Ej: 12">
          </div>
          <div class="col-md-4">
            <label class="form-label">Tiempo estimado HE</label>
            <input name="hora_extra_tiempo_estimado" class="form-control" value="<?= $h($cfg['hora_extra_tiempo_estimado'] ?? '1') ?>" placeholder="Ej: 1">
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn-nova btn-nova-primary">Guardar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Modal Proyecto -->
<div class="rm-config-view-panel <?= $activeConfigPanel === 'proyecto' ? 'is-active' : '' ?>" id="proyectoModal" tabindex="-1" aria-hidden="true">
  <div class="rm-panel-shell rm-panel-md">
    <div class="rm-panel-card">
      <div class="rm-panel-head">
        <div>
          <h5 class="rm-panel-title mb-0">Proyecto</h5>
          <div class="text-muted small">Proyecto, campos y estado inicial por defecto</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar" onclick="(function(){var el=document.getElementById('usuariosModal');if(!el)return;el.classList.remove('show');el.style.display='none';el.setAttribute('aria-hidden','true');var bd=document.getElementById('backdrop-usuarios');if(bd)bd.remove();document.body.classList.remove('modal-open');})();"></button>
      </div>
      <div class="rm-panel-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <div class="col-12">
            <label class="form-label">Project ID</label>
            <input name="project_id" class="form-control" value="<?= $h($cfg['project_id'] ?? '') ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Nombre del proyecto</label>
            <input name="project_name" class="form-control" value="<?= $h($cfg['project_name'] ?? '') ?>" placeholder="Solo referencia visual">
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn-nova btn-nova-primary">Guardar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Modal Retención -->
<div class="rm-config-view-panel <?= $activeConfigPanel === 'retencion' ? 'is-active' : '' ?>" id="RetencionModal" tabindex="-1" aria-hidden="true">
  <div class="rm-panel-shell rm-panel-md">
    <div class="rm-panel-card">
      <div class="rm-panel-head">
        <div>
          <h5 class="rm-panel-title mb-0">Retención de procesados</h5>
          <div class="text-muted small">Define cuántas horas se conservan los mensajes procesados</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="rm-panel-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <div class="col-12">
            <label class="form-label">Horas antes de borrar procesados</label>
            <input type="number" min="1" name="retencion_horas" class="form-control" value="<?= $h($cfg['retencion_horas'] ?? 24) ?>">
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button class="btn-nova btn-nova-primary">Guardar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($canManageRoles || $canManageUsers):
  $rolesList = array_keys($rolesData ?: []);
  sort($rolesList);
  if ($canManageRoles):
    $selCfg = $rolesData[$selectedRole] ?? [];
    if (!isset($selCfg['mensajes_acceso'])) $selCfg['mensajes_acceso'] = true;
    $scopeHist = $selCfg['historico_scope'] ?? 'asignados';
    $scopeMsg = $selCfg['mensajes'] ?? 'asignados';
    $scopeHoras = $selCfg['horas_extra'] ?? 'asignados';
  endif;
  if ($canManageUsers):
    // Datos para modal de usuarios
    $usersList = [];
    foreach ($usuariosSelectableData as $u) {
      if (!isset($u['id'])) continue;
      $label = ($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? '') . ' (ID ' . $u['id'] . ')';
      $usersList[(string)$u['id']] = trim($label);
    }
    ksort($usersList);
    $selUserData = $selectedUser !== '' && isset($usuariosIndex[$selectedUser]) ? $usuariosIndex[$selectedUser] : null;
    $selUserRole = $selUserData['rol'] ?? '';
    $selUserPerms = $selUserData['permisos'] ?? null;
    $roleDefaults = $selUserRole && isset($rolesData[$selUserRole]) ? $rolesData[$selUserRole] : [];
    $uCfg = is_array($selUserPerms) ? array_replace($roleDefaults, $selUserPerms) : $roleDefaults;
    $uScopeMsg = $uCfg['mensajes'] ?? 'asignados';
    $uScopeHoras = $uCfg['horas_extra'] ?? 'asignados';
    $uScopeHist = $uCfg['historico_scope'] ?? 'asignados';
    $uHistAcciones = !empty($uCfg['historico_acciones']);
    $uHas = fn($k) => !empty($uCfg[$k]);
  endif;
?>
<?php if ($canManageUsers): ?>
<!-- Modal Usuarios y permisos -->
<div class="rm-config-view-panel <?= $activeConfigPanel === 'usuarios' ? 'is-active' : '' ?>" id="usuariosModal" tabindex="-1" aria-hidden="true">
  <div class="rm-panel-shell rm-panel-xl">
    <div class="rm-panel-card">
      <div class="rm-panel-head">
        <div>
          <h5 class="rm-panel-title mb-0">Usuarios y permisos</h5>
          <div class="text-muted small">Rol asignado, accesos y alcances por usuario</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="rm-panel-body">
        <form method="post" class="row g-3 roles-modal-shell">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" id="user-action" value="save_user_perms">
          <div class="col-md-8">
            <label class="form-label">Usuario</label>
            <input type="hidden" name="user_select" id="user-select" value="<?= $h($selectedUser) ?>">
            <div class="user-picker" id="user-picker">
              <input
                type="search"
                class="form-control"
                id="user-search"
                autocomplete="off"
                placeholder="Buscar por nombre o ID"
                value="<?= $selectedUser !== '' && isset($usersList[$selectedUser]) ? $h($usersList[$selectedUser]) : '' ?>"
              >
              <div class="user-picker-results d-none" id="user-search-results" role="listbox"></div>
            </div>
            <div class="form-text">Escribe nombre o código de usuario y selecciona un resultado.</div>
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <div>
              <div class="form-label">Rol asignado</div>
              <span class="badge bg-info-subtle text-primary" id="user-role-badge"><?= $selUserRole !== '' ? $h($selUserRole) : 'N/D' ?></span>
            </div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Cambiar rol</label>
            <select name="u_role" class="form-select" id="u-role">
              <option value="">(mantener)</option>
              <?php foreach ($rolesList as $r): ?>
                <option value="<?= $h($r) ?>" <?= $selUserRole === $r ? 'selected' : '' ?>><?= ucfirst($h($r)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 permission-scope-section">
            <label class="form-label mb-1">Alcance</label>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label small mb-1">Reportes</label>
                <select name="u_mensajes_scope" class="form-select">
                  <option value="todos" <?= $uScopeMsg === 'todos' ? 'selected' : '' ?>>Ver todos</option>
                  <option value="asignados" <?= $uScopeMsg === 'asignados' ? 'selected' : '' ?>>Solo asignados</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small mb-1">Horas extra</label>
                <select name="u_horas_scope" class="form-select">
                  <option value="todos" <?= $uScopeHoras === 'todos' ? 'selected' : '' ?>>Ver todas</option>
                  <option value="asignados" <?= $uScopeHoras === 'asignados' ? 'selected' : '' ?>>Solo asignadas</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small mb-1">Hist&oacute;rico</label>
                <select name="u_historico_scope" class="form-select">
                  <option value="todos" <?= $uScopeHist === 'todos' ? 'selected' : '' ?>>Ver todos</option>
                  <option value="asignados" <?= $uScopeHist === 'asignados' ? 'selected' : '' ?>>Solo asignados</option>
                </select>
              </div>
            </div>
          </div>
          <div class="col-12 permission-grid-section">
            <label class="form-label mb-1">Accesos a vistas</label>
            <div class="row g-2">
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_mensajes" id="u_perm_mensajes_chk" <?= $uHas('mensajes_acceso') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_mensajes_chk">Reportes</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_horas_extra" id="u_perm_horas_extra_chk" <?= !empty($uCfg['horas_extra']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_horas_extra_chk">Horas extra</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_estadisticas" id="u_perm_estadisticas_chk" <?= $uHas('estadisticas') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_estadisticas_chk">Estad&iacute;sticas</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_usuarios" id="u_perm_usuarios_chk" <?= $uHas('usuarios') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_usuarios_chk">Usuarios</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_categorias" id="u_perm_categorias_chk" <?= $uHas('categorias') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_categorias_chk">Categor&iacute;as</label>
                </div>
              </div>
              <div class="col-md-4 permission-related">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_historico" id="u_perm_historico_chk" <?= $uHas('historico') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_historico_chk">Hist&oacute;rico</label>
                </div>
                <div class="form-check ms-3 mt-1 permission-child">
                  <input class="form-check-input" type="checkbox" name="u_perm_historico_acciones" id="u_perm_historico_acciones_chk" <?= $uHistAcciones ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_historico_acciones_chk">Ver acciones en hist&oacute;rico <span class="permission-tag">Adicional</span><span class="permission-note">Depende de Hist&oacute;rico.</span></label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_simulador" id="u_perm_simulador_chk" <?= $uHas('simulador') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_simulador_chk">Ingresar pendiente manual</label>
                </div>
              </div>
              <div class="col-md-4 permission-related">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_actividad" id="u_perm_actividad_chk" <?= $uHas('actividad') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_actividad_chk">Ver bit&aacute;cora de actividad</label>
                </div>
                <div class="form-check ms-3 mt-1 permission-child">
                  <input class="form-check-input" type="checkbox" name="u_perm_actividad_todos" id="u_perm_actividad_todos_chk" <?= $uHas('actividad_todos') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_actividad_todos_chk">Ver todos los registros <span class="permission-tag">Administrador</span><span class="permission-note">Sin este permiso solo ve sus eventos.</span></label>
                </div>
                <div class="form-check ms-3 mt-1 permission-child">
                  <input class="form-check-input" type="checkbox" name="u_perm_actividad_eliminar" id="u_perm_actividad_eliminar_chk" <?= $uHas('actividad_eliminar') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_actividad_eliminar_chk">Eliminar bit&aacute;cora <span class="permission-tag">Adicional</span><span class="permission-note">Depende de Ver bit&aacute;cora.</span></label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check form-switch">
                  <input class="form-check-input" role="switch" type="checkbox" name="u_perm_mis_integraciones" id="u_perm_mis_integraciones" <?= $uHas('mis_integraciones') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_mis_integraciones">Mis integraciones</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_integraciones_nextcloud" id="u_perm_integraciones_nextcloud" <?= $uHas('integraciones_nextcloud') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_integraciones_nextcloud">Administrar integración Nextcloud</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input type="hidden" name="u_perm_configuracion" value="0">
                  <input class="form-check-input" type="checkbox" name="u_perm_configuracion" value="1" id="u_perm_configuracion_chk" <?= $uHas('configuracion') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_configuracion_chk">Configuraci&oacute;n</label>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 permission-grid-section">
            <label class="form-label mb-1">Permisos de configuraci&oacute;n</label>
            <div class="row g-2">
              <?php foreach ([
                'resumen' => 'Resumen', 'categorias' => 'Categorías',
                'mantencion' => 'Mantención', 'nextcloud' => 'Nextcloud',
              ] as $cfgKey => $cfgLabel): ?>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_<?= $h($cfgKey) ?>" id="u_perm_cfg_<?= $h($cfgKey) ?>" <?= $uHas('cfg_' . $cfgKey) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_<?= $h($cfgKey) ?>"><?= $h($cfgLabel) ?></label>
                </div>
              </div>
              <?php endforeach; ?>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_conexion" id="u_perm_cfg_conexion" <?= $uHas('cfg_conexion') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_conexion">Conexi&oacute;n</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_proyecto" id="u_perm_cfg_proyecto" <?= $uHas('cfg_proyecto') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_proyecto">Proyecto</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_retencion" id="u_perm_cfg_retencion" <?= $uHas('cfg_retencion') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_retencion">Retenci&oacute;n</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_trackers" id="u_perm_cfg_trackers" <?= $uHas('cfg_trackers') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_trackers">Trackers</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_prioridades" id="u_perm_cfg_prioridades" <?= $uHas('cfg_prioridades') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_prioridades">Prioridades</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_estados" id="u_perm_cfg_estados" <?= $uHas('cfg_estados') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_estados">Estados</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_roles" id="u_perm_cfg_roles" <?= $uHas('cfg_roles') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_roles">Roles y permisos</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="u_perm_cfg_usuarios" id="u_perm_cfg_usuarios" <?= $uHas('cfg_usuarios') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="u_perm_cfg_usuarios">Usuarios y permisos</label>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 permission-form-actions">
            <button class="btn-nova btn-nova-secondary" type="button" data-permission-cancel hidden>
              <i class="bi bi-x-lg"></i>Cancelar
            </button>
            <button class="btn-nova btn-nova-primary" type="submit" id="btn-save-user-perms">Guardar permisos</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($canManageRoles): ?>
<div class="rm-config-view-panel <?= $activeConfigPanel === 'roles' ? 'is-active' : '' ?>" id="rolesModal" tabindex="-1" aria-hidden="true">
  <div class="rm-panel-shell rm-panel-xl">
    <div class="rm-panel-card">
      <div class="rm-panel-head">
        <div>
          <h5 class="rm-panel-title mb-0">Roles y permisos</h5>
          <div class="text-muted small">Define accesos y alcances por rol</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="rm-panel-body">
        <form method="post" class="row g-3 roles-modal-shell">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" id="roles-action" value="save_roles">
          <div class="col-md-6">
            <label class="form-label">Rol</label>
            <select name="role_select" class="form-select" id="role-select">
              <?php foreach ($rolesList as $r): ?>
                <option value="<?= $h($r) ?>" <?= $selectedRole === $r ? 'selected' : '' ?>><?= ucfirst($h($r)) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Para crear un rol nuevo, escribe el nombre y guarda.</div>
            <input type="text" class="form-control mt-2" name="new_role" placeholder="Nuevo rol (opcional)" value="">
          </div>
          <div class="col-12 permission-scope-section">
            <label class="form-label mb-1">Alcance</label>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label small mb-1">Reportes</label>
                <select name="mensajes_scope" class="form-select">
                  <option value="todos" <?= $scopeMsg === 'todos' ? 'selected' : '' ?>>Ver todos</option>
                  <option value="asignados" <?= $scopeMsg === 'asignados' ? 'selected' : '' ?>>Solo asignados</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small mb-1">Horas extra</label>
                <select name="horas_scope" class="form-select">
                  <option value="todos" <?= $scopeHoras === 'todos' ? 'selected' : '' ?>>Ver todas</option>
                  <option value="asignados" <?= $scopeHoras === 'asignados' ? 'selected' : '' ?>>Solo asignadas</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label small mb-1">Hist&oacute;rico</label>
                <select name="historico_scope" class="form-select">
                  <option value="todos" <?= $scopeHist === 'todos' ? 'selected' : '' ?>>Ver todos</option>
                  <option value="asignados" <?= $scopeHist === 'asignados' ? 'selected' : '' ?>>Solo asignados</option>
                </select>
              </div>
            </div>
          </div>
          <div class="col-12 permission-grid-section">
            <label class="form-label mb-1">Accesos a vistas</label>
            <div class="row g-2">
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_mensajes" id="permMsg" <?= !empty($selCfg['mensajes_acceso']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permMsg">Reportes</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_horas_extra" id="permHorasExtra" <?= !empty($selCfg['horas_extra']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permHorasExtra">Horas extra</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_estadisticas" id="permEst" <?= !empty($selCfg['estadisticas']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permEst">Estad&iacute;sticas</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_usuarios" id="permUsr" <?= !empty($selCfg['usuarios']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permUsr">Usuarios</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_categorias" id="permCat" <?= !empty($selCfg['categorias']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCat">Categorias</label>
                </div>
              </div>
              <div class="col-md-4 permission-related">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_historico" id="permHist" <?= !empty($selCfg['historico']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permHist">Hist&oacute;rico</label>
                </div>
                <div class="form-check ms-3 mt-1 permission-child">
                  <input class="form-check-input" type="checkbox" name="perm_historico_acciones" id="permHistAcc" <?= !empty($selCfg['historico_acciones']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permHistAcc">Ver acciones en hist&oacute;rico <span class="permission-tag">Adicional</span><span class="permission-note">Depende de Hist&oacute;rico.</span></label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_simulador" id="permSim" <?= !empty($selCfg['simulador']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permSim">Ingresar pendiente manual</label>
                </div>
              </div>
              <div class="col-md-4 permission-related">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_actividad" id="permActividad" <?= !empty($selCfg['actividad']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permActividad">Ver bit&aacute;cora de actividad</label>
                </div>
                <div class="form-check ms-3 mt-1 permission-child">
                  <input class="form-check-input" type="checkbox" name="perm_actividad_todos" id="permActividadTodos" <?= !empty($selCfg['actividad_todos']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permActividadTodos">Ver todos los registros <span class="permission-tag">Administrador</span><span class="permission-note">Sin este permiso solo ve sus eventos.</span></label>
                </div>
                <div class="form-check ms-3 mt-1 permission-child">
                  <input class="form-check-input" type="checkbox" name="perm_actividad_eliminar" id="permActividadEliminar" <?= !empty($selCfg['actividad_eliminar']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permActividadEliminar">Eliminar bit&aacute;cora <span class="permission-tag">Adicional</span><span class="permission-note">Depende de Ver bit&aacute;cora.</span></label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check form-switch">
                  <input class="form-check-input" role="switch" type="checkbox" name="perm_mis_integraciones" id="permMisIntegraciones" <?= !empty($selCfg['mis_integraciones']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permMisIntegraciones">Mis integraciones</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_integraciones_nextcloud" id="permIntegracionesNextcloud" <?= !empty($selCfg['integraciones_nextcloud']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permIntegracionesNextcloud">Administrar integración Nextcloud</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input type="hidden" name="perm_configuracion" value="0">
                  <input class="form-check-input" type="checkbox" name="perm_configuracion" value="1" id="permCfg" <?= !empty($selCfg['configuracion']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfg">Configuraci&oacute;n</label>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 permission-grid-section">
            <label class="form-label mb-1">Permisos de configuraci&oacute;n</label>
            <div class="row g-2">
              <?php foreach ([
                'resumen' => 'Resumen', 'categorias' => 'Categorías',
                'mantencion' => 'Mantención', 'nextcloud' => 'Nextcloud',
              ] as $cfgKey => $cfgLabel): ?>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_<?= $h($cfgKey) ?>" id="permCfg<?= $h(ucfirst($cfgKey)) ?>" <?= !empty($selCfg['cfg_' . $cfgKey]) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfg<?= $h(ucfirst($cfgKey)) ?>"><?= $h($cfgLabel) ?></label>
                </div>
              </div>
              <?php endforeach; ?>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_conexion" id="permCfgConexion" <?= !empty($selCfg['cfg_conexion']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgConexion">Conexi&oacute;n</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_proyecto" id="permCfgProyecto" <?= !empty($selCfg['cfg_proyecto']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgProyecto">Proyecto</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_retencion" id="permCfgRetencion" <?= !empty($selCfg['cfg_retencion']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgRetencion">Retenci&oacute;n</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_trackers" id="permCfgTrackers" <?= !empty($selCfg['cfg_trackers']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgTrackers">Trackers</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_prioridades" id="permCfgPrioridades" <?= !empty($selCfg['cfg_prioridades']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgPrioridades">Prioridades</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_estados" id="permCfgEstados" <?= !empty($selCfg['cfg_estados']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgEstados">Estados</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_roles" id="permCfgRoles" <?= !empty($selCfg['cfg_roles']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgRoles">Roles y permisos</label>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="perm_cfg_usuarios" id="permCfgUsuarios" <?= !empty($selCfg['cfg_usuarios']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="permCfgUsuarios">Usuarios y permisos</label>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 permission-form-actions">
            <button class="btn-nova btn-nova-secondary" type="button" data-permission-cancel hidden>
              <i class="bi bi-x-lg"></i>Cancelar
            </button>
            <button class="btn-nova btn-nova-primary" type="submit" id="btn-save-roles">Guardar permisos</button>
         
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>
<?php endif; ?>

    </main>
  </div>

</div>
</div><!-- #page-content -->

<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const configMaintenanceMode = <?= $maintenanceMode ? 'true' : 'false' ?>;
  if (configMaintenanceMode) {
    document.querySelectorAll('form').forEach(form => {
      const actionInput = form.querySelector('[name="action"]');
      const action = actionInput ? actionInput.value : '';
      const allowed = form.closest('#maintenanceModal') && action === 'maintenance_settings';
      if (!allowed) {
        form.querySelectorAll('input, select, textarea, button').forEach(control => {
          const isModalClose = control.matches('[data-bs-dismiss="modal"]');
          const isSearchField = control.matches('#user-search, #cat-filter');
          const isLoadOnlyAction = action === 'load_role' || action === 'load_user_perms';
          if (isModalClose || isSearchField || isLoadOnlyAction) {
            return;
          }
          control.disabled = true;
          control.title = 'Plataforma en mantención';
        });
      }
    });
  }
  const maintenanceModeCheck = document.getElementById('maintenance-mode-check');
  const maintenanceUntilInput = document.querySelector('input[name="maintenance_until"]');
  if (maintenanceModeCheck && maintenanceUntilInput) {
    maintenanceModeCheck.addEventListener('change', () => {
      if (!maintenanceModeCheck.checked) {
        maintenanceUntilInput.value = '';
      }
    });
  }
  const getModal = (id) => {
    const el = document.getElementById(id);
    if (!el || !window.bootstrap || !window.bootstrap.Modal) return null;
    return window.bootstrap.Modal.getOrCreateInstance(el);
  };
  const rolesEl = document.getElementById('rolesModal');
  const usuariosEl = document.getElementById('usuariosModal');
  const rolesModal = getModal('rolesModal');
  const usuariosModal = getModal('usuariosModal');
  // Filtros en tablas de categorias
  const catFilter = document.getElementById('cat-filter');
  const catTable = document.getElementById('cat-table');
  if (catFilter && catTable) {
    catFilter.addEventListener('input', () => {
      const term = catFilter.value.toLowerCase();
      catTable.querySelectorAll('tbody tr').forEach(tr => {
        tr.style.display = tr.innerText.toLowerCase().includes(term) ? '' : 'none';
      });
    });
  }
  // Mitigar warning de aria-hidden al cerrar: limpiar foco antes de ocultar
  [rolesEl, usuariosEl].forEach(el => {
    if (!el || !window.bootstrap || !window.bootstrap.Modal) return;
    el.addEventListener('hide.bs.modal', () => {
      if (document.activeElement) document.activeElement.blur();
    });
  });

  // Auto abrir si corresponde
  <?php if (!empty($openRolesModal) && $openRolesModal): ?>
    // Panel inline: roles queda activo desde PHP.
  <?php endif; ?>
  <?php if (!empty($openUsersModal) && $openUsersModal): ?>
    // Panel inline: usuarios queda activo desde PHP.
  <?php endif; ?>

  // Recarga en cambios de selects
  const selRole = document.getElementById('role-select');
  const actRole = document.getElementById('roles-action');
  const formRole = document.querySelector('#rolesModal form');
  const depInputs = ['permCat'].map(id => document.getElementById(id));
  const cfgConexion = document.getElementById('permCfg');
  const bindPermissionDependency = (parentId, childId) => {
    const parent = document.getElementById(parentId);
    const child = document.getElementById(childId);
    if (!parent || !child) return;
    const sync = () => {
      if (!parent.checked) {
        child.checked = false;
      }
      child.disabled = child.hasAttribute('data-permission-locked') || !parent.checked;
      const wrapper = child.closest('.permission-child');
      if (wrapper) {
        wrapper.classList.toggle('d-none', !parent.checked);
      }
    };
    parent.addEventListener('change', sync);
    sync();
  };
  bindPermissionDependency('permHist', 'permHistAcc');
  bindPermissionDependency('u_perm_historico_chk', 'u_perm_historico_acciones_chk');
  bindPermissionDependency('permActividad', 'permActividadEliminar');
  bindPermissionDependency('permActividad', 'permActividadTodos');
  bindPermissionDependency('u_perm_actividad_chk', 'u_perm_actividad_eliminar_chk');
  bindPermissionDependency('u_perm_actividad_chk', 'u_perm_actividad_todos_chk');
  const bindConfigPermissionGroup = (parentId, childIds) => {
    const parent = document.getElementById(parentId);
    const children = childIds.map(id => document.getElementById(id)).filter(Boolean);
    if (!parent || children.length === 0) return;
    const sync = () => {
      children.forEach(child => {
        if (!parent.checked) child.checked = false;
        child.disabled = !parent.checked;
      });
    };
    parent.addEventListener('change', sync);
    sync();
  };
  bindConfigPermissionGroup('permCfg', [
    'permCfgConexion', 'permCfgProyecto', 'permCfgRetencion', 'permCfgSesion',
    'permCfgTrackers', 'permCfgPrioridades', 'permCfgEstados', 'permCfgRoles', 'permCfgUsuarios'
  ]);
  bindConfigPermissionGroup('u_perm_configuracion_chk', [
    'u_perm_cfg_conexion', 'u_perm_cfg_proyecto', 'u_perm_cfg_retencion',
    'u_perm_cfg_trackers', 'u_perm_cfg_prioridades', 'u_perm_cfg_estados', 'u_perm_cfg_roles', 'u_perm_cfg_usuarios',
    'u_perm_cfg_resumen', 'u_perm_cfg_categorias', 'u_perm_cfg_mantencion', 'u_perm_cfg_nextcloud'
  ]);
  document.querySelectorAll('#rolesModal form.roles-modal-shell, #usuariosModal form.roles-modal-shell').forEach(form => {
    const cancelButton = form.querySelector('[data-permission-cancel]');
    if (!cancelButton) return;
    const state = () => {
      const data = new FormData(form);
      data.delete('csrf_token');
      data.delete('action');
      return new URLSearchParams(data).toString();
    };
    const initialState = state();
    const syncDirty = () => {
      cancelButton.hidden = state() === initialState;
    };
    form.addEventListener('input', syncDirty);
    form.addEventListener('change', syncDirty);
    cancelButton.addEventListener('click', () => {
      form.reset();
      ['permHist', 'u_perm_historico_chk', 'permActividad', 'u_perm_actividad_chk', 'permCfg', 'u_perm_configuracion_chk'].forEach(id => {
        document.getElementById(id)?.dispatchEvent(new Event('change', { bubbles: true }));
      });
      cancelButton.hidden = true;
    });
  });
  if (selRole && actRole && formRole) {
    selRole.addEventListener('change', () => {
      actRole.value = 'load_role';
      formRole.submit();
    });
    const btnSaveRoles = document.getElementById('btn-save-roles');
    if (btnSaveRoles) {
      btnSaveRoles.addEventListener('click', () => {
        actRole.value = 'save_roles';
      });
    }
    if (rolesEl) {
      rolesEl.addEventListener('shown.bs.modal', () => {
        actRole.value = 'save_roles';
      });
    }
  }
  const ensureCfgConexion = () => {
    if (!cfgConexion) return;
    const configAccess = document.getElementById('permCfg');
    if (configAccess && !configAccess.checked) {
      cfgConexion.checked = false;
      return;
    }
    const anyCatUni = depInputs.some(el => el && el.checked);
    if (anyCatUni) cfgConexion.checked = true;
  };
  depInputs.forEach(el => { if (el) el.addEventListener('change', ensureCfgConexion); });
  ensureCfgConexion();
  // Si viene mensaje de sincronizacion de categorias: mantener el panel visible
  const params = new URLSearchParams(window.location.search);
  if (params.has('synccat')) {
    const msg = decodeURIComponent(params.get('synccat'));
    if (msg) window.NovaToast?.success(msg, 'Sincronización');
    params.set('panel', 'categorias');
    params.delete('synccat');
    const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    window.history.replaceState({}, '', newUrl);
  }
  const selUser = document.getElementById('user-select');
  const userSearch = document.getElementById('user-search');
  const userResults = document.getElementById('user-search-results');
  const actUser = document.getElementById('user-action');
  const formUser = document.querySelector('#usuariosModal form');
  const usersForPicker = [
    <?php if ($canManageUsers): ?>
      <?php foreach ($usersList as $uid => $uname): ?>
        { id: <?= json_encode((string)$uid, JSON_UNESCAPED_UNICODE) ?>, label: <?= json_encode((string)$uname, JSON_UNESCAPED_UNICODE) ?> },
      <?php endforeach; ?>
    <?php endif; ?>
  ];
  const normalizePickerText = (value) => String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase();
  const hideUserResults = () => {
    if (userResults) userResults.classList.add('d-none');
  };
  const renderUserResults = () => {
    if (!userSearch || !userResults) return;
    const term = normalizePickerText(userSearch.value.trim());
    userResults.innerHTML = '';
    if (term.length === 0) {
      hideUserResults();
      return;
    }
    const matches = usersForPicker
      .filter(user => normalizePickerText(user.label).includes(term) || normalizePickerText(user.id).includes(term))
      .slice(0, 20);
    if (matches.length === 0) {
      userResults.innerHTML = '<div class="user-picker-empty">Sin usuarios encontrados</div>';
      userResults.classList.remove('d-none');
      return;
    }
    matches.forEach(user => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'user-picker-option';
      btn.innerHTML = `<span>${user.label}</span><span class="user-picker-id">ID ${user.id}</span>`;
      btn.addEventListener('click', () => {
        selUser.value = user.id;
        userSearch.value = user.label;
        hideUserResults();
        if (actUser && formUser) {
          actUser.value = 'load_user_perms';
          formUser.submit();
        }
      });
      userResults.appendChild(btn);
    });
    userResults.classList.remove('d-none');
  };
  if (userSearch && userResults && selUser) {
    userSearch.addEventListener('input', () => {
      selUser.value = '';
      renderUserResults();
    });
    userSearch.addEventListener('focus', renderUserResults);
    document.addEventListener('click', (event) => {
      if (!event.target.closest('#user-picker')) {
        hideUserResults();
      }
    });
  }
  if (selUser && actUser && formUser) {
    formUser.addEventListener('submit', () => {
      actUser.value = 'save_user_perms';
    });
  }
});
</script>
</body>
</html>
