<?php
$nextcloudUsersSection = $nextcloudUsersSection ?? 'create';
$nextcloudCreateUsersUrl = function_exists('url')
    ? url('/redmine-mantencion/app/integraciones-nextcloud-usuarios')
    : '/redmine-mantencion/app/integraciones-nextcloud-usuarios';
$nextcloudManageUsersUrl = function_exists('route')
    ? route('redmine.mantencion.nextcloud-users.manage')
    : $nextcloudCreateUsersUrl . '/administrar';
?>
<nav class="nextcloud-users-switcher mb-3" aria-label="Administración de usuarios Nextcloud">
  <span class="nextcloud-users-switcher-mark" aria-hidden="true"><i class="bi bi-cloud-fill"></i></span>
  <a href="<?= htmlspecialchars($nextcloudCreateUsersUrl, ENT_QUOTES, 'UTF-8') ?>"
     class="nextcloud-users-switcher-link <?= $nextcloudUsersSection === 'create' ? 'is-active' : '' ?>"
     <?= $nextcloudUsersSection === 'create' ? 'aria-current="page"' : '' ?>>
    <i class="bi bi-person-plus"></i>
    <span><strong>Crear usuarios</strong><small>Importación por lotes</small></span>
  </a>
  <a href="<?= htmlspecialchars($nextcloudManageUsersUrl, ENT_QUOTES, 'UTF-8') ?>"
     class="nextcloud-users-switcher-link <?= $nextcloudUsersSection === 'manage' ? 'is-active' : '' ?>"
     <?= $nextcloudUsersSection === 'manage' ? 'aria-current="page"' : '' ?>>
    <i class="bi bi-person-gear"></i>
    <span><strong>Administrar usuarios</strong><small>Datos y contraseñas</small></span>
  </a>
</nav>
