<?php

$pageTitle = 'Telegram';
$activeNav = 'mantenedor';
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/lib/telegram.php';

$configPath = telegram_config_path();
$config = telegram_read_config($configPath);
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $action = (string) ($_POST['action'] ?? '');
  if ($action === 'save_config') {
    $newToken = (string) ($_POST['bot_token'] ?? '');
    $config = [
      'bot_token' => $newToken !== '' ? $newToken : (string) ($config['bot_token'] ?? ''),
      'chat_id' => trim((string) ($_POST['chat_id'] ?? '')),
      'default_parse_mode' => '',
    ];
    if (!telegram_is_configured($config)) {
      $error = 'Completa BOT_TOKEN y CHAT_ID.';
    } elseif (telegram_save_config($config, $configPath)) {
      $config = telegram_read_config($configPath);
      $message = 'Configuracion Telegram guardada. El token quedo protegido en .env.';
    } else {
      $error = 'No se pudo guardar TELEGRAM_BOT_TOKEN en .env. Revisa sus permisos de escritura.';
    }
  }
  if ($action === 'test_message') {
    try {
      telegram_send_configured_message('Mensaje de prueba desde NOVA Telegram: ' . date('d/m/Y H:i:s'));
      $message = 'Mensaje de prueba enviado.';
    } catch (Throwable $e) {
      $error = $e->getMessage();
    }
  }
}

$configured = telegram_is_configured($config);
$savedChatId = (string) ($config['chat_id'] ?? '');
$storageDir = telegram_storage_path();

?>
<!doctype html>
<html lang="es">
<head>
  <?php include __DIR__ . '/views/partials/bootstrap-head.php'; ?>
</head>
<body class="telegram-page">
  <?php include __DIR__ . '/views/partials/navbar.php'; ?>

  <main class="nova-content"><div class="container-fluid py-4">
    <section class="card telegram-hero nova-system-hero mb-4">
      <div class="card-body p-4 d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div class="d-flex align-items-center gap-3">
          <span class="telegram-hero-icon"><i class="bi bi-telegram"></i></span>
          <div>
            <h1 class="h3 mb-1 text-white fw-black">Mensajes Telegram</h1>
            <p class="mb-0 text-white-50 fw-semibold">Servicio central para enviar mensajes desde proyectos NOVA.</p>
          </div>
        </div>
        <span class="telegram-status-pill"><i class="bi <?= $configured ? 'bi-check-circle' : 'bi-exclamation-triangle' ?>"></i><?= $configured ? 'Configurado' : 'Pendiente' ?></span>
      </div>
    </section>

    <?php if ($message !== ''): ?><div data-nova-flash="telegram" data-nova-flash-message="<?= $h($message) ?>" hidden></div><?php endif; ?>
    <?php if ($error !== ''): ?><div data-nova-flash="warning" data-nova-flash-message="<?= $h($error) ?>" hidden></div><?php endif; ?>

    <section class="row g-3">
      <div class="col-12 col-xl-7">
        <article class="card telegram-card h-100">
          <div class="card-body p-4">
            <h2 class="h5 fw-black mb-1">Configuracion central</h2>
            <p class="text-muted fw-semibold">El token global se guarda exclusivamente como <code>TELEGRAM_BOT_TOKEN</code> en <code>.env</code>; nunca se almacena en JSON.</p>

            <form class="row g-3" method="post">
              <input type="hidden" name="action" value="save_config">
              <?php if (function_exists('csrf_token')): ?>
                <input type="hidden" name="_token" value="<?= $h(csrf_token()) ?>">
              <?php endif; ?>
              <div class="col-12">
                <label class="form-label fw-bold" for="bot-token">TELEGRAM_BOT_TOKEN</label>
                <input class="form-control" id="bot-token" name="bot_token" type="password" autocomplete="new-password" placeholder="<?= $configured ? 'Dejar en blanco para conservar el valor de .env' : 'Token de BotFather' ?>">
              </div>
              <div class="col-12">
                <label class="form-label fw-bold" for="chat-id">TELEGRAM_CHAT_ID</label>
                <input class="form-control" id="chat-id" name="chat_id" value="<?= $h($savedChatId) ?>" placeholder="7449883192">
              </div>
              <div class="col-12 d-flex gap-2 flex-wrap">
                <button class="btn btn-primary telegram-submit" type="submit"><i class="bi bi-save"></i>Guardar</button>
              </div>
            </form>
          </div>
        </article>
      </div>

      <div class="col-12 col-xl-5">
        <article class="card telegram-card h-100">
          <div class="card-body p-4">
            <h2 class="h5 fw-black mb-3">Pruebas y uso</h2>
            <form method="post" class="mb-3">
              <input type="hidden" name="action" value="test_message">
              <?php if (function_exists('csrf_token')): ?>
                <input type="hidden" name="_token" value="<?= $h(csrf_token()) ?>">
              <?php endif; ?>
              <button class="btn btn-outline-primary w-100" type="submit" <?= $configured ? '' : 'disabled' ?>><i class="bi bi-send"></i>Enviar mensaje de prueba</button>
            </form>
            <div class="telegram-code-block">
              <span>Desde CLI</span>
              <code>php telegram/bin/listen.php</code>
            </div>
            <div class="telegram-code-block mt-3">
              <span>Config</span>
              <code><?= $h($configPath) ?></code>
            </div>
            <div class="telegram-code-block mt-3">
              <span>Storage</span>
              <code><?= $h($storageDir) ?></code>
            </div>
          </div>
        </article>
      </div>
    </section>
  </div></main><!-- /.nova-content -->
</div><!-- /.nova-layout -->
<?php
$novaUiJsPath = function_exists('base_path') ? base_path('public/assets/nova-ui.js') : __DIR__ . '/../public/assets/nova-ui.js';
$novaUiJsVersion = @filemtime($novaUiJsPath) ?: time();
$novaUiJsUrl = function_exists('asset') ? asset('assets/nova-ui.js') : '/NOVA/public/assets/nova-ui.js';
?>
<script src="<?= $h($novaUiJsUrl) ?>?v=<?= (int) $novaUiJsVersion ?>"></script>
</body>
</html>
