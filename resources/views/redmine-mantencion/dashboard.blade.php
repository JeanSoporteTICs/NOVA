@php
if (!function_exists('mantencion_dashboard_format_date_display')) {
    function mantencion_dashboard_format_date_display($dateValue, $timeValue = '') {
        $value = trim((string)($dateValue ?? ''));
        $time = trim((string)($timeValue ?? ''));
        if ($value === '') {
            return $time !== '' ? $time : '-';
        }

        if (preg_match('/^(.+?)\s+(\d{1,2}:\d{2}(?::\d{2})?)/', $value, $matches)) {
            $value = trim($matches[1]);
            if ($time === '') {
                $time = substr($matches[2], 0, 5);
            }
        }

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof \DateTimeImmutable) {
                return trim($date->format('d-m-Y') . ' ' . $time);
            }
        }

        return trim($value . ' ' . $time);
    }
}
@endphp

<!DOCTYPE html>

<html lang="es">

<head>

  <?php $pageTitle = 'Reportes'; $includeTheme = true; include base_path('RedmineMantencion/views/partials/bootstrap-head.php'); ?>

</head>

<body class="bg-light">

<?php $activeNav = 'mensajes'; include base_path('RedmineMantencion/views/partials/navbar.php'); ?>

<div id="page-content">
<?php $dashboardCssVersion = @filemtime(base_path('RedmineMantencion/assets/css/dashboard.css')) ?: time(); ?>
<link rel="stylesheet" href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/dashboard.css?v=<?= (int)$dashboardCssVersion ?>">
<div class="container-fluid py-4">
<div class="dashboard-shell">

  <?php
    $heroIcon = 'bi-speedometer2';
    $heroTitle = 'Reportes';
    $heroSubtitle = 'Panel de estados locales';
    $heroExtras = '<span class="badge bg-white bg-opacity-25 text-white border border-white"><i class="bi bi-clock-history"></i> Retención automática: ' . $h($retencionHoras) . ' h</span>'
      . '<span class="badge bg-white bg-opacity-25 text-white border border-white"><i class="bi bi-arrow-repeat"></i> Estado Redmine: ' . $h($estadoRedmineNombre ?: 'No definido') . '</span>';
    include base_path('RedmineMantencion/views/partials/hero.php');
  ?>

  <?php if ($flash): ?>
    <div data-nova-flash="success" data-nova-flash-message="<?= $h($flash) ?>" hidden></div>
  <?php endif; ?>

  <?php if ($canImportCore): ?>
  <form method="post" action="<?= $h($dashboardActionUrl) ?>" class="dashboard-panel" id="core-import-form" data-app-no-loading="1" data-no-page-loader="true">
    <input type="hidden" name="_token" value="<?= $h(function_exists('csrf_token') ? csrf_token() : '') ?>">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <input type="hidden" name="action" value="import_core_history">
    <input type="hidden" name="core_runtime_user" id="core-runtime-user-hidden" value="">
    <input type="hidden" name="core_runtime_pass" id="core-runtime-pass-hidden" value="">
    <input type="hidden" name="core_remember_credentials" id="core-remember-hidden" value="0">
    <div class="dashboard-panel__header">
      <div>
        <h3 class="dashboard-panel__title">Consulta rápida a CORE</h3>
        <p class="dashboard-panel__desc">Trae solicitudes por rango de fechas y usuario asignado con un flujo más claro.</p>
      </div>
    </div>
    <div class="dashboard-import-grid">
      <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label">CORE desde</label>
        <input type="date" name="core_desde" class="form-control" value="<?= $h($coreDesde) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">CORE hasta</label>
        <input type="date" name="core_hasta" class="form-control" value="<?= $h($coreHasta) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Asignado CORE</label>
        <?php if ($coreImportService->dashboard_can_select_core_assignee()): ?>
          <select name="core_assigned_name" class="form-select">
            <?php foreach ($userOptions as $userOption): ?>
              <?php $optionName = trim((string)($userOption['nombre'] ?? '')); ?>
              <?php if ($optionName === '') continue; ?>
              <option value="<?= $h($optionName) ?>" <?= $optionName === (string)$coreAssignedName ? 'selected' : '' ?>>
                <?= $h($optionName) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input type="text" class="form-control" value="<?= $h($coreAssignedName) ?>" readonly>
          <input type="hidden" name="core_assigned_name" value="<?= $h($coreAssignedName) ?>">
        <?php endif; ?>
      </div>
      </div>
      <button type="<?= $hasSavedCoreCredentials ? 'submit' : 'button' ?>" class="btn-nova btn-nova-primary dashboard-import-button" <?= $hasSavedCoreCredentials ? '' : 'data-bs-toggle="modal" data-bs-target="#coreCredentialsModal"' ?> <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>>
        <i class="bi bi-cloud-download"></i> Importar desde CORE
      </button>
    </div>
    <div class="dashboard-core-loading" id="dashboard-core-loading" role="status" aria-live="polite" aria-hidden="true">
      <img src="<?= $h($mantencionBaseUrl) ?>/assets/img/animacion-carga.gif" alt="">
      <div class="dashboard-core-loading__body">
        <strong>Importando solicitudes desde CORE</strong>
        <span>Validando credenciales y consultando registros...</span>
        <div class="dashboard-core-loading__bar" aria-hidden="true"><span></span></div>
      </div>
    </div>
  </form>
  <?php endif; ?>



  <div class="dashboard-stats" id="status-filters">
    <section class="dashboard-stat dashboard-stat--pending is-active" data-filter="pendiente" role="button" tabindex="0">
      <div class="dashboard-stat__top">
        <span class="dashboard-stat__icon"><i class="bi bi-hourglass-split"></i></span>
        <div class="dashboard-stat__content">
          <div class="dashboard-stat__value"><?= count($pendientes) ?></div>
          <div class="dashboard-stat__label">Pendientes por revisar</div>
        </div>
      </div>
    </section>
    <section class="dashboard-stat dashboard-stat--processed" data-filter="procesado" role="button" tabindex="0">
      <div class="dashboard-stat__top">
        <span class="dashboard-stat__icon"><i class="bi bi-check2-circle"></i></span>
        <div class="dashboard-stat__content">
          <div class="dashboard-stat__value"><?= count($procesados) ?></div>
          <div class="dashboard-stat__label">Procesados correctamente</div>
        </div>
      </div>
    </section>
    <section class="dashboard-stat dashboard-stat--error" data-filter="error" role="button" tabindex="0">
      <div class="dashboard-stat__top">
        <span class="dashboard-stat__icon"><i class="bi bi-exclamation-octagon"></i></span>
        <div class="dashboard-stat__content">
          <div class="dashboard-stat__value"><?= count($errores) ?></div>
          <div class="dashboard-stat__label">Errores pendientes</div>
        </div>
      </div>
    </section>
  </div>

  <div class="card dashboard-table-card" id="dashboard-table-card">

    <div class="card-body">
      <div class="dashboard-table-header">
        <div>
          <h3>Solicitudes activas</h3>
          <div class="dashboard-table-subtitle">Gestiona la cola actual con mejor visibilidad del estado y de las acciones disponibles.</div>
        </div>
        <div class="dashboard-table-header__meta">
          <label class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" role="switch" id="dashboard-compact-toggle">
            <span class="form-check-label fw-semibold">Modo compacto</span>
          </label>
        </div>
      </div>

      <div class="dashboard-active-chips" id="dashboard-active-chips" aria-live="polite"></div>

      <?php if ($canUnlockProcessedActions): ?>
      <div class="dashboard-toolbar px-3 pt-3">
        <div class="dashboard-toolbar__actions">
          <?php if ($canSelectReports): ?>
          <span class="dashboard-selection"><i class="bi bi-check2-square"></i> Seleccionados: <strong id="selection-count">0</strong></span>
          <?php endif; ?>
          <div class="dashboard-toolbar__button-group">
            <button type="button" id="processed-edit-toggle" class="btn-nova btn-nova-primary btn-icon d-none" aria-pressed="false" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>>
              <i class="bi bi-unlock"></i> Habilitar acciones
            </button>
            <?php if ($canEditReports): ?>
            <button type="button" id="process-btn" class="btn-nova btn-nova-success btn-icon d-none" disabled <?= $maintenanceMode ? 'title="Plataforma en mantención"' : '' ?>>
              <i class="bi bi-check2-circle"></i> Enviar reportes a Redmine
            </button>
            <button type="button" id="archive-btn" class="btn-nova btn-nova-warning btn-icon d-none" disabled <?= $maintenanceMode ? 'title="Plataforma en mantención"' : '' ?>>
              <i class="bi bi-archive"></i> Archivar
            </button>
            <?php endif; ?>
            <?php if ($canDeleteReports): ?>
            <button type="button" id="delete-selected-btn" class="btn-nova btn-nova-danger btn-icon" disabled <?= $maintenanceMode ? 'title="Plataforma en mantención"' : '' ?>>
              <i class="bi bi-trash3"></i> Eliminar seleccionados
            </button>
            <?php endif; ?>
            <?php if ($canEditReports): ?>
            <button type="button" id="reset-errors-btn" class="btn-nova btn-nova-secondary btn-icon d-none" disabled <?= $maintenanceMode ? 'title="Plataforma en mantención"' : '' ?>>
              <i class="bi bi-arrow-counterclockwise"></i> Reintentar errores (marcar pendientes)
            </button>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <div class="table-responsive rm-table-wrap dashboard-table-wrap">

        <table class="table table-striped align-middle w-100 dashboard-table">

          <thead class="table-light position-sticky top-0">

            <tr>

              <?php if ($canSelectReports): ?>
              <th class="dashboard-select-cell">
                <div class="dashboard-select-control">
                  <input type="checkbox" id="sel-all-top">
                </div>
              </th>
              <?php endif; ?>
              <th>Redmine ID</th>

              <th>Asunto</th>

              <th>Solicitante</th>

              <th>Fecha creación</th>

              <th>Categoría</th>

              <th>Departamento</th>

              <th>Estado local</th>

              <th class="nova-col-actions text-center">Acciones</th>

            </tr>

          </thead>

          <tbody>

          <?php if (!$messages): ?>
            <tr id="dashboard-empty-row"><td colspan="<?= $canSelectReports ? 9 : 8 ?>" class="nova-empty"><i class="bi bi-inbox" style="font-size:1.5rem;display:block;margin-bottom:.4rem;opacity:.35"></i>No hay solicitudes disponibles.</td></tr>
          <?php endif; ?>
          <?php foreach ($messages as $m): ?>

            <?php
              $asunto = ($m['asunto'] ?? '') ?: ($m['mensaje'] ?? '');
              $estado = strtolower($m['estado'] ?? '');
              $idAsignado = $m['asignado_a'] ?? '';
              $assignFromMap = $userMap[$idAsignado] ?? '';
              $asignadoNombre = $m['asignado_nombre'] ?? $assignFromMap ?: $idAsignado;
              $displayAsignado = $asignadoNombre;
              $displayDepartamento = dashboard_resolve_department_value($m);
              $coreStatusIndicator = $coreImportService->dashboard_core_status_indicator($m);
              $isManualSource = dashboard_normalize_text((string)($m['fuente'] ?? '')) === 'manual'
                || str_starts_with((string)($m['id'] ?? ''), 'manual-');
            ?>

            <tr
              data-id="<?= $h($m['id'] ?? '') ?>"
              data-status="<?= $h($estado) ?>"
              data-cat="<?= $h(strtolower($m['categoria'] ?? '')) ?>"
              data-unit="<?= $h(strtolower($m['unidad'] ?? '')) ?>"
              data-user="<?= $h(strtolower($asignadoNombre)) ?>"
              data-horaextra="<?= $h(strtolower($m['hora_extra'] ?? '')) ?>"
              data-text="<?= $h(strtolower($asunto . ' ' . ($m['solicitante'] ?? '') . ' ' . ($m['numero'] ?? ''))) ?>"
            >

              <?php if ($canSelectReports): ?>
              <td class="dashboard-select-cell">
                <div class="dashboard-select-control">
                  <input type="checkbox" class="msg-check" value="<?= $h($m['id'] ?? '') ?>">
                  <?php if ($coreStatusIndicator): ?>
                    <span class="badge rounded-circle text-bg-<?= $h($coreStatusIndicator['badge']) ?> p-2 action-tooltip"
                          data-bs-placement="top"
                          title="CORE: <?= $h($coreStatusIndicator['label']) ?>"
                          aria-label="CORE: <?= $h($coreStatusIndicator['label']) ?>">
                      <i class="bi <?= $h($coreStatusIndicator['icon']) ?>"></i>
                    </span>
                  <?php elseif ($isManualSource): ?>
                    <span class="badge rounded-circle p-2 action-tooltip dashboard-source-indicator is-manual"
                          data-bs-placement="top"
                          title="Origen: Creación manual"
                          aria-label="Origen: Creación manual">
                      <i class="bi bi-pencil-fill"></i>
                    </span>
                  <?php endif; ?>
                </div>
              </td>
              <?php endif; ?>
              <td><?= $h($m['redmine_id'] ?? '') ?></td>

              <td>
                <div class="dashboard-table__subject" title="<?= $h($asunto) ?>"><?= $h($asunto) ?></div>
              </td>

              <td><?= $h($m['solicitante'] ?? '') ?></td>

              <td><?= $h(mantencion_dashboard_format_date_display($m['core_fecha_creacion'] ?? ($m['fecha'] ?? ''), $m['hora'] ?? '')) ?></td>

              <td><?= $h($m['categoria'] ?? '-') ?></td>

              <td><?= $h($displayDepartamento) ?></td>

              <?php
                $statusIconClass = $estado === 'pendiente' ? 'dashboard-status-icon--pending' : ($estado === 'procesado' ? 'dashboard-status-icon--processed' : 'dashboard-status-icon--error');
                $statusIcon = $estado === 'pendiente' ? 'bi-hourglass-split' : ($estado === 'procesado' ? 'bi-check2' : 'bi-exclamation-lg');
              ?>
              <td>
                <span class="dashboard-status-icon <?= $statusIconClass ?> action-tooltip" data-bs-placement="top" title="<?= $h(ucfirst($m['estado'] ?? '')) ?>">
                  <i class="bi <?= $statusIcon ?>"></i>
                </span>
              </td>

              <td class="nova-col-actions">
                <div class="dashboard-row-actions">

                <?php
                  $previewRows = dashboard_detail_preview_rows($m);
                  $previewRowsJson = $h((string)json_encode(array_values($previewRows), JSON_UNESCAPED_UNICODE));
                  $previewColumnsJson = $h((string)json_encode(dashboard_core_detail_table_schema($m), JSON_UNESCAPED_UNICODE));
                ?>
                <button type="button" class="btn-action btn-action-view action-tooltip" data-bs-toggle="modal" data-bs-target="#detalleModal" data-bs-placement="top" title="<?= $canEditReports ? 'Detalle / Editar' : 'Detalle' ?>" aria-label="<?= $canEditReports ? 'Detalle / Editar' : 'Detalle' ?>" <?= $canEditReports ? 'data-processed-action' : '' ?>

                  data-id="<?= $h($m['id'] ?? '') ?>"

                  data-fuente="<?= $h($m['fuente'] ?? '') ?>"

                  data-tipo="<?= $h($m['tipo'] ?? '') ?>"

                  data-estado="<?= $h($m['estado'] ?? '') ?>"

                  data-asunto="<?= $h($asunto) ?>"

                  data-prioridad="<?= $h($m['prioridad'] ?? '') ?>"

                  data-categoria="<?= $h($m['categoria'] ?? '') ?>"

                  data-asignado_a="<?= $h($m['asignado_a'] ?? '') ?>"
                  data-asignado_nombre="<?= $h($asignadoNombre) ?>"

                  data-solicitante="<?= $h($m['solicitante'] ?? '') ?>"

                  data-establecimiento="<?= $h($m['core_establecimiento'] ?? ($m['unidad_solicitante'] ?? '')) ?>"

                  data-departamento="<?= $h($displayDepartamento) ?>"

                  data-hora_extra="<?= $h($m['hora_extra'] ?? '') ?>"

                  data-fecha_inicio="<?= $h($m['fecha_inicio'] ?? '') ?>"

                  data-fecha_fin="<?= $h($m['fecha_fin'] ?? '') ?>"

                  data-tiempo_estimado="<?= $h($m['tiempo_estimado'] ?? '') ?>"

                  data-fecha="<?= $h($m['fecha'] ?? '') ?>"

                  data-hora="<?= $h($m['hora'] ?? '') ?>"

                  data-numero="<?= $h($m['numero'] ?? '') ?>"
                  data-descripcion="<?= $h($m['descripcion'] ?? '') ?>"
                  data-core_fecha_creacion="<?= $h($m['core_fecha_creacion'] ?? '') ?>"
                  data-core_tipo_solicitud="<?= $h($m['core_tipo_solicitud'] ?? '') ?>"
                  data-core_establecimiento="<?= $h($m['core_establecimiento'] ?? '') ?>"
                  data-core_departamento="<?= $h($m['core_departamento'] ?? '') ?>"
                  data-core_usuario_asignado="<?= $h($m['core_usuario_asignado'] ?? '') ?>"
                  data-core_estado="<?= $h($m['core_estado'] ?? '') ?>"
                  data-core_telefono="<?= $h($m['core_telefono'] ?? '') ?>"
                  data-core_celular="<?= $h($m['core_celular'] ?? '') ?>"
                  data-core_email="<?= $h($m['core_email'] ?? '') ?>"
                  data-preview_rows="<?= $previewRowsJson ?>"
                  data-preview_columns="<?= $previewColumnsJson ?>"

                ><i class="bi <?= $canEditReports ? 'bi-pencil-square' : 'bi-eye' ?>"></i></button>
                <?php
                  $hasHoraExtra = function_exists('normalize_hour_extra_value') && normalize_hour_extra_value($m['hora_extra'] ?? '') === '1';
                ?>
                <?php if ($canEditHoursExtra): ?>
                <form method="post" action="<?= $h($dashboardHoursExtraActionUrl) ?>" data-app-no-loading="1" data-no-page-loader="true"
                      data-optimistic-toggle
                      data-toggle-active-icon="bi-clock-fill" data-toggle-inactive-icon="bi-clock"
                      data-toggle-active-class="btn-hora-extra--on" data-toggle-inactive-class="btn-hora-extra--off"
                      data-toggle-active-title="Hora extra: Sí. Cambiar a No" data-toggle-inactive-title="Hora extra: No. Cambiar a Sí">
                  <input type="hidden" name="_token" value="<?= $h(function_exists('csrf_token') ? csrf_token() : '') ?>">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="ajax" value="1">
                  <input type="hidden" name="id" value="<?= $h($m['id'] ?? '') ?>">
                  <input type="hidden" name="action" value="toggle_hora_extra">
                  <button
                    class="btn-action btn-action-sync action-tooltip <?= $hasHoraExtra ? 'btn-hora-extra--on' : 'btn-hora-extra--off' ?>"
                    type="submit"
                    data-processed-action
                    data-bs-placement="top"
                    title="<?= $maintenanceMode ? 'Plataforma en mantención' : ($hasHoraExtra ? 'Hora extra: Sí. Cambiar a No' : 'Hora extra: No. Cambiar a Sí') ?>"
                    aria-label="<?= $maintenanceMode ? 'Plataforma en mantención' : ($hasHoraExtra ? 'Hora extra: Sí. Cambiar a No' : 'Hora extra: No. Cambiar a Sí') ?>"
                    <?= $maintenanceMode ? 'disabled' : '' ?>
                  >
                    <i class="bi <?= $hasHoraExtra ? 'bi-clock-fill' : 'bi-clock' ?>"></i>
                  </button>
                </form>
                <?php endif; ?>
                <?php if (strtolower($m['estado'] ?? '') === 'error'): ?>
                  <?php
                    $logText = '';
                    if (!empty($m['id']) && isset($logsByMessage[$m['id']])) {
                        $logText = (string)$logsByMessage[$m['id']];
                    }
                  ?>
                  <button type="button" class="btn-action btn-action-view log-btn action-tooltip" data-log="<?= $h($logText) ?>" data-bs-toggle="modal" data-bs-target="#logModal" data-bs-placement="top" title="Log" aria-label="Log"><i class="bi bi-journal-text"></i></button>
                <?php endif; ?>

                <?php if ($canDeleteReports): ?>
                <form method="post" action="<?= $h($dashboardActionUrl) ?>" data-app-confirm="¿Eliminar este mensaje?" data-dashboard-ajax="row" data-app-no-loading="1">
                  <input type="hidden" name="_token" value="<?= $h(function_exists('csrf_token') ? csrf_token() : '') ?>">
                  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="id" value="<?= $h($m['id'] ?? '') ?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="btn-action btn-action-delete action-tooltip" type="submit" data-processed-action data-bs-placement="top" title="<?= $maintenanceMode ? 'Plataforma en mantención' : 'Eliminar' ?>" aria-label="<?= $maintenanceMode ? 'Plataforma en mantención' : 'Eliminar' ?>" <?= $maintenanceMode ? 'disabled' : '' ?>><i class="bi bi-trash3"></i></button>
                </form>
                <?php endif; ?>
                </div>
              </td>

            </tr>

          <?php endforeach; ?>

          </tbody>

        </table>

      </div>

    </div>

  </div>

