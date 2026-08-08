<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Historial Nextcloud'; $includeTheme = true; include base_path('RedmineMantencion/views/partials/bootstrap-head.php'); ?>
  <?php $nextcloudHistorialCssVersion = @filemtime(base_path('RedmineMantencion/assets/css/nextcloud-historial.css')) ?: time(); ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/nextcloud-historial.css?v=<?= (int)$nextcloudHistorialCssVersion ?>">
</head>
<body class="bg-light">
<?php $activeNav = 'integraciones_nextcloud_historial'; include base_path('RedmineMantencion/views/partials/navbar.php'); ?>

<div id="page-content">
  <div class="container-fluid py-4">
    <?php
      $heroIcon = 'bi-clock-history';
      $heroTitle = 'Historial Nextcloud';
      $heroSubtitle = 'Registro permanente de los lotes procesados en Nextcloud.';
      $heroExtras = '';
      include base_path('RedmineMantencion/views/partials/hero.php');
    ?>

    <?php if ($flash): ?>
      <div class="nova-alert-card is-success mb-3" role="alert">
        <i class="bi bi-check-circle"></i>
        <span><?= $h($flash) ?></span>
      </div>
    <?php endif; ?>

    <?php if ($batches): ?>
      <section class="card nextcloud-panel mb-3" aria-label="Filtros del historial">
        <div class="card-body p-3">
          <div class="row g-3 align-items-end">
            <div class="col-12 col-lg-8">
              <label class="form-label" for="nextcloud-history-search"><i class="bi bi-search"></i> Buscar</label>
              <input class="form-control" id="nextcloud-history-search" type="search" placeholder="Lote, usuario, nombre, correo o detalle" autocomplete="off">
            </div>
            <div class="col-12 col-lg-4">
              <label class="form-label" for="nextcloud-history-group"><i class="bi bi-people"></i> Grupo</label>
              <select class="form-select" id="nextcloud-history-group">
                <option value="">Todos los grupos</option>
                <?php foreach ($historyGroups as $group): ?>
                  <option value="<?= $h($group) ?>"><?= $h($group) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="text-muted small mt-2" id="nextcloud-history-count" aria-live="polite"></div>
        </div>
      </section>
      <div class="nova-empty-state mb-3" id="nextcloud-history-no-results" hidden>
        <div class="nova-empty-state-icon"><i class="bi bi-search"></i></div>
        <h3>Sin coincidencias</h3>
        <p>No hay registros que coincidan con la búsqueda y el grupo seleccionados.</p>
      </div>
    <?php endif; ?>

    <?php if (!$batches): ?>
      <div class="card nextcloud-panel">
        <div class="nova-empty-state">
          <div class="nova-empty-state-icon"><i class="bi bi-clock-history"></i></div>
          <h3>Sin historial disponible</h3>
          <p>Los nuevos lotes procesados en Nextcloud aparecerán aquí.</p>
        </div>
      </div>
    <?php endif; ?>

    <?php
      $historyRows = [];
      foreach ($batches as $batch) {
        $safeBatchId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($batch['id'] ?? uniqid()));
        $tableId = 'nextcloud-history-' . $safeBatchId;
        $modalId = 'nextcloud-history-modal-' . $safeBatchId;
        $createdUsers = (array)(($batch['created_users'] ?? null) ?: ($batch['users'] ?? []));
        $existingUsers = (array)($batch['existing_users'] ?? []);
        $failedUsers = (array)($batch['failed_users'] ?? []);
        $batchUsers = is_array($batch['result_users'] ?? null) ? (array)$batch['result_users'] : [];
        if (!$batchUsers) {
            foreach ($createdUsers as $item) {
                $item['status'] = 'created';
                $item['message'] = $item['message'] ?? 'Creado correctamente.';
                $batchUsers[] = $item;
            }
            foreach ($existingUsers as $item) {
                $item['status'] = 'existing';
                $item['message'] = $item['message'] ?? 'No se creó porque ya existe en Nextcloud.';
                $batchUsers[] = $item;
            }
            foreach ($failedUsers as $item) {
                $item['status'] = 'failed';
                $batchUsers[] = $item;
            }
        }
        foreach ($batchUsers as $idx => $item) {
            $status = (string)($item['status'] ?? '');
            if ($status === 'created') {
                $batchUsers[$idx]['_status'] = 'Creado';
                $batchUsers[$idx]['_badge'] = 'success';
                $batchUsers[$idx]['_row'] = 'table-success nextcloud-row-created';
            } elseif ($status === 'existing') {
                $batchUsers[$idx]['_status'] = 'Ya existe';
                $batchUsers[$idx]['_badge'] = 'warning';
                $batchUsers[$idx]['_row'] = 'table-warning nextcloud-row-existing';
            } else {
                $batchUsers[$idx]['_status'] = 'No creado';
                $batchUsers[$idx]['_badge'] = 'danger';
                $batchUsers[$idx]['_row'] = 'table-danger nextcloud-row-failed';
            }
        }
        $createdCount = count(array_filter($batchUsers, static fn($item): bool => (string)($item['status'] ?? '') === 'created'));
        $existingCount = count(array_filter($batchUsers, static fn($item): bool => (string)($item['status'] ?? '') === 'existing'));
        $failedCount = max(0, count($batchUsers) - $createdCount - $existingCount);
        $batchGroups = [];
        $searchParts = [
            (string)($batch['id'] ?? ''),
            (string)($batch['created_at'] ?? ''),
            (string)($batch['solicitante'] ?? ''),
            (string)($batch['solicitante_nombre'] ?? ''),
            (string)($batch['solicitante_rut'] ?? ''),
            (string)($batch['solicitante_correo'] ?? ''),
        ];
        $firstIssue = '';
        foreach ($batchUsers as $item) {
            $groupName = trim((string)($item['group'] ?? ''));
            if ($groupName !== '') $batchGroups[$groupName] = true;
            foreach (['userid', 'displayName', 'email', 'group', 'message'] as $field) {
                $searchParts[] = (string)($item[$field] ?? '');
            }
            if ($firstIssue === '' && (string)($item['status'] ?? '') !== 'created') {
                $firstIssue = trim((string)($item['message'] ?? ''));
            }
        }
        $batchGroups = array_keys($batchGroups);
        natcasesort($batchGroups);
        $batchGroups = array_values($batchGroups);
        $createdTimestamp = strtotime((string)($batch['created_at'] ?? ''));
        $historyRows[] = [
            'batch' => $batch,
            'users' => $batchUsers,
            'table_id' => $tableId,
            'modal_id' => $modalId,
            'created_count' => $createdCount,
            'existing_count' => $existingCount,
            'failed_count' => $failedCount,
            'groups' => $batchGroups,
            'date' => $createdTimestamp !== false ? date('d-m-Y H:i', $createdTimestamp) : 'Sin fecha',
            'search' => implode(' ', $searchParts),
            'detail' => $firstIssue !== '' ? $firstIssue : 'Importación completada sin incidencias.',
        ];
      }
    ?>

    <?php if ($historyRows): ?>
      <section class="card nextcloud-panel nextcloud-history-shell" aria-label="Importaciones de Nextcloud">
        <div class="nextcloud-history-head">
          <div>
            <h2><i class="bi bi-cloud-check" aria-hidden="true"></i> Importaciones procesadas</h2>
            <p>Resumen por lote. Abre el detalle para revisar todos los usuarios.</p>
          </div>
          <span class="nextcloud-history-total"><?= count($historyRows) ?> lote<?= count($historyRows) === 1 ? '' : 's' ?></span>
        </div>
        <div class="table-responsive nextcloud-history-wrap">
          <table class="table align-middle mb-0 nextcloud-history-table">
            <thead>
              <tr>
                <th>Fecha / lote</th>
                <th>Solicitante / contacto</th>
                <th>Resultado</th>
                <th>Grupos / detalle</th>
                <th class="text-end">Acción</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($historyRows as $row): ?>
                <?php
                  $batch = $row['batch'];
                  $requesterName = trim((string)($batch['solicitante_nombre'] ?? ''));
                  if ($requesterName === '') $requesterName = trim((string)($batch['solicitante'] ?? ''));
                  $statusClass = $row['failed_count'] > 0 ? 'is-danger' : ($row['existing_count'] > 0 ? 'is-warning' : 'is-success');
                  $statusLabel = $row['failed_count'] > 0 ? 'Con errores' : ($row['existing_count'] > 0 ? 'Completado con existentes' : 'Completado');
                ?>
                <tr data-history-batch data-history-search="<?= $h($row['search']) ?>" data-history-groups="<?= $h(implode('|', $row['groups'])) ?>">
                  <td class="nextcloud-history-date" data-label="Fecha / lote">
                    <span><i class="bi bi-calendar3" aria-hidden="true"></i><?= $h($row['date']) ?></span>
                    <small class="nextcloud-history-id">#<?= $h($batch['id'] ?? '') ?></small>
                  </td>
                  <td data-label="Solicitante / contacto">
                    <div class="nextcloud-history-requester">
                      <strong><?= $h($requesterName !== '' ? $requesterName : 'Sin información') ?></strong>
                      <div class="nextcloud-history-contact">
                        <span title="<?= $h($batch['solicitante_correo'] ?? '') ?>"><i class="bi bi-envelope" aria-hidden="true"></i><?= $h(($batch['solicitante_correo'] ?? '') !== '' ? $batch['solicitante_correo'] : 'No informado') ?></span>
                        <span><i class="bi bi-person-vcard" aria-hidden="true"></i><?= $h(($batch['solicitante_rut'] ?? '') !== '' ? $batch['solicitante_rut'] : 'RUT no informado') ?></span>
                      </div>
                    </div>
                  </td>
                  <td data-label="Resultado">
                    <span class="nextcloud-history-status <?= $h($statusClass) ?>"><?= $h($statusLabel) ?></span>
                    <div class="nextcloud-history-metrics">
                      <span class="is-created"><i class="bi bi-check-circle"></i><?= (int)$row['created_count'] ?> creados</span>
                      <span class="is-existing"><i class="bi bi-person-check"></i><?= (int)$row['existing_count'] ?> existentes</span>
                      <span class="is-failed"><i class="bi bi-exclamation-circle"></i><?= (int)$row['failed_count'] ?> errores</span>
                    </div>
                  </td>
                  <td data-label="Grupos / detalle">
                    <div class="nextcloud-history-context">
                      <div class="nextcloud-history-groups">
                        <?php if (!$row['groups']): ?>
                          <span class="is-empty">Sin grupo</span>
                        <?php else: ?>
                          <?php foreach (array_slice($row['groups'], 0, 2) as $groupName): ?>
                            <span title="<?= $h($groupName) ?>"><?= $h($groupName) ?></span>
                          <?php endforeach; ?>
                          <?php if (count($row['groups']) > 2): ?><span>+<?= count($row['groups']) - 2 ?></span><?php endif; ?>
                        <?php endif; ?>
                      </div>
                      <span class="nextcloud-history-detail" title="<?= $h($row['detail']) ?>"><?= $h($row['detail']) ?></span>
                    </div>
                  </td>
                  <td class="text-end" data-label="Acción">
                    <button type="button" class="btn-nova btn-nova-primary nextcloud-history-open" data-bs-toggle="modal" data-bs-target="#<?= $h($row['modal_id']) ?>" aria-label="Ver detalle del lote <?= $h($batch['id'] ?? '') ?>" title="Ver detalle">
                      <i class="bi bi-table" aria-hidden="true"></i>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <?php foreach ($historyRows as $row): ?>
        <?php
          $batch = $row['batch'];
          $requesterName = trim((string)($batch['solicitante_nombre'] ?? ''));
          if ($requesterName === '') $requesterName = trim((string)($batch['solicitante'] ?? ''));
        ?>
        <div class="modal fade nextcloud-history-modal" id="<?= $h($row['modal_id']) ?>" tabindex="-1" aria-labelledby="<?= $h($row['modal_id']) ?>-title" aria-hidden="true">
          <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
              <div class="modal-header">
                <div>
                  <p class="nextcloud-history-modal-kicker">Detalle de importación</p>
                  <h2 class="modal-title" id="<?= $h($row['modal_id']) ?>-title">Lote #<?= $h($batch['id'] ?? '') ?></h2>
                  <span><?= $h($row['date']) ?> · <?= count($row['users']) ?> usuario<?= count($row['users']) === 1 ? '' : 's' ?></span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
              </div>
              <div class="modal-body">
                <div class="nextcloud-history-requester-card">
                  <div><span>Nombre del solicitante</span><strong><?= $h($requesterName !== '' ? $requesterName : 'No informado') ?></strong></div>
                  <div><span>RUT</span><strong><?= $h(($batch['solicitante_rut'] ?? '') !== '' ? $batch['solicitante_rut'] : 'No informado') ?></strong></div>
                  <div><span>Correo</span><strong><?= $h(($batch['solicitante_correo'] ?? '') !== '' ? $batch['solicitante_correo'] : 'No informado') ?></strong></div>
                </div>
                <div class="table-responsive nextcloud-history-detail-wrap">
                  <table class="table table-sm align-middle mb-0 nextcloud-history-detail-table" id="<?= $h($row['table_id']) ?>">
                    <thead>
                      <tr>
                        <th>Estado</th>
                        <th>Usuario</th>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Grupo</th>
                        <th>Detalle</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($row['users'] as $item): ?>
                        <tr class="<?= $h($item['_row'] ?? '') ?>">
                          <td data-label="Estado"><span class="badge text-bg-<?= $h($item['_badge'] ?? 'secondary') ?>"><?= $h($item['_status'] ?? '') ?></span></td>
                          <td data-label="Usuario"><strong><?= $h($item['userid'] ?? '') ?></strong></td>
                          <td data-label="Nombre"><?= $h($item['displayName'] ?? '') ?></td>
                          <td data-label="Correo"><?= $h($item['email'] ?? '') ?></td>
                          <td data-label="Grupo"><?= $h($item['group'] ?? '') ?></td>
                          <td data-label="Detalle"><?= $h($item['message'] ?? '') ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<?php include base_path('RedmineMantencion/views/partials/bootstrap-scripts.php'); ?>
