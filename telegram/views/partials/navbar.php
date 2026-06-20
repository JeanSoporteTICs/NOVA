<?php

$h = $h ?? static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$activeNav = $activeNav ?? '';
$baseUrl = function_exists('url') ? rtrim(url('/telegram'), '/') : '/telegram';
$homeUrl = function_exists('url') ? url('/') : '/NOVA/public';
$logoutUrl = function_exists('route') ? route('logout') : '/NOVA/public/logout';
$csrfToken = function_exists('csrf_token') ? csrf_token() : '';
$currentUser = $_SESSION['user'] ?? [];
$navItems = [
  ['key' => 'mantenedor', 'label' => 'Mantenedor', 'href' => $baseUrl, 'icon' => 'bi-sliders'],
];

?>
<nav class="navbar navbar-expand-lg navbar-dark telegram-navbar">
  <div class="container-fluid px-4">
    <a class="navbar-brand d-flex align-items-center gap-3 fw-bold" href="<?= $h($baseUrl) ?>">
      <span class="telegram-brand-mark"><i class="bi bi-telegram"></i></span>
      <span>Telegram</span>
    </a>
    <div class="d-flex align-items-center gap-2 ms-auto telegram-nav-actions">
      <?php include __DIR__ . '/session-control.php'; ?>
      <span class="text-white-50 fw-bold d-none d-md-inline"><i class="bi bi-person-circle"></i> <?= $h($currentUser['nombre'] ?? $currentUser['name'] ?? 'Usuario') ?></span>
      <a class="btn btn-outline-light" href="<?= $h($homeUrl) ?>"><i class="bi bi-house-door"></i>NOVA</a>
      <form method="POST" action="<?= $h($logoutUrl) ?>" class="d-inline m-0">
        <input type="hidden" name="_token" value="<?= $h($csrfToken) ?>">
        <button class="btn btn-outline-light" type="submit"><i class="bi bi-box-arrow-right"></i>Salir</button>
      </form>
    </div>
  </div>
</nav>
<div class="telegram-menu-wrap">
  <ul class="navbar-nav telegram-nav-list me-auto mb-0">
    <?php foreach ($navItems as $item): ?>
      <?php $isActive = $activeNav === $item['key']; ?>
      <li class="nav-item">
        <a class="nav-link telegram-nav-link <?= $isActive ? 'active' : '' ?>" href="<?= $h($item['href']) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
          <i class="bi <?= $h($item['icon']) ?>"></i>
          <span><?= $h($item['label']) ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
</div>

<div class="app-page-loader" id="app-page-loader" aria-hidden="true"></div>
<script>
(function () {
  var loader = document.getElementById('app-page-loader');
  window.appUi = window.appUi || {};
  window.appUi.setLoading = function (on) {
    if (loader) loader.classList.toggle('is-visible', !!on);
  };
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]');
    if (!a || e.defaultPrevented || a.target === '_blank') return;
    try { var u = new URL(a.href, window.location.href); if (u.origin !== window.location.origin) return; } catch (_) { return; }
    window.appUi.setLoading(true);
  });
  document.addEventListener('submit', function (e) {
    if (!e.defaultPrevented) window.appUi.setLoading(true);
  });
  window.addEventListener('pageshow', function () { window.appUi.setLoading(false); });
  window.addEventListener('load', function () { window.appUi.setLoading(false); });
}());
</script>
