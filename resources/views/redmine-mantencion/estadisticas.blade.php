<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Estadisticas'; $includeTheme = true; include base_path('RedmineMantencion/views/partials/bootstrap-head.php'); ?>
  <?php $chartJsVersion = @filemtime(base_path('RedmineMantencion/assets/js/chart.umd.min.js')) ?: time(); ?>
  <script src="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/js/chart.umd.min.js?v=<?= (int)$chartJsVersion ?>"></script>
  <?php $estadisticasCssVersion = @filemtime(base_path('RedmineMantencion/assets/css/estadisticas.css')) ?: time(); ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/estadisticas.css?v=<?= (int)$estadisticasCssVersion ?>">
</head>
<body class="bg-light">
<?php $activeNav = 'estadisticas'; include base_path('RedmineMantencion/views/partials/navbar.php'); ?>

<div id="page-content">
  <div class="container-fluid py-4">
  <?php
    $heroIcon = 'bi-graph-up-arrow';
    $heroTitle = 'Estadísticas';
    $heroSubtitle = 'Resumen y detalle de reportes';
    $heroExtras = '<span class="badge bg-white bg-opacity-25 text-white border border-white">Rango: ' . $h($periodoLabel) . '</span>'
      . '<span class="badge bg-white bg-opacity-25 text-white border border-white">Actualizado: ' . $h($actualizadoTxt) . '</span>';
    include base_path('RedmineMantencion/views/partials/hero.php');

    $porFechaGrafico = $stats['por_fecha'] ?? [];
    ksort($porFechaGrafico);
    $maximoDiario = max(1, $porFechaGrafico ? (int)max($porFechaGrafico) : 0);
    $cantidadFechas = count($porFechaGrafico);
    $pasoVistaFecha = 552 / max(1, $cantidadFechas);
    $anchoBarraVista = max(1.5, $pasoVistaFecha * 0.68);
    $anchoLienzoFecha = max(1200, ($cantidadFechas * 34) + 128);
    $pasoModalFecha = ($anchoLienzoFecha - 128) / max(1, $cantidadFechas);
    $anchoBarraModal = max(12, min(24, $pasoModalFecha * 0.66));
    $saltoEtiquetaFecha = max(1, (int)ceil(max(1, $cantidadFechas) / 60));
    $barrasVistaFecha = [];
    $barrasModalFecha = [];
    $indiceFecha = 0;
    foreach ($porFechaGrafico as $fechaGrafico => $cantidadGrafico) {
      $altoVista = max(2, ((int)$cantidadGrafico / $maximoDiario) * 136);
      $altoModal = max(3, ((int)$cantidadGrafico / $maximoDiario) * 312);
      $barrasVistaFecha[] = [
        'fecha' => (string)$fechaGrafico,
        'cantidad' => (int)$cantidadGrafico,
        'x' => round(24 + ($pasoVistaFecha * $indiceFecha) + (($pasoVistaFecha - $anchoBarraVista) / 2), 2),
        'ancho' => round($anchoBarraVista, 2),
        'y' => round(176 - $altoVista, 2),
        'alto' => round($altoVista, 2),
        'slot_x' => round(24 + ($pasoVistaFecha * $indiceFecha), 2),
        'slot_ancho' => round($pasoVistaFecha, 2),
      ];
      $barrasModalFecha[] = [
        'fecha' => (string)$fechaGrafico,
        'cantidad' => (int)$cantidadGrafico,
        'x' => round(64 + ($pasoModalFecha * $indiceFecha) + (($pasoModalFecha - $anchoBarraModal) / 2), 2),
        'ancho' => round($anchoBarraModal, 2),
        'y' => round(352 - $altoModal, 2),
        'alto' => round($altoModal, 2),
        'slot_x' => round(64 + ($pasoModalFecha * $indiceFecha), 2),
        'slot_ancho' => round($pasoModalFecha, 2),
      ];
      $indiceFecha++;
    }
    $formatearFechaGrafico = static function (string $fecha): string {
      try {
        return $fecha !== '' ? (new \DateTimeImmutable($fecha))->format('d-m-Y') : '';
      } catch (\Throwable) {
        return $fecha;
      }
    };
  ?>

  <div class="row g-3 mb-4 mantencion-stats-chart-grid">
    <div class="col-12 col-xl-8">
      <div class="card p-3 chart-card mantencion-date-chart-card" id="card-fechas" role="button" tabindex="0" data-bs-toggle="modal" data-bs-target="#modalChartFechas" aria-label="Ver detalle de reportes por fecha">
        <div class="rm-stats-panel-head">
          <div><h3><i class="bi bi-bar-chart-fill"></i> Reportes por fecha</h3><p>Volumen diario</p></div>
          <span><?= $h($cantidadFechas) ?> fechas</span>
        </div>
        <?php if ($barrasVistaFecha): ?>
          <svg class="rm-date-histogram" viewBox="0 0 600 210" role="img" aria-label="Histograma de reportes por fecha" preserveAspectRatio="none">
            <?php for ($i = 0; $i <= 4; $i++): ?>
              <line class="rm-date-bar-grid" x1="24" y1="<?= 40 + ($i * 34) ?>" x2="576" y2="<?= 40 + ($i * 34) ?>" />
            <?php endfor; ?>
            <?php foreach ($barrasVistaFecha as $barra): ?>
              <g class="rm-date-bar-point" tabindex="0" role="img" data-rm-chart-point data-chart-label="<?= $h($formatearFechaGrafico($barra['fecha'])) ?>" data-chart-value="<?= $h(number_format($barra['cantidad'], 0, ',', '.')) ?>" aria-label="<?= $h($formatearFechaGrafico($barra['fecha'])) ?>: <?= $h(number_format($barra['cantidad'], 0, ',', '.')) ?> reporte(s)">
                <rect class="rm-date-bar-hit" x="<?= $barra['slot_x'] ?>" y="40" width="<?= $barra['slot_ancho'] ?>" height="136" />
                <rect class="rm-date-bar" x="<?= $barra['x'] ?>" y="<?= $barra['y'] ?>" width="<?= $barra['ancho'] ?>" height="<?= $barra['alto'] ?>" rx="1.5" />
              </g>
            <?php endforeach; ?>
          </svg>
          <div class="rm-chart-axis">
            <span><?= $h($formatearFechaGrafico((string)array_key_first($porFechaGrafico))) ?></span>
            <span><?= $h($formatearFechaGrafico((string)array_key_last($porFechaGrafico))) ?></span>
          </div>
        <?php else: ?>
          <div class="nova-empty-state">Sin datos por fecha.</div>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-12 col-xl-4">
      <div class="card p-3 chart-card" id="card-usuarios" role="button" data-bs-toggle="modal" data-bs-target="#modalChartUsuarios">
        <div class="d-flex align-items-center gap-2 mb-2">
          <i class="bi bi-pie-chart text-success"></i><span class="fw-semibold">Reportes por usuario</span>
        </div>
        <div id="no-data-usuarios" class="text-muted small d-none">Sin datos en el rango seleccionado.</div>
        <canvas id="chart-usuarios" height="220"></canvas>
      </div>
    </div>
  </div>
  <div class="card mb-3 p-3">
    <form id="stats-form" method="post" action="<?= $h($statsActionUrl) ?>" class="row g-3 align-items-end">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
      <div class="col-12">
        <div class="timeline-box">
          <div class="timeline-header">
            <span>Fecha</span>
            <span class="text-uppercase text-muted" style="font-size:0.8rem;">Meses</span>
          </div>
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="text-muted" style="font-size:0.8rem;">Trimestre <?= (int)ceil($timelineReferenceMonth/3) ?> <?= $h($timelineReferenceYear) ?></div>
            <div class="d-flex gap-2">
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('month')">Mes actual</button>
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('year')">Año actual</button>
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('30d')">Últimos 30 días</button>
              <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPeriodo('today')">Hoy</button>
            </div>
          </div>
          <div class="timeline-months" id="month-range">
            <?php
              $meses = ['ENE','FEB','MAR','ABR','MAY','JUN','JUL','AGO','SEPT','OCT','NOV','DIC'];
              foreach ($meses as $idx => $m):
                $active = ($idx+1) === $monthNow ? 'active' : '';
            ?>
              <button type="button" class="<?= $active ?>" data-month="<?= $idx+1 ?>" onclick="selectMonthRange(<?= $idx+1 ?>)"><?= $h($m) ?></button>
            <?php endforeach; ?>
          </div>
          <div class="d-flex gap-2 flex-wrap mb-2">
            <input type="date" name="desde" class="form-control form-control-sm" value="<?= $h($desdeVal) ?>" style="max-width:180px;">
            <input type="date" name="hasta" class="form-control form-control-sm" value="<?= $h($hastaVal) ?>" style="max-width:180px;">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setPeriodo('clear')">Limpiar</button>
          </div>
          <div class="timeline-footer">
            <span>Inicio</span>
            <span>Hoy</span>
            <span>Fin</span>
          </div>
        </div>
      </div>
      <div class="col-sm-6 col-lg-3">
        <label class="form-label">Periodo (YYYY-MM o YYYY)</label>
        <input type="text" name="periodo" class="form-control" placeholder="2025-12 o 2025" value="<?= $h($_POST['periodo'] ?? $_GET['periodo'] ?? '') ?>">
      </div>
      <div class="col-sm-6 col-lg-3">
        <label class="form-label">Usuario asignado</label>
        <select name="usuario" class="form-select">
          <option value="">(Todos)</option>
          <?php foreach ($users as $id=>$name): ?>
            <option value="<?= $h($id) ?>" <?= (string)$selectedUserId === (string)$id ? 'selected' : '' ?>><?= $h($name) ?> (ID <?= $h($id) ?>)</option>
          <?php endforeach; ?>
        </select>
        <?php if ($selectedUserLabel): ?>
          <div class="form-text">Seleccionado: <?= $h($selectedUserLabel) ?></div>
        <?php endif; ?>
      </div>
      <div class="col-sm-6 col-lg-3">
        <label class="form-label">Categoría</label>
        <select name="categoria" class="form-select">
          <option value="">(Todas)</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= $h($c) ?>" <?= (string)$selectedCategoria === (string)$c ? 'selected' : '' ?>><?= $h($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <button class="btn-nova btn-nova-primary btn-icon"><i class="bi bi-funnel"></i> Aplicar filtros</button>
      </div>
      <div class="col-md-3">
        <a class="btn-nova btn-nova-secondary w-100" href="<?= $h($statsActionUrl) ?>"><i class="bi bi-x-circle"></i> Limpiar</a>
      </div>
    </form>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-3 col-md-6">
      <div class="card p-3 h-100 stat-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="stat-icon"><i class="bi bi-collection"></i></div>
          <span class="fw-semibold text-muted">Total reportes</span>
        </div>
        <div class="display-6"><?= $h($stats['total'] ?? 0) ?></div>
      </div>
    </div>
    <div class="col-lg-3 col-md-6">
      <div class="card p-3 h-100 stat-card" style="border-left-color:#1cc88a;" role="button" data-bs-toggle="modal" data-bs-target="#modalUsuarios">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="stat-icon" style="background:rgba(28,200,138,0.12);color:#1cc88a;"><i class="bi bi-person-badge"></i></div>
          <span class="fw-semibold text-muted">Por usuario</span>
        </div>
        <ul class="list-group list-group-flush">
          <?php $sliceUsuarios = array_slice($stats['por_usuario'] ?? [], 0, 2, true); ?>
          <?php foreach ($sliceUsuarios as $u => $c): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= $h($u) ?></span><span class="badge bg-primary rounded-pill"><?= $h($c) ?></span>
            </li>
          <?php endforeach; ?>
          <?php if (empty($sliceUsuarios)): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
        </ul>
      </div>
    </div>
    <div class="col-lg-3 col-md-6">
      <div class="card p-3 h-100 stat-card" style="border-left-color:#f6c23e;" role="button" data-bs-toggle="modal" data-bs-target="#modalCategorias">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div class="stat-icon" style="background:rgba(246,194,62,0.12);color:#f6c23e;"><i class="bi bi-tags"></i></div>
          <span class="fw-semibold text-muted">Por categoría</span>
        </div>
        <ul class="list-group list-group-flush">
          <?php $sliceCats = array_slice($stats['por_categoria'] ?? [], 0, 2, true); ?>
          <?php foreach ($sliceCats as $cat => $c): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= $h($cat) ?></span><span class="badge bg-primary rounded-pill"><?= $h($c) ?></span>
            </li>
          <?php endforeach; ?>
          <?php if (empty($sliceCats)): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
        </ul>
      </div>
    </div>
    <div class="col-lg-3 col-md-6">
      <div class="card p-3 h-100 stat-card" style="border-left-color:#e74a3b;" role="button" data-bs-toggle="modal" data-bs-target="#modalEstado">
        <div class="d-flex align-items-center gap-2 mb-2">
          <div class="stat-icon" style="background:rgba(231,74,59,0.12);color:#e74a3b;"><i class="bi bi-flag"></i></div>
          <span class="fw-semibold text-muted">Por estado</span>
        </div>
        <ul class="list-group list-group-flush">
          <?php $sliceEst = array_slice($stats['por_estado'] ?? [], 0, 3, true); ?>
          <?php foreach ($sliceEst as $est => $c): ?>
            <li class="list-group-item d-flex justify-content-between">
              <span><?= $h($est) ?></span><span class="badge bg-primary rounded-pill"><?= $h($c) ?></span>
            </li>
          <?php endforeach; ?>
          <?php if (empty($sliceEst)): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
  <!-- Modal Usuarios -->
  <div class="modal fade" id="modalUsuarios" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Reportes por usuario (<?= array_sum($stats['por_usuario'] ?? []) ?>)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <ul class="list-group list-group-flush">
            <?php $idx=0; foreach (($stats['msgs_por_usuario'] ?? []) as $u => $lista): $idx++; $collapseId = 'u-'.$idx; ?>
              <li class="list-group-item">
                <div class="d-flex justify-content-between align-items-center" data-bs-toggle="collapse" href="#<?= $collapseId ?>" role="button" aria-expanded="false" aria-controls="<?= $collapseId ?>">
                  <strong><?= $h($u) ?></strong>
                  <span class="badge bg-primary"><?= count($lista) ?></span>
                </div>
                <div class="collapse mt-2" id="<?= $collapseId ?>">
                  <ul class="list-group list-group-flush">
                    <?php foreach ($lista as $msg): ?>
                      <li class="list-group-item">
                        <div class="fw-semibold"><?= $h(($msg['asunto'] ?? '') ?: ($msg['mensaje'] ?? '')) ?></div>
                        <small class="text-muted"><?= $h($msg['fecha_stats'] ?? ($msg['fecha'] ?? '')) ?> <?= !empty($msg['redmine_id']) ? '- Ticket ' . $h($msg['redmine_id']) : '' ?></small>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </li>
            <?php endforeach; ?>
            <?php if (empty($stats['msgs_por_usuario'])): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Categorias -->
  <div class="modal fade" id="modalCategorias" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Reportes por categor&iacute;a (<?= array_sum($stats['por_categoria'] ?? []) ?>)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <ul class="list-group list-group-flush">
            <?php $idx=0; foreach (($stats['msgs_por_categoria'] ?? []) as $cat => $lista): $idx++; $collapseId = 'c-'.$idx; ?>
              <li class="list-group-item">
                <div class="d-flex justify-content-between align-items-center" data-bs-toggle="collapse" href="#<?= $collapseId ?>" role="button" aria-expanded="false" aria-controls="<?= $collapseId ?>">
                  <strong><?= $h($cat) ?></strong>
                  <span class="badge bg-primary"><?= count($lista) ?></span>
                </div>
                <div class="collapse mt-2" id="<?= $collapseId ?>">
                  <ul class="list-group list-group-flush">
                    <?php foreach ($lista as $msg): ?>
                      <li class="list-group-item">
                        <div class="fw-semibold"><?= $h(($msg['asunto'] ?? '') ?: ($msg['mensaje'] ?? '')) ?></div>
                        <small class="text-muted"><?= $h($msg['fecha_stats'] ?? ($msg['fecha'] ?? '')) ?> <?= !empty($msg['redmine_id']) ? '- Ticket ' . $h($msg['redmine_id']) : '' ?></small>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              </li>
            <?php endforeach; ?>
            <?php if (empty($stats['msgs_por_categoria'])): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Estado -->
  <div class="modal fade" id="modalEstado" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Reportes por estado (<?= array_sum($stats['por_estado'] ?? []) ?>)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <ul class="list-group list-group-flush">
            <?php foreach (($stats['por_estado'] ?? []) as $est => $c): ?>
              <li class="list-group-item d-flex justify-content-between">
                <span><?= $h($est) ?></span><span class="badge bg-primary rounded-pill"><?= $h($c) ?></span>
              </li>
            <?php endforeach; ?>
            <?php if (empty($stats['por_estado'])): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
          </ul>
        </div>
      </div>
    </div>
  </div>
  <!-- Modal grafico fechas -->
  <div class="modal fade rm-stats-chart-modal mantencion-date-chart-modal" id="modalChartFechas" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable rm-stats-chart-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h2 class="modal-title fs-5"><i class="bi bi-bar-chart-fill"></i> Reportes por fecha</h2>
            <div class="text-muted fw-semibold"><?= $h($cantidadFechas) ?> fecha(s) con datos</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <?php if ($barrasModalFecha): ?>
            <div class="rm-date-chart-scroll">
              <section class="rm-modal-date-chart-panel" style="--date-chart-width: <?= $anchoLienzoFecha ?>px;">
                <svg class="rm-date-bar-chart" viewBox="0 0 <?= $anchoLienzoFecha ?> 450" role="img" aria-label="Histograma completo de reportes por fecha" preserveAspectRatio="none">
                  <?php for ($i = 0; $i <= 4; $i++):
                    $gridY = 40 + ($i * 78);
                    $valorEje = max(0, round($maximoDiario - (($maximoDiario / 4) * $i)));
                  ?>
                    <line class="rm-date-bar-grid" x1="64" y1="<?= $gridY ?>" x2="<?= $anchoLienzoFecha - 24 ?>" y2="<?= $gridY ?>" />
                    <text class="rm-date-bar-y-label" x="48" y="<?= $gridY + 4 ?>"><?= $h($valorEje) ?></text>
                  <?php endfor; ?>
                  <?php foreach ($barrasModalFecha as $indiceBarra => $barra): ?>
                    <g class="rm-date-bar-point" tabindex="0" role="img" data-rm-chart-point data-chart-label="<?= $h($formatearFechaGrafico($barra['fecha'])) ?>" data-chart-value="<?= $h(number_format($barra['cantidad'], 0, ',', '.')) ?>" aria-label="<?= $h($formatearFechaGrafico($barra['fecha'])) ?>: <?= $h(number_format($barra['cantidad'], 0, ',', '.')) ?> reporte(s)">
                      <rect class="rm-date-bar-hit" x="<?= $barra['slot_x'] ?>" y="40" width="<?= $barra['slot_ancho'] ?>" height="312" />
                      <rect class="rm-date-bar" x="<?= $barra['x'] ?>" y="<?= $barra['y'] ?>" width="<?= $barra['ancho'] ?>" height="<?= $barra['alto'] ?>" rx="3" />
                    </g>
                    <?php if ($indiceBarra % $saltoEtiquetaFecha === 0): ?>
                      <text class="rm-date-bar-x-label" x="<?= $barra['x'] + ($barra['ancho'] / 2) ?>" y="386" transform="rotate(-42 <?= $barra['x'] + ($barra['ancho'] / 2) ?> 386)"><?= $h($formatearFechaGrafico($barra['fecha'])) ?></text>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </svg>
              </section>
            </div>
          <?php else: ?>
            <div class="nova-empty-state">Sin datos por fecha.</div>
          <?php endif; ?>
        </div>
        <div class="modal-footer rm-chart-modal-footer">
          <span>Incluye todas las fechas con reportes dentro del rango seleccionado.</span>
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal grafico usuarios -->
  <div class="modal fade" id="modalChartUsuarios" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Reportes por usuario (detalle)</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <canvas id="chart-usuarios-modal" height="300" style="max-height:360px; width:100%;"></canvas>
          </div>
          <div class="mt-2">
            <ul class="list-group list-group-flush">
              <?php foreach (($stats['por_usuario'] ?? []) as $u => $c): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <span><?= $h($userNameMap[$u] ?? $u) ?></span><span class="badge bg-primary"><?= $h($c) ?></span>
                </li>
              <?php endforeach; ?>
              <?php if (empty($stats['por_usuario'])): ?><li class="list-group-item text-muted">Sin datos</li><?php endif; ?>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function setPeriodo(mode) {
  const desde = document.querySelector('input[name="desde"]');
  const hasta = document.querySelector('input[name="hasta"]');
  const periodo = document.querySelector('input[name="periodo"]');
  const today = new Date();
  const pad = (n) => n.toString().padStart(2,'0');
  const lastDay = (year, month) => pad(new Date(year, month, 0).getDate());
  if (periodo) periodo.value = '';
  if (mode === 'month') {
    const y = today.getFullYear();
    const m = pad(today.getMonth() + 1);
    if (desde) desde.value = `${y}-${m}-01`;
    if (hasta) hasta.value = `${y}-${m}-${lastDay(y, today.getMonth() + 1)}`;
  } else if (mode === 'year') {
    const y = today.getFullYear();
    if (desde) desde.value = `${y}-01-01`;
    if (hasta) hasta.value = `${y}-12-31`;
  } else if (mode === '30d') {
    const past = new Date(today.getTime() - 29*24*60*60*1000);
    if (desde) desde.value = `${past.getFullYear()}-${pad(past.getMonth()+1)}-${pad(past.getDate())}`;
    if (hasta) hasta.value = `${today.getFullYear()}-${pad(today.getMonth()+1)}-${pad(today.getDate())}`;
  } else if (mode === 'today') {
    const y = today.getFullYear();
    const m = pad(today.getMonth() + 1);
    const d = pad(today.getDate());
    if (desde) desde.value = `${y}-${m}-${d}`;
    if (hasta) hasta.value = `${y}-${m}-${d}`;
  } else if (mode === 'clear') {
    if (desde) desde.value = '';
    if (hasta) hasta.value = '';
    rangeStart = null; rangeEnd = null;
    document.querySelectorAll('.timeline-months button').forEach(btn => btn.classList.remove('active','range','range-edge'));
  }
  const form = document.getElementById('stats-form');
  if (form) form.submit();
}

