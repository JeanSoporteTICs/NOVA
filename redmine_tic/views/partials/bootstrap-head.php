<?php
$pageTitle = $pageTitle ?? 'Redmine';
$includeTheme = $includeTheme ?? true;
$redmineBaseUrl = function_exists('url') ? rtrim(url('/redmine_tic'), '/') : '/redmine_tic';
?>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <?php if (function_exists('csrf_token')): ?>
  <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <title><?= htmlspecialchars((string) $pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <?php
    $faviconSvgVersion = @filemtime(__DIR__ . '/../../assets/favicon.svg') ?: time();
    $faviconIcoVersion = @filemtime(__DIR__ . '/../../assets/favicon.ico') ?: time();
  ?>
  <link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($redmineBaseUrl, ENT_QUOTES, 'UTF-8') ?>/favicon.ico?v=<?= (int)$faviconIcoVersion ?>" data-app-favicon>
  <link rel="shortcut icon" type="image/x-icon" href="<?= htmlspecialchars($redmineBaseUrl, ENT_QUOTES, 'UTF-8') ?>/favicon.ico?v=<?= (int)$faviconIcoVersion ?>" data-app-favicon>
  <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($redmineBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/favicon.svg?v=<?= (int)$faviconSvgVersion ?>" data-app-favicon>
  <script>
    window.__APP_FAVICON_VERSION__ = "<?= (int)$faviconIcoVersion ?>";
    window.__APP_SYNC_FAVICON__ = function () {
      const firstPath = (window.location.pathname.split('/').filter(Boolean)[0] || 'redmine');
      const base = "<?= htmlspecialchars($redmineBaseUrl, ENT_QUOTES, 'UTF-8') ?>";
      const href = base + '/favicon.ico?v=' + (window.__APP_FAVICON_VERSION__ || Date.now());
      document.querySelectorAll('link[rel~="icon"], link[rel="shortcut icon"]').forEach(link => link.remove());
      ['icon', 'shortcut icon'].forEach(rel => {
        const link = document.createElement('link');
        link.rel = rel;
        link.type = 'image/x-icon';
        link.href = href;
        link.setAttribute('data-app-favicon', '1');
        document.head.appendChild(link);
      });
    };
    window.__APP_SYNC_FAVICON__();
    window.addEventListener('pageshow', window.__APP_SYNC_FAVICON__);
  </script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<?php if ($includeTheme): ?>
  <?php $themeVersion = @filemtime(__DIR__ . '/../../assets/theme.css') ?: time(); ?>
  <link href="<?= htmlspecialchars($redmineBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/theme.css?v=<?= (int)$themeVersion ?>" rel="stylesheet">
<?php endif; ?>
  <?php $novaUiPath = function_exists('base_path') ? base_path('public/assets/nova-ui.css') : __DIR__ . '/../../../public/assets/nova-ui.css'; ?>
  <?php $novaUiVersion = @filemtime($novaUiPath) ?: time(); ?>
  <link href="<?= htmlspecialchars(function_exists('asset') ? asset('assets/nova-ui.css') : '/NOVA/public/assets/nova-ui.css', ENT_QUOTES, 'UTF-8') ?>?v=<?= (int)$novaUiVersion ?>" rel="stylesheet">
