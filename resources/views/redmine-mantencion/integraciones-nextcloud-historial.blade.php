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
              <input class="form-control" id="nextcloud-history-search" type="search" placeholder="Usuario, nombre, correo o detalle" autocomplete="off">
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

    <?php foreach ($batches as $batch): ?>
      <?php
        $tableId = 'nextcloud-history-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($batch['id'] ?? uniqid()));
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
      ?>
      <div class="card nextcloud-panel mb-3" data-history-batch>
        <div class="card-body p-4">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
            <div>
              <h5 class="mb-1">Lote <?= $h($batch['id'] ?? '') ?></h5>
              <div class="text-muted small">
                Creado: <?= $h(date('d-m-Y H:i', strtotime((string)($batch['created_at'] ?? 'now')))) ?>
              </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
              <button type="button" class="btn btn-outline-primary" data-copy-table="#<?= $h($tableId) ?>">
                <i class="bi bi-clipboard"></i> Copiar tabla
              </button>
            </div>
          </div>
          <h6 class="mb-2">Resultado de importación</h6>
          <div class="table-responsive border rounded-4 overflow-hidden">
            <table class="table table-sm mb-0 align-middle" id="<?= $h($tableId) ?>">
              <thead class="table-light">
                <tr>
                  <th>Estado</th>
                  <th>Nombre de usuario</th>
                  <th>Nombre a desplegar</th>
                  <th>Correo</th>
                  <th>Grupo</th>
                  <th>Detalle</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($batchUsers as $item): ?>
                  <tr class="<?= $h($item['_row'] ?? '') ?>" data-history-row data-history-group="<?= $h($item['group'] ?? '') ?>">
                    <td><span class="badge text-bg-<?= $h($item['_badge'] ?? 'secondary') ?>"><?= $h($item['_status'] ?? '') ?></span></td>
                    <td><?= $h($item['userid'] ?? '') ?></td>
                    <td><?= $h($item['displayName'] ?? '') ?></td>
                    <td><?= $h($item['email'] ?? '') ?></td>
                    <td><?= $h($item['group'] ?? '') ?></td>
                    <td><?= $h($item['message'] ?? '') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php include base_path('RedmineMantencion/views/partials/bootstrap-scripts.php'); ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const search = document.getElementById('nextcloud-history-search');
  const group = document.getElementById('nextcloud-history-group');
  const count = document.getElementById('nextcloud-history-count');
  const noResults = document.getElementById('nextcloud-history-no-results');
  const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  const applyFilters = () => {
    const term = normalize(search?.value);
    const selectedGroup = normalize(group?.value);
    let visibleRows = 0;
    document.querySelectorAll('[data-history-batch]').forEach(batch => {
      let batchRows = 0;
      batch.querySelectorAll('[data-history-row]').forEach(row => {
        const matchesSearch = term === '' || normalize(row.textContent).includes(term);
        const matchesGroup = selectedGroup === '' || normalize(row.dataset.historyGroup) === selectedGroup;
        row.hidden = !(matchesSearch && matchesGroup);
        if (!row.hidden) {
          batchRows += 1;
          visibleRows += 1;
        }
      });
      batch.hidden = batchRows === 0;
    });
    if (count) count.textContent = `${visibleRows} registro(s) encontrado(s)`;
    if (noResults) noResults.hidden = visibleRows !== 0;
  };
  search?.addEventListener('input', applyFilters);
  group?.addEventListener('change', applyFilters);
  applyFilters();

  document.querySelectorAll('[data-copy-table]').forEach(button => {
    button.addEventListener('click', async () => {
      const table = document.querySelector(button.dataset.copyTable);
      if (!table) return;
      const rowsText = Array.from(table.querySelectorAll('tr')).filter(row => !row.hidden).map(row => {
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
});
</script>
</body>
</html>
