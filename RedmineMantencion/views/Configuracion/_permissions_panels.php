<?php
$mantencionPermissionRows = [
  ['key' => 'mensajes_acceso', 'input' => 'mensajes', 'label' => 'Reportes', 'icon' => 'bi-inboxes', 'scope' => 'mensajes', 'scope_input' => 'mensajes', 'children' => [
    ['key' => 'reportes_editar', 'input' => 'reportes_editar', 'label' => 'Editar'],
    ['key' => 'reportes_eliminar', 'input' => 'reportes_eliminar', 'label' => 'Eliminar'],
    ['key' => 'reportes_importar_core', 'input' => 'reportes_importar_core', 'label' => 'Importar desde CORE'],
  ]],
  ['key' => 'horas_extra', 'input' => 'horas_extra', 'label' => 'Horas extra', 'icon' => 'bi-clock-history', 'scope' => 'horas_extra', 'scope_input' => 'horas', 'children' => [
    ['key' => 'horas_extra_editar', 'input' => 'horas_extra_editar', 'label' => 'Editar'],
  ]],
  ['key' => 'historico', 'input' => 'historico', 'label' => 'Histórico', 'icon' => 'bi-archive', 'scope' => 'historico_scope', 'scope_input' => 'historico', 'children' => [
    ['key' => 'historico_estado', 'input' => 'historico_estado', 'label' => 'Cambiar estado'],
    ['key' => 'historico_eliminar', 'input' => 'historico_eliminar', 'label' => 'Eliminar'],
  ]],
  ['key' => 'estadisticas', 'input' => 'estadisticas', 'label' => 'Estadísticas', 'icon' => 'bi-bar-chart-line'],
  ['key' => 'usuarios', 'input' => 'usuarios', 'label' => 'Usuarios', 'icon' => 'bi-people'],
  ['key' => 'categorias', 'input' => 'categorias', 'label' => 'Categorías', 'icon' => 'bi-tags'],
  ['key' => 'simulador', 'input' => 'simulador', 'label' => 'Pendiente manual', 'icon' => 'bi-pencil-square'],
  ['key' => 'actividad', 'input' => 'actividad', 'label' => 'Bitácora de actividad', 'icon' => 'bi-activity', 'children' => [
    ['key' => 'actividad_todos', 'input' => 'actividad_todos', 'label' => 'Ver todos'],
    ['key' => 'actividad_eliminar', 'input' => 'actividad_eliminar', 'label' => 'Eliminar'],
  ]],
  ['key' => 'mis_integraciones', 'input' => 'mis_integraciones', 'label' => 'Mis integraciones', 'icon' => 'bi-person-lock'],
  ['key' => 'integraciones_nextcloud', 'input' => 'integraciones_nextcloud', 'label' => 'Administrar Nextcloud', 'icon' => 'bi-cloud'],
  ['key' => 'configuracion', 'input' => 'configuracion', 'label' => 'Configuración', 'icon' => 'bi-sliders'],
];
$mantencionConfigPermissions = [
  'cfg_resumen' => 'Resumen',
  'cfg_conexion' => 'Conexión',
  'cfg_proyecto' => 'Proyecto',
  'cfg_retencion' => 'Retención',
  'cfg_trackers' => 'Trackers',
  'cfg_prioridades' => 'Prioridades',
  'cfg_estados' => 'Estados',
  'cfg_categorias' => 'Categorías',
  'cfg_mantencion' => 'Mantención',
  'cfg_nextcloud' => 'Nextcloud',
  'cfg_roles' => 'Roles y permisos',
  'cfg_usuarios' => 'Usuarios y permisos',
];
$mantencionPermissionGroups = [
  [
    'label' => 'Operación diaria',
    'description' => 'Reportes, horas extra e histórico.',
    'icon' => 'bi-briefcase',
    'rows' => array_slice($mantencionPermissionRows, 0, 3),
  ],
  [
    'label' => 'Gestión y administración',
    'description' => 'Herramientas, usuarios e integraciones.',
    'icon' => 'bi-grid',
    'rows' => array_slice($mantencionPermissionRows, 3),
  ],
];
$mantencionPermissionEnabled = static function (array $permissions, string $key): bool {
  if ($key === 'horas_extra') {
    return in_array($permissions[$key] ?? '', ['todos', 'asignados'], true);
  }
  return !empty($permissions[$key]);
};
$mantencionActivePermissionCount = static function (array $permissions) use (
  $mantencionPermissionRows,
  $mantencionConfigPermissions,
  $mantencionPermissionEnabled
): int {
  $count = 0;
  foreach ($mantencionPermissionRows as $row) {
    if ($mantencionPermissionEnabled($permissions, $row['key'])) $count++;
    foreach ($row['children'] ?? [] as $child) {
      if (!empty($permissions[$child['key']])) $count++;
    }
  }
  foreach (array_keys($mantencionConfigPermissions) as $key) {
    if (!empty($permissions[$key])) $count++;
  }
  return $count;
};
$mantencionInitials = static function (array $user): string {
  $first = mb_substr(trim((string)($user['nombre'] ?? 'U')), 0, 1);
  $last = mb_substr(trim((string)($user['apellido'] ?? '')), 0, 1);
  return strtoupper($first . $last) ?: 'U';
};
$renderMantencionPermissionGroups = static function (
  array $permissions,
  string $prefix
) use (
  $h,
  $isNovaRoot,
  $mantencionPermissionGroups,
  $mantencionConfigPermissions,
  $mantencionPermissionEnabled
): void {
  $kind = $prefix === '' ? 'role' : 'user';
  ?>
  <div class="rm-permission-groups">
    <?php foreach ($mantencionPermissionGroups as $group): ?>
      <?php
        $groupActive = 0;
        foreach ($group['rows'] as $row) {
          if ($mantencionPermissionEnabled($permissions, $row['key'])) $groupActive++;
        }
      ?>
      <details class="rm-permission-group" open>
        <summary>
          <span class="rm-permission-group-icon"><i class="bi <?= $h($group['icon']) ?>"></i></span>
          <span class="rm-permission-group-copy">
            <strong><?= $h($group['label']) ?></strong>
            <small><?= $h($group['description']) ?></small>
          </span>
          <span class="rm-permission-group-count" data-permission-group-count><?= $groupActive ?>/<?= count($group['rows']) ?></span>
          <i class="bi bi-chevron-down rm-permission-group-chevron"></i>
        </summary>
        <div class="rm-role-permission-list">
          <?php foreach ($group['rows'] as $row): ?>
            <?php
              $accessName = $prefix . 'perm_' . $row['input'];
              $accessId = 'mantencion-' . $kind . '-' . str_replace('_', '-', $row['input']);
              $enabled = $mantencionPermissionEnabled($permissions, $row['key']);
              $isConfiguration = $row['key'] === 'configuracion';
            ?>
            <section class="rm-role-permission-item <?= $enabled ? 'is-enabled' : '' ?>" data-permission-card>
              <div class="rm-role-permission-main">
                <strong><i class="bi <?= $h($row['icon']) ?>"></i><?= $h($row['label']) ?></strong>
                <label class="rm-toggle-line" for="<?= $h($accessId) ?>">
                  <span>Ver</span>
                  <input
                    class="rm-switch"
                    type="checkbox"
                    id="<?= $h($accessId) ?>"
                    name="<?= $h($accessName) ?>"
                    value="1"
                    data-access-toggle
                    <?= $isConfiguration ? 'data-config-access-toggle' : '' ?>
                    <?= $enabled ? 'checked' : '' ?>
                  >
                </label>
              </div>

              <?php if (!empty($row['scope']) && $isNovaRoot): ?>
                <?php
                  $scopeName = $prefix . $row['scope_input'] . '_scope';
                  $scopeValue = (string)($permissions[$row['scope']] ?? 'asignados');
                ?>
                <label class="rm-permission-scope-inline" data-dependent-actions>
                  <span><i class="bi bi-diagram-3"></i>Alcance</span>
                  <select class="form-select" name="<?= $h($scopeName) ?>" data-scope-select>
                    <option value="todos" <?= $scopeValue === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="asignados" <?= $scopeValue !== 'todos' ? 'selected' : '' ?>>Solo asignados</option>
                  </select>
                </label>
              <?php endif; ?>

              <?php if (!empty($row['children'])): ?>
                <div class="rm-role-permission-children" data-dependent-actions>
                  <?php foreach ($row['children'] as $child): ?>
                    <?php
                      $childName = $prefix . 'perm_' . $child['input'];
                      $childId = 'mantencion-' . $kind . '-' . str_replace('_', '-', $child['input']);
                    ?>
                    <label class="rm-toggle-line rm-role-permission-child" for="<?= $h($childId) ?>">
                      <span><?= $h($child['label']) ?></span>
                      <input class="rm-switch" type="checkbox" id="<?= $h($childId) ?>" name="<?= $h($childName) ?>" value="1" <?= !empty($permissions[$child['key']]) ? 'checked' : '' ?>>
                    </label>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </section>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endforeach; ?>

    <?php
      $configActive = 0;
      foreach (array_keys($mantencionConfigPermissions) as $key) {
        if (!empty($permissions[$key])) $configActive++;
      }
    ?>
    <details class="rm-permission-group is-config" data-config-dependent-panel open>
      <summary>
        <span class="rm-permission-group-icon"><i class="bi bi-sliders"></i></span>
        <span class="rm-permission-group-copy">
          <strong>Secciones de configuración</strong>
          <small>Define qué apartados puede administrar.</small>
        </span>
        <span class="rm-permission-group-count" data-config-group-count><?= $configActive ?>/<?= count($mantencionConfigPermissions) ?></span>
        <i class="bi bi-chevron-down rm-permission-group-chevron"></i>
      </summary>
      <div class="rm-permission-grid">
        <?php foreach ($mantencionConfigPermissions as $permissionKey => $permissionLabel): ?>
          <?php $configId = 'mantencion-' . $kind . '-' . str_replace('_', '-', $permissionKey); ?>
          <label class="rm-toggle-line rm-config-permission-switch" for="<?= $h($configId) ?>">
            <span><?= $h($permissionLabel) ?></span>
            <input class="rm-switch" type="checkbox" id="<?= $h($configId) ?>" name="<?= $h($prefix . 'perm_' . $permissionKey) ?>" value="1" <?= !empty($permissions[$permissionKey]) ? 'checked' : '' ?>>
          </label>
        <?php endforeach; ?>
      </div>
    </details>
  </div>
  <?php
};