</div>



<div class="core-import-overlay" id="core-import-overlay" role="status" aria-live="polite" aria-hidden="true">
  <div class="core-import-card">
    <div class="core-import-card__media">
      <img
        class="core-import-card__gif"
        id="dashboard-progress-gif"
        src="<?= $h($mantencionBaseUrl) ?>/assets/img/animacion-carga.gif"
        data-core-src="<?= $h($mantencionBaseUrl) ?>/assets/img/animacion-carga.gif"
        data-redmine-src="<?= $h($mantencionBaseUrl) ?>/assets/img/redmine.gif"
        alt=""
      >
    </div>
    <div class="core-import-card__header">
      <div class="core-import-card__icon">
        <i class="bi bi-cloud-download"></i>
      </div>
      <div>
        <h3 class="core-import-card__title">Importando desde CORE</h3>
        <p class="core-import-card__text" id="core-import-progress-text">Conectando con CORE...</p>
      </div>
    </div>
    <div class="core-import-progress" aria-label="Progreso de importación">
      <div class="core-import-progress__bar" id="core-import-progress-bar"></div>
    </div>
    <div class="core-import-card__meta">
      <span id="core-import-progress-step">Preparando consulta</span>
      <span id="core-import-progress-percent">0%</span>
    </div>
  </div>
