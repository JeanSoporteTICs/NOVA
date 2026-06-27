<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_role(['root', 'gestor'], '/redmine-mantencion/login.php');
require_once __DIR__ . '/../../controllers/usuarios.php';
list($usuarios, $flash) = handle_usuarios();
$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$csrf = legacy_csrf_token();
$maintenanceMode = function_exists('maintenance_mode_enabled') && maintenance_mode_enabled();
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Usuarios'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
  <style>
    body { margin: 0; }
    .navbar { margin-top: 0 !important; margin-bottom: 0; }
    .btn-icon { display: inline-flex; align-items: center; gap: .35rem; }
    .table thead th { font-weight: 600; text-transform: uppercase; font-size: .78rem; letter-spacing: .02em; }
    .user-status-badge { min-width: 78px; justify-content: center; }
    .credential-icons { display: inline-flex; align-items: center; gap: .35rem; flex-wrap: wrap; }
    .credential-icon {
      width: 28px;
      height: 28px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 999px;
      color: #fff;
      font-size: .92rem;
      line-height: 1;
    }
    .credential-icon--api { background: #198754; }
    .credential-icon--core { background: #0dcaf0; color: #052c33; }
    .credential-icon--nextcloud { background: #0d6efd; }
    .credential-icon--empty { background: #f8f9fa; color: #6c757d; border: 1px solid #dee2e6; }
  </style>
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
    if ($flash) {
      $heroExtras = '<div class="alert alert-success py-2 px-3 mb-0" id="flash-msg"><i class="bi bi-info-circle"></i> ' . $h($flash) . '</div>';
    }
    include __DIR__ . '/../partials/hero.php';
  ?>

  <div class="card">
    <div class="card-body">
      <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-3">
        <div class="d-flex align-items-center gap-2 flex-grow-1" style="min-width:260px;">
          <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width:32px;height:32px;">
            <i class="bi bi-search"></i>
          </div>
          <input id="user-search" class="form-control" placeholder="Buscar usuario" aria-label="Buscar usuario">
          <span class="badge bg-light text-dark border ms-2">Total: <?= count($usuarios) ?></span>
        </div>
        <div class="d-flex gap-2">
          <form method="post" class="m-0">
            <input type="hidden" name="action" value="sync_remote">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <button class="btn btn-outline-primary btn-icon" type="submit" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>>
              <i class="bi bi-cloud-download"></i> Importar desde Redmine
            </button>
          </form>
          <button class="btn btn-primary btn-icon" data-bs-toggle="modal" data-bs-target="#createUserModal" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>>
            <i class="bi bi-person-plus"></i> Nuevo usuario
          </button>
        </div>
      </div>
      <div class="table-responsive">
        <table id="user-table" class="table table-striped align-middle">
          <thead class="table-light">
            <tr>
              <th scope="col" style="width:90px;">ID</th>
              <th scope="col">Nombre</th>
              <th scope="col">Rol</th>
              <th scope="col">Estado</th>
              <th scope="col">API</th>
              <th scope="col" style="width:240px;">Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$usuarios): ?>
            <tr><td colspan="6" class="nova-empty"><i class="bi bi-people" style="font-size:1.4rem;display:block;margin-bottom:.4rem;opacity:.4"></i>No hay usuarios registrados.</td></tr>
          <?php endif; ?>
          <?php foreach ($usuarios as $u): ?>
            <tr>
              <td data-col="id"><?= $h($u['id'] ?? '') ?></td>
              <td data-col="nombre"><?= $h(trim((string)($u['nombre'] ?? '') . ' ' . (string)($u['apellido'] ?? ''))) ?></td>
              <td data-col="rol"><?= $h($u['rol'] ?? 'usuario') ?></td>
              <td data-col="estado">
                <?php $userEstado = strtolower(trim((string)($u['estado'] ?? 'activo'))); ?>
                <span class="badge user-status-badge <?= $userEstado === 'baneado' ? 'text-bg-danger' : 'text-bg-success' ?>">
                  <?= $userEstado === 'baneado' ? 'Baneado' : 'Activo' ?>
                </span>
              </td>
              <td data-col="api">
                <div class="credential-icons">
                  <?php if (trim((string)($u['api'] ?? '')) !== ''): ?>
                    <span class="credential-icon credential-icon--api" title="API configurada" aria-label="API configurada"><i class="bi bi-key-fill"></i></span>
                  <?php else: ?>
                    <span class="credential-icon credential-icon--empty" title="Sin token API" aria-label="Sin token API"><i class="bi bi-key"></i></span>
                  <?php endif; ?>
                  <?php if (trim((string)($u['core_user'] ?? '')) !== '' && trim((string)($u['core_pass_enc'] ?? '')) !== ''): ?>
                    <span class="credential-icon credential-icon--core" title="CORE guardado" aria-label="CORE guardado"><i class="bi bi-hospital"></i></span>
                  <?php endif; ?>
                  <?php if (trim((string)($u['nextcloud_user'] ?? '')) !== '' && trim((string)($u['nextcloud_pass_enc'] ?? '')) !== ''): ?>
                    <span class="credential-icon credential-icon--nextcloud" title="Nextcloud guardado" aria-label="Nextcloud guardado"><i class="bi bi-cloud-fill"></i></span>
                  <?php endif; ?>
                </div>
              </td>
              <td class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-sm btn-outline-primary btn-icon" data-bs-toggle="modal" data-bs-target="#editModal" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>
                  data-id="<?= $h($u['id'] ?? '') ?>"
                  data-nombre="<?= $h($u['nombre'] ?? '') ?>"
                  data-apellido="<?= $h($u['apellido'] ?? '') ?>"
                  data-rol="<?= $h($u['rol'] ?? 'usuario') ?>"
                  aria-label="Editar rol de proyecto">
                  <i class="bi bi-pencil-square"></i> Editar
                </button>
                <form method="post" data-app-confirm="¿Quitar el acceso de este usuario al proyecto Mantención?" class="m-0">
                  <input type="hidden" name="id" value="<?= $h($u['id'] ?? '') ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                  <button class="btn btn-sm btn-outline-danger btn-icon" aria-label="Quitar acceso al proyecto" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>><i class="bi bi-person-x"></i> Quitar acceso</button>
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

<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
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
              <div class="alert alert-info mb-0">
                <i class="bi bi-person-lock"></i>
                Desde esta vista solo se modifica el rol dentro del proyecto. La identidad, estado y credenciales personales se mantienen en NOVA.
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
          <button class="btn btn-primary" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>>Guardar cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="createUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
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
              <div class="alert alert-info mb-0">
                <i class="bi bi-person-lock"></i>
                Las credenciales de Redmine, CORE y Nextcloud son personales. Cada usuario debe ingresarlas desde el modulo correspondiente.
              </div>
            </div>
          </div>
          <div class="text-end mt-3">
            <button class="btn btn-primary btn-icon" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>><i class="bi bi-check-lg"></i> Guardar</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>
<button id="users-scroll-top" type="button" title="Volver arriba" aria-label="Volver arriba" style="position:fixed;bottom:28px;right:28px;z-index:1050;width:44px;height:44px;min-height:44px!important;border-radius:50%!important;display:none;align-items:center;justify-content:center;padding:0;box-shadow:0 8px 24px rgba(37,99,235,0.35);" class="btn btn-primary">
    <i class="bi bi-arrow-up"></i>
</button>
<script>
const userFilterInput = document.getElementById('user-search');
if (userFilterInput) {
  userFilterInput.addEventListener('input', () => {
    const term = userFilterInput.value.toLowerCase();
    document.querySelectorAll('#user-table tbody tr').forEach(tr => {
      const hay = Array.from(tr.querySelectorAll('[data-col]')).some(td =>
        (td.textContent || '').toLowerCase().includes(term)
      );
      tr.style.display = hay ? '' : 'none';
    });
  });
}

const flash = document.getElementById('flash-msg');
if (flash) setTimeout(() => flash.classList.add('d-none'), 5000);

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

(() => {
  const scrollTopBtn = document.getElementById('users-scroll-top');
  if (!scrollTopBtn) return;
  if (scrollTopBtn.parentElement !== document.body) {
    document.body.appendChild(scrollTopBtn);
  }
  const update = () => {
    scrollTopBtn.style.display = (window.scrollY || document.documentElement.scrollTop || 0) > 220 ? 'flex' : 'none';
  };
  scrollTopBtn.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
  window.addEventListener('scroll', update, { passive: true });
  window.addEventListener('resize', update);
  update();
})();
</script>
</div>
</body>
</html>