$rolesList = array_keys($rolesData);
sort($rolesList, SORT_NATURAL | SORT_FLAG_CASE);
$selCfg = is_array($rolesData[$selectedRole] ?? null) ? $rolesData[$selectedRole] : [];
if (!array_key_exists('mensajes_acceso', $selCfg)) $selCfg['mensajes_acceso'] = true;
$selectedRoleAssignedUsers = count(array_filter($usuariosData, static fn($user): bool =>
  is_array($user) && strtolower(trim((string)($user['rol'] ?? ''))) === strtolower($selectedRole)
));
$selectedRoleIsBase = in_array(strtolower($selectedRole), $baseRoles, true);
$selectedRoleInitials = strtoupper(mb_substr($selectedRole ?: 'R', 0, 2));

$selUserData = $selectedUser !== '' && isset($usuariosIndex[$selectedUser]) ? $usuariosIndex[$selectedUser] : null;
$selUserRole = (string)($selUserData['rol'] ?? 'usuario');
$selUserPerms = is_array($selUserData['permisos'] ?? null) ? $selUserData['permisos'] : [];
$roleDefaults = is_array($rolesData[$selUserRole] ?? null) ? $rolesData[$selUserRole] : [];
$uCfg = array_replace($roleDefaults, $selUserPerms);
$selectedUserName = $selUserData
  ? trim((string)($selUserData['nombre'] ?? '') . ' ' . (string)($selUserData['apellido'] ?? ''))
  : '';