</div>

  <form id="process-form" method="post" action="<?= $h($dashboardActionUrl) ?>" class="d-none">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <input type="hidden" name="action" id="process-action" value="process_selected">
    <input type="hidden" name="ids" id="process-ids">
  </form>

<datalist id="cat-list">

  <?php foreach ($catOptions as $c): ?>

    <option value="<?= $h($c) ?>"></option>

  <?php endforeach; ?>

</datalist>



<datalist id="unit-list">

  <?php foreach ($unitOptions as $u): ?>

    <option value="<?= $h($u) ?>"></option>

  <?php endforeach; ?>

</datalist>

<datalist id="tipo-list">
  <?php foreach ($tipoOptions as $t): ?>
    <option value="<?= $h($t) ?>"></option>
  <?php endforeach; ?>
</datalist>

<datalist id="prioridad-list">
  <?php foreach ($prioridadOptions as $p): ?>
    <option value="<?= $h($p) ?>"></option>
  <?php endforeach; ?>
</datalist>

<datalist id="estado-list">
  <?php foreach ($estadoOptions as $e): ?>
    <option value="<?= $h($e) ?>"></option>
  <?php endforeach; ?>
</datalist>

<datalist id="user-list">
  <?php foreach ($userOptions as $u): ?>
    <option value="<?= $h($u['nombre']) ?>" data-id="<?= $h($u['id']) ?>"></option>
  <?php endforeach; ?>
</datalist>
<datalist id="estado-error-list">
  <option value="pendiente"></option>
</datalist>



<div class="modal fade" id="coreCredentialsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Credenciales CORE</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="core-credentials-animation" aria-hidden="true">
          <img src="<?= $h($mantencionBaseUrl) ?>/assets/img/animacion-carga.gif" alt="">
        </div>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Usuario CORE</label>
            <input type="text" class="form-control" id="core-runtime-user-input" placeholder="RUT sin DV o email" autocomplete="username" value="<?= $h($coreRuntimeUserSession) ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Contraseña CORE</label>
            <div class="input-group">
              <input type="password" class="form-control" id="core-runtime-pass-input" placeholder="Solo se usa para esta consulta" autocomplete="current-password">
              <button class="btn btn-outline-secondary" type="button" id="core-toggle-password" aria-label="Ver contraseña" title="Ver contraseña">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="core-remember-input">
              <label class="form-check-label" for="core-remember-input">Recordar credenciales CORE para mi usuario</label>
            </div>
            <div class="form-text">La contraseña se guarda cifrada y no se volverá a mostrar en pantalla.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-nova btn-nova-primary" id="core-credentials-submit-btn">Consultar CORE</button>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const button = document.getElementById('core-toggle-password');
  const input = document.getElementById('core-runtime-pass-input');
  if (!button || !input) return;

  button.addEventListener('click', function (event) {
    event.preventDefault();
    event.stopPropagation();

    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    button.setAttribute('aria-label', showing ? 'Ver contraseña' : 'Ocultar contraseña');
    button.setAttribute('title', showing ? 'Ver contraseña' : 'Ocultar contraseña');
    button.innerHTML = showing ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
    input.focus();
  });
})();
</script>

