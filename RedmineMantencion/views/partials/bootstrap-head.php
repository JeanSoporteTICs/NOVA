<?php
$pageTitle = $pageTitle ?? 'Redmine Mantencion';
$includeTheme = $includeTheme ?? true;
$mantencionBaseUrl = function_exists('url') ? rtrim(url('/redmine-mantencion'), '/') : '/redmine-mantencion';
$novaFaviconUrl = function_exists('asset') ? asset('assets/logos/favicon-nova.svg') : '/NOVA/public/assets/logos/favicon-nova.svg';
$novaFaviconPath = function_exists('base_path') ? base_path('public/assets/logos/favicon-nova.svg') : __DIR__ . '/../../../public/assets/logos/favicon-nova.svg';
$novaTouchIconUrl = function_exists('asset') ? asset('assets/logos/favicon-nova-512.png') : '/NOVA/public/assets/logos/favicon-nova-512.png';
$novaFaviconVersion = @filemtime($novaFaviconPath) ?: time();
?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php if (function_exists('csrf_token')): ?>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <title><?= htmlspecialchars((string) $pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($novaFaviconUrl, ENT_QUOTES, 'UTF-8') ?>?v=<?= (int)$novaFaviconVersion ?>">
  <link rel="shortcut icon" type="image/svg+xml" href="<?= htmlspecialchars($novaFaviconUrl, ENT_QUOTES, 'UTF-8') ?>?v=<?= (int)$novaFaviconVersion ?>">
  <link rel="apple-touch-icon" href="<?= htmlspecialchars($novaTouchIconUrl, ENT_QUOTES, 'UTF-8') ?>?v=<?= (int)$novaFaviconVersion ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php if ($includeTheme): ?>
  <?php $themeVersion = @filemtime(__DIR__ . '/../../assets/theme.css') ?: time(); ?>
  <link href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/theme.css?v=<?= (int)$themeVersion ?>" rel="stylesheet">
<?php endif; ?>
  <?php $novaUiPath = function_exists('base_path') ? base_path('public/assets/nova-ui.css') : __DIR__ . '/../../../public/assets/nova-ui.css'; ?>
  <?php $novaUiVersion = @filemtime($novaUiPath) ?: time(); ?>
  <link href="<?= htmlspecialchars(function_exists('asset') ? asset('assets/nova-ui.css') : '/NOVA/public/assets/nova-ui.css', ENT_QUOTES, 'UTF-8') ?>?v=<?= (int)$novaUiVersion ?>" rel="stylesheet">
