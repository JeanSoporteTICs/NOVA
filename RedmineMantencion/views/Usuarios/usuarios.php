<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine-mantencion/login.php');
if (!auth_can('usuarios')) {
  http_response_code(403);
  exit('No tienes permiso para ver Usuarios.');
}
require_once __DIR__ . '/../../controllers/usuarios.php';
[$usuarios, $flash, $importPreview] = handle_usuarios();
$usuariosActivos = count(array_filter($usuarios, static fn ($u): bool => strtolower(trim((string)($u['estado'] ?? 'activo'))) !== 'baneado'));
$usuariosBaneados = count(array_filter($usuarios, static fn ($u): bool => strtolower(trim((string)($u['estado'] ?? 'activo'))) === 'baneado'));
$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$csrf = legacy_csrf_token();
$maintenanceMode = function_exists('maintenance_mode_enabled') && maintenance_mode_enabled();
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Usuarios'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
  <?php $usuariosCssVersion = @filemtime(__DIR__ . '/../../assets/css/usuarios.css') ?: time(); ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/usuarios.css?v=<?= (int)$usuariosCssVersion ?>">
</head>
<body class="bg-light">
<?php $activeNav = 'usuarios'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
<div class="container-fluid py-4">
  <?php
    $heroIcon = 'bi-people';
    $heroTitle = 'Usuarios';
    $heroSubtitle = 'Gestion de usuarios e integraciones personales';
    $heroExtras = '';
    include __DIR__ . '/../partials/hero.php';
  ?>
  <?php if ($flash): ?><div data-nova-flash="success" data-nova-flash-message="<?= $h($flash) ?>" hidden></div><?php endif; ?>

  <div class="nova-user-summary-grid mb-3" id="user-status-filters">
    <section class="nova-user-summary-card is-enabled is-active" data-filter="activo" role="button" tabindex="0">
      <div class="nova-user-summary-icon"><i class="bi bi-person-check"></i></div>
      <div>
        <span>Usuarios activos</span>
        <strong><?= $usuariosActivos ?></strong>
      </div>
    </section>
    <section class="nova-user-summary-card is-banned" data-filter="baneado" role="button" tabindex="0">
      <div class="nova-user-summary-icon"><i class="bi bi-person-x"></i></div>
      <div>
        <span>Usuarios baneados</span>
        <strong><?= $usuariosBaneados ?></strong>
      </div>
    </section>
  </div>

  <div class="nova-table-card">
    <div class="nova-table-toolbar">
      <span class="nova-table-toolbar-title">Usuarios del proyecto</span>
      <div class="nova-table-search">
        <i class="bi bi-search"></i>
        <input id="user-search" type="search" placeholder="Buscar nombre, ID o rol" aria-label="Buscar usuario">
      </div>
      <span class="nova-user-meta ms-auto">Total: <?= count($usuarios) ?></span>
      <form method="post" class="m-0">
        <input type="hidden" name="action" value="preview_remote">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <button class="btn-nova btn-nova-info" type="submit" <?= $maintenanceMode ? 'disabled title="Plataforma en mantencion"' : '' ?>>
          <i class="bi bi-cloud-download"></i> Importar Redmine
        </button>
      </form>
      <button class="btn-nova btn-nova-primary" data-bs-toggle="modal" data-bs-target="#createUserModal" <?= $maintenanceMode ? 'disabled title="Plataforma en mantencion"' : '' ?>>
        <i class="bi bi-person-plus"></i> Nuevo usuario
      </button>
    </div>
    <div class="table-responsive">
      <table id="user-table" class="nova-user-table">
        <thead>
          <tr>
            <th>Usuario</th>
            <th>Rol</th>
            <th>Estado</th>
            <th>Credenciales</th>
            <th class="nova-col-hide-md">Ultimo ingreso</th>
            <th class="nova-col-actions">Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$usuarios): ?>
          <tr><td colspan="6" class="nova-table-empty"><i class="bi bi-people" style="font-size:1.4rem;display:block;margin-bottom:.4rem;opacity:.4"></i>No hay usuarios registrados.</td></tr>
        <?php endif; ?>
        <?php foreach ($usuarios as $u):
          $uEstado = strtolower(trim((string)($u['estado'] ?? 'activo')));
          $uNombre = trim((string)($u['nombre'] ?? '') . ' ' . (string)($u['apellido'] ?? ''));
          $uInitials = strtoupper(mb_substr((string)($u['nombre'] ?? 'U'), 0, 1) . mb_substr((string)($u['apellido'] ?? ''), 0, 1));
          $uRol = strtolower(trim((string)($u['rol'] ?? 'usuario')));
          $uLogin = trim((string)($u['ultimo_login_at'] ?? ''));
          $uLoginFmt = $uLogin !== '' ? date('d/m/Y H:i', strtotime($uLogin)) : '-';
          $hasApi = trim((string)($u['api'] ?? '')) !== '';
          $hasCore = !empty($u['has_core_credentials'])
              || (trim((string)($u['core_user'] ?? '')) !== '' && trim((string)($u['core_pass_enc'] ?? '')) !== '');
          $hasNc = !empty($u['has_nextcloud_credentials'])
              || (trim((string)($u['nextcloud_user'] ?? '')) !== '' && trim((string)($u['nextcloud_pass_enc'] ?? '')) !== '');
          $roleBadge = match ($uRol) {
              'root' => 'is-root',
              'administrador', 'admin' => 'is-admin',
              'gestor' => 'is-gestor',
              default => 'is-usuario',
          };
          $estadoBadge = $uEstado === 'baneado' ? 'is-baneado' : 'is-activo';
        ?>
          <tr data-user-status="<?= $h($uEstado === 'baneado' ? 'baneado' : 'activo') ?>">
            <td data-col="id nombre">
              <div class="nova-user-cell">
                <div class="nova-user-avatar"><?= $h($uInitials) ?></div>
                <div>
                  <div class="nova-user-name"><?= $h($uNombre) ?></div>
                  <div class="nova-user-meta"><?= $h($u['id'] ?? '') ?></div>
                </div>
              </div>
            </td>
            <td data-col="rol">
              <span class="nova-badge <?= $h($roleBadge) ?>"><?= $h($u['rol'] ?? 'usuario') ?></span>
            </td>
            <td data-col="estado">
              <span class="nova-badge <?= $h($estadoBadge) ?>"><?= $uEstado === 'baneado' ? 'Baneado' : 'Activo' ?></span>
            </td>
            <td data-col="api">
              <div class="nova-table-actions user-credentials-cell">
                <?php if ($hasApi): ?>
                  <span class="nova-badge is-redmine" title="API Redmine configurada">API</span>
                <?php endif; ?>
                <?php if ($hasCore): ?>
                  <span class="nova-badge is-core" title="CORE guardado">CORE</span>
                <?php endif; ?>
                <?php if ($hasNc): ?>
                  <span class="nova-badge is-nextcloud" title="Nextcloud guardado">NC</span>
                <?php endif; ?>
              </div>
            </td>
            <td class="nova-col-hide-md nova-date-meta" data-col="login"><?= $h($uLoginFmt) ?></td>
            <td>
              <div class="nova-table-actions">
                <button type="button" class="btn-action btn-action-edit" data-bs-toggle="modal" data-bs-target="#editModal" <?= $maintenanceMode ? 'disabled' : '' ?>
                  data-id="<?= $h($u['id'] ?? '') ?>"
                  data-nombre="<?= $h($u['nombre'] ?? '') ?>"
                  data-apellido="<?= $h($u['apellido'] ?? '') ?>"
                  data-rol="<?= $h($u['rol'] ?? 'usuario') ?>"
                  title="Editar rol de proyecto"
                  aria-label="Editar rol de proyecto">
                  <i class="bi bi-pencil"></i>
                </button>
                <button
                  type="button"
                  class="btn-action btn-action-delete"
                  data-bs-toggle="modal"
                  data-bs-target="#deleteAccessModal"
                  data-id="<?= $h($u['id'] ?? '') ?>"
                  data-usuario="<?= $h($uNombre) ?>"
                  aria-label="Quitar acceso al proyecto"
                  title="Quitar acceso"
                  <?= $maintenanceMode ? 'disabled' : '' ?>>
                  <i class="bi bi-person-x"></i>
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade detail-drawer-modal" id="importUsersModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable detail-drawer-dialog">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title">Importar usuarios desde Redmine</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="sync_remote">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <?php if (is_array($importPreview) && $importPreview): ?>
            <div class="nova-table-toolbar mb-3">
              <span class="nova-table-toolbar-title">Selecciona usuarios</span>
              <div class="nova-table-search">
                <i class="bi bi-search"></i>
                <input id="import-user-search" type="search" placeholder="Buscar nombre o ID" aria-label="Buscar usuario para importar">
              </div>
              <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="import-select-all">Seleccionar todos</button>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="import-clear-all">Limpiar</button>
            </div>
            <div class="table-responsive">
              <table class="nova-user-table">
                <thead>
                  <tr data-import-search="<?= $h(strtolower($fullName . ' ' . (string)($item['id'] ?? ''))) ?>">
                    <th style="width:48px"></th>
                    <th>Usuario Redmine</th>
                    <th>Estado NOVA</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($importPreview as $item):
                  $status = (string)($item['status'] ?? 'new');
                  $checked = in_array($status, ['new', 'changed'], true) ? 'checked' : '';
                  $label = match ($status) {
                      'current' => 'Ya tiene acceso',
                      'revoked' => 'Existe sin acceso',
                      'changed' => 'ID cambiado: ' . ($item['previous_id'] ?? '-') . ' → ' . ($item['id'] ?? '-'),
                      default => 'Nuevo, se creara baneado',
                  };
                  $badge = match ($status) {
                      'current' => 'is-activo',
                      'revoked' => 'is-baneado',
                      'changed' => 'is-gestor',
                      default => 'is-usuario',
                  };
                  $fullName = trim((string)($item['nombre'] ?? '') . ' ' . (string)($item['apellido'] ?? ''));
                ?>
                  <tr>
                    <td>
                      <input class="form-check-input import-user-check" type="checkbox" name="remote_user_ids[]" value="<?= $h($item['id'] ?? '') ?>" <?= $checked ?>>
                    </td>
                    <td>
                      <div class="nova-user-name"><?= $h($fullName) ?></div>
                      <div class="nova-user-meta"><?= $h($item['id'] ?? '') ?></div>
                    </td>
                    <td><span class="nova-badge <?= $h($badge) ?>"><?= $h($label) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php elseif (is_array($importPreview)): ?>
            <div class="nova-table-empty">No hay usuarios importables desde Redmine.</div>
          <?php else: ?>
            <div class="nova-table-empty">Presiona Importar Redmine para cargar la lista.</div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn-nova btn-nova-primary" <?= empty($importPreview) || $maintenanceMode ? 'disabled' : '' ?>>
            <i class="bi bi-cloud-download"></i> Importar seleccionados
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade detail-drawer-modal" id="deleteAccessModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title">Quitar acceso</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <input type="hidden" name="id" id="delete-user-id">
          <p class="mb-1">Quieres quitar el acceso de este usuario al proyecto Mantencion?</p>
          <p class="mb-0 fw-bold" id="delete-user-name"></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn-nova btn-nova-danger" <?= $maintenanceMode ? 'disabled title="Plataforma en mantencion"' : '' ?>>
            <i class="bi bi-person-x"></i> Quitar acceso
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade detail-drawer-modal" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg detail-drawer-dialog">
    <div class="modal-content">
      <form method="post">
        <div class="modal-header">
          <h5 class="modal-title">Editar rol de proyecto</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <input type="hidden" name="id" id="em-id">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label">ID</label><input name="id_display" id="em-id-display" class="form-control" readonly></div>
            <div class="col-md-6"><label class="form-label">Usuario</label><input id="em-user-display" class="form-control" readonly></div>
            <div class="col-md-6">
              <label class="form-label">Rol</label>
              <select name="rol" id="em-rol" class="form-select">
                <option value="usuario">Usuario</option>
                <option value="administrador">Administrador</option>
                <option value="gestor">Gestor</option>
                <option value="root">Root</option>
              </select>
            </div>
            <div class="col-12">
              <div class="nova-alert-card is-info mb-0">
                <i class="bi bi-person-lock"></i>
                Desde esta vista solo se modifica el rol dentro del proyecto. La identidad, estado y credenciales personales se mantienen en NOVA.
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
          <button class="btn-nova btn-nova-primary" <?= $maintenanceMode ? 'disabled title="Plataforma en mantencion"' : '' ?>>Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade detail-drawer-modal" id="createUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable detail-drawer-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Crear usuario</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <form method="post">
          <input type="hidden" name="action" value="create">
          <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label">ID (manual)</label><input name="id_manual" id="new-id" class="form-control" placeholder="ID" aria-label="ID"></div>
            <div class="col-md-4"><label class="form-label">Nombre</label><input name="nombre" class="form-control" placeholder="Nombre" required></div>
            <div class="col-md-4"><label class="form-label">Apellido</label><input name="apellido" class="form-control" placeholder="Apellido" required></div>
            <div class="col-md-4">
              <label class="form-label">Rol</label>
              <select name="rol" class="form-select">
                <option value="usuario" selected>Usuario</option>
                <option value="administrador">Administrador</option>
                <option value="gestor">Gestor</option>
                <option value="root">Root</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Estado</label>
              <select name="estado" class="form-select">
                <option value="activo" selected>Activo</option>
                <option value="baneado">Baneado</option>
              </select>
            </div>
            <div class="col-12">
              <div class="nova-alert-card is-info mb-0">
                <i class="bi bi-person-lock"></i>
                Las credenciales de Redmine, CORE y Nextcloud son personales. Cada usuario debe ingresarlas desde el modulo correspondiente.
              </div>
            </div>
          </div>
          <div class="text-end mt-3">
            <button class="btn-nova btn-nova-primary btn-icon" <?= $maintenanceMode ? 'disabled title="Plataforma en mantencion"' : '' ?>><i class="bi bi-check-lg"></i> Guardar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>
