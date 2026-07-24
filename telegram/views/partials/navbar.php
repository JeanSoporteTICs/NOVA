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
    <button class="nova-sidebar-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#novaSidebar" aria-controls="novaSidebar" aria-label="Abrir men&uacute; lateral">
      <i class="bi bi-list"></i>
    </button>
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
<div class="nova-layout">
  <aside class="nova-sidebar offcanvas-lg offcanvas-start" id="novaSidebar" tabindex="-1" aria-labelledby="novaSidebarLabel">
    <div class="offcanvas-header d-lg-none border-bottom py-3">
      <strong class="offcanvas-title fw-bold" id="novaSidebarLabel">Telegram</strong>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
    </div>
    <nav class="nova-sidebar-body" aria-label="Navegaci&oacute;n Telegram">
      <?php foreach ($navItems as $item): ?>
        <?php $isActive = $activeNav === $item['key']; ?>
        <a class="nova-sidebar-link <?= $isActive ? 'active' : '' ?>" href="<?= $h($item['href']) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
          <i class="bi <?= $h($item['icon']) ?> nova-sidebar-icon"></i>
          <span><?= $h($item['label']) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
  </aside>

<div class="app-page-loader" id="app-page-loader" aria-hidden="true"></div>
