<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_login('/redmine-mantencion/login.php');
if (!auth_can('integraciones_nextcloud')) { http_response_code(403); exit('No tienes permiso para administrar Nextcloud.'); }
header('Location: ' . legacy_app_url('views/Configuracion/configuracion.php'));
exit;