<button id="users-scroll-top" type="button" title="Volver arriba" aria-label="Volver arriba" class="btn btn-primary nova-scroll-top">
    <i class="bi bi-arrow-up"></i>
</button>
<script>
const userFilterInput = document.getElementById('user-search');
const userStatusFilters = document.getElementById('user-status-filters');
const hasImportPreview = <?= is_array($importPreview) ? 'true' : 'false' ?>;
let activeUserStatus = userStatusFilters?.querySelector('[data-filter].is-active')?.getAttribute('data-filter') || 'activo';

function applyUserFilters() {
  const term = (userFilterInput?.value || '').toLowerCase().trim();
  document.querySelectorAll('#user-table tbody tr[data-user-status]').forEach(tr => {
    const statusMatches = (tr.getAttribute('data-user-status') || 'activo') === activeUserStatus;
    const textMatches = term === '' || Array.from(tr.querySelectorAll('[data-col]')).some(td =>
      (td.textContent || '').toLowerCase().includes(term)
    );
    tr.style.display = statusMatches && textMatches ? '' : 'none';
  });
}

if (userFilterInput) {
  userFilterInput.addEventListener('input', applyUserFilters);
}

if (userStatusFilters) {
  userStatusFilters.addEventListener('click', ev => {
    const card = ev.target.closest('[data-filter]');
    if (!card) return;
    activeUserStatus = card.getAttribute('data-filter') || 'activo';
    userStatusFilters.querySelectorAll('[data-filter]').forEach(item => item.classList.toggle('is-active', item === card));
    applyUserFilters();
  });
  userStatusFilters.addEventListener('keydown', ev => {
    if (ev.key !== 'Enter' && ev.key !== ' ') return;
    const card = ev.target.closest('[data-filter]');
    if (!card) return;
    ev.preventDefault();
    card.click();
  });
}

