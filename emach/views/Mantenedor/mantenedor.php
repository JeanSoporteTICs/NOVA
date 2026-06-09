<?php

$pageTitle = 'Mantenedor EMACH';
$activeNav = 'mantenedor';
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/../../../telegram/lib/telegram.php';
$sessionUser = function_exists('request') ? request()->session()->get('nova_user') : ($_SESSION['user'] ?? []);
$sessionUser = is_array($sessionUser) ? $sessionUser : [];
$adminRoles = function_exists('config') ? config('nova.module_admin_roles', []) : ['admin', 'root'];
$isAdminView = in_array((string) ($sessionUser['role'] ?? $sessionUser['rol'] ?? 'usuario'), $adminRoles, true);
$integrationUsers = function_exists('app') ? app(\App\Support\Integrations\UserIntegrationRepository::class)->users() : [];
$emachStoragePath = function_exists('storage_path') ? storage_path('app/emach') : __DIR__ . '/../../../storage/app/emach';
$statePath = $emachStoragePath . '/monitor_state.json';
$configPath = $emachStoragePath . '/monitor_config.json';
$config = is_file($configPath) ? json_decode((string) file_get_contents($configPath), true) : [];
$config = is_array($config) ? $config : [];
$configMessage = '';
$configError = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'save_monitor_config' && $isAdminView) {
  $newPassword = (string) ($_POST['emach_password'] ?? '');
  $config = [
    'emach_user' => trim((string) ($_POST['emach_user'] ?? '')),
    'emach_password' => $newPassword !== '' ? $newPassword : (string) ($config['emach_password'] ?? ''),
    'make_webhook_url' => trim((string) ($_POST['make_webhook_url'] ?? '')),
    'schedule' => trim((string) ($_POST['schedule'] ?? '07:00-09:30=15,16:30-19:30=15')),
    'slow_interval' => max(15, (int) ($_POST['slow_interval'] ?? 300)),
    'updated_at' => date(DATE_ATOM),
  ];
  $hasMake = $config['make_webhook_url'] !== '';
  $hasTelegram = telegram_is_configured();
  if ($config['emach_user'] === '' || $config['emach_password'] === '') {
    $configError = 'Completa usuario EMACH y contrasena EMACH.';
  } elseif (!$hasMake && !$hasTelegram) {
    $configError = 'Configura Make o el servicio Telegram central en NOVA para enviar notificaciones.';
  } else {
    $configDirectory = dirname($configPath);
    if (!is_dir($configDirectory) && !@mkdir($configDirectory, 0775, true) && !is_dir($configDirectory)) {
      $configError = 'No se pudo crear el directorio de configuracion: ' . $configDirectory;
    } elseif (!is_writable($configDirectory)) {
      $configError = 'El directorio de configuracion no tiene permisos de escritura: ' . $configDirectory;
    } else {
      $written = @file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
      if ($written === false) {
        $configError = 'No se pudo guardar la configuracion. Revisa permisos en: ' . $configDirectory;
      } else {
        @chmod($configPath, 0666);
        $configMessage = 'Configuracion guardada.';
      }
    }
  }
}
$state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : [];
$state = is_array($state) ? $state : [];
$fingerprints = array_values(array_filter($state['fingerprints'] ?? [], 'is_string'));
$lastUpdate = (string) ($state['updated_at'] ?? '');
$stateYear = (string) ($state['year'] ?? date('Y'));
$stateMonth = (int) ($state['month'] ?? date('n'));
$monthNames = [
  1 => 'Enero',
  2 => 'Febrero',
  3 => 'Marzo',
  4 => 'Abril',
  5 => 'Mayo',
  6 => 'Junio',
  7 => 'Julio',
  8 => 'Agosto',
  9 => 'Septiembre',
  10 => 'Octubre',
  11 => 'Noviembre',
  12 => 'Diciembre',
];
$stateMonthName = $monthNames[$stateMonth] ?? (string) $stateMonth;
$hasState = !empty($fingerprints);
$savedEmachUser = (string) ($config['emach_user'] ?? '');
$savedWebhookUrl = (string) ($config['make_webhook_url'] ?? '');
$savedSchedule = (string) ($config['schedule'] ?? '07:00-09:30=15,16:30-19:30=15');
$savedSlowInterval = (string) ($config['slow_interval'] ?? '300');
$makeWebhookConfigured = trim((string) getenv('MAKE_WEBHOOK_URL')) !== '' || trim($savedWebhookUrl) !== '';
$centralNotificationsConfigured = telegram_is_configured();
$emachUserConfigured = trim((string) getenv('EMACH_USER')) !== '' || trim($savedEmachUser) !== '';
$emachPasswordConfigured = (string) getenv('EMACH_PASSWORD') !== '' || (string) ($config['emach_password'] ?? '') !== '';
$monitorReady = ($makeWebhookConfigured || $centralNotificationsConfigured) && $emachUserConfigured && $emachPasswordConfigured;
$basePath = function_exists('base_path') ? base_path() : dirname(__DIR__, 3);
$scheduleSpec = trim((string) (getenv('EMACH_SCHEDULE') ?: $savedSchedule));
$slowInterval = trim((string) (getenv('EMACH_SLOW_INTERVAL') ?: $savedSlowInterval));
$smartSchedule = $scheduleSpec !== '' ? $scheduleSpec : '07:00-09:30=15,16:30-19:30=15';
$smartSlowInterval = $slowInterval !== '' ? $slowInterval : '300';
$monitorCommand = 'cd ' . $basePath . ' && php emach/bin/monitor.php --loop --schedule=' . $smartSchedule . ' --slow-interval=' . $smartSlowInterval;

