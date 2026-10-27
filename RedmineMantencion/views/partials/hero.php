<?php
// Encabezado de página reutilizable (NOVA v2: título, subtítulo y etiquetas).
$esc = isset($h) ? $h : fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$icon = $heroIcon ?? 'bi-broadcast-pin';
$title = $heroTitle ?? '';
$subtitle = $heroSubtitle ?? '';
?>
<section class="nova-v2-head mb-3">
  <div>
    <h1 class="nova-v2-title"><i class="bi <?= $esc($icon) ?>" aria-hidden="true"></i> <?= $esc($title) ?></h1>
    <?php if ($subtitle): ?><p class="nova-v2-subtitle"><?= $esc($subtitle) ?></p><?php endif; ?>
  </div>
  <?php if (!empty($heroExtras)): ?>
    <div class="nova-v2-head__meta">
      <?= $heroExtras ?>
    </div>
  <?php endif; ?>
</section>
