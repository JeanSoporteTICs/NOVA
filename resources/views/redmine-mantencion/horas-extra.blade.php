<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Horas extra'; $includeTheme = true; include base_path('RedmineMantencion/views/partials/bootstrap-head.php'); ?>
  <?php $horasExtraCssVersion = @filemtime(base_path('RedmineMantencion/assets/css/horas-extra.css')) ?: time(); ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/horas-extra.css?v=<?= (int)$horasExtraCssVersion ?>">
</head>
<body class="bg-light">
<?php $activeNav = 'horas'; include base_path('RedmineMantencion/views/partials/navbar.php'); ?>

<div id="page-content">
<div class="container-fluid py-4">
  <?php
    $heroIcon = 'bi-alarm';
    $heroTitle = 'Horas extra';
    $heroSubtitle = 'Reportes con hora extra agrupados por fecha';
    $heroExtras = '';
    if ($selMes || $selAnio) {
      $heroExtras = '<span class="badge bg-white bg-opacity-25 text-white border border-white">Filtrado: ' . ($selMes ? ucfirst($meses[(int)$selMes] ?? $selMes) : 'Todos los meses') . ' ' . ($selAnio ?: '') . '</span>';
    }
    include base_path('RedmineMantencion/views/partials/hero.php');
  ?>

  <form class="card shadow-sm mb-3" method="get">
    <div class="card-body row g-3 align-items-end">
      <div class="col-md-4 col-lg-3">
        <label class="form-label">Mes</label>
        <select name="mes" class="form-select">
          <option value="">Todos</option>
          <?php foreach ($meses as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($selMes !== '' && (int)$selMes === $k) ? 'selected' : '' ?>><?= ucwords($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 col-lg-3">
        <label class="form-label">A&ntilde;o</label>
        <select name="anio" class="form-select">
          <option value="" <?= $selAnio === '' ? 'selected' : '' ?>>Todos</option>
          <?php foreach ($aniosDisponibles as $an): ?>
            <option value="<?= $h($an) ?>" <?= ($selAnio !== '' && (string)$selAnio === (string)$an) ? 'selected' : '' ?>><?= $h($an) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 col-lg-3 d-flex gap-2">
        <button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-funnel"></i> Filtrar</button>
        <a class="btn-nova btn-nova-secondary" href="?"><i class="bi bi-x-circle"></i> Limpiar</a>
      </div>
    </div>
  </form>

  <?php if ($flash): ?><div data-nova-flash="<?= $flash === 'No se encontraron registros para esa fecha' ? 'info' : 'success' ?>" data-nova-flash-message="<?= $h($flash) ?>" hidden></div><?php endif; ?>

  <?php
    $totalHorasTablaMins = 0;
    $totalHorasTabla = '';
  ?>
  <div class="card shadow-sm">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-table text-primary"></i>
          <span class="fw-semibold">Listado</span>
        </div>
        <button type="button" class="btn-nova btn-nova-secondary" id="copy-table-btn" aria-label="Copiar tabla">
          <i class="bi bi-clipboard"></i> Copiar tabla
        </button>
      </div>

      <?php if (empty($grupos)): ?>
        <div class="nova-alert-card is-info"><i class="bi bi-calendar-x"></i> <span>No se encontraron registros para esa fecha</span></div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle" id="extras-table" data-total-hours="">
            <thead class="table-light">
              <tr>
                <th>Fecha</th>
                <th>Detalle</th>
                <th>N&deg; Ticket</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($grupos as $g):
                $fechaKey = $g['fecha'] ?? '';
                $dt = DateTime::createFromFormat('Y-m-d', $fechaKey) ?: DateTime::createFromFormat('d-m-Y', $fechaKey);
                $mesNum = $dt ? (int)$dt->format('n') : null;
                $anioNum = $dt ? $dt->format('Y') : '';
                $diaNum = $dt ? (int)$dt->format('d') : '';
                $mesTxt = $mesNum ? ucfirst($meses[$mesNum] ?? '') : '';
                $diaNombre = $dt ? ucfirst($dias[(int)$dt->format('w')] ?? '') : '';
                $horaIni = $g['hora_inicio'] ?? '';
                $horaFin = $g['hora_fin'] ?? '';
                $minsGrupo = $horasExtraService->minutosDiff($horaIni, $horaFin);
                if ($minsGrupo !== null) $totalHorasTablaMins += $minsGrupo;
                $totalGrupo = $horasExtraService->hhmm($minsGrupo);
                $emachSuggestion = $emachSuggestions[$fechaKey] ?? [];
              ?>
                <tr class="group-row" data-fecha="<?= $h($horasExtraService->formatFecha($fechaKey)) ?>" data-horaini="<?= $h($horaIni) ?>" data-horafin="<?= $h($horaFin) ?>" data-total="<?= $h($totalGrupo) ?>">
                  <td colspan="3">
                    <div class="d-flex justify-content-between align-items-center w-100">
                      <span><strong><?= $h($horasExtraService->formatFecha($fechaKey)) ?></strong> &middot; Hora inicio: <?= $h($horaIni) ?> | Hora término: <?= $h($horaFin) ?><?= $totalGrupo ? ' | Total de horas: ' . $h($totalGrupo) : '' ?></span>
                      <?php if ($canEditHours): ?>
                        <button type="button" class="btn-action btn-action-edit" data-bs-toggle="modal" data-bs-target="#editModal" data-fecha="<?= $h($horasExtraService->formatFecha($fechaKey)) ?>" data-horaini="<?= $h($horaIni) ?>" data-horafin="<?= $h($horaFin) ?>" data-emach-ok="<?= !empty($emachSuggestion['ok']) ? '1' : '0' ?>" data-emach-hora-inicio="<?= $h($emachSuggestion['hora_inicio'] ?? '') ?>" data-emach-hora-fin="<?= $h($emachSuggestion['hora_fin'] ?? '') ?>" data-emach-total="<?= $h($emachSuggestion['total'] ?? '') ?>" data-emach-status="<?= $h($emachSuggestion['status'] ?? 'Sin datos EMACH para calcular esta fecha.') ?>" title="Editar horas" aria-label="Editar horas"><i class="bi bi-pencil-square"></i></button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php if (isset($g['reports']) && is_array($g['reports'])):
                  foreach ($g['reports'] as $r):
                    $detalleFecha = $r['fecha_inicio'] ?? $r['fecha'] ?? $fechaKey;
                  ?>
                  <tr class="detail-row" data-detalle="<?= $h($r['asunto'] ?? '') ?>" data-ticket="<?= $h($r['redmine_id'] ?? '') ?>">
                    <td><?= $h($horasExtraService->formatFecha($detalleFecha)) ?></td>
                    <td style="white-space:pre-line;"><?= $h($r['asunto'] ?? '') ?></td>
                    <td class="text-center"><?= $h($r['redmine_id'] ?? '') ?></td>
                  </tr>
                <?php endforeach; endif; ?>
              <?php endforeach; ?>
            </tbody>
            <?php $totalHorasTabla = $horasExtraService->hhmm($totalHorasTablaMins); ?>
            <tfoot>
              <tr>
                <th colspan="3">Total de horas: <?= $h($totalHorasTabla ?: '00:00') ?></th>
              </tr>
            </tfoot>
          </table>
        </div>
        <script>document.getElementById('extras-table').dataset.totalHours = '<?= $h($totalHorasTabla ?: '') ?>';</script>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal editar horas -->
<?php if ($canEditHours): ?>
<div class="modal fade detail-drawer-modal" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog detail-drawer-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Editar horas por fecha</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" action="<?= $h($hoursActionUrl) ?>">
        <input type="hidden" name="action" value="update_extra">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Fecha</label>
            <input type="text" class="form-control" name="fecha" id="md-fecha" readonly>
          </div>
          <div class="mb-3">
            <label class="form-label">Hora de inicio</label>
            <input type="time" class="form-control" name="hora_ini" id="md-hora-ini" step="1">
          </div>
          <div class="mb-3">
            <label class="form-label">Hora de t&eacute;rmino</label>
            <input type="time" class="form-control" name="hora_fin" id="md-hora-fin" step="1">
          </div>
          <div class="mb-3">
            <button type="button" class="btn btn-outline-primary" id="md-calcular-emach">
              <i class="bi bi-calculator"></i> Calcular desde EMACH
            </button>
            <div class="form-text fw-semibold" id="md-emach-status"></div>
          </div>
          <div class="mb-2 text-muted small" id="md-total-horas"></div>
          <p class="text-muted small mb-0">Las horas se aplican a todos los reportes de esa fecha.</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn-nova btn-nova-primary" <?= $maintenanceMode ? 'disabled title="Plataforma en mantencion"' : '' ?>>Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include base_path('RedmineMantencion/views/partials/bootstrap-scripts.php'); ?>
<script>
const editModal = document.getElementById('editModal');
const totalHorasEl = document.getElementById('md-total-horas');
const horaIniInput = document.getElementById('md-hora-ini');
const horaFinInput = document.getElementById('md-hora-fin');
const calcularEmachBtn = document.getElementById('md-calcular-emach');
const emachStatusEl = document.getElementById('md-emach-status');
const emachSuggestionEndpoint = '<?= $h(function_exists('url') ? url('/emach/horas-extra-sugerencia') : '/emach/horas-extra-sugerencia') ?>';
const laravelCsrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

function parseTimeInput(value) {
  if (!value) return null;
  const parts = value.split(':');
  if (parts.length === 3) {
    const date = new Date(`1970-01-01T${value}`);
    return isNaN(date) ? null : date;
  }
  if (parts.length === 2) {
    const date = new Date(`1970-01-01T${value}:00`);
    return isNaN(date) ? null : date;
  }
  return null;
}

function updateTotalHorasPreview() {
  if (!totalHorasEl || !horaIniInput || !horaFinInput) return;
  const d1 = parseTimeInput(horaIniInput.value);
  const d2 = parseTimeInput(horaFinInput.value);
  if (d1 && d2 && d2 > d1) {
    const diffMs = d2 - d1;
    const mins = Math.floor(diffMs / 60000);
    const hh = String(Math.floor(mins / 60)).padStart(2,'0');
    const mm = String(mins % 60).padStart(2,'0');
    totalHorasEl.textContent = `Total de horas: ${hh}:${mm}`;
    return;
  }
  totalHorasEl.textContent = '';
}

if (editModal) {
  editModal.addEventListener('show.bs.modal', ev => {
    const btn = ev.relatedTarget;
    if (!btn) return;
    const setVal = (id, attr) => {
      const el = document.getElementById(id);
      if (el) el.value = btn.getAttribute(attr) || '';
    };
    setVal('md-fecha', 'data-fecha');
    setVal('md-hora-ini', 'data-horaini');
    setVal('md-hora-fin', 'data-horafin');
    editModal.dataset.emachOk = btn.getAttribute('data-emach-ok') || '0';
    editModal.dataset.emachHoraInicio = btn.getAttribute('data-emach-hora-inicio') || '';
    editModal.dataset.emachHoraFin = btn.getAttribute('data-emach-hora-fin') || '';
    editModal.dataset.emachTotal = btn.getAttribute('data-emach-total') || '';
    editModal.dataset.emachStatus = btn.getAttribute('data-emach-status') || '';
    if (emachStatusEl) {
      emachStatusEl.classList.remove('text-success', 'text-danger');
      emachStatusEl.textContent = '';
    }
    updateTotalHorasPreview();
  });
}
if (horaIniInput) horaIniInput.addEventListener('input', updateTotalHorasPreview);
if (horaFinInput) horaFinInput.addEventListener('input', updateTotalHorasPreview);
if (calcularEmachBtn && editModal && emachStatusEl) {
  const applyEmachSuggestion = (suggestion, message) => {
    if (horaIniInput) horaIniInput.value = suggestion.hora_inicio || '';
    if (horaFinInput) horaFinInput.value = suggestion.hora_fin || '';
    emachStatusEl.classList.remove('text-danger');
    emachStatusEl.classList.add('text-success');
    emachStatusEl.textContent = message || `Inicio desde tu salida programada y termino desde EMACH. Total: ${suggestion.total || '00:00'}.`;
    updateTotalHorasPreview();
  };

  calcularEmachBtn.addEventListener('click', async () => {
    emachStatusEl.classList.remove('text-success', 'text-danger');
    if (editModal.dataset.emachOk === '1') {
      applyEmachSuggestion({
        hora_inicio: editModal.dataset.emachHoraInicio || '',
        hora_fin: editModal.dataset.emachHoraFin || '',
        total: editModal.dataset.emachTotal || '00:00'
      });
      return;
    }

    calcularEmachBtn.disabled = true;
    emachStatusEl.textContent = 'Consultando EMACH...';
    try {
      const fecha = document.getElementById('md-fecha')?.value || '';
      const response = await fetch(emachSuggestionEndpoint, {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': laravelCsrfToken
        },
        body: JSON.stringify({ fecha })
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || editModal.dataset.emachStatus || 'Sin datos EMACH para calcular esta fecha.');
      }

      editModal.dataset.emachOk = '1';
      editModal.dataset.emachHoraInicio = payload.hora_inicio || '';
      editModal.dataset.emachHoraFin = payload.hora_fin || '';
      editModal.dataset.emachTotal = payload.total || '00:00';
      editModal.dataset.emachStatus = payload.message || '';
      applyEmachSuggestion(payload, `${payload.message || 'Calculado desde EMACH.'} Total: ${payload.total || '00:00'}.`);
    } catch (error) {
      emachStatusEl.classList.add('text-danger');
      emachStatusEl.textContent = error?.message || 'No se pudo consultar EMACH.';
    } finally {
      calcularEmachBtn.disabled = false;
    }
  });
}

// Copiar tabla con formato similar al ejemplo (compatible con Excel)
const copyBtn = document.getElementById('copy-table-btn');
if (copyBtn) {
  copyBtn.addEventListener('click', () => {
    const table = document.getElementById('extras-table');
    if (!table) return;
    const groupRows = Array.from(table.querySelectorAll('tbody tr.group-row'));
    if (!groupRows.length) {
      window.appModal?.show({
        title: 'Sin datos',
        message: 'No hay datos para copiar.',
        tone: 'warning'
      });
      return;
    }

    const totalHorasTabla = table.dataset.totalHours || '';
    const grupos = [];
    groupRows.forEach(grp => {
      const fecha = grp.dataset.fecha || '';
      const ini = grp.dataset.horaini || '';
      const fin = grp.dataset.horafin || '';
      const total = grp.dataset.total || '';
      const detalles = [];
      let node = grp.nextElementSibling;
      while (node && !node.classList.contains('group-row')) {
        if (node.dataset) detalles.push({ detalle: node.dataset.detalle || '', ticket: node.dataset.ticket || '' });
        node = node.nextElementSibling;
      }
      grupos.push({ fecha, ini, fin, total, detalles });
    });

    const html = [];
    html.push('<table border="1" style="border-collapse:collapse;border:1px solid #000;font-family:Arial,sans-serif;font-size:12px;">');
    html.push('<tr style="background:#d9d9d9;font-weight:bold;"><th style="border:1px solid #000;padding:6px;">Fecha</th><th style="border:1px solid #000;padding:6px;">Detalle</th><th style="border:1px solid #000;padding:6px;width:120px;">N° Ticket</th></tr>');
    grupos.forEach(g => {
      const header = `${g.fecha} · Hora inicio: ${g.ini} | Hora término: ${g.fin}${g.total ? ' | Total de horas: ' + g.total : ''}`;
      html.push(`<tr style=\"background:#cfe2ff;\"><td colspan=\"3\" style=\"border:1px solid #000;padding:6px;\">${header}</td></tr>`);
      g.detalles.forEach(d => {
        html.push(`<tr><td style=\"border:1px solid #000;padding:6px;\"></td><td style=\"border:1px solid #000;padding:6px;white-space:pre-line;\">${d.detalle}</td><td style=\"border:1px solid #000;padding:6px;text-align:center;\">${d.ticket}</td></tr>`);
      });
    });
    html.push(`<tr><td colspan=\"3\" style=\"border:1px solid #000;padding:6px;font-weight:bold;\">Total de horas extra realizadas${totalHorasTabla ? ': ' + totalHorasTabla : ''}</td></tr>`);
    html.push('</table>');
    const textPlain = grupos.map(g => {
      const header = `${g.fecha} · Hora inicio: ${g.ini} | Hora término: ${g.fin}${g.total ? ' | Total de horas: ' + g.total : ''}`;
      const dets = g.detalles.map(d => `\t${d.detalle}\t${d.ticket}`).join('\n');
      return header + '\n' + dets;
    }).join('\n');
    const finalPlain = textPlain + `\nTotal de horas extra realizadas${totalHorasTabla ? ': ' + totalHorasTabla : ''}`;
    const fallbackCopyPlain = (txt) => {
      const ta = document.createElement('textarea');
      ta.value = txt;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    };

    const fallbackCopyHtml = (htmlStr, plainStr) => {
      const container = document.createElement('div');
      container.innerHTML = htmlStr;
      container.style.position = 'fixed';
      container.style.pointerEvents = 'none';
      container.style.opacity = '0';
      document.body.appendChild(container);
      const range = document.createRange();
      range.selectNodeContents(container);
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
      const ok = document.execCommand('copy');
      sel.removeAllRanges();
      document.body.removeChild(container);
      if (!ok) fallbackCopyPlain(plainStr);
    };

    const htmlString = html.join('');

    if (navigator.clipboard && navigator.clipboard.write) {
      const item = new ClipboardItem({
        'text/html': new Blob([htmlString], { type: 'text/html' }),
        'text/plain': new Blob([finalPlain], { type: 'text/plain' })
      });
      navigator.clipboard.write([item]).then(
        () => window.appModal?.show({ title: 'Tabla copiada', message: 'Tabla copiada al portapapeles.', tone: 'success' }),
        () => { fallbackCopyHtml(htmlString, finalPlain); window.appModal?.show({ title: 'Tabla copiada', message: 'Tabla copiada al portapapeles.', tone: 'success' }); }
      );
    } else if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(finalPlain).then(
        () => window.appModal?.show({ title: 'Tabla copiada', message: 'Tabla copiada al portapapeles.', tone: 'success' }),
        () => { fallbackCopyHtml(htmlString, finalPlain); window.appModal?.show({ title: 'Tabla copiada', message: 'Tabla copiada al portapapeles.', tone: 'success' }); }
      );
    } else {
      fallbackCopyHtml(htmlString, finalPlain);
      window.appModal?.show({
        title: 'Tabla copiada',
        message: 'Tabla copiada al portapapeles.',
        tone: 'success'
      });
    }
  });
}
</script>
<button id="horas-extra-scroll-top" type="button" title="Volver arriba" aria-label="Volver arriba" class="btn btn-primary nova-scroll-top">
  <i class="bi bi-arrow-up"></i>
</button>
</div> <!-- #page-content -->
</body>
</html>