<div class="modal fade detail-drawer-modal" id="detalleModal" tabindex="-1" aria-hidden="true">

  <div class="modal-dialog modal-dialog-scrollable detail-drawer-dialog">

    <div class="modal-content">

      <form method="post" action="<?= $h($dashboardActionUrl) ?>" data-dashboard-ajax="row" data-app-no-loading="1" data-no-page-loader="true">
        <input type="hidden" name="_token" value="<?= $h(function_exists('csrf_token') ? csrf_token() : '') ?>">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">

        <div class="modal-header">

          <div>
            <p class="detail-drawer-kicker">Reporte seleccionado</p>
            <h5 class="modal-title">
              <span class="detail-drawer-icon"><i class="bi <?= $canEditReports ? 'bi-pencil-square' : 'bi-eye' ?>"></i></span>
              <?= $canEditReports ? 'Detalle / Editar' : 'Detalle' ?>
            </h5>
          </div>

          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>

        </div>

        <div class="modal-body">

          <input type="hidden" name="id" id="md-id">

          <input type="hidden" name="action" value="update">

          <div class="detail-drawer-view is-active" id="drawer-detail-view">

          <div class="row g-3">

            <div class="col-12"><label class="form-label">Asunto</label><textarea name="asunto" id="md-asunto" class="form-control" rows="2"></textarea></div>

            <div class="col-md-4">
              <label class="form-label">Tipo</label>
              <input id="md-tipo" class="form-control" list="tipo-list" disabled>
              <input type="hidden" name="tipo" id="md-tipo-hidden">
            </div>

            <div class="col-md-4 position-relative">
              <label class="form-label">Estado</label>
              <input id="md-estado" class="form-control" list="estado-list" placeholder="pendiente/procesado/error" disabled>
              <input type="hidden" name="estado" id="md-estado-hidden" value="pendiente">
              <!-- <div class="form-text" id="estado-help"></div> -->
            </div>

            <div class="col-md-4">
              <label class="form-label">Prioridad</label>
              <input id="md-prioridad" class="form-control" list="prioridad-list" disabled>
              <input type="hidden" name="prioridad" id="md-prioridad-hidden">
            </div>

            <div class="col-md-6">
              <label class="form-label" for="md-categoria">Categorías</label>
              <select name="categoria" id="md-categoria" class="form-select mantencion-select2" data-mantencion-select2 data-placeholder="Seleccionar categoría">
                <option value=""></option>
                <?php foreach ($catOptions as $categoryOption): ?>
                  <option value="<?= $h($categoryOption) ?>"><?= $h($categoryOption) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6">
              <label class="form-label" for="md-asignado">Asignado a</label>
              <select name="asignado_a" id="md-asignado" class="form-select mantencion-select2" data-mantencion-select2 data-placeholder="Seleccionar usuario activo">
                <option value="">Sin asignar</option>
                <?php foreach ($userOptions as $userOption): ?>
                  <option value="<?= $h($userOption['id']) ?>"><?= $h($userOption['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text" id="md-asignado-help"></div>
            </div>

            <div class="col-md-6"><label class="form-label">Solicitante</label><input name="solicitante" id="md-solicitante" class="form-control"></div>

            <div class="col-md-6"><label class="form-label">Número</label><input name="numero" id="md-numero" class="form-control"></div>

            <div class="col-md-6"><label class="form-label">Correo</label><input name="core_email" id="md-core_email" class="form-control" type="email"></div>

            <div class="col-md-6"><label class="form-label">Establecimiento</label><input name="establecimiento" id="md-establecimiento" class="form-control"></div>
 
            <div class="col-md-6"><label class="form-label">Departamento</label><input name="departamento" id="md-departamento" class="form-control"></div>

            <div class="col-md-6">
              <label class="form-label">Estado Redmine</label>
              <input class="form-control" value="<?= $h($estadoRedmineNombre ?: ($estadoRedmineId ? ('ID ' . $estadoRedmineId) : 'No definido')) ?>" disabled>
            </div>

            <div class="col-md-3"><label class="form-label">Fecha Inicio</label><input type="date" name="fecha_inicio" id="md-fecha_inicio" class="form-control"></div>

            <div class="col-md-3"><label class="form-label">Fecha Fin</label><input type="date" name="fecha_fin" id="md-fecha_fin" class="form-control"></div>

            <div class="col-md-3"><label class="form-label">Fecha</label><input type="date" name="fecha" id="md-fecha" class="form-control"></div>

            <div class="col-md-3"><label class="form-label">Hora</label><input name="hora" id="md-hora" class="form-control"></div>

            <div class="col-md-6">
              <label class="form-label">Hora extra</label>
              <select name="hora_extra" id="md-hora_extra" class="form-select" <?= $canEditReports && $canEditHoursExtra ? '' : 'disabled' ?>>
                <option value="0" selected>No</option>
                <option value="1">Sí</option>
              </select>
              <?php if ($canEditReports && !$canEditHoursExtra): ?>
                <div class="form-text"><i class="bi bi-lock"></i> Requiere el permiso Editar de Horas extra.</div>
              <?php endif; ?>
            </div>

            <div class="col-md-6"><label class="form-label">Tiempo Estimado</label><input name="tiempo_estimado" id="md-tiempo_estimado" class="form-control" <?= $canEditReports && $canEditHoursExtra ? '' : 'disabled' ?>></div>

            <div class="col-12 d-none" id="md-descripcion-wrap">
              <label class="form-label d-block">Descripción</label>
              <input type="hidden" name="descripcion" id="md-descripcion">
              <button type="button" class="btn btn-outline-secondary" id="open-descripcion-modal-btn" data-bs-toggle="modal" data-bs-target="#descripcionModal">
                <i class="bi bi-text-paragraph"></i> <?= $canEditReports ? 'Editar descripción' : 'Ver descripción' ?>
              </button>
            </div>

            <div class="col-12">
              <label class="form-label d-block">Vista previa de la tabla</label>
              <button type="button" class="btn btn-outline-primary" id="open-preview-modal-btn">
                <i class="bi bi-table"></i> Ver tabla
              </button>
            </div>

          </div>

          </div>

          <div class="detail-drawer-view" id="drawer-table-view" aria-hidden="true">
            <div class="detail-drawer-table-header">
              <div>
                <p class="detail-drawer-kicker">Vista previa</p>
                <h6 class="detail-drawer-table-title">Detalle de la tabla</h6>
                <p class="detail-drawer-table-subtitle">Revisa la información recibida antes de volver a editar el reporte.</p>
              </div>
              <button type="button" class="btn btn-outline-primary" id="back-to-detail-btn">
                <i class="bi bi-arrow-left"></i> Volver al detalle
              </button>
            </div>
            <div class="table-responsive detail-preview-wrap">
              <table class="table table-sm mb-0 align-middle">
                <thead id="md-preview-head">
                  <tr>
                    <th>Detalle</th>
                  </tr>
                </thead>
                <tbody id="md-preview-body">
                  <tr>
                    <td colspan="1" class="text-muted text-center">Sin detalle para previsualizar.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

        </div>

        <div class="modal-footer" id="detail-drawer-footer">

          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
            <i class="bi bi-x-lg"></i> Cerrar
          </button>

          <?php if ($canEditReports): ?>
            <button type="submit" class="btn-nova btn-nova-primary" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>>
              <i class="bi bi-check2-circle"></i> Guardar cambios
            </button>
          <?php endif; ?>

        </div>

      </form>

    </div>

  </div>

</div>

<div class="modal fade" id="descripcionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><?= $canEditReports ? 'Editar descripción' : 'Ver descripción' ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="nova-description-tabs" role="tablist" aria-label="Vista de descripción">
          <button type="button" class="nova-description-tab is-active" id="dashboard-description-edit-tab" role="tab" aria-selected="true"><i class="bi <?= $canEditReports ? 'bi-pencil' : 'bi-text-paragraph' ?>"></i> <?= $canEditReports ? 'Modificar' : 'Contenido' ?></button>
          <button type="button" class="nova-description-tab" id="dashboard-description-preview-tab" role="tab" aria-selected="false"><i class="bi bi-table"></i> Previsualizar</button>
        </div>
        <div id="dashboard-description-edit-panel" role="tabpanel" aria-labelledby="dashboard-description-edit-tab">
          <label class="form-label" for="md-descripcion-editor">Descripción</label>
          <textarea id="md-descripcion-editor" class="form-control nova-description-editor" rows="10" <?= $canEditReports ? '' : 'readonly' ?>></textarea>
        </div>
        <div class="nova-description-preview" id="dashboard-description-preview" role="tabpanel" aria-labelledby="dashboard-description-preview-tab" hidden></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        <?php if ($canEditReports): ?>
          <button type="button" class="btn-nova btn-nova-primary" id="save-descripcion-btn" <?= $maintenanceMode ? 'disabled title="Plataforma en mantención"' : '' ?>>Guardar descripción</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="logModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Log de errores (envío plataforma)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <pre class="small bg-light p-3 border rounded" style="white-space: pre-wrap;" id="logModalContent"></pre>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<?php if ($canDeleteReports): ?>
<div class="modal fade" id="deleteSelectedModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirmar eliminación</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0">Se eliminarán <strong id="delete-selected-count">0</strong> mensaje(s) seleccionados. Esta acción no se puede deshacer.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-nova btn-nova-danger" id="confirm-delete-selected-btn">
          <i class="bi bi-trash3"></i> Eliminar seleccionados
        </button>
      </div>
    </div>
  </div>
 </div>
<?php endif; ?>

<button type="button" class="btn btn-primary dashboard-scroll-top" id="dashboard-scroll-top" aria-label="Volver arriba" title="Volver arriba">
  <i class="bi bi-arrow-up"></i>
</button>

<?php include base_path('RedmineMantencion/views/partials/bootstrap-scripts.php'); ?>

<script>

  const dashboardMaintenanceMode = <?= $maintenanceMode ? 'true' : 'false' ?>;
  const dashboardCanEditReports = <?= $canEditReports ? 'true' : 'false' ?>;
  const dashboardScrollKey = 'nova:mantencion-dashboard:scroll-y';
  const dashboardFilterKey = 'nova:mantencion-dashboard:filter';
  const dashboardProcessedEditKey = 'nova:mantencion-dashboard:processed-edit';
  const savedDashboardFilter = sessionStorage.getItem(dashboardFilterKey) || '';
  sessionStorage.removeItem(dashboardFilterKey);

  const savedDashboardScroll = Number(sessionStorage.getItem(dashboardScrollKey) || '');
  if (Number.isFinite(savedDashboardScroll) && savedDashboardScroll >= 0) {
    sessionStorage.removeItem(dashboardScrollKey);
    const restoreDashboardScroll = () => {
      requestAnimationFrame(() => requestAnimationFrame(() => {
        window.scrollTo({ top: savedDashboardScroll, behavior: 'auto' });
      }));
    };
    if (document.readyState === 'complete') {
      restoreDashboardScroll();
    } else {
      window.addEventListener('load', restoreDashboardScroll, { once: true });
    }
  }

  document.addEventListener('submit', event => {
    const form = event.target.closest('form');
    if (!form || form.matches('[data-dashboard-ajax="row"]')) return;
    sessionStorage.setItem(dashboardScrollKey, String(window.scrollY || 0));
  }, true);

  document.querySelectorAll('.action-tooltip').forEach(el => {
    new bootstrap.Tooltip(el);
  });

  const detalleModal = document.getElementById('detalleModal');
  const setMantencionSelectValue = (select, value, label = '') => {
    if (!select) return;
    Array.from(select.options).forEach(option => {
      if (option.dataset.transientOption === '1') option.remove();
    });
    const normalizedValue = String(value || '').trim();
    const hasOption = Array.from(select.options).some(option => option.value === normalizedValue);
    if (normalizedValue !== '' && !hasOption) {
      const transientOption = new Option(label || normalizedValue, normalizedValue, false, false);
      transientOption.dataset.transientOption = '1';
      select.add(transientOption);
    }
    select.value = normalizedValue;
    if (window.jQuery?.fn?.select2) window.jQuery(select).trigger('change.select2');
  };
  const initMantencionDashboardSelect2 = () => {
    if (!window.jQuery?.fn?.select2 || !detalleModal) return;
    const modal = window.jQuery(detalleModal);
    modal.find('[data-mantencion-select2]').each(function () {
      const select = window.jQuery(this);
      if (select.hasClass('select2-hidden-accessible')) return;
      select.select2({
        width: '100%',
        dropdownParent: modal,
        allowClear: false,
        dropdownCssClass: 'tic-select2-dropdown',
        placeholder: this.dataset.placeholder || 'Seleccionar',
        language: {
          noResults: () => 'No se encontraron resultados',
          searching: () => 'Buscando...'
        }
      });
    });
  };
  if (detalleModal && !dashboardCanEditReports) {
    detalleModal.querySelectorAll('input:not([type="hidden"]), textarea, select').forEach(control => {
      control.disabled = true;
    });
  }
  initMantencionDashboardSelect2();
  let currentPreviewRows = [];
  let currentPreviewColumns = [];
  const openPreviewModalBtn = document.getElementById('open-preview-modal-btn');
  const drawerDetailView = document.getElementById('drawer-detail-view');
  const drawerTableView = document.getElementById('drawer-table-view');
  const backToDetailBtn = document.getElementById('back-to-detail-btn');
  const detailDrawerFooter = document.getElementById('detail-drawer-footer');
  const descripcionModal = document.getElementById('descripcionModal');
  const descripcionEditor = document.getElementById('md-descripcion-editor');
  const descripcionHidden = document.getElementById('md-descripcion');
  const saveDescripcionBtn = document.getElementById('save-descripcion-btn');
  const descripcionEditTab = document.getElementById('dashboard-description-edit-tab');
  const descripcionPreviewTab = document.getElementById('dashboard-description-preview-tab');
  const descripcionEditPanel = document.getElementById('dashboard-description-edit-panel');
  const descripcionPreview = document.getElementById('dashboard-description-preview');
  let reopenDetalleModalAfterDescripcion = false;

  const descriptionTableCells = line => line.trim()
    .replace(/^\||\|$/g, '')
    .split('|')
    .map(cell => cell.trim().replace(/<br\s*\/?>/gi, '\n').replace(/\\\|/g, '|'));

  const renderDescriptionPreview = () => {
    if (!descripcionPreview || !descripcionEditor) return;
    descripcionPreview.replaceChildren();
    const value = descripcionEditor.value.trim();
    if (!value) {
      const empty = document.createElement('div');
      empty.className = 'nova-empty-state';
      empty.innerHTML = '<i class="bi bi-text-paragraph"></i><strong>Sin descripción</strong><p>No hay contenido para previsualizar.</p>';
      descripcionPreview.appendChild(empty);
      return;
    }

    const lines = value.split(/\r?\n/).filter(line => line.trim() !== '');
    const tableLines = lines.filter(line => line.includes('|'));
    if (tableLines.length !== lines.length) {
      const text = document.createElement('div');
      text.className = 'nova-description-preview__text';
      text.textContent = value;
      descripcionPreview.appendChild(text);
      return;
    }

    const separatorPattern = /^\s*\|?(?:\s*:?-{3,}:?\s*\|)+\s*$/;
    const hasHeader = lines.length > 1 && separatorPattern.test(lines[1]);
    const wrapper = document.createElement('div');
    wrapper.className = 'table-responsive';
    const table = document.createElement('table');
    table.className = 'table table-sm table-bordered align-middle mb-0 nova-description-table';
    const tbody = document.createElement('tbody');
    const appendRow = (target, line, cellTag) => {
      const row = document.createElement('tr');
      descriptionTableCells(line).forEach(value => {
        const cell = document.createElement(cellTag);
        cell.textContent = value;
        row.appendChild(cell);
      });
      target.appendChild(row);
    };
    if (hasHeader) {
      const thead = document.createElement('thead');
      appendRow(thead, lines[0], 'th');
      table.appendChild(thead);
      lines.slice(2).forEach(line => appendRow(tbody, line, 'td'));
    } else {
      lines.forEach(line => appendRow(tbody, line, 'td'));
    }
    table.appendChild(tbody);
    wrapper.appendChild(table);
    descripcionPreview.appendChild(wrapper);
  };

  const setDescriptionView = preview => {
    if (!descripcionEditPanel || !descripcionPreview) return;
    if (preview) renderDescriptionPreview();
    descripcionEditPanel.hidden = preview;
    descripcionPreview.hidden = !preview;
    descripcionEditTab?.classList.toggle('is-active', !preview);
    descripcionPreviewTab?.classList.toggle('is-active', preview);
    descripcionEditTab?.setAttribute('aria-selected', preview ? 'false' : 'true');
    descripcionPreviewTab?.setAttribute('aria-selected', preview ? 'true' : 'false');
  };
  descripcionEditTab?.addEventListener('click', () => setDescriptionView(false));
  descripcionPreviewTab?.addEventListener('click', () => setDescriptionView(true));

  const setDrawerView = view => {
    const showTable = view === 'table';
    if (drawerDetailView) {
      drawerDetailView.classList.toggle('is-active', !showTable);
      drawerDetailView.setAttribute('aria-hidden', showTable ? 'true' : 'false');
    }
    if (drawerTableView) {
      drawerTableView.classList.toggle('is-active', showTable);
      drawerTableView.setAttribute('aria-hidden', showTable ? 'false' : 'true');
    }
    if (detailDrawerFooter) {
      detailDrawerFooter.classList.toggle('d-none', showTable);
    }
    const modalBody = detalleModal?.querySelector('.modal-body');
    if (modalBody) {
      modalBody.scrollTo({ top: 0, behavior: 'smooth' });
    }
  };

  if (detalleModal) {
  detalleModal.addEventListener('show.bs.modal', event => {

  setDrawerView('detail');

  const btn = event.relatedTarget;
  if (!btn) return;

  const normalizeDateForInput = value => {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
    const match = raw.match(/^(\d{2})-(\d{2})-(\d{4})$/);
    if (match) {
      return `${match[3]}-${match[2]}-${match[1]}`;
    }
    return '';
  };

  const set = (id, key) => {

    const el = document.getElementById(id);

    if (el) el.value = btn.getAttribute(key) || '';

  };

  const setDate = (id, key) => {
    const el = document.getElementById(id);
    if (el) el.value = normalizeDateForInput(btn.getAttribute(key) || '');
  };

  set('md-id', 'data-id');

  set('md-tipo', 'data-tipo');
  set('md-tipo-hidden', 'data-tipo');

  set('md-estado', 'data-estado');
  set('md-estado-hidden', 'data-estado');

  set('md-asunto', 'data-asunto');

  set('md-prioridad', 'data-prioridad');
  set('md-prioridad-hidden', 'data-prioridad');

  setMantencionSelectValue(
    document.getElementById('md-categoria'),
    btn.getAttribute('data-categoria') || '',
    btn.getAttribute('data-categoria') || ''
  );

  setMantencionSelectValue(
    document.getElementById('md-asignado'),
    btn.getAttribute('data-asignado_a') || '',
    btn.getAttribute('data-asignado_nombre') || ''
  );

  set('md-solicitante', 'data-solicitante');

  set('md-establecimiento', 'data-establecimiento');
 
  set('md-departamento', 'data-departamento');

  const horaSel = document.getElementById('md-hora_extra');
  if (horaSel) {
    const hv = (btn.getAttribute('data-hora_extra') || '').toLowerCase();
    horaSel.value = (hv === 'si' || hv === 's\\u00ed' || hv === '1' || hv === 'true') ? '1' : '0';
  }

  setDate('md-fecha_inicio', 'data-fecha_inicio');

  setDate('md-fecha_fin', 'data-fecha_fin');

  set('md-tiempo_estimado', 'data-tiempo_estimado');

  setDate('md-fecha', 'data-fecha');

  set('md-hora', 'data-hora');

  set('md-numero', 'data-numero');

  set('md-descripcion', 'data-descripcion');

  set('md-core_email', 'data-core_email');

  const descripcionWrap = document.getElementById('md-descripcion-wrap');
  if (descripcionWrap) {
    descripcionWrap.classList.toggle('d-none', (btn.getAttribute('data-fuente') || '') !== 'manual');
  }

  const previewHead = document.getElementById('md-preview-head');
  const previewBody = document.getElementById('md-preview-body');
  if (previewBody && previewHead) {
    let previewRows = [];
    let previewColumns = [];
    try {
      previewRows = JSON.parse(btn.getAttribute('data-preview_rows') || '[]');
    } catch (error) {
      previewRows = [];
    }
    try {
      previewColumns = JSON.parse(btn.getAttribute('data-preview_columns') || '[]');
    } catch (error) {
      previewColumns = [];
    }
    const escapeHtml = value => String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
    const normalizeText = value => String(value ?? '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, ' ')
      .trim();
    const resolveDefaultColumns = currentBtn => {
      const fuente = (currentBtn?.getAttribute('data-fuente') || '').trim().toLowerCase();
      if (fuente === 'manual') {
        return [
          { label: 'Tipo solicitud', key: 'detalle_tipo_solicitud' },
          { label: 'Solicitante', key: 'detalle_solicitante' },
          { label: 'Categoría', key: 'detalle_categoria' },
          { label: 'Unidad', key: 'detalle_unidad' },
          { label: 'Descripción', key: 'detalle_descripcion' }
        ];
      }
      const tipoCore = normalizeText(currentBtn?.getAttribute('data-core_tipo_solicitud') || currentBtn?.getAttribute('data-asunto') || '');
      if (tipoCore === 'creacion de usuario' || tipoCore === 'creacion usuario' || (tipoCore.includes('creacion') && tipoCore.includes('usuario'))) {
        return [
          { label: 'Tipo solicitud', key: 'detalle_tipo_solicitud' },
          { label: 'RUN', key: 'detalle_run' },
          { label: 'Nombre', key: 'detalle_nombre' },
          { label: 'Fecha de nacimiento', key: 'detalle_fecha_nacimiento' },
          { label: 'Email', key: 'detalle_email' },
          { label: 'Departamento', key: 'detalle_departamento' },
          { label: 'Cargo', key: 'detalle_cargo' },
          { label: 'Rol', key: 'detalle_rol' }
        ];
      }
      if (tipoCore === 'agregar establecimiento') {
        return [
          { label: 'Tipo solicitud', key: 'detalle_tipo_solicitud' },
          { label: 'RUN', key: 'detalle_run' },
          { label: 'Nombre', key: 'detalle_nombre' },
          { label: 'Motivo', key: 'detalle_motivo' },
          { label: 'Establecimientos', key: 'detalle_establecimientos' },
          { label: 'Otros permisos', key: 'detalle_otros_permisos' }
        ];
      }
      return [
        { label: 'Tipo solicitud', key: 'detalle_tipo_solicitud' },
        { label: 'RUN', key: 'detalle_run' },
        { label: 'Nombre', key: 'detalle_nombre' },
        { label: 'Motivo', key: 'detalle_motivo' },
        { label: 'Otros permisos', key: 'detalle_otros_permisos' }
      ];
    };
    const forcedColumns = resolveDefaultColumns(btn);
    const forcedType = normalizeText(btn?.getAttribute('data-core_tipo_solicitud') || btn?.getAttribute('data-asunto') || '');
    const shouldForceColumns =
      forcedType === 'creacion de usuario' ||
      forcedType === 'creacion usuario' ||
      forcedType === 'modificar usuario' ||
      forcedType === 'agregar establecimiento';
    const columns = shouldForceColumns
      ? forcedColumns
      : (Array.isArray(previewColumns) && previewColumns.length ? previewColumns : forcedColumns);
    currentPreviewRows = Array.isArray(previewRows) ? previewRows : [];
    currentPreviewColumns = Array.isArray(columns) ? columns : [];
    if (openPreviewModalBtn) {
      openPreviewModalBtn.setAttribute('data-preview_rows', JSON.stringify(currentPreviewRows));
      openPreviewModalBtn.setAttribute('data-preview_columns', JSON.stringify(currentPreviewColumns));
      openPreviewModalBtn.setAttribute('data-core_tipo_solicitud', btn.getAttribute('data-core_tipo_solicitud') || '');
      openPreviewModalBtn.setAttribute('data-fuente', btn.getAttribute('data-fuente') || '');
      openPreviewModalBtn.setAttribute('data-asunto', btn.getAttribute('data-asunto') || '');
    }
    previewHead.innerHTML = `<tr>${columns.map(col => `<th>${escapeHtml(col.label || '')}</th>`).join('')}</tr>`;
    if (!Array.isArray(previewRows) || previewRows.length === 0) {
      previewBody.innerHTML = `<tr><td colspan="${columns.length}" class="text-muted text-center">Sin detalle para previsualizar.</td></tr>`;
    } else {
      previewBody.innerHTML = previewRows.map(row => `
        <tr>
          ${columns.map(col => `<td>${escapeHtml(row[col.key] || '')}</td>`).join('')}
        </tr>
      `).join('');
    }
  }

  const estadoInput = document.getElementById('md-estado');
  const estadoHelp = document.getElementById('estado-help');
  const estadoActual = (btn.getAttribute('data-estado') || '').toLowerCase();
  const estadoHidden = document.getElementById('md-estado-hidden');
  if (estadoInput) {
    estadoInput.disabled = true;
    estadoInput.removeAttribute('list');
    if (estadoHelp) estadoHelp.textContent = '';
    if (estadoHidden) {
      estadoHidden.value = estadoInput.value || estadoActual || 'pendiente';
    }
    estadoInput.addEventListener('input', () => {
      if (estadoHidden) estadoHidden.value = estadoInput.value;
    });
  }

  const asignadoHelp = document.getElementById('md-asignado-help');
  if (asignadoHelp) {
    const nombre = btn.getAttribute('data-asignado_nombre') || '';
    asignadoHelp.textContent = nombre ? `Actual: ${nombre}` : '';
  }

});
  }

  if (openPreviewModalBtn) {
    openPreviewModalBtn.addEventListener('click', event => {
      event.preventDefault();
      setDrawerView('table');
    });
  }

  if (backToDetailBtn) {
    backToDetailBtn.addEventListener('click', () => {
      setDrawerView('detail');
    });
  }

  if (descripcionModal) {
    descripcionModal.addEventListener('show.bs.modal', () => {
      reopenDetalleModalAfterDescripcion = true;
      setDescriptionView(false);
      if (descripcionEditor) {
        descripcionEditor.value = descripcionHidden ? (descripcionHidden.value || '') : '';
      }
    });
    descripcionModal.addEventListener('hidden.bs.modal', () => {
      if (!reopenDetalleModalAfterDescripcion || !detalleModal) {
        return;
      }
      reopenDetalleModalAfterDescripcion = false;
      const modal = bootstrap.Modal.getOrCreateInstance(detalleModal);
      modal.show();
    });
  }

  if (saveDescripcionBtn) {
    saveDescripcionBtn.addEventListener('click', () => {
      if (descripcionHidden && descripcionEditor) {
        descripcionHidden.value = descripcionEditor.value || '';
      }
      if (descripcionModal) {
        const modal = bootstrap.Modal.getOrCreateInstance(descripcionModal);
        modal.hide();
      }
    });
  }



function getVisibleRows() {
  return Array.from(document.querySelectorAll('table tbody tr')).filter(tr => tr.style.display !== 'none');
}

function getSelectedVisibleChecks() {
  return Array.from(document.querySelectorAll('.msg-check')).filter(cb => {
    if (cb.disabled || !cb.checked || !cb.value) return false;
    const row = cb.closest('tr');
    return !!row && row.style.display !== 'none';
  });
}

const filterNav = document.getElementById('status-filters');
const processedEditToggleBtn = document.getElementById('processed-edit-toggle');
let currentDashboardFilter = filterNav?.querySelector('[data-filter].is-active')?.getAttribute('data-filter') || 'pendiente';
let processedEditEnabled = sessionStorage.getItem(dashboardProcessedEditKey) === '1';

function processedActionsLocked() {
  return currentDashboardFilter === 'procesado' && !processedEditEnabled;
}

function syncProcessedActionControls() {
  const locked = processedActionsLocked();
  document.getElementById('dashboard-table-card')?.classList.toggle('is-processed-locked', locked);
  document.querySelectorAll('[data-processed-action]').forEach(control => {
    control.setAttribute('aria-disabled', locked ? 'true' : 'false');
    if (locked) {
      control.disabled = true;
      if (control.matches('input[type="checkbox"]')) {
        control.checked = false;
      }
      return;
    }
    control.removeAttribute('aria-disabled');
    if (!dashboardMaintenanceMode && !control.matches('#archive-btn, #delete-selected-btn')) {
      control.disabled = false;
    }
  });
  if (processedEditToggleBtn) {
    processedEditToggleBtn.classList.toggle('d-none', currentDashboardFilter !== 'procesado');
    processedEditToggleBtn.setAttribute('aria-pressed', processedEditEnabled ? 'true' : 'false');
    processedEditToggleBtn.innerHTML = processedEditEnabled
      ? '<i class="bi bi-lock"></i> Bloquear acciones'
      : '<i class="bi bi-unlock"></i> Habilitar acciones';
  }
}

function setAllChecks(checked) {
  document.querySelectorAll('.msg-check').forEach(cb => {
    if (!cb.disabled) {
      cb.checked = checked;
    }
  });
}

  function refreshDashboardCounters() {
  syncProcessedActionControls();
  const visibleCount = document.getElementById('visible-count');
  const selectionCount = document.getElementById('selection-count');
  const visibleRows = getVisibleRows();
  const selectedChecks = getSelectedVisibleChecks();
  if (visibleCount) visibleCount.textContent = String(visibleRows.length);
  if (selectionCount) selectionCount.textContent = String(selectedChecks.length);
  const processBtn = document.getElementById('process-btn');
  const archiveBtn = document.getElementById('archive-btn');
  const deleteSelectedBtn = document.getElementById('delete-selected-btn');
  const resetErrorsBtn = document.getElementById('reset-errors-btn');
  if (processBtn) processBtn.disabled = dashboardMaintenanceMode || selectedChecks.length === 0;
  if (archiveBtn) archiveBtn.disabled = dashboardMaintenanceMode || selectedChecks.length === 0;
  if (deleteSelectedBtn) deleteSelectedBtn.disabled = dashboardMaintenanceMode || selectedChecks.length === 0;
  if (resetErrorsBtn) resetErrorsBtn.disabled = dashboardMaintenanceMode || selectedChecks.length === 0;
}

if (processedEditToggleBtn && processedEditToggleBtn.dataset.processedToggleReady !== 'true') {
  processedEditToggleBtn.dataset.processedToggleReady = 'true';
  processedEditToggleBtn.addEventListener('click', () => {
    processedEditEnabled = !processedEditEnabled;
    sessionStorage.setItem(dashboardProcessedEditKey, processedEditEnabled ? '1' : '0');
    refreshDashboardCounters();
  });
}

const processedWorkPanel = document.getElementById('dashboard-table-card');
if (processedWorkPanel && processedWorkPanel.dataset.processedLockGuardReady !== 'true') {
  processedWorkPanel.dataset.processedLockGuardReady = 'true';
  processedWorkPanel.addEventListener('click', event => {
    if (!processedWorkPanel.classList.contains('is-processed-locked')) return;
    if (!event.target.closest('[data-processed-action]')) return;
    event.preventDefault();
    event.stopImmediatePropagation();
  }, true);
  processedWorkPanel.addEventListener('change', event => {
    if (!processedWorkPanel.classList.contains('is-processed-locked')) return;
    const checkbox = event.target.closest('input[type="checkbox"][data-processed-action]');
    if (!checkbox) return;
    checkbox.checked = false;
    event.preventDefault();
    event.stopImmediatePropagation();
    refreshDashboardCounters();
  }, true);
}

const selAllTop = document.getElementById('sel-all-top');

if (selAllTop) {

  selAllTop.addEventListener('change', () => {
    setAllChecks(selAllTop.checked);
    refreshDashboardCounters();
  });

}

const selAllBtn = document.getElementById('sel-all-btn');

if (selAllBtn) {

  selAllBtn.addEventListener('click', () => {

    const boxes = document.querySelectorAll('.msg-check');

    const allChecked = Array.from(boxes).every(cb => cb.checked);

    setAllChecks(!allChecked);

    if (selAllTop) selAllTop.checked = !allChecked;
    refreshDashboardCounters();

  });

}

const processForm = document.getElementById('process-form');

const processAction = document.getElementById('process-action');
const processIds = document.getElementById('process-ids');
let redmineSubmitDelayDone = false;

if (processForm && processIds) {

  processForm.addEventListener('submit', (e) => {

    const ids = Array.from(document.querySelectorAll('.msg-check'))

      .filter(cb => {
        if (cb.disabled || !cb.checked || !cb.value) return false;
        const row = cb.closest('tr');
        if (!row) return false;
        // Solo tomar los visibles (segun filtro activo)
        return row.style.display !== 'none';
      })

      .map(cb => cb.value);

    processIds.value = ids.join(',');

    if (ids.length === 0) {

      e.preventDefault();

      window.appModal?.show({
        title: 'Seleccion requerida',
        message: 'Selecciona al menos un mensaje para procesar.',
        tone: 'warning'
      });

    }
    const currentProcessAction = processAction?.value || '';
    if (!e.defaultPrevented && currentProcessAction !== 'process_selected') {
      e.preventDefault();
      submitDashboardBulkAction(processForm);
      return;
    }
    if (!e.defaultPrevented && currentProcessAction === 'process_selected' && !redmineSubmitDelayDone) {
      e.preventDefault();
      showDashboardProgress('redmine');
      redmineSubmitDelayDone = true;
      window.setTimeout(() => {
        processForm.requestSubmit();
      }, 3000);
    }

    refreshDashboardCounters();

  });

}

function filterRows(filter) {
  document.querySelectorAll('table tbody tr').forEach(tr => {
    const status = (tr.getAttribute('data-status') || '').toLowerCase();
    tr.style.display = (filter === 'all' || status === filter) ? '' : 'none';
  });
  refreshDashboardCounters();
}

function applyFilterButtons(filter) {
  const previousFilter = currentDashboardFilter;
  currentDashboardFilter = filter;
  if (filter !== 'procesado' || previousFilter !== 'procesado') {
    processedEditEnabled = false;
    sessionStorage.setItem(dashboardProcessedEditKey, '0');
  }
  const chips = document.getElementById('dashboard-active-chips');
  if (chips) {
    const labels = {
      pendiente: ['bi-hourglass-split', 'Pendientes por revisar'],
      procesado: ['bi-check2-circle', 'Procesados correctamente'],
      error: ['bi-exclamation-octagon', 'Errores pendientes'],
      all: ['bi-table', 'Todos los registros']
    };
    const [icon, label] = labels[filter] || labels.all;
    chips.innerHTML = `<span class="dashboard-filter-chip"><i class="bi ${icon}"></i>${label}</span>`;
  }

  const processBtn = document.getElementById('process-btn');
  if (processBtn) {
    processBtn.classList.toggle('d-none', filter !== 'pendiente');
  }

  const archiveBtn = document.getElementById('archive-btn');
  if (archiveBtn) {
    const showArchive = (filter === 'procesado');
    archiveBtn.classList.toggle('d-none', !showArchive);
  }
  const resetErrorsBtn = document.getElementById('reset-errors-btn');
  if (resetErrorsBtn) {
    resetErrorsBtn.classList.toggle('d-none', filter !== 'error');
  }
  syncProcessedActionControls();
  refreshDashboardCounters();
}

const dashboardTableCard = document.getElementById('dashboard-table-card');
const dashboardCompactToggle = document.getElementById('dashboard-compact-toggle');
const dashboardCompactKey = 'redmine-mantencion-dashboard-compact';
if (dashboardTableCard && dashboardCompactToggle) {
  const savedCompact = localStorage.getItem(dashboardCompactKey) === '1';
  dashboardTableCard.classList.toggle('is-compact', savedCompact);
  dashboardCompactToggle.checked = savedCompact;
  dashboardCompactToggle.addEventListener('change', () => {
    const enabled = dashboardCompactToggle.checked;
    dashboardTableCard.classList.toggle('is-compact', enabled);
    localStorage.setItem(dashboardCompactKey, enabled ? '1' : '0');
  });
}

function escapeDashboardId(value) {
  const raw = String(value);
  if (window.CSS && typeof CSS.escape === 'function') {
    return CSS.escape(raw);
  }
  return raw.replace(/["\\]/g, '\\$&');
}

function showDashboardToast(message, tone = 'success') {
  if (!message) return;
  const type = tone === 'danger' ? 'error' : tone;
  if (window.NovaToast?.show) {
    window.NovaToast.show({ type, message });
    return;
  }
  if (window.appUi?.toast) {
    window.appUi.toast(message, type);
    return;
  }
  window.appModal?.show({
    title: type === 'success' ? 'Listo' : 'Aviso',
    message,
    tone: type === 'error' ? 'danger' : type
  });
}

function updateDashboardStatusCards(counts) {
  if (!counts || typeof counts !== 'object') return;
  Object.entries(counts).forEach(([status, count]) => {
    const value = document.querySelector(`[data-filter="${status}"] .dashboard-stat__value`);
    if (value) value.textContent = String(count);
  });
}

async function submitDashboardAction(form) {
  const row = form.closest('tr');
  const submitter = form.querySelector('button[type="submit"], input[type="submit"]');
  const data = new FormData(form);
  data.set('ajax', '1');
  const csrfToken = String(data.get('_token') || document.querySelector('meta[name="csrf-token"]')?.content || '');
  row?.classList.add('is-row-updating');
  try {
    const response = await fetch(form.getAttribute('action') || window.location.href, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {})
      },
      body: data
    });
    const raw = await response.text();
    let payload = {};
    try {
      payload = raw ? JSON.parse(raw) : {};
    } catch (error) {
      throw new Error('La acción respondió HTML en vez de JSON. Revisa la ruta del formulario.');
    }
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.message || 'No se pudo completar la acción.');
    }
    applyDashboardActionResult(payload, form, row);
    showDashboardToast(payload.message || 'Acción completada.');
    if ((data.get('action') || '') === 'update' && form.closest('#detalleModal')) {
      const reportId = String(data.get('id') || '');
      const reportRow = reportId ? document.querySelector(`tr[data-id="${escapeDashboardId(reportId)}"]`) : null;
      const detailButton = reportRow?.querySelector('[data-bs-target="#detalleModal"]');
      const fieldAttributes = {
        tipo: 'data-tipo', estado: 'data-estado', prioridad: 'data-prioridad', asunto: 'data-asunto',
        categoria: 'data-categoria', asignado_a: 'data-asignado_a', solicitante: 'data-solicitante',
        establecimiento: 'data-establecimiento', departamento: 'data-departamento', hora_extra: 'data-hora_extra',
        fecha_inicio: 'data-fecha_inicio', fecha_fin: 'data-fecha_fin', tiempo_estimado: 'data-tiempo_estimado',
        fecha: 'data-fecha', hora: 'data-hora', numero: 'data-numero', descripcion: 'data-descripcion',
        core_email: 'data-core_email'
      };
      Object.entries(fieldAttributes).forEach(([field, attribute]) => {
        if (data.has(field)) detailButton?.setAttribute(attribute, String(data.get(field) ?? ''));
      });
      const assignedSelect = form.querySelector('#md-asignado');
      if (assignedSelect) {
        const assignedLabel = assignedSelect.selectedOptions[0]?.textContent?.trim() || '';
        detailButton?.setAttribute('data-asignado_nombre', assignedLabel === 'Sin asignar' ? '' : assignedLabel);
      }
      reportRow?.setAttribute('data-horaextra', String(data.get('hora_extra') || '0'));
      bootstrap.Modal.getInstance(document.getElementById('detalleModal'))?.hide();
      sessionStorage.setItem(dashboardScrollKey, String(window.scrollY || 0));
      sessionStorage.setItem(dashboardFilterKey, currentDashboardFilter || 'pendiente');
      window.location.reload();
    }
  } catch (error) {
    row?.classList.remove('is-row-updating');
    showDashboardToast(error.message || 'No se pudo completar la acción.', 'danger');
  } finally {
    submitter?.classList.remove('is-submitting');
  }
}