let rangeStart = null;
let rangeEnd = null;

function highlightMonths(startM, endM) {
  const allButtons = document.querySelectorAll('.timeline-months button');
  allButtons.forEach(btn => {
    btn.classList.remove('active','range','range-edge');
    const val = parseInt(btn.getAttribute('data-month'),10);
    if (startM !== null && endM !== null) {
      if (val === startM || val === endM) btn.classList.add('range-edge');
      if (val >= startM && val <= endM) btn.classList.add('range');
    } else if (startM !== null && endM === null) {
      if (val === startM) btn.classList.add('active','range-edge');
    }
  });
}

function selectMonthRange(m) {
  if (rangeStart === null || (rangeStart !== null && rangeEnd !== null)) {
    rangeStart = m; rangeEnd = null;
  } else {
    rangeEnd = m;
    if (rangeEnd < rangeStart) {
      const tmp = rangeStart; rangeStart = rangeEnd; rangeEnd = tmp;
    }
  }
  const allButtons = document.querySelectorAll('.timeline-months button');
  highlightMonths(rangeStart, rangeEnd);
  if (rangeStart !== null && rangeEnd !== null) {
    const year = new Date().getFullYear();
    const pad = (n) => n.toString().padStart(2,'0');
    const lastDay = (month) => pad(new Date(year, month, 0).getDate());
    const desde = document.querySelector('input[name="desde"]');
    const hasta = document.querySelector('input[name="hasta"]');
    const periodo = document.querySelector('input[name="periodo"]');
    if (periodo) periodo.value = '';
    if (desde) desde.value = `${year}-${pad(rangeStart)}-01`;
    if (hasta) hasta.value = `${year}-${pad(rangeEnd)}-${lastDay(rangeEnd)}`;
    const form = document.getElementById('stats-form');
    if (form) form.submit();
  } else {
    const btn = Array.from(allButtons).find(b => b.classList.contains('range-edge'));
    if (btn && rangeStart !== null) {
      const year = new Date().getFullYear();
      const pad = (n) => n.toString().padStart(2,'0');
      const lastDay = (month) => pad(new Date(year, month, 0).getDate());
      const desde = document.querySelector('input[name="desde"]');
      const hasta = document.querySelector('input[name="hasta"]');
      const periodo = document.querySelector('input[name="periodo"]');
      if (periodo) periodo.value = '';
      if (desde) desde.value = `${year}-${pad(rangeStart)}-01`;
      if (hasta) hasta.value = `${year}-${pad(rangeStart)}-${lastDay(rangeStart)}`;
    }
  }
}

