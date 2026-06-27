<?php
require_once __DIR__ . '/../../controllers/auth.php';
auth_require_role(['root', 'gestor'], '/redmine-mantencion/login.php');
header('Location: ' . legacy_app_url('views/Configuracion/configuracion.php'));
exit;