async function submitDashboardBulkAction(form) {
  const data = new FormData(form);
  data.set('ajax', '1');
  const ids = String(data.get('ids') || '').split(',').filter(Boolean);
  ids.forEach(id => document.querySelector(`tr[data-id="${escapeDashboardId(id)}"]`)?.classList.add('is-row-updating'));
  try {
    const response = await fetch(form.getAttribute('action') || window.location.href, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      body: data
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.message || 'No se pudo completar la acción.');
    }
    applyDashboardActionResult(payload, form, null);
    showDashboardToast(payload.message || 'Acción completada.');
  } catch (error) {
    ids.forEach(id => document.querySelector(`tr[data-id="${escapeDashboardId(id)}"]`)?.classList.remove('is-row-updating'));
    showDashboardToast(error.message || 'No se pudo completar la acción.', 'danger');
  }
}

function applyDashboardActionResult(payload, form, row) {
  const action = payload.action || form.querySelector('[name="action"]')?.value || '';
  updateDashboardStatusCards(payload.counts);
  // toggle_hora_extra is handled by NovaOptimisticToggle (nova-ui.js) instead of this
  // generic dispatcher — see the nova-optimistic-toggle:change listener below.
  if (['delete', 'archive_selected', 'delete_selected'].includes(action)) {
    (payload.ids || []).forEach(id => {
      document.querySelector(`tr[data-id="${escapeDashboardId(id)}"]`)?.remove();
    });
    refreshDashboardCounters();
  } else if (action === 'reset_errors') {
    (payload.ids || []).forEach(id => {
      const targetRow = document.querySelector(`tr[data-id="${escapeDashboardId(id)}"]`);
      if (!targetRow) return;
      targetRow.setAttribute('data-status', 'pendiente');
      targetRow.classList.remove('is-row-updating');
      const detailBtn = targetRow.querySelector('[data-bs-target="#detalleModal"]');
      detailBtn?.setAttribute('data-estado', 'pendiente');
      const statusIcon = targetRow.querySelector('.dashboard-status-icon');
      if (statusIcon) {
        statusIcon.classList.remove('dashboard-status-icon--processed', 'dashboard-status-icon--error');
        statusIcon.classList.add('dashboard-status-icon--pending');
        statusIcon.setAttribute('title', 'Pendiente');
        const icon = statusIcon.querySelector('i');
        if (icon) icon.className = 'bi bi-hourglass-split';
        bootstrap.Tooltip.getInstance(statusIcon)?.dispose();
        new bootstrap.Tooltip(statusIcon);
      }
      const currentFilter = filterNav?.querySelector('[data-filter].is-active')?.getAttribute('data-filter') || 'pendiente';
      targetRow.style.display = currentFilter === 'pendiente' || currentFilter === 'all' ? '' : 'none';
    });
    refreshDashboardCounters();
  } else {
    row?.classList.remove('is-row-updating');
    refreshDashboardCounters();
  }
}