<script data-partial-nav-script>
document.addEventListener('DOMContentLoaded', () => {
  const search = document.getElementById('nextcloud-history-search');
  const group = document.getElementById('nextcloud-history-group');
  const count = document.getElementById('nextcloud-history-count');
  const noResults = document.getElementById('nextcloud-history-no-results');
  const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const applyFilters = () => {
    const term = normalize(search?.value);
    const selectedGroup = normalize(group?.value);
    let visibleBatches = 0;
    document.querySelectorAll('[data-history-batch]').forEach(batch => {
      const matchesSearch = term === '' || normalize(batch.dataset.historySearch).includes(term);
      const batchGroups = String(batch.dataset.historyGroups || '').split('|').map(normalize);
      const matchesGroup = selectedGroup === '' || batchGroups.includes(selectedGroup);
      batch.hidden = !(matchesSearch && matchesGroup);
      if (!batch.hidden) visibleBatches += 1;
    });
    if (count) count.textContent = `${visibleBatches} importación${visibleBatches === 1 ? '' : 'es'} encontrada${visibleBatches === 1 ? '' : 's'}`;
    if (noResults) noResults.hidden = visibleBatches !== 0;
  };
  search?.addEventListener('input', applyFilters);
  group?.addEventListener('change', applyFilters);
  applyFilters();
});
</script>
</body>
</html>