function applyInitialMonthSelection() {
  const parseDateVal = (str) => {
    if (!str) return null;
    if (/^\d{2}-\d{2}-\d{4}$/.test(str)) {
      const [d,m,y] = str.split('-');
      return new Date(Number(y), Number(m) - 1, Number(d));
    }
    if (/^\d{4}-\d{2}-\d{2}$/.test(str)) {
      const [y,m,d] = str.split('-');
      return new Date(Number(y), Number(m) - 1, Number(d));
    }
    return new Date(str);
  };
  const desdeRaw = document.querySelector('input[name="desde"]')?.value;
  const hastaRaw = document.querySelector('input[name="hasta"]')?.value;
  const d1 = parseDateVal(desdeRaw);
  const d2 = parseDateVal(hastaRaw);
  if (d1 && d2 && !isNaN(d1) && !isNaN(d2) && d1.getFullYear() === d2.getFullYear()) {
    const m1 = d1.getMonth() + 1;
    const m2 = d2.getMonth() + 1;
    rangeStart = Math.min(m1, m2);
    rangeEnd = Math.max(m1, m2);
    highlightMonths(rangeStart, rangeEnd);
    return;
  }
  highlightMonths(null, null);
}

document.addEventListener('DOMContentLoaded', applyInitialMonthSelection);
</script>
<script>
(() => {
  const tooltip = document.createElement('div');
  tooltip.className = 'rm-chart-point-tooltip';
  tooltip.hidden = true;
  tooltip.setAttribute('role', 'tooltip');
  tooltip.innerHTML = '<strong></strong><span></span>';
  document.body.appendChild(tooltip);

  const positionTooltip = (x, y) => {
    const gap = 14;
    const edge = 12;
    const left = Math.min(Math.max(edge, x + gap), window.innerWidth - tooltip.offsetWidth - edge);
    const candidateTop = y - tooltip.offsetHeight - gap;
    tooltip.style.left = `${left}px`;
    tooltip.style.top = `${Math.max(edge, candidateTop < edge ? y + gap : candidateTop)}px`;
  };
  const showTooltip = (point, x, y) => {
    tooltip.querySelector('strong').textContent = point.dataset.chartLabel || 'Sin fecha';
    tooltip.querySelector('span').textContent = `${point.dataset.chartValue || '0'} reporte(s)`;
    tooltip.hidden = false;
    positionTooltip(x, y);
  };
  const hideTooltip = () => { tooltip.hidden = true; };

  document.addEventListener('pointerover', (event) => {
    const point = event.target instanceof Element ? event.target.closest('[data-rm-chart-point]') : null;
    if (point) showTooltip(point, event.clientX, event.clientY);
  });
  document.addEventListener('pointermove', (event) => {
    const point = event.target instanceof Element ? event.target.closest('[data-rm-chart-point]') : null;
    if (point && !tooltip.hidden) positionTooltip(event.clientX, event.clientY);
  });
  document.addEventListener('pointerout', (event) => {
    const point = event.target instanceof Element ? event.target.closest('[data-rm-chart-point]') : null;
    const nextPoint = event.relatedTarget instanceof Element ? event.relatedTarget.closest('[data-rm-chart-point]') : null;
    if (point && point !== nextPoint) hideTooltip();
  });
  document.addEventListener('focusin', (event) => {
    const point = event.target instanceof Element ? event.target.closest('[data-rm-chart-point]') : null;
    if (!point) return;
    const bounds = point.getBoundingClientRect();
    showTooltip(point, bounds.left + (bounds.width / 2), bounds.top);
  });
  document.addEventListener('focusout', (event) => {
    if (event.target instanceof Element && event.target.closest('[data-rm-chart-point]')) hideTooltip();
  });
  document.addEventListener('keydown', (event) => {
    const card = event.target instanceof Element ? event.target.closest('.mantencion-date-chart-card') : null;
    if (!card || (event.key !== 'Enter' && event.key !== ' ')) return;
    event.preventDefault();
    card.click();
  });
})();