document.querySelectorAll('form[data-dashboard-ajax="row"]').forEach(form => {
  form.addEventListener('submit', event => {
    if (dashboardMaintenanceMode) return;
    if (form.matches('[data-app-confirm]') && form.dataset.appConfirmAccepted !== '1') return;
    event.preventDefault();
    submitDashboardAction(form);
  });
});

// Keep the row's "detalle" button in sync with the Hora Extra toggle so opening
// the detail modal right after toggling doesn't show the pre-toggle value.
document.addEventListener('nova-optimistic-toggle:change', (event) => {
  const row = event.target.closest('tr');
  row?.setAttribute('data-horaextra', event.detail.active ? '1' : '0');
  const detailBtn = row?.querySelector('[data-bs-target="#detalleModal"]');
  detailBtn?.setAttribute('data-hora_extra', event.detail.active ? '1' : '0');
  detailBtn?.setAttribute('data-tiempo_estimado', event.detail.active ? '1' : '');
});

if (filterNav) {

  const initialFilter = savedDashboardFilter
    || filterNav.querySelector('[data-filter].is-active')?.getAttribute('data-filter')
    || 'pendiente';
  filterRows(initialFilter);
  applyFilterButtons(initialFilter);

  filterNav.addEventListener('click', (e) => {

    const btn = e.target.closest('[data-filter]');

    if (!btn) return;

    e.preventDefault();

    const filter = btn.getAttribute('data-filter');

    filterNav.querySelectorAll('[data-filter]').forEach(link => link.classList.remove('is-active'));

    btn.classList.add('is-active');

    filterRows(filter);
    applyFilterButtons(filter);

  });

  filterNav.addEventListener('keydown', (e) => {
    const card = e.target.closest('[data-filter]');
    if (!card) return;
    if (e.key !== 'Enter' && e.key !== ' ') return;
    e.preventDefault();
    card.click();
  });

}

