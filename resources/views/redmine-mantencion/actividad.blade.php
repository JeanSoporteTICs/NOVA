<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Actividad de seguridad'; $includeTheme = true; include base_path('RedmineMantencion/views/partials/bootstrap-head.php'); ?>
</head>
<body class="bg-light">
<?php $activeNav = 'security'; include base_path('RedmineMantencion/views/partials/navbar.php'); ?>
<div id="page-content">
  <div class="container-fluid py-4">
    <?php
      $heroIcon = 'bi-shield-lock';
      $heroTitle = 'Actividad reciente';
      $heroSubtitle = 'Consultas, movimientos, accesos y eventos operativos registrados en Mantención.';
      include base_path('RedmineMantencion/views/partials/hero.php');
    ?>

    <?php if ($flash): ?><div data-nova-flash="success" data-nova-flash-message="<?= $h($flash) ?>" hidden></div><?php endif; ?>

    <div class="card mb-3">
      <div class="card-body">
        <div class="security-activity-intro">
          <div>
            <span class="security-activity-eyebrow"><i class="bi bi-database-check"></i> Auditoría en base de datos</span>
            <p class="text-muted mb-0">Consulta accesos, cambios, importaciones, envíos a Redmine y eventos operativos de Mantención.</p>
          </div>
          <div class="security-activity-total">
            <strong><?= $h(number_format($totalEvents, 0, ',', '.')) ?></strong>
            <span><?= $hasFilters ? 'coincidencias' : 'eventos registrados' ?></span>
          </div>
        </div>

        <form method="get" class="security-activity-filters" aria-label="Filtros de actividad">
          <div class="security-activity-filter is-search">
            <label for="activity-search"><i class="bi bi-search"></i> Buscar</label>
            <input id="activity-search" name="buscar" class="form-control" type="search" value="<?= $h($search) ?>" placeholder="Detalle, evento, canal o ID">
          </div>
          <div class="security-activity-filter">
            <label for="activity-tag"><i class="bi bi-tag"></i> Evento</label>
            <select id="activity-tag" name="tag" class="form-select">
              <option value="">Todos los eventos</option>
              <option value="NEXTCLOUD" <?= $selectedTag === 'NEXTCLOUD' ? 'selected' : '' ?>>Todos los eventos Nextcloud</option>
              <?php foreach ($eventTags as $tag): ?>
                <option value="<?= $h($tag) ?>" <?= $selectedTag === strtoupper((string)$tag) ? 'selected' : '' ?>><?= $h($tag) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="security-activity-filter">
            <label for="activity-channel"><i class="bi bi-diagram-3"></i> Canal</label>
            <select id="activity-channel" name="canal" class="form-select">
              <option value="">Todos los canales</option>
              <?php foreach ($eventChannels as $channel): ?>
                <option value="<?= $h($channel) ?>" <?= $selectedChannel === strtolower((string)$channel) ? 'selected' : '' ?>><?= $h(ucfirst((string)$channel)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="security-activity-filter">
            <label for="activity-from"><i class="bi bi-calendar-event"></i> Desde</label>
            <input id="activity-from" name="desde" class="form-control" type="date" value="<?= $h($dateFrom) ?>">
          </div>
          <div class="security-activity-filter">
            <label for="activity-to"><i class="bi bi-calendar-check"></i> Hasta</label>
            <input id="activity-to" name="hasta" class="form-control" type="date" value="<?= $h($dateTo) ?>">
          </div>
          <div class="security-activity-filter is-size">
            <label for="activity-per-page"><i class="bi bi-list-ol"></i> Por página</label>
            <select id="activity-per-page" name="per_page" class="form-select">
              <?php foreach ([25, 50, 100] as $option): ?>
                <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="security-activity-filter-actions">
            <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Aplicar filtros</button>
            <a href="activity.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i> Limpiar</a>
          </div>
        </form>

        <div class="security-activity-resultbar">
          <div>
            <i class="bi bi-eye"></i>
            Mostrando <?= count($events) ?> de <?= $h(number_format($totalEvents, 0, ',', '.')) ?> eventos
            <span class="security-activity-filtered"><?= auth_can('actividad_todos') ? 'Todos los usuarios' : 'Solo mis registros' ?></span>
            <?php if ($hasFilters): ?><span class="security-activity-filtered">Filtros activos</span><?php endif; ?>
          </div>
          <?php if (auth_can('actividad_eliminar')): ?>
            <form method="post" action="<?= $h($activityActionUrl) ?>" class="mb-0" data-app-confirm="¿Eliminar toda la actividad reciente? Esta acción no se puede deshacer.">
              <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action" value="clear_activity">
              <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-trash3"></i> Vaciar mi bitácora
              </button>
            </form>
          <?php endif; ?>
        </div>
        <?php if (empty($events)): ?>
          <div class="nova-empty-state security-activity-empty">
            <i class="bi bi-search"></i>
            <h3><?= $hasFilters ? 'No hay coincidencias' : 'Todavía no hay eventos' ?></h3>
            <p><?= $hasFilters ? 'Prueba ampliando las fechas o quitando alguno de los filtros.' : 'Los nuevos eventos de Mantención aparecerán aquí.' ?></p>
            <?php if ($hasFilters): ?><a href="activity.php" class="btn btn-outline-primary"><i class="bi bi-arrow-counterclockwise"></i> Limpiar filtros</a><?php endif; ?>
          </div>
        <?php else: ?>
          <div class="security-console-wrap">
            <div class="security-console-toolbar">
              <span class="security-console-dot" aria-hidden="true"></span>
              <span>Actividad Mantención :: página <?= $page ?> de <?= $totalPages ?></span>
            </div>
            <div class="table-responsive">
              <table class="table align-middle security-console security-operational-console">
              <thead>
                <tr>
                  <th scope="col" class="security-console-col-time">Fecha / hora</th>
                  <th scope="col" class="security-console-col-user">Usuario</th>
                  <th scope="col" class="security-console-col-event">Acción</th>
                  <th scope="col" class="security-console-col-result">Resultado</th>
                  <th scope="col">Detalle seguro</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($events as $evt): ?>
                  <tr>
                    <td class="console-time"><?= $h($formatSecurityTimestamp($evt['ts'])) ?: '----' ?></td>
                    <td class="console-user"><i class="bi <?= ($evt['user'] ?? '') === 'Sistema' ? 'bi-cpu' : 'bi-person-circle' ?>"></i> <?= $h($evt['user'] ?? 'Sistema') ?></td>
                    <td><span class="console-tag" title="<?= $h($evt['tag'] ?? '') ?>"><?= $h($evt['action'] ?? 'Evento') ?></span></td>
                    <td><span class="security-result is-<?= $h($evt['result'] ?? 'info') ?>"><i class="bi <?= ($evt['result'] ?? '') === 'success' ? 'bi-check-circle' : (($evt['result'] ?? '') === 'error' ? 'bi-x-circle' : 'bi-info-circle') ?>"></i><?= ($evt['result'] ?? '') === 'success' ? 'Correcto' : (($evt['result'] ?? '') === 'error' ? 'Error' : 'Informativo') ?></span></td>
                    <td class="console-details"><?= $h($evt['details']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              </table>
            </div>
            <?php if ($totalPages > 1): ?>
              <div class="security-activity-pagination">
                <span>Página <?= $page ?> de <?= $totalPages ?></span>
                <nav aria-label="Paginación de actividad">
                  <a class="btn btn-sm btn-outline-light <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $h($pageUrl($page - 1)) ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>><i class="bi bi-chevron-left"></i> Anterior</a>
                  <a class="btn btn-sm btn-outline-light <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $h($pageUrl($page + 1)) ?>" <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Siguiente <i class="bi bi-chevron-right"></i></a>
                </nav>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php include base_path('RedmineMantencion/views/partials/bootstrap-scripts.php'); ?>
</body>
</html>
