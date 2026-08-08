<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Crear usuarios por lotes'; $includeTheme = true; include base_path('RedmineMantencion/views/partials/bootstrap-head.php'); ?>
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
  <?php $nextcloudUsuariosCssVersion = @filemtime(base_path('RedmineMantencion/assets/css/nextcloud-usuarios.css')) ?: time(); ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/nextcloud-usuarios.css?v=<?= (int)$nextcloudUsuariosCssVersion ?>">
</head>
<body class="bg-light">
<?php $activeNav = 'integraciones_nextcloud_usuarios'; include base_path('RedmineMantencion/views/partials/navbar.php'); ?>

<div id="page-content">
  <div class="container-fluid py-4">
    <?php
      $heroIcon = 'bi-people-fill';
      $heroTitle = 'Crear usuarios por lotes';
      $heroSubtitle = 'Carga masiva de usuarios en Nextcloud desde CSV o XLSX.';
      $heroExtras = '';
      include base_path('RedmineMantencion/views/partials/hero.php');
    ?>

    <?php if (trim((string)$flash) !== ''): ?>
      <div class="nova-alert-card is-warning mb-3" role="alert">
        <i class="bi bi-exclamation-triangle"></i>
        <span><?= $h($flash) ?></span>
      </div>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-12">
        <form method="post" action="<?= $h($nextcloudUsersActionUrl) ?>" enctype="multipart/form-data" class="card nextcloud-panel" id="nextcloud-import-form" autocomplete="off">
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

            <section class="nextcloud-requester-fields mb-3" aria-labelledby="nextcloud-requester-title">
              <div class="nextcloud-requester-head">
                <span><i class="bi bi-person-vcard" aria-hidden="true"></i></span>
                <div>
                  <h6 id="nextcloud-requester-title">Datos del solicitante</h6>
                  <p>Estos datos identificarán la importación en el historial.</p>
                </div>
              </div>
              <div class="row g-3">
                <div class="col-md-6 col-xl-4">
                  <label class="form-label" for="nextcloud-requester-name">Nombre del solicitante <span class="text-muted fw-normal">(opcional)</span></label>
                  <input class="form-control" id="nextcloud-requester-name" name="solicitante_nombre" value="<?= $h($requesterForm['solicitante_nombre'] ?? '') ?>" maxlength="200" placeholder="Nombre completo">
                </div>
                <div class="col-md-6 col-xl-4">
                  <label class="form-label" for="nextcloud-requester-rut">RUT <span class="text-muted fw-normal">(opcional)</span></label>
                  <input class="form-control" id="nextcloud-requester-rut" name="solicitante_rut" value="<?= $h($requesterForm['solicitante_rut'] ?? '') ?>" maxlength="12" placeholder="12.345.678-5" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false" aria-describedby="nextcloud-requester-rut-help nextcloud-requester-rut-feedback">
                  <!-- <div class="form-text" id="nextcloud-requester-rut-help">Los puntos y el guion se añaden automáticamente.</div> -->
                  <div class="invalid-feedback" id="nextcloud-requester-rut-feedback">Ingresa un RUT válido, incluido su dígito verificador.</div>
                </div>
                <div class="col-md-6 col-xl-4">
                  <label class="form-label" for="nextcloud-requester-email">Correo <span class="text-muted fw-normal">(opcional)</span></label>
                  <input class="form-control" id="nextcloud-requester-email" name="solicitante_correo" type="email" value="<?= $h($requesterForm['solicitante_correo'] ?? '') ?>" maxlength="190" placeholder="solicitante@dominio.cl" inputmode="email" autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="nextcloud-requester-email-feedback">
                  <div class="invalid-feedback" id="nextcloud-requester-email-feedback">Si ingresas un correo, debe tener un formato válido, por ejemplo nombre@dominio.cl.</div>
                </div>
              </div>
            </section>

            <div class="mb-3">
              <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <label class="form-label mb-0">Archivo CSV o XLSX</label>
                <a class="btn-nova btn-nova-success" href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/templates/plantilla-usuarios-nextcloud-v2.xlsx" download>
                  <i class="bi bi-file-earmark-excel"></i> Descargar plantilla
                </a>
              </div>
              <input type="file" name="nextcloud_file" class="form-control" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
              <!-- <div class="form-text">CSV funciona de inmediato. XLSX requiere la extensión ZIP habilitada en PHP.</div> -->
            </div>

            <button class="btn-nova btn-nova-primary" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>>
              <i class="bi bi-eye"></i> Previsualizar usuarios
            </button>
          </div>
        </form>

        <?php
          $selectableNextcloudGroups = array_values(array_unique(array_filter(
              array_map(static fn($group): string => trim((string)$group), is_array($nextcloudGroups) ? $nextcloudGroups : []),
              static fn(string $group): bool => $group !== ''
          )));
          natcasesort($selectableNextcloudGroups);
          $selectableNextcloudGroups = array_values($selectableNextcloudGroups);
        ?>
        <?php if ($previewUsers): ?>
          <div class="card nextcloud-panel mt-3">
            <div class="card-body p-4">
              <form method="post" action="<?= $h($nextcloudUsersActionUrl) ?>" id="nextcloud-preview-form">
                <input type="hidden" name="action" value="confirm_nextcloud_import">
                <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                <input type="hidden" name="nextcloud_runtime_user" id="nextcloud-runtime-user-hidden" value="">
                <input type="hidden" name="nextcloud_runtime_pass" id="nextcloud-runtime-pass-hidden" value="">
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
                  <div class="nextcloud-preview-actions">
                    <span class="nextcloud-preview-count"><i class="bi bi-people"></i><?= count($previewUsers) ?> usuario<?= count($previewUsers) === 1 ? '' : 's' ?></span>
                    <button type="<?= $hasSavedNextcloudCredentials ? 'submit' : 'button' ?>" class="btn-nova btn-nova-success" id="nextcloud-confirm-btn" data-maintenance="<?= $maintenanceMode ? '1' : '0' ?>" <?= $hasSavedNextcloudCredentials ? '' : 'data-bs-toggle="modal" data-bs-target="#nextcloudCredentialsModal"' ?> disabled <?= $maintenanceMode ? 'title="Plataforma en mantención"' : '' ?>>
                      <i class="bi bi-cloud-arrow-up"></i> Confirmar creación
                    </button>
                  </div>
                </div>

                <?php if ($previewRequester): ?>
                  <section class="nextcloud-requester-summary mb-3" aria-label="Solicitante de la importación">
                    <div class="nextcloud-requester-summary-icon"><i class="bi bi-person-check" aria-hidden="true"></i></div>
                    <div>
                      <span>Solicitante</span>
                      <strong><?= $h(($previewRequester['solicitante_nombre'] ?? '') !== '' ? $previewRequester['solicitante_nombre'] : ($previewRequester['solicitante'] ?? '')) ?></strong>
                    </div>
                    <div>
                      <span>RUT</span>
                      <strong><?= $h(($previewRequester['solicitante_rut'] ?? '') !== '' ? $previewRequester['solicitante_rut'] : 'No informado') ?></strong>
                    </div>
                    <div>
                      <span>Correo</span>
                      <strong><?= $h($previewRequester['solicitante_correo'] ?? '') ?></strong>
                    </div>
                  </section>
                <?php endif; ?>

                <div class="nextcloud-group-tools mb-3">
                  <div class="nextcloud-bulk-head">
                    <div>
                      <strong><i class="bi bi-people-fill" aria-hidden="true"></i> Cambios masivos</strong>
                      <span>Se aplican únicamente a los usuarios marcados.</span>
                    </div>
                    <span class="nextcloud-bulk-selected" id="nextcloud-bulk-selected">0 seleccionados</span>
                  </div>
                  <div class="row g-2 align-items-start">
                    <div class="col-lg-5">
                      <label class="form-label" for="nextcloud-bulk-group">Grupo para seleccionados</label>
                      <select class="form-select nextcloud-group-select" id="nextcloud-bulk-group">
                        <option value="__keep__" selected>No cambiar grupo</option>
                        <?php foreach ($selectableNextcloudGroups as $group): ?>
                          <option value="<?= $h($group) ?>"><?= $h($group) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-lg-3">
                      <label class="form-label" for="nextcloud-bulk-quota">Cuota para seleccionados</label>
                      <select class="form-select" id="nextcloud-bulk-quota">
                        <option value="__keep__" selected>No cambiar cuota</option>
                        <option value="">Predeterminada</option>
                        <option value="none">Ilimitado</option>
                        <option value="1 GB">1 GB</option>
                        <option value="5 GB">5 GB</option>
                        <option value="10 GB">10 GB</option>
                      </select>
                    </div>
                    <div class="col-lg-4 d-grid pt-lg-4">
                      <button type="button" class="btn-nova btn-nova-primary" id="nextcloud-apply-changes" disabled>
                        <i class="bi bi-check2-square"></i> Aplicar a seleccionados
                      </button>
                    </div>
                  </div>
                  <!-- <div class="form-text mt-2"><strong>Opcional:</strong> puedes aplicar solo grupo, solo cuota o ambos. Los valores en “No cambiar” se conservan.</div> -->
                </div>

                <div class="table-responsive nextcloud-table-wrap" role="region" aria-label="Usuarios preparados para crear en Nextcloud" tabindex="0">
                  <table class="table table-sm mb-0 align-middle nextcloud-preview-table">
                    <thead>
                      <tr>
                        <th scope="col" class="nextcloud-col-select"><input type="checkbox" class="form-check-input" id="nextcloud-check-all" aria-label="Seleccionar todos"></th>
                        <th scope="col" class="nextcloud-col-user">Usuario</th>
                        <th scope="col" class="nextcloud-col-name">Nombre a desplegar</th>
                        <th scope="col" class="nextcloud-col-email">Correo</th>
                        <th scope="col" class="nextcloud-col-group">Grupo</th>
                        <th scope="col" class="nextcloud-col-quota">Cuota</th>
                        <th scope="col" class="nextcloud-col-password">Contraseña</th>
                        <th scope="col" class="nextcloud-col-action">Acción</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($previewUsers as $idx => $item): ?>
                        <?php
                          $selectedGroup = (string)($item['groups'][0] ?? '');
                          $emailValid = !empty($item['email_valid']);
                        ?>
                        <tr data-nextcloud-row>
                          <td class="nextcloud-col-select"><input type="checkbox" class="form-check-input nextcloud-row-check" name="selected_users[]" value="<?= (int)$idx ?>" aria-label="Seleccionar usuario <?= $h($item['userid'] ?? '') ?>"></td>
                          <td class="nextcloud-col-user">
                            <span class="nextcloud-user-id"><i class="bi bi-person-badge" aria-hidden="true"></i><?= $h($item['userid'] ?? '') ?></span>
                            <?php if (!empty($item['duplicate_in_file'])): ?>
                              <span class="badge text-bg-danger nextcloud-inline-status">Duplicado</span>
                            <?php endif; ?>
                            <input type="hidden" name="users[<?= (int)$idx ?>][userid]" value="<?= $h($item['userid'] ?? '') ?>">
                            <input type="hidden" name="users[<?= (int)$idx ?>][displayName]" value="<?= $h($item['displayName'] ?? '') ?>">
                            <input type="hidden" name="users[<?= (int)$idx ?>][email]" value="<?= $h($item['email'] ?? '') ?>">
                            <input type="hidden" name="users[<?= (int)$idx ?>][language]" value="<?= $h($item['language'] ?? 'es') ?>">
                            <input type="hidden" name="users[<?= (int)$idx ?>][password]" value="<?= $h($item['password'] ?? '') ?>">
                          </td>
                          <td class="nextcloud-col-name"><span class="nextcloud-primary-text"><?= $h($item['displayName'] ?? '') ?></span></td>
                          <td class="nextcloud-col-email">
                            <span class="nextcloud-email-value"><?= $h($item['email'] ?? '') ?></span>
                            <?php if (!$emailValid): ?>
                              <span class="badge text-bg-danger nextcloud-inline-status">Correo inválido</span>
                            <?php endif; ?>
                          </td>
                          <td class="nextcloud-col-group">
                            <select name="users[<?= (int)$idx ?>][group]" class="form-select form-select-sm nextcloud-group-select nextcloud-row-group-select" data-placeholder="Seleccionar grupo" aria-label="Grupo de <?= $h($item['userid'] ?? '') ?>">
                              <option value=""></option>
                              <?php foreach ($selectableNextcloudGroups as $group): ?>
                                <option value="<?= $h($group) ?>" <?= $selectedGroup === $group ? 'selected' : '' ?>><?= $h($group) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                          <td class="nextcloud-col-quota">
                            <?php $itemQuota = (string)($item['quota'] ?? ''); ?>
                            <select name="users[<?= (int)$idx ?>][quota]" class="form-select form-select-sm nextcloud-row-quota">
                              <option value="" <?= $itemQuota === '' ? 'selected' : '' ?>>Predeterminada</option>
                              <option value="none" <?= $itemQuota === 'none' ? 'selected' : '' ?>>Ilimitado</option>
                              <option value="1 GB" <?= $itemQuota === '1 GB' ? 'selected' : '' ?>>1 GB</option>
                              <option value="5 GB" <?= $itemQuota === '5 GB' ? 'selected' : '' ?>>5 GB</option>
                              <option value="10 GB" <?= $itemQuota === '10 GB' ? 'selected' : '' ?>>10 GB</option>
                            </select>
                          </td>
                          <td class="nextcloud-col-password"><code class="nextcloud-password-value"><?= $h($item['password'] ?? '') ?></code></td>
                          <td class="nextcloud-col-action">
                            <button type="button" class="btn btn-outline-danger nextcloud-remove-row" aria-label="Eliminar usuario <?= $h($item['userid'] ?? '') ?>" title="Quitar usuario">
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
        <?php $failedUsers = is_array($lastImport['failed_users'] ?? null) ? $lastImport['failed_users'] : []; ?>
        <?php
          $resultUsers = is_array($lastImport['result_users'] ?? null) ? $lastImport['result_users'] : [];
          if (!$resultUsers) {
              foreach ($createdUsers as $item) {
                  $item['status'] = 'created';
                  $item['message'] = $item['message'] ?? 'Creado correctamente.';
                  $resultUsers[] = $item;
              }
              foreach ($existingUsers as $item) {
                  $item['status'] = 'existing';
                  $item['message'] = $item['message'] ?? 'No se creó porque ya existe en Nextcloud.';
                  $resultUsers[] = $item;
              }
              foreach ($failedUsers as $item) {
                  $item['status'] = 'failed';
                  $resultUsers[] = $item;
              }
          }
          foreach ($resultUsers as $idx => $item) {
              $status = (string)($item['status'] ?? '');
              if ($status === 'created') {
                  $resultUsers[$idx]['_status'] = 'Creado';
                  $resultUsers[$idx]['_badge'] = 'success';
                  $resultUsers[$idx]['_row'] = 'table-success nextcloud-row-created';
              } elseif ($status === 'existing') {
                  $resultUsers[$idx]['_status'] = 'Ya existe';
                  $resultUsers[$idx]['_badge'] = 'warning';
                  $resultUsers[$idx]['_row'] = 'table-warning nextcloud-row-existing';
              } else {
                  $resultUsers[$idx]['_status'] = 'No creado';
                  $resultUsers[$idx]['_badge'] = 'danger';
                  $resultUsers[$idx]['_row'] = 'table-danger';
              }
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
                    <div class="text-muted small">Todos los usuarios enviados, indicando si fue creado o no se creó porque ya existía. Disponible en historial por 24 horas.</div>
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
                      <th>Detalle</th>
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
                        <td><?= $h($item['message'] ?? '') ?></td>
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
      <?php include base_path('resources/views/partials/nextcloud-loader.php'); ?>
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

<div class="modal fade detail-drawer-modal" id="nextcloudCredentialsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog detail-drawer-dialog">
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
          <div class="nova-alert-card is-info py-2 small">Hay credenciales guardadas para tu usuario. Puedes dejar los campos vacios para usarlas.</div>
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
            <div class="form-text">Estas credenciales se usarán solo en esta operación. Para guardarlas, usa <a href="<?= htmlspecialchars(route('integrations.nova'), ENT_QUOTES, 'UTF-8') ?>">Mis integraciones de NOVA</a>.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn-nova btn-nova-success" form="nextcloud-preview-form">
          <i class="bi bi-cloud-arrow-up"></i> Crear usuarios
        </button>
      </div>
    </div>
  </div>
</div>

<?php include base_path('RedmineMantencion/views/partials/bootstrap-scripts.php'); ?>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const importForm = document.getElementById('nextcloud-import-form');
  const requesterRut = document.getElementById('nextcloud-requester-rut');
  const requesterEmail = document.getElementById('nextcloud-requester-email');
  const bulkGroup = document.getElementById('nextcloud-bulk-group');
  const bulkQuota = document.getElementById('nextcloud-bulk-quota');
  const applyChanges = document.getElementById('nextcloud-apply-changes');
  const bulkSelected = document.getElementById('nextcloud-bulk-selected');
  const checkAll = document.getElementById('nextcloud-check-all');
  const confirmBtn = document.getElementById('nextcloud-confirm-btn');
  const previewForm = document.getElementById('nextcloud-preview-form');
  const hasSavedNextcloudCredentials = <?= $hasSavedNextcloudCredentials ? 'true' : 'false' ?>;
  const nextcloudRuntimeUserInput = document.getElementById('nextcloud-runtime-user-input');
  const nextcloudRuntimePassInput = document.getElementById('nextcloud-runtime-pass-input');
  const nextcloudRuntimeUserHidden = document.getElementById('nextcloud-runtime-user-hidden');
  const nextcloudRuntimePassHidden = document.getElementById('nextcloud-runtime-pass-hidden');
  const nextcloudCredentialsModal = document.getElementById('nextcloudCredentialsModal');
  const nextcloudLoadingOverlay = document.getElementById('nextcloud-loading-overlay');
  const nextcloudLoadingProgressBar = document.getElementById('nextcloud-loading-progress-bar');
  const nextcloudLoadingPercent = document.getElementById('nextcloud-loading-percent');
  const nextcloudLoadingText = document.getElementById('nextcloud-loading-text');
  const nextcloudLoadingStep = document.getElementById('nextcloud-loading-step');
  const getRows = () => Array.from(document.querySelectorAll('[data-nextcloud-row]'));
  const keepBulkValue = '__keep__';
  let nextcloudProgressTimer = null;
  let nextcloudSubmitAccepted = false;

  function formatRequesterRut(value) {
    const clean = String(value || '').replace(/[^0-9kK]/g, '').toUpperCase().slice(0, 9);
    if (clean.length <= 1) return clean;

    const number = clean.slice(0, -1);
    const verifier = clean.slice(-1);
    const dottedNumber = number.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return `${dottedNumber}-${verifier}`;
  }

  function isValidRequesterRut(value) {
    const clean = String(value || '').replace(/[^0-9kK]/g, '').toLowerCase();
    if (!/^\d{7,8}[0-9k]$/.test(clean)) return false;

    const number = clean.slice(0, -1);
    const verifier = clean.slice(-1);
    let factor = 2;
    let sum = 0;
    for (let index = number.length - 1; index >= 0; index -= 1) {
      sum += Number(number[index]) * factor;
      factor = factor === 7 ? 2 : factor + 1;
    }

    const remainder = 11 - (sum % 11);
    const expected = remainder === 11 ? '0' : remainder === 10 ? 'k' : String(remainder);
    return expected === verifier;
  }

  function updateRequesterRutState(showFeedback = false) {
    if (!requesterRut) return true;
    const hasValue = requesterRut.value.trim() !== '';
    const valid = !hasValue || isValidRequesterRut(requesterRut.value);
    requesterRut.setCustomValidity(valid ? '' : 'Ingresa un RUT válido, incluido su dígito verificador.');
    requesterRut.classList.toggle('is-invalid', showFeedback && !valid);
    requesterRut.classList.toggle('is-valid', showFeedback && hasValue && valid);
    return valid;
  }

  function isValidRequesterEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(value || '').trim());
  }

  function updateRequesterEmailState(showFeedback = false) {
    if (!requesterEmail) return true;
    const hasValue = requesterEmail.value.trim() !== '';
    const valid = !hasValue || isValidRequesterEmail(requesterEmail.value);
    requesterEmail.setCustomValidity(valid ? '' : 'Si ingresas un correo, debe tener un formato válido.');
    requesterEmail.classList.toggle('is-invalid', showFeedback && !valid);
    requesterEmail.classList.toggle('is-valid', showFeedback && hasValue && valid);
    return valid;
  }

  if (requesterRut) {
    requesterRut.value = formatRequesterRut(requesterRut.value);
    requesterRut.addEventListener('input', () => {
      const cursorAtEnd = requesterRut.selectionStart === requesterRut.value.length;
      requesterRut.value = formatRequesterRut(requesterRut.value);
      updateRequesterRutState(false);
      if (cursorAtEnd) requesterRut.setSelectionRange(requesterRut.value.length, requesterRut.value.length);
    });
    requesterRut.addEventListener('blur', () => {
      requesterRut.value = formatRequesterRut(requesterRut.value);
      updateRequesterRutState(true);
    });
    requesterRut.addEventListener('invalid', () => updateRequesterRutState(true));
    updateRequesterRutState(false);
  }

  if (requesterEmail) {
    requesterEmail.value = requesterEmail.value.trim().toLowerCase();
    requesterEmail.addEventListener('input', () => {
      requesterEmail.value = requesterEmail.value.toLowerCase();
      updateRequesterEmailState(false);
    });
    requesterEmail.addEventListener('blur', () => {
      requesterEmail.value = requesterEmail.value.trim().toLowerCase();
      updateRequesterEmailState(true);
    });
    requesterEmail.addEventListener('invalid', () => updateRequesterEmailState(true));
    updateRequesterEmailState(false);
  }

  if (importForm) {
    importForm.addEventListener('submit', event => {
      if (requesterRut) requesterRut.value = formatRequesterRut(requesterRut.value);
      if (requesterEmail) requesterEmail.value = requesterEmail.value.trim().toLowerCase();
      const rutValid = updateRequesterRutState(true);
      const emailValid = updateRequesterEmailState(true);
      if (rutValid && emailValid) return;

      event.preventDefault();
      const firstInvalid = !rutValid ? requesterRut : requesterEmail;
      firstInvalid?.focus();
      firstInvalid?.reportValidity();
    });
  }

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
    const selectedRows = getRows().filter(row => row.querySelector('.nextcloud-row-check')?.checked);
    const invalidEmail = selectedRows.some(row => Array.from(row.querySelectorAll('.badge')).some(badge => badge.textContent.trim() === 'Correo inválido'));
    const duplicateRows = selectedRows.some(row => Array.from(row.querySelectorAll('.badge')).some(badge => badge.textContent.trim() === 'Duplicado'));
    confirmBtn.disabled = confirmBtn.dataset.maintenance === '1' || selectedRows.length === 0 || invalidEmail || duplicateRows;
  }

  function hasSelectedRows() {
    return document.querySelectorAll('.nextcloud-row-check:checked').length > 0;
  }

  function selectedBulkGroup() {
    if (!bulkGroup || bulkGroup.value === keepBulkValue) return '';
    return bulkGroup.value.trim();
  }

  function hasBulkQuotaChange() {
    return Boolean(bulkQuota && bulkQuota.value !== keepBulkValue);
  }

  function updateApplyState() {
    if (!applyChanges) return;
    const hasBulkChange = selectedBulkGroup() !== '' || hasBulkQuotaChange();
    applyChanges.disabled = !hasBulkChange || !hasSelectedRows();
  }

  function updateSelectionState() {
    const checks = Array.from(document.querySelectorAll('.nextcloud-row-check'));
    const selectedCount = checks.filter(check => check.checked).length;
    checks.forEach(check => {
      check.closest('[data-nextcloud-row]')?.classList.toggle('is-selected', check.checked);
    });
    if (checkAll) {
      checkAll.checked = checks.length > 0 && selectedCount === checks.length;
      checkAll.indeterminate = selectedCount > 0 && selectedCount < checks.length;
    }
    if (bulkSelected) bulkSelected.textContent = `${selectedCount} seleccionado${selectedCount === 1 ? '' : 's'}`;
    updateApplyState();
    updateConfirmState();
  }

  const select2Available = Boolean(window.jQuery?.fn?.select2);
  if (select2Available) {
    window.jQuery('.nextcloud-group-select').each(function () {
      const select = window.jQuery(this);
      select.select2({
        width: '100%',
        allowClear: false,
        placeholder: this.dataset.placeholder || 'Seleccionar grupo',
        language: {
          noResults: () => 'No se encontraron grupos',
          searching: () => 'Buscando grupos...'
        }
      });
    });
  }

  if (bulkGroup) {
    bulkGroup.addEventListener('change', updateApplyState);
    if (select2Available) window.jQuery(bulkGroup).on('change.novaBulk', updateApplyState);
  }

  document.querySelectorAll('.nextcloud-row-group-select').forEach(select => {
    select.addEventListener('change', updateConfirmState);
  });

  if (checkAll) {
    checkAll.addEventListener('change', () => {
      document.querySelectorAll('.nextcloud-row-check').forEach(check => {
        check.checked = checkAll.checked;
      });
      updateSelectionState();
    });
  }

  document.querySelectorAll('.nextcloud-row-check').forEach(check => {
    check.addEventListener('change', updateSelectionState);
  });

  document.querySelectorAll('.nextcloud-remove-row').forEach(button => {
    button.addEventListener('click', () => {
      const row = button.closest('[data-nextcloud-row]');
      if (row) row.remove();
      updateSelectionState();
    });
  });

  if (bulkQuota) {
    bulkQuota.addEventListener('change', updateApplyState);
  }

  if (applyChanges) {
    applyChanges.addEventListener('click', () => {
      const groupToApply = selectedBulkGroup();
      const applyQuota = hasBulkQuotaChange();
      if (!groupToApply && !applyQuota) return;
      const selectedChecks = Array.from(document.querySelectorAll('.nextcloud-row-check:checked'));
      selectedChecks.forEach(check => {
        const row = check.closest('[data-nextcloud-row]');
        const groupSelect = row ? row.querySelector('.nextcloud-row-group-select') : null;
        const quota = row ? row.querySelector('.nextcloud-row-quota') : null;
        if (groupToApply && groupSelect) {
          groupSelect.value = groupToApply;
          if (select2Available) window.jQuery(groupSelect).trigger('change.select2');
        }
        if (applyQuota && bulkQuota && quota) {
          quota.value = bulkQuota.value;
        }
      });
      updateApplyState();
      updateConfirmState();
      const changes = [groupToApply ? `grupo ${groupToApply}` : '', applyQuota ? 'cuota' : ''].filter(Boolean).join(' y ');
      window.NovaToast?.success(`Se aplicó ${changes} a ${selectedChecks.length} usuario${selectedChecks.length === 1 ? '' : 's'}.`, 'Cambios aplicados');
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
      if (!hasSelectedRows()) {
        event.preventDefault();
        window.appModal?.show({
          title: 'Selecciona usuarios',
          message: 'Marca al menos un usuario antes de confirmar la creación.',
          tone: 'warning'
        });
        updateSelectionState();
        return;
      }
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

  updateSelectionState();

});
</script>
</body>
</html>
