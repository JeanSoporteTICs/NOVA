<?php

$pageTitle = $pageTitle ?? 'EMACH';
$includeTheme = $includeTheme ?? true;
$moduleKey = 'emach';
$moduleBaseUrl = function_exists('url') ? rtrim(url('/' . $moduleKey), '/') : '/' . $moduleKey;
$h = $h ?? static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$novaFaviconUrl = function_exists('asset') ? asset('assets/logos/favicon-nova.svg') : '/NOVA/public/assets/logos/favicon-nova.svg';
$novaTouchIconUrl = function_exists('asset') ? asset('assets/logos/favicon-nova-512.png') : '/NOVA/public/assets/logos/favicon-nova-512.png';
$novaFaviconPath = function_exists('base_path') ? base_path('public/assets/logos/favicon-nova.svg') : __DIR__ . '/../../../public/assets/logos/favicon-nova.svg';
$novaFaviconVersion = @filemtime($novaFaviconPath) ?: time();

?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php if (function_exists('csrf_token')): ?>
  <meta name="csrf-token" content="<?= $h(csrf_token()) ?>">
  <?php endif; ?>
  <title><?= $h($pageTitle) ?></title>
  <link rel="icon" type="image/svg+xml" href="<?= $h($novaFaviconUrl) ?>?v=<?= (int) $novaFaviconVersion ?>">
  <link rel="shortcut icon" type="image/svg+xml" href="<?= $h($novaFaviconUrl) ?>?v=<?= (int) $novaFaviconVersion ?>">
  <link rel="apple-touch-icon" href="<?= $h($novaTouchIconUrl) ?>?v=<?= (int) $novaFaviconVersion ?>">
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
  <script defer src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
