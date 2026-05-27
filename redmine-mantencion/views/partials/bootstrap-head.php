<?php
$pageTitle = $pageTitle ?? 'Redmine Mantencion';
$includeTheme = $includeTheme ?? true;
$mantencionBaseUrl = function_exists('url') ? rtrim(url('/redmine-mantencion'), '/') : '/redmine-mantencion';
?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php if (function_exists('csrf_token')): ?>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <title><?= htmlspecialchars((string) $pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php if ($includeTheme): ?>
  <?php $themeVersion = @filemtime(__DIR__ . '/../../assets/theme.css') ?: time(); ?>
  <link href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/theme.css?v=<?= (int)$themeVersion ?>" rel="stylesheet">
<?php endif; ?>
  <?php $novaUiPath = function_exists('base_path') ? base_path('public/assets/nova-ui.css') : __DIR__ . '/../../../public/assets/nova-ui.css'; ?>
  <?php $novaUiVersion = @filemtime($novaUiPath) ?: time(); ?>
  <link href="<?= htmlspecialchars(function_exists('asset') ? asset('assets/nova-ui.css') : '/NOVA/public/assets/nova-ui.css', ENT_QUOTES, 'UTF-8') ?>?v=<?= (int)$novaUiVersion ?>" rel="stylesheet">
  <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/favicon.svg">