const mantencionUserChartColors = Object.freeze([
  '#2563eb',
  '#e11d48',
  '#059669',
  '#d97706',
  '#7c3aed',
  '#0891b2',
  '#db2777',
  '#65a30d',
  '#ea580c',
  '#4f46e5',
  '#0f766e',
  '#9333ea'
]);
</script>
<script>
  // Carga diferida de Chart.js si no está presente
  function loadChartLibrary(callback) {
    if (typeof Chart !== 'undefined') {
      callback();
      return;
    }
    const existing = document.querySelector('script[data-chartjs-inline]');
    if (existing) {
      existing.addEventListener('load', () => callback());
      return;
    }
    const s = document.createElement('script');
    s.src = '<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/js/chart.umd.min.js?v=<?= (int)$chartJsVersion ?>';
    s.async = true;
    s.setAttribute('data-chartjs-inline', '1');
    s.onload = () => callback();
    s.onerror = () => console.error('No se pudo cargar Chart.js');
    document.head.appendChild(s);
  }
</script>
<script>
  loadChartLibrary(function(){
    let chartUsuariosMain = null;
    const dataPorUsuario = <?= json_encode($stats['por_usuario'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const userNameMap = <?= json_encode($userNameMap ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const ensureCanvasHeight = (canvas, px = 320, containerPx = 360) => {
      if (!canvas) return;
      canvas.style.height = `${px}px`;
      canvas.height = px;
      canvas.style.maxHeight = `${containerPx}px`;
      if (canvas.parentElement) canvas.parentElement.style.height = `${containerPx}px`;
    };
    const ctxUsers = document.getElementById('chart-usuarios');
    if (ctxUsers) {
      const emptyMsgU = document.getElementById('no-data-usuarios');
      const userIds = Object.keys(dataPorUsuario);
      const userValues = userIds.map(k => dataPorUsuario[k]);
      const userLabels = userIds.map(id => userNameMap[id] ?? id);
      if (!userLabels.length) {
        if (emptyMsgU) emptyMsgU.classList.remove('d-none');
        ctxUsers.classList.add('d-none');
        return;
      } else {
        if (emptyMsgU) emptyMsgU.classList.add('d-none');
        ctxUsers.classList.remove('d-none');
      }
      const colors = mantencionUserChartColors;
      ensureCanvasHeight(ctxUsers, 280, 320);
      if (chartUsuariosMain) {
        chartUsuariosMain.destroy();
      }
      chartUsuariosMain = new Chart(ctxUsers, {
        type: 'doughnut',
        data: {
          labels: userLabels,
          datasets: [{
            data: userValues,
            backgroundColor: userLabels.map((_,i)=> colors[i % colors.length]),
            borderColor: '#ffffff',
            hoverBorderColor: '#ffffff',
            hoverOffset: 6,
            borderWidth: 3
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '65%',
          plugins: {
            legend: {
              position: 'bottom',
              labels: { usePointStyle: true, pointStyle: 'circle', padding: 14 }
            }
          }
        }
      });
    }
  });
</script>
<script>
  loadChartLibrary(function() {
    const dataPorUsuario = <?= json_encode($stats['por_usuario'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const userNameMap = <?= json_encode($userNameMap ?? [], JSON_UNESCAPED_UNICODE) ?>;
    const ensureCanvasHeight = (canvas, px = 360, containerPx = 380) => {
      if (!canvas) return;
      canvas.style.height = `${px}px`;
      canvas.height = px;
      canvas.style.maxHeight = `${containerPx}px`;
      if (canvas.parentElement) canvas.parentElement.style.height = `${containerPx}px`;
    };
    let chartUsuariosModal = null;
    function renderUsuariosModal() {
      const canvas = document.getElementById('chart-usuarios-modal');
      if (!canvas) return;
      ensureCanvasHeight(canvas, 340, 380);
      const ids = Object.keys(dataPorUsuario);
      const values = ids.map(k => dataPorUsuario[k]);
      const labels = ids.map(id => userNameMap[id] ?? id);
      if (chartUsuariosModal) {
        chartUsuariosModal.destroy();
        chartUsuariosModal = null;
      }
      if (!labels.length) return;
      const colors = mantencionUserChartColors;
      chartUsuariosModal = new Chart(canvas, {
        type: 'doughnut',
        data: {
          labels,
          datasets: [{
            data: values,
            backgroundColor: labels.map((_,i)=> colors[i % colors.length]),
            borderColor: '#ffffff',
            hoverBorderColor: '#ffffff',
            hoverOffset: 6,
            borderWidth: 3
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '65%',
          plugins: {
            legend: {
              position: 'bottom',
              labels: { usePointStyle: true, pointStyle: 'circle', padding: 14 }
            }
          }
        }
      });
    }

    const modalUsuarios = document.getElementById('modalChartUsuarios');
    if (modalUsuarios) {
      modalUsuarios.addEventListener('shown.bs.modal', renderUsuariosModal);
    }
  });
</script>
</div> <!-- #page-content -->
<?php include base_path('RedmineMantencion/views/partials/bootstrap-scripts.php'); ?>
</body>
</html>