document.querySelectorAll('.msg-check').forEach(cb => {
  cb.addEventListener('change', refreshDashboardCounters);
});

const processBtn = document.getElementById('process-btn');
if (processBtn && processForm) {
  processBtn.addEventListener('click', () => {
    if (processAction) processAction.value = 'process_selected';
    processForm.requestSubmit();
  });
}

const archiveBtn = document.getElementById('archive-btn');
if (archiveBtn && processForm && processAction) {
  archiveBtn.addEventListener('click', () => {
    processAction.value = 'archive_selected';
    processForm.requestSubmit();
  });
}

const deleteSelectedBtn = document.getElementById('delete-selected-btn');
const deleteSelectedModalEl = document.getElementById('deleteSelectedModal');
const deleteSelectedCount = document.getElementById('delete-selected-count');
const confirmDeleteSelectedBtn = document.getElementById('confirm-delete-selected-btn');
const deleteSelectedModal = deleteSelectedModalEl ? new bootstrap.Modal(deleteSelectedModalEl) : null;
if (deleteSelectedBtn && processForm && processAction) {
  deleteSelectedBtn.addEventListener('click', () => {
    const selected = getSelectedVisibleChecks();
    if (selected.length === 0) {
      window.appModal?.show({
        title: 'Seleccion requerida',
        message: 'Selecciona al menos un mensaje para eliminar.',
        tone: 'warning'
      });
      return;
    }
    if (deleteSelectedCount) {
      deleteSelectedCount.textContent = String(selected.length);
    }
    deleteSelectedModal?.show();
  });
}

if (confirmDeleteSelectedBtn && processForm && processAction) {
  confirmDeleteSelectedBtn.addEventListener('click', () => {
    processAction.value = 'delete_selected';
    deleteSelectedModal?.hide();
    processForm.requestSubmit();
  });
}

const resetErrorsBtn = document.getElementById('reset-errors-btn');
if (resetErrorsBtn && processForm && processAction) {
  resetErrorsBtn.addEventListener('click', () => {
    processAction.value = 'reset_errors';
    processForm.requestSubmit();
  });
}

const logModal = document.getElementById('logModal');

if (logModal) {

  logModal.addEventListener('show.bs.modal', event => {

    const btn = event.relatedTarget;

    const logText = btn ? (btn.getAttribute('data-log') || '') : '';

    const container = document.getElementById('logModalContent');

    if (container) {

      container.textContent = logText || 'Sin registros de error para este mensaje.';

    }

  });

}

const coreImportForm = document.getElementById('core-import-form');
const coreRuntimeUserInput = document.getElementById('core-runtime-user-input');
const coreRuntimePassInput = document.getElementById('core-runtime-pass-input');
const coreRuntimeUserHidden = document.getElementById('core-runtime-user-hidden');
const coreRuntimePassHidden = document.getElementById('core-runtime-pass-hidden');
const coreRememberInput = document.getElementById('core-remember-input');
const coreRememberHidden = document.getElementById('core-remember-hidden');
const coreCredentialsModal = document.getElementById('coreCredentialsModal');
const coreImportOverlay = document.getElementById('core-import-overlay');
const coreImportProgressBar = document.getElementById('core-import-progress-bar');
const coreImportProgressPercent = document.getElementById('core-import-progress-percent');
const coreImportProgressText = document.getElementById('core-import-progress-text');
const coreImportProgressStep = document.getElementById('core-import-progress-step');
const dashboardProgressGif = document.getElementById('dashboard-progress-gif');
const dashboardCoreLoading = document.getElementById('dashboard-core-loading');
const hasSavedCoreCredentials = <?= $hasSavedCoreCredentials ? 'true' : 'false' ?>;
const shouldOpenCoreCredentialsModal = <?= $openCoreCredentialsModal ? 'true' : 'false' ?>;
let coreImportProgressTimer = null;

if (coreImportOverlay && coreImportOverlay.parentElement !== document.body) {
  document.body.appendChild(coreImportOverlay);
}

function resetDashboardProgressViewport() {
  const scrollingElement = document.scrollingElement || document.documentElement;
  scrollingElement?.scrollTo({ top: 0, behavior: 'auto' });
  window.scrollTo({ top: 0, behavior: 'auto' });
  document.querySelector('.dashboard-table-wrap')?.scrollTo({ top: 0, behavior: 'auto' });
}

