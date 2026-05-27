<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_role(['root', 'gestor'], '/redmine-mantencion/login.php');
require_once __DIR__ . '/../../controllers/nextcloud.php';
[$flash, $nextcloudCfg, $nextcloudGroups, $lastImport, $preview] = handle_nextcloud();
$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$csrf = legacy_csrf_token();
$maintenanceMode = function_exists('maintenance_mode_enabled') && maintenance_mode_enabled();
$hasSavedNextcloudCredentials = (function_exists('auth_get_user_id') && nextcloud_credentials_has_saved((string)auth_get_user_id()))
    || (trim((string)($nextcloudCfg['admin_user'] ?? '')) !== '' && trim((string)($nextcloudCfg['admin_pass'] ?? '')) !== '');
$previewUsers = is_array($preview['users'] ?? null) ? $preview['users'] : [];
$hasInvalidPreview = false;
foreach ($previewUsers as $item) {
    if (empty($item['groups']) || empty($item['email_valid'])) {
        $hasInvalidPreview = true;
        break;
    }
}
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Crear usuarios por lotes'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
  <style>
    body { margin: 0; }
    .nextcloud-panel { border: 0; border-radius: 1.1rem; box-shadow: 0 16px 38px rgba(15, 23, 42, .08); }
    .nextcloud-preview-table th { white-space: nowrap; }
    .nextcloud-preview-table td { vertical-align: middle; }
    .nextcloud-group-badge { display: inline-flex; align-items: center; gap: .4rem; min-height: 2rem; }
    .nextcloud-group-tools { background: #f8fbff; border: 1px solid #dbeafe; border-radius: 1rem; padding: 1rem; }
    .nextcloud-row-created > * { background-color: #dcfce7 !important; }
    .nextcloud-row-existing > * { background-color: #fef3c7 !important; }
    .nextcloud-loading-overlay {
      position: fixed;
      inset: 0;
      z-index: 2050;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 1rem;
      background: rgba(15, 23, 42, .58);
      backdrop-filter: blur(6px);
    }
    .nextcloud-loading-overlay.is-visible { display: flex; }
    .nextcloud-loading-card {
      width: min(520px, 94vw);
      border-radius: 1.25rem;
      background: #fff;
      box-shadow: 0 28px 70px rgba(15, 23, 42, .28);
      overflow: hidden;
    }
    .nextcloud-loading-media {
      height: 220px;
      background: linear-gradient(135deg, #e0f2fe, #f0fdf4);
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .nextcloud-loading-media img {
      width: 320px;
      max-width: calc(100% - 2rem);
      height: auto;
      max-height: 205px;
      object-fit: contain;
      image-rendering: auto;
    }
    .nextcloud-loading-body { padding: 1.25rem; }
    .nextcloud-loading-title { margin: 0; font-size: 1.15rem; font-weight: 800; color: #0f172a; }
    .nextcloud-loading-text { margin: .25rem 0 1rem; color: #64748b; }
    .nextcloud-loading-progress {
      height: .7rem;
      border-radius: 999px;
      background: #e5eef8;
      overflow: hidden;
    }
    .nextcloud-loading-progress-bar {
      width: 0%;
      height: 100%;
      border-radius: inherit;
      background: linear-gradient(90deg, #38bdf8, #22c55e);
      transition: width .25s ease;
    }
    .nextcloud-loading-meta {
      display: flex;
      justify-content: space-between;
      gap: 1rem;
      margin-top: .75rem;
      color: #64748b;
      font-size: .9rem;
      font-weight: 700;
    }
  </style>
</head>
<body class="bg-light">
<?php $activeNav = 'integraciones_nextcloud_usuarios'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
  <div class="container-fluid py-4">
    <?php
      $heroIcon = 'bi-people-fill';
      $heroTitle = 'Crear usuarios por lotes';
      $heroSubtitle = 'Carga masiva de usuarios en Nextcloud desde CSV o XLSX.';
      $heroExtras = '';
      include __DIR__ . '/../partials/hero.php';
    ?>

    <div class="row g-3">
      <div class="col-12">
        <form method="post" enctype="multipart/form-data" class="card nextcloud-panel">
          <div class="card-body p-4">
            <input type="hidden" name="action" value="import_nextcloud_users">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
              <div class="d-flex align-items-center gap-3">
                <div class="rounded-4 bg-success bg-opacity-10 text-success d-inline-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                  <i class="bi bi-file-earmark-spreadsheet fs-4"></i>
                </div>
                <div>
                  <h5 class="mb-0">Archivo de usuarios</h5>
                  <div class="text-muted small">Sube un CSV o XLSX con los usuarios a crear.</div>
                </div>
              </div>
              <span class="badge text-bg-light border">API OCS</span>
            </div>

            <div class="mb-3">
              <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <label class="form-label mb-0">Archivo CSV o XLSX</label>
                <a class="btn btn-sm btn-outline-success" href="/redmine-mantencion/assets/templates/plantilla-usuarios-nextcloud-v2.xlsx" download>
                  <i class="bi bi-file-earmark-excel"></i> Descargar plantilla Excel
                </a>
              </div>
              <input type="file" name="nextcloud_file" class="form-control" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
              <div class="form-text">CSV funciona de inmediato. XLSX requiere la extensión ZIP habilitada en PHP.</div>
            </div>

            <button class="btn btn-primary" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>>
              <i class="bi bi-eye"></i> Previsualizar usuarios
            </button>
          </div>
        </form>

        <?php if ($previewUsers): ?>
          <div class="card nextcloud-panel mt-3">
            <div class="card-body p-4">
              <form method="post" id="nextcloud-preview-form">
                <input type="hidden" name="action" value="confirm_nextcloud_import">
                <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                <input type="hidden" name="prepared_users" value="<?= $h(json_encode(['users' => $previewUsers], JSON_UNESCAPED_UNICODE)) ?>">
                <input type="hidden" name="nextcloud_runtime_user" id="nextcloud-runtime-user-hidden" value="">
                <input type="hidden" name="nextcloud_runtime_pass" id="nextcloud-runtime-pass-hidden" value="">
                <input type="hidden" name="nextcloud_remember_credentials" id="nextcloud-remember-hidden" value="0">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                  <div class="d-flex align-items-center gap-3">
                    <div class="rounded-4 bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                      <i class="bi bi-table fs-4"></i>
                    </div>
                    <div>
                      <h5 class="mb-0">Previsualización de envío</h5>
                      <div class="text-muted small">Marca filas para cambiar su grupo o elimina las que no quieras crear. Los correos inválidos deben corregirse en el archivo y volver a cargarse.</div>
                    </div>
                  </div>
                  <button type="<?= $hasSavedNextcloudCredentials ? 'submit' : 'button' ?>" class="btn btn-success" id="nextcloud-confirm-btn" data-maintenance="<?= $maintenanceMode ? '1' : '0' ?>" <?= $hasSavedNextcloudCredentials ? '' : 'data-bs-toggle="modal" data-bs-target="#nextcloudCredentialsModal"' ?> <?= ($maintenanceMode || $hasInvalidPreview) ? 'disabled' : '' ?> <?= $maintenanceMode ? 'title="Plataforma en mantención"' : '' ?>>
                    <i class="bi bi-cloud-arrow-up"></i> Confirmar creación
                  </button>
                </div>

                <div class="nextcloud-group-tools mb-3">
                  <div class="row g-2 align-items-start">
                    <div class="col-lg-6">
                      <label class="form-label">Buscar grupo existente</label>
                      <input type="search" class="form-control" id="nextcloud-group-search" placeholder="Buscar grupo en tiempo real" autocomplete="off" list="nextcloud-group-list">
                      <datalist id="nextcloud-group-list"></datalist>
                    </div>
                    <div class="col-lg-2">
                      <label class="form-label">Cuota</label>
                      <select class="form-select" id="nextcloud-bulk-quota">
                        <option value="">Predeterminada</option>
                        <option value="none">Ilimitado</option>
                        <option value="1 GB">1 GB</option>
                        <option value="5 GB">5 GB</option>
                        <option value="10 GB">10 GB</option>
                      </select>
                    </div>
                    <div class="col-lg-2 d-grid pt-lg-4">
                      <button type="button" class="btn btn-outline-primary" id="nextcloud-apply-changes" disabled>
                        <i class="bi bi-check2-square"></i> Aplicar cambios
                      </button>
                    </div>
                  </div>
                  <div class="form-text mt-2">Solo se pueden aplicar grupos existentes consultados desde Nextcloud.</div>
                </div>

                <div class="table-responsive border rounded-4 overflow-hidden">
                  <table class="table table-sm mb-0 align-middle nextcloud-preview-table">
                    <thead class="table-light">
                      <tr>
                        <th style="width:44px;"><input type="checkbox" class="form-check-input" id="nextcloud-check-all" aria-label="Seleccionar todos"></th>
                        <th>Nombre de usuario</th>
                        <th>Nombre a desplegar</th>
                        <th>Correo</th>
                        <th>Grupo</th>
                        <th>Cuota</th>
                        <th>Contraseña</th>
                        <th style="width:58px;">Acción</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($previewUsers as $idx => $item): ?>
                        <?php
                          $selectedGroup = (string)($item['groups'][0] ?? '');
                          $emailValid = !empty($item['email_valid']);
                        ?>
                        <tr data-nextcloud-row>
                          <td><input type="checkbox" class="form-check-input nextcloud-row-check" aria-label="Seleccionar fila"></td>
                          <td class="fw-bold">
                            <?= $h($item['userid'] ?? '') ?>
                            <input type="hidden" name="users[<?= (int)$idx ?>][userid]" value="<?= $h($item['userid'] ?? '') ?>">
                            <input type="hidden" name="users[<?= (int)$idx ?>][displayName]" value="<?= $h($item['displayName'] ?? '') ?>">
                            <input type="hidden" name="users[<?= (int)$idx ?>][email]" value="<?= $h($item['email'] ?? '') ?>">
                            <input type="hidden" name="users[<?= (int)$idx ?>][language]" value="<?= $h($item['language'] ?? 'es') ?>">
                            <input type="hidden" name="users[<?= (int)$idx ?>][password]" value="<?= $h($item['password'] ?? '') ?>">
                            <input type="hidden" name="users[<?= (int)$idx ?>][group]" value="<?= $h($selectedGroup) ?>" class="nextcloud-row-group-input">
                          </td>
                          <td><?= $h($item['displayName'] ?? '') ?></td>
                          <td>
                            <?= $h($item['email'] ?? '') ?>
                            <?php if (!$emailValid): ?>
                              <span class="badge text-bg-danger ms-1">Correo inválido</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php if ($selectedGroup !== ''): ?>
                              <span class="badge text-bg-success nextcloud-group-badge" data-group-label><?= $h($selectedGroup) ?></span>
                            <?php else: ?>
                              <span class="badge text-bg-warning nextcloud-group-badge" data-group-label>Sin coincidencia</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php $itemQuota = (string)($item['quota'] ?? ''); ?>
                            <select name="users[<?= (int)$idx ?>][quota]" class="form-select form-select-sm nextcloud-row-quota">
                              <option value="" <?= $itemQuota === '' ? 'selected' : '' ?>>Predeterminada</option>
                              <option value="none" <?= $itemQuota === 'none' ? 'selected' : '' ?>>Ilimitado</option>
                              <option value="1 GB" <?= $itemQuota === '1 GB' ? 'selected' : '' ?>>1 GB</option>
                              <option value="5 GB" <?= $itemQuota === '5 GB' ? 'selected' : '' ?>>5 GB</option>
                              <option value="10 GB" <?= $itemQuota === '10 GB' ? 'selected' : '' ?>>10 GB</option>
                            </select>
                          </td>
                          <td class="fw-bold text-primary"><?= $h($item['password'] ?? '') ?></td>
                          <td>
                            <button type="button" class="btn btn-sm btn-outline-danger nextcloud-remove-row" aria-label="Eliminar fila">
                              <i class="bi bi-trash"></i>
                            </button>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </form>
            </div>
          </div>
        <?php endif; ?>

        <?php $existingUsers = is_array($lastImport['existing_users'] ?? null) ? $lastImport['existing_users'] : []; ?>
        <?php $createdUsers = is_array($lastImport['created_users'] ?? null) ? $lastImport['created_users'] : []; ?>
        <?php
          $resultUsers = [];
          foreach ($createdUsers as $item) {
              $item['_status'] = 'Creado';
              $item['_badge'] = 'success';
              $item['_row'] = 'table-success nextcloud-row-created';
              $resultUsers[] = $item;
          }
          foreach ($existingUsers as $item) {
              $item['_status'] = 'Existente';
              $item['_badge'] = 'warning';
              $item['_row'] = 'table-warning nextcloud-row-existing';
              $resultUsers[] = $item;
          }
        ?>
        <?php if (!empty($lastImport) && $resultUsers): ?>
          <div class="card nextcloud-panel mt-3">
            <div class="card-body p-4">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                <div class="d-flex align-items-center gap-3">
                  <div class="rounded-4 bg-primary bg-opacity-10 text-primary d-inline-flex align-items-center justify-content-center" style="width:48px;height:48px;">
                    <i class="bi bi-table fs-4"></i>
                  </div>
                  <div>
                    <h5 class="mb-0">Resultado de importación</h5>
                    <div class="text-muted small">Usuarios creados y existentes en una sola tabla. Disponible en historial por 24 horas.</div>
                  </div>
                </div>
                <button type="button" class="btn btn-outline-primary" data-copy-table="#nextcloud-result-table">
                  <i class="bi bi-clipboard"></i> Copiar tabla
                </button>
              </div>
              <div class="table-responsive border rounded-4 overflow-hidden">
                <table class="table table-sm mb-0 align-middle" id="nextcloud-result-table">
                  <thead class="table-light">
                    <tr>
                      <th>Estado</th>
                      <th>Nombre de usuario</th>
                      <th>Nombre a desplegar</th>
                      <th>Correo</th>
                      <th>Grupo</th>
                      <th>Contraseña</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($resultUsers as $item): ?>
                      <tr class="<?= $h($item['_row'] ?? '') ?>">
                        <td><span class="badge text-bg-<?= $h($item['_badge'] ?? 'secondary') ?>"><?= $h($item['_status'] ?? '') ?></span></td>
                        <td><?= $h($item['userid'] ?? '') ?></td>
                        <td><?= $h($item['displayName'] ?? '') ?></td>
                        <td><?= $h($item['email'] ?? '') ?></td>
                        <td><?= $h($item['group'] ?? '') ?></td>
                        <td><?= $h($item['password'] ?? '') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<div class="nextcloud-loading-overlay" id="nextcloud-loading-overlay" role="status" aria-live="polite" aria-hidden="true">
  <div class="nextcloud-loading-card">
    <div class="nextcloud-loading-media">
      <img src="/redmine-mantencion/assets/img/Nextcloud.gif" alt="">
    </div>
    <div class="nextcloud-loading-body">
      <h3 class="nextcloud-loading-title">Creando usuarios en Nextcloud</h3>
      <p class="nextcloud-loading-text" id="nextcloud-loading-text">Conectando con la API OCS...</p>
      <div class="nextcloud-loading-progress" aria-label="Progreso de creación">
        <div class="nextcloud-loading-progress-bar" id="nextcloud-loading-progress-bar"></div>
      </div>
      <div class="nextcloud-loading-meta">
        <span id="nextcloud-loading-step">Preparando credenciales</span>
        <span id="nextcloud-loading-percent">0%</span>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="nextcloudCredentialsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-0">Credenciales Nextcloud</h5>
          <div class="text-muted small">Se usarán solo para crear usuarios por API OCS.</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <?php if ($hasSavedNextcloudCredentials): ?>
          <div class="alert alert-info py-2 small">Hay credenciales guardadas para tu usuario. Puedes dejar los campos vacíos para usarlas.</div>
        <?php endif; ?>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Usuario administrador Nextcloud</label>
            <input type="text" class="form-control" id="nextcloud-runtime-user-input" autocomplete="username" placeholder="Usuario administrador o cuenta con permisos">
          </div>
          <div class="col-12">
            <label class="form-label">Contraseña de aplicación</label>
            <input type="password" class="form-control" id="nextcloud-runtime-pass-input" autocomplete="current-password" placeholder="Contraseña de aplicación Nextcloud">
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="nextcloud-remember-input">
              <label class="form-check-label" for="nextcloud-remember-input">Recordar credenciales Nextcloud para mi usuario</label>
            </div>
            <div class="form-text">La contraseña se guarda cifrada en tu usuario, junto a las credenciales CORE.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-success" form="nextcloud-preview-form">
          <i class="bi bi-cloud-arrow-up"></i> Crear usuarios
        </button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const search = document.getElementById('nextcloud-group-search');
  const groupList = document.getElementById('nextcloud-group-list');
  const bulkQuota = document.getElementById('nextcloud-bulk-quota');
  const applyChanges = document.getElementById('nextcloud-apply-changes');
  const checkAll = document.getElementById('nextcloud-check-all');
  const confirmBtn = document.getElementById('nextcloud-confirm-btn');
  const previewForm = document.getElementById('nextcloud-preview-form');
  const hasSavedNextcloudCredentials = <?= $hasSavedNextcloudCredentials ? 'true' : 'false' ?>;
  const nextcloudRuntimeUserInput = document.getElementById('nextcloud-runtime-user-input');
  const nextcloudRuntimePassInput = document.getElementById('nextcloud-runtime-pass-input');
  const nextcloudRememberInput = document.getElementById('nextcloud-remember-input');
  const nextcloudRuntimeUserHidden = document.getElementById('nextcloud-runtime-user-hidden');
  const nextcloudRuntimePassHidden = document.getElementById('nextcloud-runtime-pass-hidden');
  const nextcloudRememberHidden = document.getElementById('nextcloud-remember-hidden');
  const nextcloudCredentialsModal = document.getElementById('nextcloudCredentialsModal');
  const nextcloudLoadingOverlay = document.getElementById('nextcloud-loading-overlay');
  const nextcloudLoadingProgressBar = document.getElementById('nextcloud-loading-progress-bar');
  const nextcloudLoadingPercent = document.getElementById('nextcloud-loading-percent');
  const nextcloudLoadingText = document.getElementById('nextcloud-loading-text');
  const nextcloudLoadingStep = document.getElementById('nextcloud-loading-step');
  const getRows = () => Array.from(document.querySelectorAll('[data-nextcloud-row]'));
  const groups = <?= json_encode(array_values($nextcloudGroups), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  let selectedGroup = '';
  let selectedGroupText = '';
  let quotaChanged = false;
  let nextcloudProgressTimer = null;
  let nextcloudSubmitAccepted = false;

  function showNextcloudLoading() {
    if (!nextcloudLoadingOverlay || !nextcloudLoadingProgressBar) return;
    const steps = [
      { at: 10, text: 'Conectando con la API OCS...', step: 'Validando credenciales' },
      { at: 28, text: 'Preparando usuarios seleccionados...', step: 'Armando solicitudes' },
      { at: 52, text: 'Creando cuentas en Nextcloud...', step: 'Enviando usuarios' },
      { at: 76, text: 'Asignando grupos y cuotas...', step: 'Aplicando configuración' },
      { at: 92, text: 'Finalizando y registrando resultado...', step: 'Guardando historial' },
    ];
    let progress = 0;
    let stepIndex = 0;
    const setProgress = (value) => {
      progress = Math.min(94, Math.max(progress, value));
      nextcloudLoadingProgressBar.style.width = `${progress}%`;
      if (nextcloudLoadingPercent) nextcloudLoadingPercent.textContent = `${Math.round(progress)}%`;
    };
    const setStep = (item) => {
      if (nextcloudLoadingText) nextcloudLoadingText.textContent = item.text;
      if (nextcloudLoadingStep) nextcloudLoadingStep.textContent = item.step;
    };
    nextcloudLoadingOverlay.classList.add('is-visible');
    nextcloudLoadingOverlay.setAttribute('aria-hidden', 'false');
    setProgress(6);
    setStep(steps[0]);
    clearInterval(nextcloudProgressTimer);
    nextcloudProgressTimer = setInterval(() => {
      const target = steps[stepIndex] || steps[steps.length - 1];
      if (progress < target.at) {
        setProgress(progress + Math.max(1, (target.at - progress) * 0.18));
        return;
      }
      if (stepIndex < steps.length - 1) {
        stepIndex += 1;
        setStep(steps[stepIndex]);
        return;
      }
      setProgress(progress + 0.35);
    }, 420);
  }

  function updateConfirmState() {
    if (!confirmBtn) return;
    const rows = getRows();
    const missingGroup = rows.length === 0 || rows.some(row => {
      const input = row.querySelector('.nextcloud-row-group-input');
      return !input || input.value.trim() === '';
    });
    const invalidEmail = rows.some(row => row.querySelector('.badge.text-bg-danger'));
    confirmBtn.disabled = confirmBtn.dataset.maintenance === '1' || missingGroup || invalidEmail;
  }

  function hasSelectedRows() {
    return document.querySelectorAll('.nextcloud-row-check:checked').length > 0;
  }

  function updateApplyState() {
    if (!applyChanges) return;
    applyChanges.disabled = (!selectedGroup && !quotaChanged) || !hasSelectedRows();
  }

  if (search) {
    search.addEventListener('input', () => {
      const term = search.value.trim();
      const normalized = term.toLowerCase();
      const exactGroup = groups.find(group => group.toLowerCase() === normalized) || '';
      selectedGroup = exactGroup;
      selectedGroupText = exactGroup;
      updateApplyState();
      if (!groupList) return;
      groupList.innerHTML = '';
      if (normalized.length < 1) return;
      groups
        .filter(group => group.toLowerCase().includes(normalized))
        .slice(0, 30)
        .forEach(group => {
          const option = document.createElement('option');
          option.value = group;
          groupList.appendChild(option);
        });
    });
  }

  if (checkAll) {
    checkAll.addEventListener('change', () => {
      document.querySelectorAll('.nextcloud-row-check').forEach(check => {
        check.checked = checkAll.checked;
      });
      updateApplyState();
    });
  }

  document.querySelectorAll('.nextcloud-row-check').forEach(check => {
    check.addEventListener('change', updateApplyState);
  });

  document.querySelectorAll('.nextcloud-remove-row').forEach(button => {
    button.addEventListener('click', () => {
      const row = button.closest('[data-nextcloud-row]');
      if (row) row.remove();
      if (checkAll) {
        const checks = Array.from(document.querySelectorAll('.nextcloud-row-check'));
        checkAll.checked = checks.length > 0 && checks.every(check => check.checked);
      }
      updateApplyState();
      updateConfirmState();
    });
  });

  if (bulkQuota) {
    bulkQuota.addEventListener('change', () => {
      quotaChanged = true;
      updateApplyState();
    });
  }

  if (applyChanges) {
    applyChanges.addEventListener('click', () => {
      if (!selectedGroup && !quotaChanged) return;
      document.querySelectorAll('.nextcloud-row-check:checked').forEach(check => {
        const row = check.closest('[data-nextcloud-row]');
        const groupInput = row ? row.querySelector('.nextcloud-row-group-input') : null;
        const label = row ? row.querySelector('[data-group-label]') : null;
        const quota = row ? row.querySelector('.nextcloud-row-quota') : null;
        if (selectedGroup && groupInput && label) {
          groupInput.value = selectedGroup;
          label.textContent = selectedGroupText;
          label.classList.remove('text-bg-warning');
          label.classList.add('text-bg-success');
        }
        if (quotaChanged && bulkQuota && quota) {
          quota.value = bulkQuota.value;
        }
      });
      quotaChanged = false;
      updateApplyState();
      updateConfirmState();
    });
  }

  document.querySelectorAll('[data-copy-table]').forEach(button => {
    button.addEventListener('click', async () => {
      const table = document.querySelector(button.dataset.copyTable);
      if (!table) return;
      const rowsText = Array.from(table.querySelectorAll('tr')).map(row => {
        return Array.from(row.children).map(cell => cell.innerText.trim()).join('\t');
      }).join('\n');
      try {
        await navigator.clipboard.writeText(rowsText);
        button.innerHTML = '<i class="bi bi-check2"></i> Copiado';
        setTimeout(() => { button.innerHTML = '<i class="bi bi-clipboard"></i> Copiar tabla'; }, 2000);
      } catch (error) {
        const area = document.createElement('textarea');
        area.value = rowsText;
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
      }
    });
  });

  if (previewForm) {
    previewForm.addEventListener('submit', event => {
      if (nextcloudRuntimeUserHidden) nextcloudRuntimeUserHidden.value = nextcloudRuntimeUserInput?.value || '';
      if (nextcloudRuntimePassHidden) nextcloudRuntimePassHidden.value = nextcloudRuntimePassInput?.value || '';
      if (nextcloudRememberHidden) nextcloudRememberHidden.value = nextcloudRememberInput?.checked ? '1' : '0';
      if (nextcloudSubmitAccepted) {
        showNextcloudLoading();
        return;
      }
      event.preventDefault();
      if (!hasSavedNextcloudCredentials && (!nextcloudRuntimeUserHidden?.value.trim() || !nextcloudRuntimePassHidden?.value.trim())) {
        window.appModal?.show({
          title: 'Credenciales requeridas',
          message: 'Debes ingresar usuario administrador y contraseña de aplicación de Nextcloud.',
          tone: 'warning'
        });
        return;
      }
      showNextcloudLoading();
      previewForm.querySelectorAll('button[type="submit"]').forEach(button => {
        button.disabled = true;
      });
      setTimeout(() => {
        nextcloudSubmitAccepted = true;
        previewForm.submit();
      }, 5000);
    });
  }

  if (nextcloudCredentialsModal) {
    nextcloudCredentialsModal.addEventListener('hidden.bs.modal', () => {
      if (nextcloudRuntimePassInput) nextcloudRuntimePassInput.value = '';
      if (nextcloudRuntimePassHidden) nextcloudRuntimePassHidden.value = '';
    });
  }

  updateConfirmState();
  updateApplyState();

});
</script>
</body>
</html>
