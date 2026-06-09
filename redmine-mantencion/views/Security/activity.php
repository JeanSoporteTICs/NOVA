<?php
require_once __DIR__ . '/../../controllers/auth.php';
require_once __DIR__ . '/../../controllers/security.php';
require_once __DIR__ . '/../../controllers/maintenance.php';
auth_require_role(['root', 'administrador', 'gestor'], '/redmine-mantencion/login.php');
if (!auth_can('actividad')) {
  header('Location: ' . legacy_app_url());
  exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$flash = $_SESSION['security_flash'] ?? null;
unset($_SESSION['security_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    if (function_exists('maintenance_mode_block_if_enabled')) maintenance_mode_block_if_enabled();
    if (($_POST['action'] ?? '') === 'clear_activity') {
        security_clear_events();
        if (function_exists('log_security_event')) {
            log_security_event(
                'ACTIVITY_CLEAR',
                sprintf(
                    'Actividad reciente limpiada por %s (ID %s)',
                    (string)($_SESSION['user']['nombre'] ?? 'usuario'),
                    (string)($_SESSION['user']['id'] ?? '')
                )
            );
        }
        $_SESSION['security_flash'] = 'Actividad reciente borrada.';
    }
    header('Location: ' . legacy_app_url('views/Security/activity.php'));
    exit;
}

$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$formatSecurityTimestamp = fn($ts) => (function($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    try {
        $dt = new DateTimeImmutable($value);
    } catch (Throwable $_) {
        return $value;
    }
    return $dt->setTimezone(new DateTimeZone('America/Santiago'))->format('d-m-Y H:i:s');
})($ts);
$selectedTag = strtoupper(trim((string)($_GET['tag'] ?? '')));
$allEvents = security_load_events(200);
$eventTags = array_values(array_unique(array_filter(array_map(static fn(array $event): string => (string)($event['tag'] ?? ''), $allEvents))));
sort($eventTags);
$events = $selectedTag !== ''
    ? array_values(array_filter($allEvents, static fn(array $event): bool => (string)($event['tag'] ?? '') === $selectedTag))
    : array_slice($allEvents, 0, 80);
$events = array_slice($events, 0, 120);
$tagUrl = static function (string $tag): string {
    return $tag === ''
        ? 'activity.php'
        : 'activity.php?tag=' . rawurlencode($tag);
};
$activeNav = 'security';
$csrf = legacy_csrf_token();
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Actividad de seguridad'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
</head>
<body class="bg-light">
<?php $activeNav = 'security'; include __DIR__ . '/../partials/navbar.php'; ?>
<div id="page-content">
  <div class="container-fluid py-4">
    <?php
      $heroIcon = 'bi-shield-lock';
      $heroTitle = 'Actividad reciente';
      $heroSubtitle = 'Accesos, CSRF y eventos críticos registrados en la plataforma.';
      include __DIR__ . '/../partials/hero.php';
    ?>

    <?php if ($flash): ?>
      <div class="alert alert-success"><?= $h($flash) ?></div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-body">
        <p class="text-muted mb-3">Se registran los últimos intentos de inicio de sesión y alertas de seguridad. Si ves fallas repetidas en poco tiempo, considera rotar tokens API o revisar accesos.</p>
        <div class="security-terminal-filters mb-3" aria-label="Filtros de actividad">
          <a class="security-terminal-filter <?= $selectedTag === '' ? 'is-active' : '' ?>" href="<?= $h($tagUrl('')) ?>">
            <span>$</span> ALL
          </a>
          <?php foreach ($eventTags as $tag): ?>
            <a class="security-terminal-filter <?= $selectedTag === $tag ? 'is-active' : '' ?>" href="<?= $h($tagUrl($tag)) ?>">
              <span>grep</span> <?= $h($tag) ?>
            </a>
          <?php endforeach; ?>
        </div>
        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
          <div class="text-muted small">
            <?= $selectedTag !== '' ? 'Filtro activo: ' . $h($selectedTag) . ' | ' : '' ?><?= count($events) ?> eventos visibles
          </div>
          <form method="post" class="mb-0">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="clear_activity">
            <button type="submit" class="btn btn-outline-danger btn-sm">
              <i class="bi bi-trash3"></i> Limpiar actividad reciente
            </button>
          </form>
        </div>
        <?php if (empty($events)): ?>
          <div class="alert alert-info mb-0">Todavía no hay eventos registrados.</div>
        <?php else: ?>
          <div class="security-console-wrap">
            <div class="security-console-toolbar">
              <span class="security-console-dot" aria-hidden="true"></span>
              <span>security.log :: live tail</span>
            </div>
            <div class="table-responsive">
            <table class="table align-middle security-console">
              <thead>
                <tr>
                  <th scope="col" style="width:200px;">Fecha / hora</th>
                  <th scope="col" style="width:150px;">Evento</th>
                  <th scope="col">Detalles</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($events as $evt): ?>
                  <tr>
                    <td class="console-time"><?= $h($formatSecurityTimestamp($evt['ts'])) ?: '----' ?></td>
                    <td><span class="console-tag"><?= $h($evt['tag']) ?></span></td>
                    <td class="console-details"><?= $h($evt['details']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../partials/bootstrap-scripts.php'; ?>
</body>
</html>
