<?php

$h = $h ?? fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$novaHomeUrl = function_exists('url') ? url('/') : '/NOVA/public';

?>
<a class="btn btn-light btn-sm" href="<?= $h($novaHomeUrl) ?>">
  <i class="bi bi-house-door"></i>
  <span>NOVA</span>
</a>

