<?php

$h = $h ?? static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$activeNav = $activeNav ?? '';
$baseUrl = function_exists('url') ? rtrim(url('/telegram'), '/') : '/telegram';
$homeUrl = function_exists('url') ? url('/') : '/NOVA/public';
$logoutUrl = function_exists('route') ? route('logout') : '/NOVA/public/logout';
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
      <a class="btn btn-outline-light" href="<?= $h($logoutUrl) ?>"><i class="bi bi-box-arrow-right"></i>Salir</a>
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
