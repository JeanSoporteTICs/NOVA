<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Historico'; $includeTheme = true; include base_path('RedmineMantencion/views/partials/bootstrap-head.php'); ?>
  <?php $historicoCssVersion = @filemtime(base_path('RedmineMantencion/assets/css/historico.css')) ?: time(); ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/historico.css?v=<?= (int)$historicoCssVersion ?>">
</head>
<body class="bg-light">
<?php $activeNav = 'historico'; include base_path('RedmineMantencion/views/partials/navbar.php'); ?>

<div id="page-content">
<div class="container-fluid py-4">
  <?php
    $heroIcon = 'bi-archive';
    $heroTitle = 'Histórico';
    $heroSubtitle = 'Registros procesados archivados y horas extra.';
    include base_path('RedmineMantencion/views/partials/hero.php');
  ?>

  <?php if ($alert): ?>
    <div data-nova-flash="<?= $ok ? 'success' : 'warning' ?>" data-nova-flash-message="<?= $h($alert) ?>" hidden></div>
  <?php endif; ?>

  <form id="filter-form" class="card card-body shadow-sm mb-3 historico-filter-card" method="get" aria-live="polite">
    <input type="hidden" name="per_page" value="<?= $h($perPage) ?>">
    <input type="hidden" name="page" value="1">
    <div class="row g-3 align-items-end">
      <?php
        $filterFields = [
          ['label' => 'Desde', 'name' => 'desde', 'type' => 'date', 'value' => $f_desde, 'col' => 2, 'aria_label' => 'Fecha desde'],
          ['label' => 'Hasta', 'name' => 'hasta', 'type' => 'date', 'value' => $f_hasta, 'col' => 2, 'aria_label' => 'Fecha hasta'],
          ['label' => 'Fuente', 'name' => 'fuente', 'type' => 'select', 'options' => ['' => 'Todas', 'reportes' => 'Reportes', 'horas_extra' => 'Horas extra'], 'value' => $f_fuente, 'col' => 2],
          ['label' => 'Estado Redmine', 'name' => 'estado_redmine', 'type' => 'select', 'options' => ['' => 'Todos'] + $redmineStatusesSel, 'value' => $f_estado_redmine, 'col' => 2],
        ];
        if (!$scopeBloqueado) {
          $filterFields[] = [
            'label' => 'Asignado',
            'name' => 'usuario',
            'type' => 'select',
            'options' => ['' => 'Todos'] + $usuariosSel,
            'value' => $f_usuario,
            'col' => 3,
          ];
        }
        $filterFields[] = [
          'label' => 'Categoría',
          'name' => 'categoria',
          'type' => 'select',
          'options' => ['' => 'Todas'] + $catsSel,
          'value' => $f_categoria,
          'col' => 3,
        ];
        $filterFields[] = [
          'label' => 'Buscar solicitante / nombre / rut',
          'name' => 'buscar',
          'type' => 'text',
          'value' => $f_busqueda,
          'col' => 4,
          'aria_label' => 'Buscar por solicitante, nombre o rut',
        ];
        $filterFields[] = [
          'label' => 'Buscar en descripción',
          'name' => 'descripcion',
          'type' => 'text',
          'value' => $f_descripcion,
          'col' => 4,
          'aria_label' => 'Buscar texto en la descripción de los reportes',
        ];
      ?>
      <?php foreach ($filterFields as $field): ?>
        <?php include base_path('RedmineMantencion/views/partials/filter-field.php'); ?>
      <?php endforeach; ?>
      <div class="col-md-2">
        <button
          type="submit"
          id="btn-apply"
          class="btn-nova btn-nova-primary w-100"
          data-bs-spinner="true"
          aria-label="Aplicar filtros"
          aria-pressed="false">
          <i class="bi bi-funnel"></i> Filtrar
        </button>
      </div>
      <div class="col-md-2">
        <a
          class="btn-nova btn-nova-secondary w-100"
          id="btn-clear"
          href="<?= $h($mantencionAppUrl . '/historico') ?>"
          aria-label="Limpiar filtros"
          aria-pressed="false">
          <i class="bi bi-x-circle"></i> Limpiar
        </a>
      </div>
    </div>
    <div id="filter-feedback" class="d-none mt-3 alert alert-info d-flex align-items-center" role="status" aria-live="polite">
      <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>
      Aplicando filtros...
    </div>
  </form>

  <div class="card shadow-sm historico-table-card" id="historico-table-card">
    <div class="historico-summary">
      <div>
        <span class="historico-count"><i class="bi bi-clock-history text-primary"></i> <?= count($filtered) ?> registros</span>
        <span class="text-muted ms-2">Mostrando <?= $h($visibleRows) ?> de <?= $h($totalFiltered) ?> registros</span>
      </div>
      <div class="historico-summary__tools">
        <?php if ($canChangeHistoryStatus): ?>
          <div class="dropdown historico-bulk-status">
            <button
              type="button"
              class="btn-nova btn-nova-primary historico-bulk-status__button dropdown-toggle"
              id="historico-bulk-status-button"
              data-bs-toggle="dropdown"
              aria-expanded="false"
              disabled>
              <i class="bi bi-kanban"></i>
              Cambiar estado
              <span class="historico-selection-count" id="historico-selection-count">0</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-end historico-status-menu" aria-labelledby="historico-bulk-status-button">
              <li class="dropdown-header">Aplicar a seleccionados</li>
              <?php foreach ($redmineStatusOptions as $statusId => $statusLabel): ?>
                <li>
                  <button
                    type="button"
                    class="dropdown-item js-bulk-status-choice"
                    data-status-id="<?= $h($statusId) ?>"
                    data-status-label="<?= $h($statusLabel) ?>">
                    <span class="historico-status-dot is-status-<?= $h($statusId) ?>"></span>
                    <?= $h($statusLabel) ?>
                  </button>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
          <form method="post" action="<?= $h($historicoActionUrl) ?>" id="historico-bulk-status-form" class="d-none js-redmine-status-form"
                data-app-no-loading="1"
                data-app-confirm-title="Cambiar estado en Redmine"
                data-app-confirm-tone="info"
                data-app-confirm-text="Cambiar estado">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="update_redmine_status">
            <input type="hidden" name="redmine_ids" id="historico-bulk-redmine-ids" value="">
            <input type="hidden" name="status_id" id="historico-bulk-status-id" value="">
          </form>
        <?php endif; ?>
        <label class="form-check form-switch m-0">
          <input class="form-check-input" type="checkbox" role="switch" id="historico-compact-toggle">
          <span class="form-check-label fw-semibold">Modo compacto</span>
        </label>
        <div class="historico-page-size text-muted small">
          Página <?= $h($currentPage) ?> de <?= $h($totalPages) ?>
        </div>
      </div>
    </div>
    <?php if (!empty($historicoFilterChips)): ?>
      <div class="historico-filter-chips" aria-label="Filtros activos">
        <?php foreach ($historicoFilterChips as $chip): ?>
          <a class="historico-filter-chip" href="<?= $h($historicoChipUrl($chip['remove'])) ?>" title="Quitar filtro">
            <i class="bi <?= $h($chip['icon']) ?>"></i>
            <?= $h($chip['label']) ?>
            <i class="bi bi-x"></i>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div id="redmine-sync-panel" class="historico-redmine-sync d-none" role="status" aria-live="polite">
      <div class="historico-redmine-sync__header">
        <span><i class="bi bi-arrow-repeat"></i> Sincronizando estados con Redmine</span>
        <strong id="redmine-sync-count">0/0</strong>
      </div>
      <div class="progress" aria-hidden="true">
        <div id="redmine-sync-bar" class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
      </div>
    </div>
    <div class="card-body p-0 position-relative">
      <div class="table-responsive position-relative">
        <div id="table-loader" class="loader-overlay d-none" role="status" aria-live="polite">
          <div class="d-flex align-items-center gap-2">
            <span class="spinner-border spinner-border-lg text-primary" role="status" aria-hidden="true"></span>
            <strong>Cargando registros…</strong>
          </div>
        </div>
        <table class="table table-hover historico-table align-middle mb-0" role="grid" aria-label="Histórico de reportes" aria-busy="false">
          <colgroup>
            <?php if ($canChangeHistoryStatus): ?><col class="historico-col-select"><?php endif; ?>
            <col class="historico-col-date">
            <col class="historico-col-id">
            <col class="historico-col-redmine-status">
            <col class="historico-col-requester">
            <col class="historico-col-category">
            <col class="historico-col-department">
            <col class="historico-col-subject">
            <col class="historico-col-source">
            <col class="historico-col-detail">
            <?php if ($showActions): ?><col class="historico-col-actions"><?php endif; ?>
          </colgroup>
          <thead class="table-light">
            <tr class="position-sticky top-0 bg-light">
              <?php if ($canChangeHistoryStatus): ?>
                <th scope="col" class="historico-select-cell">
                  <input
                    class="form-check-input js-history-select-all"
                    type="checkbox"
                    id="historico-select-all"
                    aria-label="Seleccionar todos los reportes abiertos"
                    disabled>
                </th>
              <?php endif; ?>
              <th scope="col">Fecha</th>
              <th scope="col">Redmine ID</th>
              <th scope="col">Estado Redmine</th>
              <th scope="col" class="text-truncate" style="max-width: 160px;">Solicitante</th>
              <th scope="col" class="text-truncate" style="max-width: 220px;">Categoría</th>
              <th scope="col" class="text-truncate" style="max-width: 140px;">Departamento</th>
              <th scope="col">Asunto</th>
              <th scope="col">Fuente</th>
              <th scope="col">Detalle</th>
              <?php if ($showActions): ?>
                <th scope="col" class="historico-actions-cell">Acciones</th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($pagedRows)): ?>
              <tr><td colspan="<?= $tableColspan ?>" class="nova-empty"><i class="bi bi-archive" style="font-size:1.5rem;display:block;margin-bottom:.4rem;opacity:.35"></i>Sin registros para el criterio seleccionado.</td></tr>
            <?php else: ?>
              <?php foreach ($pagedRows as $row): ?>
                <?php
                  $previewRows = dashboard_detail_preview_rows($row);
                  $previewRowsJson = $h((string)json_encode(array_values($previewRows), JSON_UNESCAPED_UNICODE));
                  $previewColumnsJson = $h((string)json_encode(dashboard_core_detail_table_schema($row), JSON_UNESCAPED_UNICODE));
                  // La descripción también contiene los datos completos usados al
                  // generar reportes CORE. No limitarla a registros manuales.
                  $detalleDescripcion = trim((string)($row['descripcion'] ?? ''));
                  $isManual = strtolower(trim((string)($row['fuente'] ?? ''))) === 'manual';
                  $sourceLabel = $isManual ? 'Manual' : 'CORE';
                  $sourceIcon = $isManual ? 'bi-pencil-square' : 'bi-cloud-arrow-down';
                  $isHoraExtra = normalize_hour_extra_value($row['hora_extra'] ?? '') === '1';
                  $redmineId = trim((string)($row['redmine_id'] ?? ''));
                  $redmineIssueUrl = $historicoService->redmineIssueUrl($redminePlatformUrl, $redmineId);
                ?>
                <tr data-redmine-row="<?= $h($redmineId) ?>">
                  <?php if ($canChangeHistoryStatus): ?>
                    <td class="historico-select-cell">
                      <?php if ($redmineId !== ''): ?>
                        <input
                          class="form-check-input js-history-select"
                          type="checkbox"
                          value="<?= $h($redmineId) ?>"
                          data-redmine-id="<?= $h($redmineId) ?>"
                          aria-label="Seleccionar ticket Redmine <?= $h($redmineId) ?>"
                          disabled>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
                  <td><span class="historico-date"><i class="bi bi-calendar3"></i><?= $h($historicoService->formatDate($row['_fecha_norm'] ?? '')) ?></span></td>
                  <td>
                    <?php if ($redmineId !== '' && $redmineIssueUrl !== ''): ?>
                      <a class="historico-redmine-link" href="<?= $h($redmineIssueUrl) ?>" target="_blank" rel="noopener">
                        <i class="bi bi-box-arrow-up-right"></i> <?= $h($redmineId) ?>
                      </a>
                    <?php else: ?>
                      <span class="text-muted"><?= $h($redmineId !== '' ? $redmineId : '-') ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($redmineId !== ''): ?>
                      <span
                        class="historico-redmine-status historico-redmine-status--syncing js-redmine-status"
                        data-redmine-id="<?= $h($redmineId) ?>"
                        title="Sincronizando con Redmine">
                        <i class="bi bi-arrow-repeat"></i>
                        <span>Sincronizando</span>
                      </span>
                    <?php else: ?>
                      <span class="text-muted">-</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-truncate" style="max-width: 160px;" title="<?= $h($row['solicitante'] ?? '') ?>"><?= $h($row['solicitante'] ?? '') ?></td>
                  <td class="text-truncate" style="max-width: 220px;" title="<?= $h($row['categoria'] ?? '') ?>"><?= $h($row['categoria'] ?? '') ?></td>
                  <td class="text-truncate" style="max-width: 140px;" title="<?= $h($row['core_departamento'] ?? ($row['unidad'] ?? '')) ?>"><?= $h($row['core_departamento'] ?? ($row['unidad'] ?? '')) ?></td>
                  <td class="text-truncate" title="<?= $h($row['asunto'] ?? '') ?>"><?= $h($row['asunto'] ?? '-') ?></td>
                  <td>
                    <span class="historico-source-badge <?= $isManual ? 'is-manual' : 'is-core' ?>" title="<?= $h($isManual ? 'Creado manualmente' : 'Importado desde CORE') ?>">
                      <i class="bi <?= $h($sourceIcon) ?>"></i>
                      <?= $h($sourceLabel) ?>
                      <?php if ($isHoraExtra): ?>
                        <span class="historico-overtime-icon" title="Hora extra" aria-label="Hora extra"><i class="bi bi-clock-fill"></i></span>
                      <?php endif; ?>
                    </span>
                  </td>
                  <td>
                    <button
                      type="button"
                      class="btn-action btn-action-view historico-detail-btn"
                      data-bs-toggle="modal"
                      data-bs-target="#historicoDetalleModal"
                      data-preview_rows="<?= $previewRowsJson ?>"
                      data-preview_columns="<?= $previewColumnsJson ?>"
                      data-fuente="<?= $h($row['fuente'] ?? '') ?>"
                      data-core_tipo_solicitud="<?= $h($row['core_tipo_solicitud'] ?? '') ?>"
                      data-asunto="<?= $h($row['asunto'] ?? '') ?>"
                      data-solicitante="<?= $h($row['solicitante'] ?? '') ?>"
                      data-descripcion="<?= $h($detalleDescripcion) ?>"
                      data-fecha="<?= $h($historicoService->formatDate($row['_fecha_norm'] ?? '')) ?>"
                      data-redmine-id="<?= $h($redmineId) ?>"
                      data-estado-redmine="<?= $h($row['estado_redmine'] ?? $row['redmine_estado'] ?? $row['status_name'] ?? '') ?>"
                      data-categoria="<?= $h($row['categoria'] ?? '') ?>"
                      data-establecimiento="<?= $h($row['core_establecimiento'] ?? ($row['unidad_solicitante'] ?? '')) ?>"
                      data-departamento="<?= $h($row['core_departamento'] ?? ($row['unidad'] ?? '')) ?>"
                      data-asignado="<?= $h($row['core_usuario_asignado'] ?? ($row['asignado_nombre'] ?? ($row['asignado_a'] ?? ''))) ?>"
                      data-fuente-label="<?= $h($sourceLabel) ?>"
                      data-hora-extra="<?= $isHoraExtra ? 'Sí' : 'No' ?>"
                      data-prioridad="<?= $h($row['prioridad'] ?? '') ?>"
                      data-tipo="<?= $h($row['tipo'] ?? $row['core_tipo_solicitud'] ?? '') ?>"
                      data-correo="<?= $h($row['core_email'] ?? $row['correo'] ?? '') ?>"
                      data-anexo="<?= $h($row['anexo'] ?? $row['core_telefono'] ?? '') ?>"
                      data-tiempo-estimado="<?= $h($row['tiempo_estimado'] ?? '') ?>"
                      data-fecha-inicio="<?= $h($historicoService->formatDate($row['fecha_inicio'] ?? '')) ?>"
                      data-fecha-fin="<?= $h($historicoService->formatDate($row['fecha_fin'] ?? '')) ?>"
                      title="Ver detalle"
                      aria-label="Ver detalle"
                    >
                      <i class="bi bi-eye"></i>
                    </button>
                  </td>
                  <?php if ($showActions): ?>
                    <td class="historico-actions-cell">
                      <div class="historico-row-actions">
                        <?php if ($canChangeHistoryStatus && $redmineId !== ''): ?>
                          <div class="dropdown">
                            <button
                              type="button"
                              class="btn-action btn-action-sync dropdown-toggle no-caret js-redmine-status-menu d-none"
                              data-redmine-id="<?= $h($redmineId) ?>"
                              data-bs-toggle="dropdown"
                              data-bs-boundary="viewport"
                              aria-expanded="false"
                              title="Cambiar estado en Redmine"
                              aria-label="Cambiar estado del ticket <?= $h($redmineId) ?>">
                              <i class="bi bi-arrow-repeat"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end historico-status-menu">
                              <li class="dropdown-header">Cambiar estado #<?= $h($redmineId) ?></li>
                              <?php foreach ($redmineStatusOptions as $statusId => $statusLabel): ?>
                                <li>
                                  <form
                                    method="post"
                                    action="<?= $h($historicoActionUrl) ?>"
                                    class="m-0 js-redmine-status-form"
                                    data-app-no-loading="1"
                                    data-app-confirm="¿Cambiar el ticket #<?= $h($redmineId) ?> a <?= $h($statusLabel) ?>?"
                                    data-app-confirm-title="Cambiar estado en Redmine"
                                    data-app-confirm-tone="info"
                                    data-app-confirm-text="Cambiar estado">
                                    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                                    <input type="hidden" name="action" value="update_redmine_status">
                                    <input type="hidden" name="redmine_ids" value="<?= $h($redmineId) ?>">
                                    <input type="hidden" name="status_id" value="<?= $h($statusId) ?>">
                                    <button type="submit" class="dropdown-item">
                                      <span class="historico-status-dot is-status-<?= $h($statusId) ?>"></span>
                                      <?= $h($statusLabel) ?>
                                    </button>
                                  </form>
                                </li>
                              <?php endforeach; ?>
                            </ul>
                          </div>
                        <?php endif; ?>
                        <?php if ($canDeleteHistory): ?>
                          <form method="post" action="<?= $h($historicoActionUrl) ?>" class="m-0" data-app-confirm="Eliminar este registro del histórico?">
                            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $h($row['id'] ?? '') ?>">
                            <input type="hidden" name="fuente" value="<?= $h($row['_fuente'] ?? '') ?>">
                            <button type="submit" class="btn-action btn-action-delete" title="Eliminar" aria-label="Eliminar">
                              <i class="bi bi-trash"></i>
                            </button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php
        $windowStart = max(1, $currentPage - 2);
        $windowEnd = min($totalPages, $currentPage + 2);
      ?>
      <nav class="historico-pagination" aria-label="Paginación histórico">
        <div class="historico-pagination__left">
          <form method="get" class="historico-page-size-form">
            <input type="hidden" name="desde" value="<?= $h($f_desde) ?>">
            <input type="hidden" name="hasta" value="<?= $h($f_hasta) ?>">
            <input type="hidden" name="fuente" value="<?= $h($f_fuente) ?>">
            <input type="hidden" name="estado_redmine" value="<?= $h($f_estado_redmine) ?>">
            <input type="hidden" name="descripcion" value="<?= $h($f_descripcion) ?>">
            <input type="hidden" name="buscar" value="<?= $h($f_busqueda) ?>">
            <input type="hidden" name="categoria" value="<?= $h($f_categoria) ?>">
            <input type="hidden" name="usuario" value="<?= $h($f_usuario) ?>">
            <input type="hidden" name="mensajes_scope" value="<?= $h($f_scope) ?>">
            <input type="hidden" name="page" value="1">
            <label for="historico-per-page" class="form-label mb-0">Mostrar</label>
            <select id="historico-per-page" name="per_page" class="form-select form-select-sm" onchange="this.form.submit()">
              <?php foreach ($perPageOptions as $option): ?>
                <option value="<?= $h($option) ?>" <?= $option === $perPage ? 'selected' : '' ?>><?= $h($option) ?></option>
              <?php endforeach; ?>
            </select>
            <span>registros</span>
          </form>
          <div class="historico-pagination__meta">
            Mostrando <?= $h($visibleRows) ?> de <?= $h($totalFiltered) ?> registros
          </div>
        </div>
        <?php if ($totalPages > 1): ?>
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $h($historicoPageUrl(1)) ?>" aria-label="Primera">&laquo;</a>
            </li>
            <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $h($historicoPageUrl($currentPage - 1)) ?>" aria-label="Anterior">Anterior</a>
            </li>
            <?php for ($page = $windowStart; $page <= $windowEnd; $page++): ?>
              <li class="page-item <?= $page === $currentPage ? 'active' : '' ?>">
                <a class="page-link" href="<?= $h($historicoPageUrl($page)) ?>"><?= $h($page) ?></a>
              </li>
            <?php endfor; ?>
            <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $h($historicoPageUrl($currentPage + 1)) ?>" aria-label="Siguiente">Siguiente</a>
            </li>
            <li class="page-item <?= $currentPage >= $totalPages ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= $h($historicoPageUrl($totalPages)) ?>" aria-label="Última">&raquo;</a>
            </li>
          </ul>
        <?php endif; ?>
      </nav>
    </div>
  </div>


  <div class="modal fade rm-side-drawer detail-drawer-modal" id="historicoDetalleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable rm-side-drawer-dialog detail-drawer-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Detalle histórico</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <div class="fw-semibold" id="historico-detalle-titulo"></div>
            <div class="text-muted small" id="historico-detalle-solicitante"></div>
          </div>
          <dl class="historico-detail-facts" id="historico-detail-facts">
            <div><dt><i class="bi bi-calendar3"></i>Fecha</dt><dd data-history-fact="fecha">-</dd></div>
            <div><dt><i class="bi bi-box-arrow-up-right"></i>Redmine ID</dt><dd data-history-fact="redmineId">-</dd></div>
            <div><dt><i class="bi bi-folder2-open"></i>Estado Redmine</dt><dd data-history-fact="estadoRedmine">-</dd></div>
            <div><dt><i class="bi bi-cloud-arrow-down"></i>Fuente</dt><dd data-history-fact="fuenteLabel">-</dd></div>
            <div><dt><i class="bi bi-ticket-perforated"></i>Tipo</dt><dd data-history-fact="tipo">-</dd></div>
            <div><dt><i class="bi bi-flag"></i>Prioridad</dt><dd data-history-fact="prioridad">-</dd></div>
            <div><dt><i class="bi bi-tags"></i>Categoría</dt><dd data-history-fact="categoria">-</dd></div>
            <div><dt><i class="bi bi-person-check"></i>Asignado a</dt><dd data-history-fact="asignado">-</dd></div>
            <div><dt><i class="bi bi-building"></i>Establecimiento</dt><dd data-history-fact="establecimiento">-</dd></div>
            <div><dt><i class="bi bi-diagram-3"></i>Departamento</dt><dd data-history-fact="departamento">-</dd></div>
            <div><dt><i class="bi bi-calendar-event"></i>Fecha inicio</dt><dd data-history-fact="fechaInicio">-</dd></div>
            <div><dt><i class="bi bi-calendar-check"></i>Fecha fin</dt><dd data-history-fact="fechaFin">-</dd></div>
            <div><dt><i class="bi bi-clock-history"></i>Hora extra</dt><dd data-history-fact="horaExtra">-</dd></div>
            <div><dt><i class="bi bi-hourglass-split"></i>Tiempo estimado</dt><dd data-history-fact="tiempoEstimado">-</dd></div>
            <div><dt><i class="bi bi-envelope"></i>Correo</dt><dd data-history-fact="correo">-</dd></div>
            <div><dt><i class="bi bi-telephone"></i>Anexo</dt><dd data-history-fact="anexo">-</dd></div>
          </dl>
          <div id="historico-detalle-tabla-wrap" class="table-responsive border rounded">
            <table class="table table-sm mb-0 align-middle">
              <thead class="table-light" id="historico-detalle-head"></thead>
              <tbody id="historico-detalle-body"></tbody>
            </table>
          </div>
          <div id="historico-detalle-descripcion-wrap" class="d-none">
            <div class="form-label fw-semibold"><i class="bi bi-table me-2"></i>Descripción / datos del reporte</div>
            <div id="historico-detalle-descripcion" class="nova-description-preview historico-description-preview"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const form = document.getElementById('filter-form');
      const feedback = document.getElementById('filter-feedback');
      const table = document.querySelector('table[role=\"grid\"]');
      const loader = document.getElementById('table-loader');
      const btnApply = document.getElementById('btn-apply');
      const btnClear = document.getElementById('btn-clear');

      const setLoading = (state) => {
        if (feedback) feedback.classList.toggle('d-none', !state);
        if (loader) loader.classList.toggle('d-none', !state);
        if (table) table.setAttribute('aria-busy', state ? 'true' : 'false');
        if (btnApply) {
          btnApply.disabled = state;
          btnApply.setAttribute('aria-pressed', state ? 'true' : 'false');
        }
      };

      if (form) {
        form.addEventListener('submit', function (event) {
          event.preventDefault();
          setLoading(true);
          setTimeout(() => form.submit(), 60);
        });
      }

      if (btnClear) {
        btnClear.addEventListener('click', function () {
          btnClear.setAttribute('aria-pressed', 'true');
        });
      }

      setLoading(false);

      const initializeHistoricoTable = () => {
      const statusBadges = Array.from(document.querySelectorAll('.js-redmine-status[data-redmine-id]'));
      const syncPanel = document.getElementById('redmine-sync-panel');
      const syncBar = document.getElementById('redmine-sync-bar');
      const syncCount = document.getElementById('redmine-sync-count');
      const historicoTableCard = document.getElementById('historico-table-card');
      const historicoCompactToggle = document.getElementById('historico-compact-toggle');
      const historicoCompactKey = 'redmine-mantencion-historico-compact';
      const selectAll = document.getElementById('historico-select-all');
      const rowCheckboxes = Array.from(document.querySelectorAll('.js-history-select[data-redmine-id]'));
      const bulkStatusButton = document.getElementById('historico-bulk-status-button');
      const selectionCount = document.getElementById('historico-selection-count');
      const bulkStatusForm = document.getElementById('historico-bulk-status-form');
      const bulkRedmineIds = document.getElementById('historico-bulk-redmine-ids');
      const bulkStatusId = document.getElementById('historico-bulk-status-id');

      if (window.bootstrap?.Dropdown) {
        document.querySelectorAll('.js-redmine-status-menu').forEach(trigger => {
          const dropdown = trigger.closest('.dropdown');
          const menu = dropdown?.querySelector('.historico-status-menu');
          if (!dropdown || !menu) return;

          trigger.addEventListener('show.bs.dropdown', () => {
            menu.classList.add('is-portal');
            document.body.appendChild(menu);
          });
          trigger.addEventListener('hidden.bs.dropdown', () => {
            menu.classList.remove('is-portal');
            dropdown.appendChild(menu);
          });

          window.bootstrap.Dropdown.getOrCreateInstance(trigger, {
            boundary: 'viewport',
            popperConfig(defaultConfig) {
              return { ...defaultConfig, strategy: 'fixed' };
            },
          });
        });
      }

      const selectedOpenIds = () => [...new Set(
        rowCheckboxes
          .filter(checkbox => !checkbox.disabled && checkbox.checked)
          .map(checkbox => checkbox.value)
          .filter(Boolean)
      )];
      const refreshSelectionState = () => {
        const enabled = rowCheckboxes.filter(checkbox => !checkbox.disabled);
        const selected = selectedOpenIds();
        if (selectionCount) selectionCount.textContent = String(selected.length);
        if (bulkStatusButton) bulkStatusButton.disabled = selected.length === 0;
        if (selectAll) {
          selectAll.disabled = enabled.length === 0;
          selectAll.checked = enabled.length > 0 && enabled.every(checkbox => checkbox.checked);
          selectAll.indeterminate = selected.length > 0 && selected.length < enabled.length;
        }
      };

      rowCheckboxes.forEach(checkbox => checkbox.addEventListener('change', refreshSelectionState));
      selectAll?.addEventListener('change', () => {
        rowCheckboxes.forEach(checkbox => {
          if (!checkbox.disabled) checkbox.checked = selectAll.checked;
        });
        refreshSelectionState();
      });
      document.querySelectorAll('.js-bulk-status-choice').forEach(choice => {
        choice.addEventListener('click', () => {
          const ids = selectedOpenIds();
          const statusId = choice.getAttribute('data-status-id') || '';
          const statusLabel = choice.getAttribute('data-status-label') || '';
          if (!ids.length || !statusId || !bulkStatusForm || !bulkRedmineIds || !bulkStatusId) return;
          bulkRedmineIds.value = ids.join(',');
          bulkStatusId.value = statusId;
          bulkStatusForm.dataset.appConfirm = `¿Cambiar ${ids.length} ticket(s) seleccionado(s) a “${statusLabel}”?`;
          delete bulkStatusForm.dataset.appConfirmAccepted;
          bulkStatusForm.requestSubmit();
        });
      });

      if (historicoTableCard && historicoCompactToggle) {
        const savedCompact = localStorage.getItem(historicoCompactKey) === '1';
        historicoTableCard.classList.toggle('is-compact', savedCompact);
        historicoCompactToggle.checked = savedCompact;
        historicoCompactToggle.addEventListener('change', () => {
          const enabled = historicoCompactToggle.checked;
          historicoTableCard.classList.toggle('is-compact', enabled);
          localStorage.setItem(historicoCompactKey, enabled ? '1' : '0');
        });
      }

      const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#039;');
      const normalizeStatus = value => String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
      const redmineStatusTone = statusName => {
        const key = normalizeStatus(statusName);
        if (key.includes('nueva') || key.includes('new')) return 'historico-redmine-status--new';
        if (key.includes('curso') || key.includes('progress') || key.includes('proceso')) return 'historico-redmine-status--progress';
        if (key.includes('resuelt') || key.includes('resolved')) return 'historico-redmine-status--resolved';
        if (key.includes('rechaz') || key.includes('reject')) return 'historico-redmine-status--rejected';
        return 'historico-redmine-status--open';
      };
      const setBadgeStatus = (badge, status) => {
        const available = Boolean(status && status.available);
        const closed = Boolean(status && status.closed);
        const statusName = String((status && status.name) || '');
        const message = String((status && status.message) || '');
        const cssClass = !available
          ? 'historico-redmine-status--unknown'
          : (closed ? 'historico-redmine-status--closed' : redmineStatusTone(statusName));
        const iconClass = !available ? 'bi-question-circle' : (closed ? 'bi-lock-fill' : 'bi-folder2-open');
        const label = !available ? 'No disponible' : (closed ? 'Cerrado' : 'Abierto');
        const detail = available && !closed && statusName ? `<small>${escapeHtml(statusName)}</small>` : '';

        badge.className = `historico-redmine-status js-redmine-status ${cssClass}`;
        badge.title = available ? `Redmine: ${statusName}` : message;
        badge.innerHTML = `<i class="bi ${iconClass}"></i><span>${escapeHtml(label)}</span>${detail}`;

        const redmineId = badge.getAttribute('data-redmine-id') || '';
        if (redmineId) {
          const open = available && !closed;
          document.querySelectorAll(`.js-history-select[data-redmine-id="${CSS.escape(redmineId)}"]`).forEach(checkbox => {
            checkbox.disabled = !open;
            if (!open) checkbox.checked = false;
          });
          document.querySelectorAll(`.js-redmine-status-menu[data-redmine-id="${CSS.escape(redmineId)}"]`).forEach(menu => {
            menu.classList.toggle('d-none', !open);
          });
          refreshSelectionState();
        }
      };

      const syncRedmineStatuses = async () => {
        const ids = [...new Set(statusBadges.map(badge => badge.getAttribute('data-redmine-id')).filter(Boolean))];
        if (!ids.length) return;

        const redmineStatusEndpoint = <?= json_encode($historicoActionUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        const chunkSize = 5;
        const chunks = [];
        for (let index = 0; index < ids.length; index += chunkSize) {
          chunks.push(ids.slice(index, index + chunkSize));
        }

        let done = 0;
        if (syncPanel) syncPanel.classList.remove('d-none');
        if (syncCount) syncCount.textContent = `0/${ids.length}`;
        if (syncBar) syncBar.style.width = '0%';

        for (const chunk of chunks) {
          try {
            const statusUrl = new URL(redmineStatusEndpoint, window.location.href);
            statusUrl.searchParams.set('ajax', 'redmine_statuses');
            statusUrl.searchParams.set('ids', chunk.join(','));
            const response = await fetch(statusUrl.toString(), {
              headers: { 'Accept': 'application/json' },
              cache: 'no-store',
            });
            if (!response.ok) {
              throw new Error(`No se pudo consultar Redmine (HTTP ${response.status}).`);
            }
            const payload = await response.json();
            const statuses = payload && payload.statuses ? payload.statuses : {};
            chunk.forEach(id => {
              document.querySelectorAll(`.js-redmine-status[data-redmine-id="${CSS.escape(id)}"]`).forEach(badge => {
                setBadgeStatus(badge, statuses[id] || { available: false, message: 'Sin respuesta desde Redmine' });
              });
            });
          } catch (error) {
            chunk.forEach(id => {
              document.querySelectorAll(`.js-redmine-status[data-redmine-id="${CSS.escape(id)}"]`).forEach(badge => {
                setBadgeStatus(badge, { available: false, message: 'No se pudo sincronizar con Redmine' });
              });
            });
          }

          done += chunk.length;
          const percent = Math.min(100, Math.round((done / ids.length) * 100));
          if (syncCount) syncCount.textContent = `${Math.min(done, ids.length)}/${ids.length}`;
          if (syncBar) syncBar.style.width = `${percent}%`;
        }

        if (syncPanel) {
          syncPanel.classList.add('historico-redmine-sync--done');
          setTimeout(() => syncPanel.classList.add('d-none'), 1200);
        }
      };

      syncRedmineStatuses();
      };

      const refreshHistoricoTable = async (url) => {
        const response = await fetch(url, {
          headers: {
            'Accept': 'text/html',
            'X-Requested-With': 'XMLHttpRequest',
          },
          cache: 'no-store',
        });
        if (!response.ok) {
          throw new Error(`No se pudo actualizar la tabla (HTTP ${response.status}).`);
        }

        const html = await response.text();
        const documentResult = new DOMParser().parseFromString(html, 'text/html');
        const updatedCard = documentResult.getElementById('historico-table-card');
        const currentCard = document.getElementById('historico-table-card');
        if (!updatedCard || !currentCard) {
          throw new Error('El servidor no devolvió la tabla del histórico.');
        }

        currentCard.replaceWith(updatedCard);
        initializeHistoricoTable();
      };

      const submitRedmineStatus = async (statusForm) => {
        if (statusForm.dataset.statusSubmitting === '1') return;
        statusForm.dataset.statusSubmitting = '1';

        const currentCard = document.getElementById('historico-table-card');
        const currentLoader = currentCard?.querySelector('#table-loader');
        currentCard?.classList.add('nova-card-loading');
        currentLoader?.classList.remove('d-none');

        try {
          const response = await fetch(statusForm.action, {
            method: 'POST',
            body: new FormData(statusForm),
            headers: {
              'Accept': 'application/json',
              'X-Requested-With': 'XMLHttpRequest',
            },
          });
          const payload = await response.json().catch(() => null);
          if (!response.ok || !payload || payload.ok !== true) {
            throw new Error(payload?.message || `No se pudo cambiar el estado (HTTP ${response.status}).`);
          }

          await refreshHistoricoTable(statusForm.action);
          if (Array.isArray(payload.errors) && payload.errors.length > 0) {
            window.NovaToast?.warning(payload.message || 'Algunos reportes no pudieron actualizarse.');
          } else {
            window.NovaToast?.success(payload.message || 'Estados actualizados correctamente.');
          }
        } catch (error) {
          currentCard?.classList.remove('nova-card-loading');
          currentLoader?.classList.add('d-none');
          window.NovaToast?.error(error.message || 'No se pudo cambiar el estado en Redmine.');
        } finally {
          delete statusForm.dataset.statusSubmitting;
          delete statusForm.dataset.appConfirmAccepted;
        }
      };

      document.addEventListener('submit', event => {
        const statusForm = event.target instanceof HTMLFormElement && event.target.matches('.js-redmine-status-form')
          ? event.target
          : null;
        if (!statusForm) return;
        if (statusForm.matches('[data-app-confirm]') && statusForm.dataset.appConfirmAccepted !== '1') return;

        event.preventDefault();
        event.stopPropagation();
        submitRedmineStatus(statusForm);
      });

      initializeHistoricoTable();

      const historicoDetalleModal = document.getElementById('historicoDetalleModal');
      if (historicoDetalleModal) {
        historicoDetalleModal.addEventListener('show.bs.modal', function (event) {
          const triggerBtn = event.relatedTarget;
          if (!triggerBtn) return;

          const titleEl = document.getElementById('historico-detalle-titulo');
          const subtitleEl = document.getElementById('historico-detalle-solicitante');
          const tableWrap = document.getElementById('historico-detalle-tabla-wrap');
          const tableHead = document.getElementById('historico-detalle-head');
          const tableBody = document.getElementById('historico-detalle-body');
          const descriptionWrap = document.getElementById('historico-detalle-descripcion-wrap');
          const descriptionField = document.getElementById('historico-detalle-descripcion');

          const escapeHtml = value => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\"/g, '&quot;')
            .replace(/'/g, '&#039;');

          let rows = [];
          let columns = [];
          try {
            rows = JSON.parse(triggerBtn.getAttribute('data-preview_rows') || '[]');
          } catch (error) {
            rows = [];
          }
          try {
            columns = JSON.parse(triggerBtn.getAttribute('data-preview_columns') || '[]');
          } catch (error) {
            columns = [];
          }

          const fuente = (triggerBtn.getAttribute('data-fuente') || '').trim().toLowerCase();
          const asunto = triggerBtn.getAttribute('data-asunto') || triggerBtn.getAttribute('data-core_tipo_solicitud') || 'Detalle histórico';
          const solicitante = triggerBtn.getAttribute('data-solicitante') || '';
          const descripcion = triggerBtn.getAttribute('data-descripcion') || '';

          if (titleEl) titleEl.textContent = asunto;
          if (subtitleEl) subtitleEl.textContent = solicitante ? `Solicitante: ${solicitante}` : '';

          document.querySelectorAll('#historico-detail-facts [data-history-fact]').forEach(field => {
            const key = field.dataset.historyFact || '';
            const value = triggerBtn.dataset[key] || '';
            field.textContent = value.trim() || '-';
          });

          const renderDescription = () => {
            if (!descriptionField) return;
            if (window.NovaDescriptionTables?.render) {
              window.NovaDescriptionTables.render({ value: descripcion }, descriptionField);
              return;
            }
            descriptionField.textContent = descripcion || 'Sin descripción.';
          };

          if (fuente === 'manual') {
            if (tableWrap) tableWrap.classList.add('d-none');
            if (descriptionWrap) descriptionWrap.classList.remove('d-none');
            renderDescription();
            return;
          }

          // Los reportes CORE archivados conservan en `descripcion` campos que no
          // tienen columnas propias (motivo, permisos, teléfono, correo, etc.).
          // Mostrar ese bloque junto con la vista tabular evita perderlos.
          if (descriptionWrap) descriptionWrap.classList.toggle('d-none', descripcion.trim() === '');
          if (tableWrap) tableWrap.classList.remove('d-none');
          renderDescription();

          if (!Array.isArray(columns) || columns.length === 0) {
            columns = [{ label: 'Detalle', key: 'detalle_nombre' }];
          }
          if (tableHead) {
            tableHead.innerHTML = `<tr>${columns.map(col => `<th>${escapeHtml(col.label || '')}</th>`).join('')}</tr>`;
          }
          if (tableBody) {
            if (!Array.isArray(rows) || rows.length === 0) {
              tableBody.innerHTML = `<tr><td colspan="${columns.length}" class="text-center text-muted py-4">Sin detalle para mostrar.</td></tr>`;
            } else {
              tableBody.innerHTML = rows.map(row => `
                <tr>
                  ${columns.map(col => `<td>${escapeHtml(row[col.key] || '')}</td>`).join('')}
                </tr>
              `).join('');
            }
          }
        });
      }
    });

  </script>
<?php include base_path('RedmineMantencion/views/partials/bootstrap-scripts.php'); ?>
<button id="historico-scroll-top" type="button" title="Volver arriba" aria-label="Volver arriba" class="btn btn-primary nova-scroll-top">
    <i class="bi bi-arrow-up"></i>
</button>
</div> <!-- #page-content -->
</body>
</html>