?>
<!doctype html>
<html lang="es">
<head>
  <?php include __DIR__ . '/../partials/bootstrap-head.php'; ?>
</head>
<body class="emach-page">
  <?php include __DIR__ . '/../partials/navbar.php'; ?>

  <main class="container-fluid py-4">
    <?php if (!$isAdminView): ?>
      <div class="alert alert-warning fw-semibold">
        <i class="bi bi-shield-lock"></i> Esta vista es de administracion. Usa la consulta EMACH para revisar tus marcaciones.
      </div>
    <?php endif; ?>
    <section class="card card-hero sb-page-hero emach-hero mb-4">
      <div class="card-body p-4 d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div class="d-flex align-items-center gap-3">
          <span class="emach-hero-icon"><i class="bi bi-sliders"></i></span>
          <div>
            <h1 class="h3 mb-1 text-white fw-black">Administracion EMACH</h1>
            <p class="mb-0 text-white-50 fw-semibold">Monitor global, dependencias y estado de credenciales por usuario.</p>
          </div>
        </div>
        <span class="emach-status-pill"><i class="bi <?= $monitorReady ? 'bi-check-circle' : 'bi-exclamation-triangle' ?>"></i><?= $monitorReady ? 'Listo para monitorear' : 'Configuracion pendiente' ?></span>
      </div>
    </section>

    <section class="row g-3 mb-4">
      <div class="col-12 col-md-6 col-xl-3">
        <article class="emach-stat-card">
          <span class="emach-stat-icon <?= $monitorReady ? 'is-success' : 'is-exit' ?>"><i class="bi bi-broadcast-pin"></i></span>
          <div><strong><?= $makeWebhookConfigured ? 'OK' : 'Opcional' ?></strong><span>Webhook Make</span></div>
        </article>
      </div>
      <div class="col-12 col-md-6 col-xl-3">
        <article class="emach-stat-card">
          <span class="emach-stat-icon <?= $centralNotificationsConfigured ? 'is-success' : 'is-exit' ?>"><i class="bi bi-bell"></i></span>
          <div><strong><?= $centralNotificationsConfigured ? 'OK' : 'Pendiente' ?></strong><span>Canal central NOVA</span></div>
        </article>
      </div>
      <div class="col-12 col-md-6 col-xl-3">
        <article class="emach-stat-card">
          <span class="emach-stat-icon <?= $emachUserConfigured && $emachPasswordConfigured ? 'is-success' : 'is-exit' ?>"><i class="bi bi-shield-lock"></i></span>
          <div><strong><?= $emachUserConfigured && $emachPasswordConfigured ? 'OK' : 'Falta' ?></strong><span>Credenciales EMACH</span></div>
        </article>
      </div>
      <div class="col-12 col-md-6 col-xl-3">
        <article class="emach-stat-card">
          <span class="emach-stat-icon is-entry"><i class="bi bi-fingerprint"></i></span>
          <div><strong><?= $h(count($fingerprints)) ?></strong><span>Marcaciones conocidas</span></div>
        </article>
      </div>
    </section>

    <section class="row g-3">
      <div class="col-12">
        <article class="card emach-card mb-3">
          <div class="card-body p-4">
            <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
              <div>
                <h2 class="h5 fw-black mb-1">Credenciales por usuario</h2>
                <p class="text-muted fw-semibold mb-0">Cada usuario gestiona sus datos personales. Administracion puede cargarlos manualmente desde Usuarios NOVA.</p>
              </div>
              <a class="btn btn-outline-primary" href="<?= $h(function_exists('route') ? route('administracion.section', 'usuarios') : '#') ?>"><i class="bi bi-people"></i>Usuarios NOVA</a>
            </div>
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th>Usuario</th>
                    <th>EMACH</th>
                    <th>Telegram</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($integrationUsers as $integrationUser): ?>
                    <?php
                      $emachCred = is_array($integrationUser['emach_credentials'] ?? null) ? $integrationUser['emach_credentials'] : [];
                      $telegramData = is_array($integrationUser['telegram_settings'] ?? null) ? $integrationUser['telegram_settings'] : [];
                      $hasEmach = trim((string) ($emachCred['user'] ?? '')) !== '' && trim((string) ($emachCred['password'] ?? '')) !== '';
                      $hasTelegram = trim((string) ($telegramData['chat_id'] ?? '')) !== '';
                    ?>
                    <tr>
                      <td>
                        <strong><?= $h(trim((string) ($integrationUser['name'] ?? '') . ' ' . (string) ($integrationUser['apellido'] ?? '')) ?: ($integrationUser['username'] ?? '')) ?></strong>
                        <div class="text-muted small"><?= $h($integrationUser['username'] ?? '') ?></div>
                      </td>
                      <td><span class="badge <?= $hasEmach ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $hasEmach ? 'Guardadas' : 'Pendiente' ?></span></td>
                      <td><span class="badge <?= $hasTelegram ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $hasTelegram ? 'Chat ID' : 'Pendiente' ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if ($integrationUsers === []): ?>
                    <tr><td colspan="3">No hay usuarios NOVA registrados.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </article>
        <article class="card emach-card">
          <div class="card-body p-4">
            <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
              <div>
                <h2 class="h5 fw-black mb-1">Configuracion guardada</h2>
                <p class="text-muted fw-semibold mb-0">Estos datos quedan en el servidor local y el monitor los usa automaticamente.</p>
              </div>
              <span class="emach-count-pill"><i class="bi bi-file-lock"></i><?= is_file($configPath) ? 'Archivo creado' : 'Sin archivo' ?></span>
            </div>

            <?php if ($configMessage !== ''): ?>
              <div class="alert emach-success-alert fw-semibold"><i class="bi bi-check-circle"></i><?= $h($configMessage) ?></div>
            <?php endif; ?>
            <?php if ($configError !== ''): ?>
              <div class="alert alert-warning fw-semibold"><i class="bi bi-exclamation-triangle"></i><?= $h($configError) ?></div>
            <?php endif; ?>

            <form class="row g-3" method="post" action="">
              <input type="hidden" name="action" value="save_monitor_config">
              <?php if (function_exists('csrf_token')): ?>
                <input type="hidden" name="_token" value="<?= $h(csrf_token()) ?>">
              <?php endif; ?>
              <div class="col-12 col-lg-3">
                <label class="form-label fw-bold" for="emach-user">EMACH_USER</label>
                <input class="form-control" id="emach-user" name="emach_user" value="<?= $h($savedEmachUser) ?>" autocomplete="username" required>
              </div>
              <div class="col-12 col-lg-3">
                <label class="form-label fw-bold" for="emach-password">EMACH_PASSWORD</label>
                <input class="form-control" id="emach-password" name="emach_password" type="password" autocomplete="current-password" placeholder="<?= $emachPasswordConfigured ? 'Dejar en blanco para conservar' : '' ?>" <?= $emachPasswordConfigured ? '' : 'required' ?>>
              </div>
              <div class="col-12 col-lg-6">
                <label class="form-label fw-bold" for="make-webhook-url">MAKE_WEBHOOK_URL</label>
                <input class="form-control" id="make-webhook-url" name="make_webhook_url" value="<?= $h($savedWebhookUrl) ?>" placeholder="Opcional si usas el canal central de NOVA">
              </div>
              <div class="col-12 col-lg-5">
                <label class="form-label fw-bold" for="emach-schedule">Horario inteligente</label>
                <input class="form-control" id="emach-schedule" name="schedule" value="<?= $h($smartSchedule) ?>" placeholder="07:00-09:30=15,16:30-19:30=15">
              </div>
              <div class="col-12 col-lg-2">
                <label class="form-label fw-bold" for="emach-slow-interval">Intervalo lento</label>
                <input class="form-control" id="emach-slow-interval" name="slow_interval" type="number" min="15" value="<?= $h($smartSlowInterval) ?>">
              </div>
              <div class="col-12 col-lg-2 d-flex align-items-end">
                <button class="btn btn-primary emach-submit-button w-100" type="submit"><i class="bi bi-save"></i>Guardar</button>
              </div>
              <div class="col-12">
                <div class="form-text fw-semibold">Ruta: <?= $h($configPath) ?>. El archivo se guarda con permisos 600 cuando el sistema lo permite.</div>
                <div class="form-text fw-semibold">Las notificaciones compartidas se administran desde NOVA, fuera del mantenedor EMACH.</div>
              </div>
            </form>
          </div>
        </article>
      </div>

      <div class="col-12 col-xl-7">
        <article class="card emach-card h-100">
          <div class="card-body p-4">
            <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap mb-3">
              <div>
                <h2 class="h5 fw-black mb-1">Monitor de marcaciones</h2>
                <p class="text-muted fw-semibold mb-0">El monitor se ejecuta por consola y envia solo marcaciones nuevas.</p>
              </div>
              <span class="emach-count-pill"><i class="bi bi-clock-history"></i><?= $lastUpdate !== '' ? $h($lastUpdate) : 'Sin ejecuciones' ?></span>
            </div>

            <div class="emach-maintainer-grid">
              <div class="emach-maintainer-item">
                <span>Estado local</span>
                <strong><?= $hasState ? 'Linea base creada' : 'Sin linea base' ?></strong>
              </div>
              <div class="emach-maintainer-item">
                <span>Archivo de estado</span>
                <strong><?= $h($statePath) ?></strong>
              </div>
              <div class="emach-maintainer-item">
                <span>Intervalo sugerido</span>
                <strong>15 segundos en ventanas criticas, <?= $h($smartSlowInterval) ?> segundos el resto del dia</strong>
              </div>
              <div class="emach-maintainer-item">
                <span>Horario inteligente</span>
                <strong><?= $h($smartSchedule) ?></strong>
              </div>
            </div>

            <div class="alert emach-maintainer-note mt-4 mb-0">
              <i class="bi bi-info-circle"></i>
              La primera ejecucion normal crea la linea base y no envia el historico. Desde la segunda ejecucion avisa solo lo nuevo.
            </div>
          </div>
        </article>
      </div>

      <div class="col-12 col-xl-5">
        <article class="card emach-card h-100">
          <div class="card-body p-4">
            <h2 class="h5 fw-black mb-1">Dependencias del monitor</h2>
            <p class="text-muted fw-semibold">EMACH guarda solo sus credenciales y el webhook opcional de Make.</p>

            <div class="emach-env-list">
              <div class="<?= $emachUserConfigured ? 'is-ok' : 'is-missing' ?>"><i class="bi <?= $emachUserConfigured ? 'bi-check-circle' : 'bi-x-circle' ?>"></i><span>EMACH_USER</span></div>
              <div class="<?= $emachPasswordConfigured ? 'is-ok' : 'is-missing' ?>"><i class="bi <?= $emachPasswordConfigured ? 'bi-check-circle' : 'bi-x-circle' ?>"></i><span>EMACH_PASSWORD</span></div>
              <div class="<?= $makeWebhookConfigured ? 'is-ok' : 'is-missing' ?>"><i class="bi <?= $makeWebhookConfigured ? 'bi-check-circle' : 'bi-dash-circle' ?>"></i><span>MAKE_WEBHOOK_URL opcional</span></div>
              <div class="<?= $centralNotificationsConfigured ? 'is-ok' : 'is-missing' ?>"><i class="bi <?= $centralNotificationsConfigured ? 'bi-check-circle' : 'bi-dash-circle' ?>"></i><span>Canal central NOVA</span></div>
            </div>

            <div class="emach-code-block mt-4">
              <span>Comando recomendado</span>
              <code><?= $h($monitorCommand) ?></code>
            </div>
            <div class="emach-code-block mt-3">
              <span>Variables opcionales</span>
              <code>export EMACH_SCHEDULE='<?= $h($smartSchedule) ?>' && export EMACH_SLOW_INTERVAL='<?= $h($smartSlowInterval) ?>'</code>
            </div>
          </div>
        </article>
      </div>

      <div class="col-12">
        <article class="card emach-card">
          <div class="card-body p-4">
            <h2 class="h5 fw-black mb-3">Pruebas rapidas</h2>
            <div class="row g-3">
              <div class="col-12 col-lg-4">
                <div class="emach-command-card">
                  <span>Probar sin enviar</span>
                  <code>php emach/bin/monitor.php --dry-run</code>
                </div>
              </div>
              <div class="col-12 col-lg-4">
                <div class="emach-command-card">
                  <span>Probar notificacion</span>
                  <code>php emach/bin/monitor.php --test-webhook</code>
                </div>
              </div>
              <div class="col-12 col-lg-4">
                <div class="emach-command-card">
                  <span>Listener central</span>
                  <code>php telegram/bin/listen.php</code>
                </div>
              </div>
            </div>
          </div>
        </article>
      </div>
    </section>
  </main>
</body>
</html>
