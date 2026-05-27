<?php

$pageTitle = $pageTitle ?? 'Nuevo Proyecto';
$includeTheme = $includeTheme ?? true;
$moduleKey = 'nuevo-proyecto';
$moduleBaseUrl = function_exists('url') ? rtrim(url('/' . $moduleKey), '/') : '/' . $moduleKey;
$h = $h ?? fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $h($pageTitle) ?></title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

<?php if ($includeTheme): ?>
  <?php $themePath = __DIR__ . '/../../assets/theme.css'; ?>
  <?php $themeVersion = @filemtime($themePath) ?: time(); ?>
  <link href="<?= $h($moduleBaseUrl) ?>/assets/theme.css?v=<?= (int) $themeVersion ?>" rel="stylesheet">
<?php endif; ?>

  <?php $novaUiPath = function_exists('base_path') ? base_path('public/assets/nova-ui.css') : __DIR__ . '/../../../public/assets/nova-ui.css'; ?>
  <?php $novaUiVersion = @filemtime($novaUiPath) ?: time(); ?>
  <link href="<?= $h(function_exists('asset') ? asset('assets/nova-ui.css') : '/NOVA/public/assets/nova-ui.css') ?>?v=<?= (int) $novaUiVersion ?>" rel="stylesheet">

