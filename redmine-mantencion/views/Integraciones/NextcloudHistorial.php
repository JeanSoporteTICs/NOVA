<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_role(['root', 'gestor'], '/redmine-mantencion/login.php');
require_once __DIR__ . '/../../controllers/nextcloud.php';
$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$csrf = legacy_csrf_token();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_nextcloud_history') {
    if (function_exists('csrf_validate')) csrf_validate();
    nextcloud_created_history_clear();
    nextcloud_set_flash('Historial Nextcloud eliminado.');
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/redmine-mantencion/views/Integraciones/NextcloudHistorial.php'));
    exit;
}
$flash = nextcloud_consume_flash();
$batches = nextcloud_created_history_load();
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Historial Nextcloud'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
  <style>
    body { margin: 0; }
    .nextcloud-panel { border: 0; border-radius: 1.1rem; box-shadow: 0 16px 38px rgba(15, 23, 42, .08); }
    .nextcloud-row-created > * { background-color: #dcfce7 !important; }
    .nextcloud-row-existing > * { background-color: #fef3c7 !important; }
    .nextcloud-row-failed > * { background-color: #fee2e2 !important; }
  </style>
</head>
<body class="bg-light">
<?php $activeNav = 'integraciones_nextcloud_historial'; include __DIR__ . '/../partials/navbar.php'; ?>

<div id="page-content">
  <div class="container-fluid py-4">
    <?php
      $heroIcon = 'bi-clock-history';
      $heroTitle = 'Historial Nextcloud';
      $heroSubtitle = 'Lotes de usuarios creados disponibles por 24 horas para copiar credenciales.';
      $heroExtras = '';
      include __DIR__ . '/../partials/hero.php';
    ?>

    <?php if ($flash): ?>
      <div class="alert alert-success d-flex align-items-center gap-2" role="alert">
        <i class="bi bi-check-circle"></i>
        <span><?= $h($flash) ?></span>
      </div>
    <?php endif; ?>

    <div class="d-flex justify-content-end mb-3">
      <form method="post" onsubmit="return confirm('¿Eliminar todo el historial de Nextcloud?');">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <input type="hidden" name="action" value="clear_nextcloud_history">
        <button type="submit" class="btn btn-outline-danger" <?= !$batches ? 'disabled' : '' ?>>
          <i class="bi bi-trash"></i> Limpiar historial
        </button>
      </form>
    </div>

    <?php if (!$batches): ?>
      <div class="card nextcloud-panel">
        <div class="card-body p-4 text-muted">
          No hay lotes temporales disponibles.
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
      <div class="card nextcloud-panel mb-3">
        <div class="card-body p-4">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
            <div>
              <h5 class="mb-1">Lote <?= $h($batch['id'] ?? '') ?></h5>
              <div class="text-muted small">
                Creado: <?= $h(date('d-m-Y H:i', strtotime((string)($batch['created_at'] ?? 'now')))) ?>
                Disponible hasta: <?= $h(date('d-m-Y H:i', strtotime((string)($batch['expires_at'] ?? 'now')))) ?>
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
                  <th>Contraseña</th>
                  <th>Detalle</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($batchUsers as $item): ?>
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
    <?php endforeach; ?>
  </div>
</div>

<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
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
});
</script>
</body>
</html>