function showDashboardProgress(mode = 'core') {
  resetDashboardProgressViewport();
  if (mode === 'core' && dashboardCoreLoading) {
    dashboardCoreLoading.classList.add('is-visible');
    dashboardCoreLoading.setAttribute('aria-hidden', 'false');
    coreImportForm?.classList.add('nova-card-loading');
    coreImportForm?.querySelectorAll('button[type="submit"]').forEach(button => {
      button.disabled = true;
      button.setAttribute('aria-busy', 'true');
    });
  }
  if (!coreImportOverlay || !coreImportProgressBar) return;
  const stepSets = {
    core: [
      { at: 8, text: 'Conectando con CORE...', step: 'Abriendo sesión' },
      { at: 24, text: 'Autenticando credenciales...', step: 'Validando acceso' },
      { at: 42, text: 'Consultando solicitudes...', step: 'Leyendo datos' },
      { at: 64, text: 'Procesando registros...', step: 'Normalizando solicitudes' },
      { at: 82, text: 'Guardando importación...', step: 'Actualizando panel' },
      { at: 94, text: 'Finalizando...', step: 'Esperando respuesta' }
    ],
    redmine: [
      { at: 8, text: 'Preparando reportes...', step: 'Validando seleccion' },
      { at: 24, text: 'Conectando con Redmine...', step: 'Abriendo conexion' },
      { at: 46, text: 'Enviando reportes...', step: 'Creando tickets' },
      { at: 68, text: 'Confirmando respuestas...', step: 'Registrando resultados' },
      { at: 84, text: 'Actualizando estados locales...', step: 'Guardando cambios' },
      { at: 94, text: 'Finalizando...', step: 'Esperando respuesta' }
    ]
  };
  const titles = {
    core: 'Importando desde CORE',
    redmine: 'Enviando reportes a Redmine'
  };
  if (dashboardProgressGif) {
    const gifSrc = mode === 'redmine'
      ? dashboardProgressGif.getAttribute('data-redmine-src')
      : dashboardProgressGif.getAttribute('data-core-src');
    if (gifSrc && dashboardProgressGif.getAttribute('src') !== gifSrc) {
      dashboardProgressGif.setAttribute('src', gifSrc);
    }
  }
  const steps = stepSets[mode] || stepSets.core;
  const title = coreImportOverlay.querySelector('.core-import-card__title');
  if (title) title.textContent = titles[mode] || titles.core;
  let progress = 0;
  let stepIndex = 0;
  const setProgress = value => {
    progress = Math.min(94, Math.max(progress, value));
    coreImportProgressBar.style.width = `${progress}%`;
    if (coreImportProgressPercent) coreImportProgressPercent.textContent = `${Math.round(progress)}%`;
  };
  const setStep = item => {
    if (coreImportProgressText) coreImportProgressText.textContent = item.text;
    if (coreImportProgressStep) coreImportProgressStep.textContent = item.step;
  };
  coreImportOverlay.classList.add('is-visible');
  coreImportOverlay.setAttribute('aria-hidden', 'false');
  setStep(steps[0]);
  setProgress(6);
  window.clearInterval(coreImportProgressTimer);
  coreImportProgressTimer = window.setInterval(() => {
    const target = steps[Math.min(stepIndex, steps.length - 1)];
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

if (coreImportForm) {
  coreImportForm.addEventListener('submit', event => {
    if (coreRuntimeUserHidden) coreRuntimeUserHidden.value = coreRuntimeUserInput?.value || '';
    if (coreRuntimePassHidden) coreRuntimePassHidden.value = coreRuntimePassInput?.value || '';
    if (coreRememberHidden) coreRememberHidden.value = coreRememberInput?.checked ? '1' : '0';
    if (!hasSavedCoreCredentials && (!coreRuntimeUserHidden?.value.trim() || !coreRuntimePassHidden?.value.trim())) {
      event.preventDefault();
      window.appModal?.show({
        title: 'Credenciales requeridas',
        message: 'Debes ingresar usuario y contraseña de CORE.',
        tone: 'warning'
      });
      return;
    }
    showDashboardProgress('core');
  });
}

if (coreCredentialsModal) {
  let _coreSubmitAfterClose = false;

  coreCredentialsModal.addEventListener('hidden.bs.modal', () => {
    if (_coreSubmitAfterClose) {
      _coreSubmitAfterClose = false;
      if (coreImportForm) coreImportForm.requestSubmit();
    }
    if (coreRuntimePassInput) coreRuntimePassInput.value = '';
    if (coreRuntimePassHidden) coreRuntimePassHidden.value = '';
  });

  const coreCredentialsSubmitBtn = document.getElementById('core-credentials-submit-btn');
  if (coreCredentialsSubmitBtn) {
    coreCredentialsSubmitBtn.addEventListener('click', () => {
      const user = coreRuntimeUserInput?.value.trim() || '';
      const pass = coreRuntimePassInput?.value.trim() || '';
      if (!user || !pass) {
        window.appModal?.show({
          title: 'Credenciales requeridas',
          message: 'Debes ingresar usuario y contraseña de CORE.',
          tone: 'warning'
        });
        return;
      }
      _coreSubmitAfterClose = true;
      bootstrap.Modal.getOrCreateInstance(coreCredentialsModal).hide();
    });
  }

  if (shouldOpenCoreCredentialsModal && window.bootstrap?.Modal) {
    window.setTimeout(() => {
      window.bootstrap.Modal.getOrCreateInstance(coreCredentialsModal).show();
      coreRuntimePassInput?.focus();
    }, 250);
  }
}

const scrollTopBtn = document.getElementById('dashboard-scroll-top');
if (scrollTopBtn) {
  if (scrollTopBtn.parentElement !== document.body) {
    document.body.appendChild(scrollTopBtn);
  }
  const tableScrollWrap = document.querySelector('.dashboard-table-wrap');
  scrollTopBtn.addEventListener('click', () => {
    if (tableScrollWrap && tableScrollWrap.scrollTop > 0) {
      tableScrollWrap.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  const scrollTopTarget = filterNav || document.getElementById('status-filters');
  let statusFiltersVisible = true;
  const currentPageScrollTop = () => window.scrollY || window.pageYOffset || document.documentElement.scrollTop || 0;
  const currentTableScrollTop = () => tableScrollWrap ? tableScrollWrap.scrollTop || 0 : 0;
  const updateScrollTopVisibility = () => {
    const rect = scrollTopTarget?.getBoundingClientRect();
    const hiddenByGeometry = rect ? rect.bottom <= 0 : false;
    const shouldShow = hiddenByGeometry || !statusFiltersVisible || currentPageScrollTop() > 220 || currentTableScrollTop() > 80;
    scrollTopBtn.classList.toggle('is-visible', shouldShow);
    scrollTopBtn.style.display = shouldShow ? 'flex' : 'none';
  };
  let scrollTopTicking = false;
  const queueScrollTopVisibility = () => {
    if (scrollTopTicking) return;
    scrollTopTicking = true;
    window.requestAnimationFrame(() => {
      updateScrollTopVisibility();
      scrollTopTicking = false;
    });
  };
  if (scrollTopTarget && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver(([entry]) => {
      statusFiltersVisible = entry.isIntersecting;
      queueScrollTopVisibility();
    }, { threshold: 0 });
    observer.observe(scrollTopTarget);
  }
  window.addEventListener('scroll', queueScrollTopVisibility, { passive: true });
  if (tableScrollWrap) {
    tableScrollWrap.addEventListener('scroll', queueScrollTopVisibility, { passive: true });
  }
  window.addEventListener('resize', queueScrollTopVisibility);
  window.addEventListener('load', queueScrollTopVisibility);
  if (filterNav) {
    filterNav.addEventListener('click', () => window.setTimeout(queueScrollTopVisibility, 0));
  }
  window.setTimeout(queueScrollTopVisibility, 250);
  updateScrollTopVisibility();
}

window.__dashboardBulkControlsReady = true;
refreshDashboardCounters();

</script>

<script>
(function () {
  if (window.__dashboardBulkControlsReady) return;

  const maintenanceMode = <?= $maintenanceMode ? 'true' : 'false' ?>;
  const filterNav = document.getElementById('status-filters');
  const processForm = document.getElementById('process-form');
  const processAction = document.getElementById('process-action');
  const processIds = document.getElementById('process-ids');
  const processBtn = document.getElementById('process-btn');
  const archiveBtn = document.getElementById('archive-btn');
  const deleteSelectedBtn = document.getElementById('delete-selected-btn');
  const resetErrorsBtn = document.getElementById('reset-errors-btn');
  const selAllTop = document.getElementById('sel-all-top');
  const visibleCount = document.getElementById('visible-count');
  const selectionCount = document.getElementById('selection-count');

  const visibleRows = () => Array.from(document.querySelectorAll('.dashboard-table tbody tr'))
    .filter(row => row.style.display !== 'none' && row.id !== 'dashboard-empty-row');

  const selectedVisibleChecks = () => Array.from(document.querySelectorAll('.msg-check'))
    .filter(input => input.checked && input.value && input.closest('tr')?.style.display !== 'none');

  const refresh = () => {
    const selected = selectedVisibleChecks();
    if (visibleCount) visibleCount.textContent = String(visibleRows().length);
    if (selectionCount) selectionCount.textContent = String(selected.length);
    [processBtn, archiveBtn, deleteSelectedBtn, resetErrorsBtn].forEach(button => {
      if (button) button.disabled = maintenanceMode || selected.length === 0;
    });
  };

  const showActionsFor = filter => {
    processBtn?.classList.toggle('d-none', filter !== 'pendiente');
    archiveBtn?.classList.toggle('d-none', filter !== 'procesado');
    resetErrorsBtn?.classList.toggle('d-none', filter !== 'error');
    const chips = document.getElementById('dashboard-active-chips');
    if (chips) {
      const labels = {
        pendiente: ['bi-hourglass-split', 'Pendientes por revisar'],
        procesado: ['bi-check2-circle', 'Procesados correctamente'],
        error: ['bi-exclamation-octagon', 'Errores pendientes'],
      };
      const [icon, label] = labels[filter] || labels.pendiente;
      chips.innerHTML = `<span class="dashboard-filter-chip"><i class="bi ${icon}"></i>${label}</span>`;
    }
    refresh();
  };

  const applyFilter = filter => {
    document.querySelectorAll('.dashboard-table tbody tr').forEach(row => {
      if (row.id === 'dashboard-empty-row') return;
      const status = (row.getAttribute('data-status') || '').toLowerCase();
      row.style.display = status === filter ? '' : 'none';
      const check = row.querySelector('.msg-check');
      if (check && row.style.display === 'none') check.checked = false;
    });
    if (selAllTop) selAllTop.checked = false;
    filterNav?.querySelectorAll('[data-filter]').forEach(card => {
      card.classList.toggle('is-active', card.getAttribute('data-filter') === filter);
    });
    showActionsFor(filter);
  };

  selAllTop?.addEventListener('change', () => {
    visibleRows().forEach(row => {
      const check = row.querySelector('.msg-check');
      if (check) check.checked = selAllTop.checked;
    });
    refresh();
  });

  document.querySelectorAll('.msg-check').forEach(input => {
    input.addEventListener('change', refresh);
  });

  filterNav?.addEventListener('click', event => {
    const card = event.target.closest('[data-filter]');
    if (!card) return;
    event.preventDefault();
    applyFilter(card.getAttribute('data-filter') || 'pendiente');
  });

  const submitBulk = action => {
    const ids = selectedVisibleChecks().map(input => input.value);
    if (ids.length === 0 || !processForm || !processAction || !processIds) {
      window.appModal?.show({
        title: 'Seleccion requerida',
        message: 'Selecciona al menos un mensaje.',
        tone: 'warning'
      });
      return;
    }
    processAction.value = action;
    processIds.value = ids.join(',');
    processForm.submit();
  };

  processBtn?.addEventListener('click', () => submitBulk('process_selected'));
  archiveBtn?.addEventListener('click', () => submitBulk('archive_selected'));
  resetErrorsBtn?.addEventListener('click', () => submitBulk('reset_errors'));
  deleteSelectedBtn?.addEventListener('click', () => submitBulk('delete_selected'));

  const activeFilter = filterNav?.querySelector('[data-filter].is-active')?.getAttribute('data-filter') || 'pendiente';
  applyFilter(activeFilter);
})();
</script>

</div>
</div> <!-- #page-content -->
</body>

</html>
