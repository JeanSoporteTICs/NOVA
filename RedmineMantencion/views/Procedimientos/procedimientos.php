<?php
require_once __DIR__ . '/../../controllers/auth.php';
require_once __DIR__ . '/../../controllers/nc_browser.php';

auth_require_login('/redmine-mantencion/login.php');
if (!auth_can('procedimientos')) {
    http_response_code(403);
    exit('No tienes permiso para acceder a Procedimientos.');
}

$activeNav = 'procedimientos';
$csrf = legacy_csrf_token();
$canEditProcedures = !(function_exists('maintenance_mode_enabled') && maintenance_mode_enabled());
?>
<!doctype html>
<html lang="es">
<head>
  <?php $pageTitle = 'Procedimientos'; $includeTheme = true; include __DIR__ . '/../partials/bootstrap-head.php'; ?>
</head>
<body class="bg-light">
<?php include __DIR__ . '/../partials/navbar.php'; ?>
<div id="page-content">
  <main class="rm-layout">
    <?php
      $heroIcon = 'bi-cloud';
      $heroTitle = 'Procedimientos';
      $heroSubtitle = 'Archivos administrados directamente en tu cuenta Nextcloud.';
      $heroExtras = '';
      include __DIR__ . '/../partials/hero.php';
      include __DIR__ . '/_nc_browser.php';
    ?>
  </main>
</div>
</body>
</html>