if ($selectedUserName === '' && $selUserData) $selectedUserName = (string)($selUserData['id'] ?? 'Usuario');
?>

<?php if ($canManageUsers && $activeConfigPanel === 'usuarios'): ?>
  <section class="rm-config-feature-form rm-permissions-page" id="usuariosModal">
    <div class="rm-feature-head rm-user-permission-feature-head">
      <span class="rm-feature-head-icon is-orange"><i class="bi bi-person-lock"></i></span>
      <div class="rm-feature-selection-copy">
        <small>Permisos por usuario</small>
        <h2>Usuarios y permisos</h2>
        <?php if ($selUserData): ?>
          <div class="rm-feature-selection-identity">
            <span class="rm-selected-user-avatar is-small"><?= $h($mantencionInitials($selUserData)) ?></span>
            <div>
              <strong><?= $h($selectedUserName) ?></strong>
              <p>Redmine ID <?= $h($selectedUser) ?> · Rol actual: <?= $h($selUserRole) ?></p>
            </div>
          </div>
        <?php else: ?>
          <p>Selecciona un usuario activo para administrar sus permisos.</p>
        <?php endif; ?>
      </div>
      <div class="rm-feature-meter">
        <strong><?= count($usuariosSelectableData) ?></strong>
        <span>usuarios activos</span>
      </div>
    </div>

    <?php if ($selUserData): ?>
      <div class="rm-permissions-layout">
        <aside class="rm-permissions-list-panel">
          <div class="rm-permissions-list-head">
            <div>
              <h3>Seleccionar usuario</h3>
              <p><?= count($usuariosSelectableData) ?> usuario(s) activo(s)</p>
            </div>
            <span><strong data-active-permission-count><?= $mantencionActivePermissionCount($uCfg) ?></strong>&nbsp;permisos</span>
          </div>
          <form method="get" action="<?= $h($configBaseUrl) ?>" class="rm-user-combobox rm-picker-combobox" data-navigation-picker data-editor-target="mantencion-user-permissions-form">
            <input type="hidden" name="panel" value="usuarios">
            <input type="hidden" name="user_id" value="<?= $h($selectedUser) ?>" data-picker-value>
            <div class="rm-picker-combobox-control">
              <label>
                <i class="bi bi-search"></i>
                <input class="form-control" type="search" value="<?= $h($selectedUserName . ' · ' . $selUserRole . ' · ID ' . $selectedUser) ?>" placeholder="Buscar usuario activo" role="combobox" aria-autocomplete="list" aria-expanded="false" autocomplete="off" data-picker-search>
              </label>
              <button type="button" class="rm-picker-combobox-toggle" aria-label="Mostrar usuarios activos" aria-expanded="false" data-picker-toggle><i class="bi bi-chevron-down"></i></button>
            </div>
            <div class="rm-picker-combobox-menu is-users" role="listbox" data-picker-menu hidden>
              <?php foreach ($usuariosSelectableData as $userOption): ?>
                <?php
                  $userOptionId = (string)($userOption['id'] ?? '');
                  $userOptionName = trim((string)($userOption['nombre'] ?? '') . ' ' . (string)($userOption['apellido'] ?? '')) ?: $userOptionId;
                  $userOptionRole = (string)($userOption['rol'] ?? 'usuario');
                  $userOptionLabel = $userOptionName . ' · ' . $userOptionRole . ' · ID ' . $userOptionId;
                ?>
                <button type="button" class="rm-picker-combobox-option is-user <?= $selectedUser === $userOptionId ? 'is-selected' : '' ?>" role="option" aria-selected="<?= $selectedUser === $userOptionId ? 'true' : 'false' ?>" data-picker-option data-value="<?= $h($userOptionId) ?>" data-label="<?= $h($userOptionLabel) ?>" data-search="<?= $h($userOptionName . ' ' . $userOptionRole . ' ' . $userOptionId) ?>">
                  <span class="rm-picker-user-avatar"><?= $h($mantencionInitials($userOption)) ?></span>
                  <span class="rm-picker-user-copy"><strong><?= $h($userOptionName) ?></strong><small><?= $h(ucfirst($userOptionRole)) ?> · ID <?= $h($userOptionId) ?></small></span>
                  <i class="bi bi-check2 rm-picker-combobox-option-check"></i>
                </button>
              <?php endforeach; ?>
              <div class="rm-picker-combobox-empty" data-picker-empty hidden>No se encontraron usuarios activos.</div>
            </div>
          </form>
        </aside>

        <div class="rm-permissions-editor-panel">
          <form method="post" action="<?= $h($configBaseUrl) ?>" id="mantencion-user-permissions-form" class="rm-permissions-inline-form" data-permission-editor-form data-permission-kind="user">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="save_user_perms">
            <input type="hidden" name="user_select" value="<?= $h($selectedUser) ?>">

            <section class="rm-role-template-bar">
              <div>
                <small>Plantilla de acceso</small>
                <strong>Rol asignado</strong>
                <p>Cambia el rol sin reemplazar los ajustes personalizados.</p>
              </div>
              <div class="rm-inline-field">
                <span>Rol</span>
                <div class="rm-user-combobox rm-picker-combobox" data-value-picker>
                  <input type="hidden" name="u_role" value="<?= $h($selUserRole) ?>" data-picker-value>
                  <div class="rm-picker-combobox-control">
                    <label>
                      <i class="bi bi-search"></i>
                      <input class="form-control" type="search" value="<?= $h(ucfirst($selUserRole)) ?>" placeholder="Seleccionar rol" role="combobox" aria-autocomplete="list" aria-expanded="false" autocomplete="off" data-picker-search>
                    </label>
                    <button type="button" class="rm-picker-combobox-toggle" aria-label="Mostrar roles" aria-expanded="false" data-picker-toggle><i class="bi bi-chevron-down"></i></button>
                  </div>
                  <div class="rm-picker-combobox-menu" role="listbox" data-picker-menu hidden>
                    <?php foreach ($rolesList as $roleOption): ?>
                      <button type="button" class="rm-picker-combobox-option <?= $selUserRole === $roleOption ? 'is-selected' : '' ?>" role="option" aria-selected="<?= $selUserRole === $roleOption ? 'true' : 'false' ?>" data-picker-option data-value="<?= $h($roleOption) ?>" data-label="<?= $h(ucfirst($roleOption)) ?>" data-search="<?= $h($roleOption) ?>">
                        <span class="rm-picker-combobox-option-icon"><i class="bi bi-shield"></i></span>
                        <span><?= $h(ucfirst($roleOption)) ?></span>
                        <i class="bi bi-check2 rm-picker-combobox-option-check"></i>
                      </button>
                    <?php endforeach; ?>
                    <div class="rm-picker-combobox-empty" data-picker-empty hidden>No se encontraron roles.</div>
                  </div>
                </div>
              </div>
              <button class="btn-nova btn-nova-info" type="button" data-apply-role-template><i class="bi bi-stars"></i>Aplicar plantilla</button>
            </section>

            <?php $renderMantencionPermissionGroups($uCfg, 'u_'); ?>
          </form>

          <div class="rm-permission-savebar" data-permission-savebar>
            <div class="rm-permission-save-state" aria-live="polite">
              <span><i class="bi bi-check2"></i></span>
              <div>
                <strong data-permission-state-title>Todo guardado</strong>
                <small data-permission-state-copy>No hay cambios pendientes.</small>
              </div>
            </div>
            <button class="btn-nova btn-nova-secondary" type="button" data-permission-reset disabled><i class="bi bi-arrow-counterclockwise"></i><span>Descartar cambios</span></button>
            <button class="btn-nova btn-nova-primary" type="submit" form="mantencion-user-permissions-form" data-permission-save disabled><span class="btn-nova-icon"><i class="bi bi-save"></i></span><span>Guardar cambios</span></button>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="nova-empty-state">Sin usuarios activos registrados.</div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($canManageRoles && $activeConfigPanel === 'roles'): ?>
  <section class="rm-config-feature-form rm-permissions-page" id="rolesModal">
    <div class="rm-feature-head rm-role-permission-feature-head">
      <span class="rm-feature-head-icon is-green"><i class="bi bi-shield-check"></i></span>
      <div class="rm-feature-selection-copy">
        <small>Matriz de acceso</small>
        <h2>Roles y permisos</h2>
        <div class="rm-feature-selection-identity">
          <span class="rm-selected-user-avatar is-small"><?= $h($selectedRoleInitials) ?></span>
          <div>
            <strong><?= $h(ucfirst($selectedRole)) ?></strong>
            <p>Activa vistas y acciones disponibles para este rol.</p>
          </div>
        </div>
      </div>
      <div class="rm-feature-meter">
        <strong><?= count($rolesList) ?></strong>
        <span>roles</span>
      </div>
    </div>

    <div class="rm-permissions-layout">
      <aside class="rm-permissions-list-panel">
        <div class="rm-permissions-list-head">
          <div>
            <h3>Seleccionar rol</h3>
            <p><?= count($rolesList) ?> rol(es) configurado(s)</p>
          </div>
          <span><strong data-active-permission-count><?= $mantencionActivePermissionCount($selCfg) ?></strong>&nbsp;permisos</span>
        </div>
        <form method="get" action="<?= $h($configBaseUrl) ?>" class="rm-user-combobox rm-picker-combobox" data-navigation-picker data-editor-target="mantencion-role-permissions-form">
          <input type="hidden" name="panel" value="roles">
          <input type="hidden" name="role" value="<?= $h($selectedRole) ?>" data-picker-value>
          <div class="rm-picker-combobox-control">
            <label>
              <i class="bi bi-search"></i>
              <input class="form-control" type="search" value="<?= $h(ucfirst($selectedRole)) ?>" placeholder="Buscar y seleccionar rol" role="combobox" aria-autocomplete="list" aria-expanded="false" autocomplete="off" data-picker-search>
            </label>
            <button type="button" class="rm-picker-combobox-toggle" aria-label="Mostrar roles" aria-expanded="false" data-picker-toggle><i class="bi bi-chevron-down"></i></button>
          </div>
          <div class="rm-picker-combobox-menu" role="listbox" data-picker-menu hidden>
            <?php foreach ($rolesList as $roleOption): ?>
              <button type="button" class="rm-picker-combobox-option <?= $selectedRole === $roleOption ? 'is-selected' : '' ?>" role="option" aria-selected="<?= $selectedRole === $roleOption ? 'true' : 'false' ?>" data-picker-option data-value="<?= $h($roleOption) ?>" data-label="<?= $h(ucfirst($roleOption)) ?>" data-search="<?= $h($roleOption) ?>">
                <span class="rm-picker-combobox-option-icon"><i class="bi bi-shield"></i></span>
                <span><?= $h(ucfirst($roleOption)) ?></span>
                <i class="bi bi-check2 rm-picker-combobox-option-check"></i>
              </button>
            <?php endforeach; ?>
            <div class="rm-picker-combobox-empty" data-picker-empty hidden>No se encontraron roles.</div>
          </div>
        </form>

        <details class="rm-create-role-disclosure">
          <summary><span><i class="bi bi-plus-circle"></i>Crear un rol</span><i class="bi bi-chevron-down"></i></summary>
          <form class="rm-create-role-form" method="post" action="<?= $h($configBaseUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="save_roles">
            <input type="hidden" name="role_select" value="<?= $h($selectedRole) ?>">
            <label class="rm-inline-field">
              <span>Nombre del nuevo rol</span>
              <input class="form-control" name="new_role" placeholder="Ej. supervisor" pattern="[A-Za-z0-9_-]{2,40}" maxlength="40" autocomplete="off" required>
            </label>
            <button class="btn-nova btn-nova-success" type="submit"><i class="bi bi-plus-lg"></i><span>Crear rol</span></button>
          </form>
        </details>

        <?php if ($selectedRoleIsBase): ?>
          <div class="rm-role-delete-state is-protected">
            <i class="bi bi-lock-fill"></i>
            <span><strong>Rol base protegido</strong><small><?= $h(ucfirst($selectedRole)) ?> es necesario para el funcionamiento del módulo.</small></span>
          </div>
        <?php else: ?>
          <form class="rm-role-delete-form" method="post" action="<?= $h($configBaseUrl) ?>" data-app-confirm="¿Eliminar el rol <?= $h(ucfirst($selectedRole)) ?>? Se eliminarán sus permisos y la acción no se puede deshacer." data-app-confirm-title="Eliminar rol" data-app-confirm-text="Eliminar rol" data-app-confirm-tone="danger">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="delete_role">
            <input type="hidden" name="role_select" value="<?= $h($selectedRole) ?>">
            <button class="btn-nova btn-nova-danger" type="submit" <?= $selectedRoleAssignedUsers > 0 ? 'disabled' : '' ?>><i class="bi bi-trash3"></i><span>Eliminar rol</span></button>
            <small><?= $selectedRoleAssignedUsers > 0 ? 'Asignado a ' . $selectedRoleAssignedUsers . ' usuario(s). Reasígnalos antes de eliminar.' : 'Elimina este rol personalizado de forma permanente.' ?></small>
          </form>
        <?php endif; ?>
      </aside>

      <div class="rm-permissions-editor-panel">
        <form method="post" action="<?= $h($configBaseUrl) ?>" id="mantencion-role-permissions-form" class="rm-permissions-inline-form" data-permission-editor-form data-permission-kind="role">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" value="save_roles">
          <input type="hidden" name="role_select" value="<?= $h($selectedRole) ?>">
          <?php $renderMantencionPermissionGroups($selCfg, ''); ?>
        </form>

        <div class="rm-permission-savebar" data-permission-savebar>
          <div class="rm-permission-save-state" aria-live="polite">
            <span><i class="bi bi-check2"></i></span>
            <div>
              <strong data-permission-state-title>Todo guardado</strong>
              <small data-permission-state-copy>No hay cambios pendientes.</small>
            </div>
          </div>
          <button class="btn-nova btn-nova-secondary" type="button" data-permission-reset disabled><i class="bi bi-arrow-counterclockwise"></i><span>Descartar cambios</span></button>
          <button class="btn-nova btn-nova-primary" type="submit" form="mantencion-role-permissions-form" data-permission-save disabled><span class="btn-nova-icon"><i class="bi bi-save"></i></span><span>Guardar cambios</span></button>
        </div>
      </div>
    </div>
  </section>
<?php endif; ?>