applyUserFilters();

function setupEditModal() {
  const modal = document.getElementById('editModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', ev => {
    const btn = ev.relatedTarget;
    if (!btn) return;
    const set = (id, attr) => {
      const el = document.getElementById(id);
      if (el) el.value = btn.getAttribute(attr) || '';
    };
    set('em-id', 'data-id');
    set('em-id-display', 'data-id');
    set('em-rol', 'data-rol');
    const nameEl = document.getElementById('em-user-display');
    if (nameEl) nameEl.value = `${btn.getAttribute('data-nombre') || ''} ${btn.getAttribute('data-apellido') || ''}`.trim();
  });
}

function setupDeleteModal() {
  const modal = document.getElementById('deleteAccessModal');
  if (!modal) return;
  modal.addEventListener('show.bs.modal', ev => {
    const btn = ev.relatedTarget;
    if (!btn) return;
    const idInput = document.getElementById('delete-user-id');
    const nameEl = document.getElementById('delete-user-name');
    if (idInput) idInput.value = btn.getAttribute('data-id') || '';
    if (nameEl) nameEl.textContent = btn.getAttribute('data-usuario') || '';
  });
}

function setupImportModal() {
  const modal = document.getElementById('importUsersModal');
  if (!modal) return;
  const checks = Array.from(modal.querySelectorAll('.import-user-check'));
  const rows = Array.from(modal.querySelectorAll('tbody tr[data-import-search]'));
  const search = document.getElementById('import-user-search');
  const submit = modal.querySelector('button[type="submit"]');
  const normalize = value => String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  const updateSubmit = () => {
    if (submit && checks.length > 0) {
      submit.disabled = !checks.some(check => check.checked);
    }
  };
  const applyImportSearch = () => {
    const q = normalize(search?.value || '');
    rows.forEach(row => {
      row.style.display = q === '' || normalize(row.getAttribute('data-import-search')).includes(q) ? '' : 'none';
    });
  };
  search?.addEventListener('input', applyImportSearch);
  document.getElementById('import-select-all')?.addEventListener('click', () => {
    rows.forEach(row => {
      if (row.style.display === 'none') return;
      const check = row.querySelector('.import-user-check');
      if (check) check.checked = true;
    });
    updateSubmit();
  });
  document.getElementById('import-clear-all')?.addEventListener('click', () => {
    checks.forEach(check => { check.checked = false; });
    updateSubmit();
  });
  checks.forEach(check => check.addEventListener('change', updateSubmit));
  updateSubmit();

  if (hasImportPreview && window.bootstrap) {
    window.bootstrap.Modal.getOrCreateInstance(modal).show();
  }
}

const existingIds = <?= json_encode(array_values(array_map(fn($u) => $u['id'] ?? '', $usuarios)), JSON_UNESCAPED_UNICODE) ?>.filter(Boolean);

function markDuplicate(input, isDup, msg = 'Ya existe') {
  if (!input) return;
  input.classList.toggle('is-invalid', isDup);
  let fb = input.parentElement.querySelector('.invalid-feedback');
  if (!fb) {
    fb = document.createElement('div');
    fb.className = 'invalid-feedback';
    input.parentElement.appendChild(fb);
  }
  fb.textContent = isDup ? msg : '';
}

function checkDuplicatesCreate() {
  const idInput = document.getElementById('new-id');
  const idVal = (idInput?.value || '').trim();
  markDuplicate(idInput, idVal !== '' && existingIds.includes(idVal), 'El ID ya existe');
}

const newIdInput = document.getElementById('new-id');
if (newIdInput) {
  newIdInput.addEventListener('input', checkDuplicatesCreate);
}

setupEditModal();
setupDeleteModal();
setupImportModal();
</script>
</div>
</body>
</html>
