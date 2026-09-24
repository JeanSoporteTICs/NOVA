-- NOVA: estructura solamente. El ledger se importa con nova:database-baseline bootstrap.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=0;
CREATE TABLE `catalogos_modulo` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `modulo_id` bigint(20) unsigned NOT NULL,
  `tipo` varchar(40) NOT NULL,
  `clave_externa` varchar(100) DEFAULT NULL,
  `nombre` varchar(255) NOT NULL,
  `predeterminado` tinyint(1) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_catalogo_modulo_item` (`modulo_id`,`tipo`,`clave_externa`),
  KEY `idx_catalogos_tipo` (`tipo`),
  KEY `idx_catalogos_nombre` (`nombre`),
  CONSTRAINT `fk_catalogos_modulo` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_nova` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `categorias` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `modulo_id` bigint(20) unsigned DEFAULT NULL,
  `nombre` varchar(255) NOT NULL,
  `clave_externa` varchar(120) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `predeterminado` tinyint(1) NOT NULL DEFAULT 0,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `categorias_activo_index` (`activo`),
  KEY `categorias_modulo_id_index` (`modulo_id`),
  CONSTRAINT `categorias_modulo_id_foreign` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_nova` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `configuraciones_modulo` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `modulo_id` bigint(20) unsigned NOT NULL,
  `clave` varchar(120) NOT NULL,
  `valor` text DEFAULT NULL,
  `tipo` varchar(30) NOT NULL DEFAULT 'string',
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_configuracion_modulo_clave` (`modulo_id`,`clave`),
  KEY `idx_configuraciones_modulo_clave` (`clave`),
  CONSTRAINT `fk_configuraciones_modulo` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_nova` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `destinatarios_informes_modulo` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `modulo_id` bigint(20) unsigned NOT NULL,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `recibe_informe` tinyint(1) NOT NULL DEFAULT 0,
  `es_jefatura` tinyint(1) NOT NULL DEFAULT 0,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_destinatario_informe_modulo_usuario` (`modulo_id`,`usuario_id`),
  KEY `destinatarios_informes_modulo_usuario_id_foreign` (`usuario_id`),
  KEY `idx_destinatario_informe` (`modulo_id`,`recibe_informe`),
  KEY `idx_jefatura_informe` (`modulo_id`,`es_jefatura`),
  CONSTRAINT `destinatarios_informes_modulo_modulo_id_foreign` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_nova` (`id`) ON DELETE CASCADE,
  CONSTRAINT `destinatarios_informes_modulo_usuario_id_foreign` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios_nova` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `emach_horarios_usuario` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `dia_semana` tinyint(3) unsigned NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 0,
  `hora_entrada` time DEFAULT NULL,
  `hora_salida` time DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_emach_horario_usuario_dia` (`usuario_id`,`dia_semana`),
  KEY `idx_emach_horario_usuario_activo` (`usuario_id`,`activo`),
  CONSTRAINT `emach_horarios_usuario_usuario_id_foreign` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios_nova` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `emach_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `evento` varchar(120) NOT NULL,
  `usuario_id` varchar(160) DEFAULT NULL,
  `detalle` text DEFAULT NULL,
  `contexto` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`contexto`)),
  `registrado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `emach_log_evento_index` (`evento`),
  KEY `emach_log_usuario_id_index` (`usuario_id`),
  KEY `emach_log_registrado_at_index` (`registrado_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `horas_extra_grupos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint(20) unsigned DEFAULT NULL,
  `fecha` date NOT NULL,
  `hora_inicio` time DEFAULT NULL,
  `hora_fin` time DEFAULT NULL,
  `total_minutos` int(10) unsigned DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_horas_extra_usuario_fecha` (`usuario_id`,`fecha`),
  KEY `idx_horas_extra_fecha` (`fecha`),
  CONSTRAINT `horas_extra_grupos_usuario_id_foreign` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios_nova` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `horas_extra_grupo_reportes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `grupo_id` bigint(20) unsigned NOT NULL,
  `origen` varchar(30) NOT NULL,
  `reporte_id` bigint(20) unsigned NOT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_he_reporte` (`grupo_id`,`origen`,`reporte_id`),
  KEY `idx_he_origen_reporte` (`origen`,`reporte_id`),
  CONSTRAINT `horas_extra_grupo_reportes_grupo_id_foreign` FOREIGN KEY (`grupo_id`) REFERENCES `horas_extra_grupos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `integraciones_usuario` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `tipo` varchar(40) NOT NULL,
  `usuario_externo` varchar(180) DEFAULT NULL,
  `valor_secreto` text DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_integracion_usuario_tipo` (`usuario_id`,`tipo`),
  KEY `idx_integraciones_usuario_externo` (`usuario_externo`),
  CONSTRAINT `fk_integraciones_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios_nova` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mantencion_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `canal` varchar(30) NOT NULL,
  `tipo` varchar(80) DEFAULT NULL,
  `mensaje_id` varchar(160) DEFAULT NULL,
  `detalle` text DEFAULT NULL,
  `contexto` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`contexto`)),
  `registrado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `redmine_mantencion_eventos_canal_index` (`canal`),
  KEY `redmine_mantencion_eventos_tipo_index` (`tipo`),
  KEY `redmine_mantencion_eventos_mensaje_id_index` (`mensaje_id`),
  KEY `redmine_mantencion_eventos_registrado_at_index` (`registrado_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mantencion_permisos_rol` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rol` varchar(40) NOT NULL,
  `permiso` varchar(80) NOT NULL,
  `valor` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mpr_rol_permiso` (`rol`,`permiso`),
  KEY `mantencion_permisos_rol_rol_index` (`rol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mantencion_permisos_usuario` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `permiso` varchar(80) NOT NULL,
  `valor` varchar(255) NOT NULL DEFAULT '',
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mpu_usuario_permiso` (`usuario_id`,`permiso`),
  CONSTRAINT `mantencion_permisos_usuario_usuario_id_foreign` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios_nova` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `modulos_nova` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `clave_modulo` varchar(80) NOT NULL,
  `nombre` varchar(160) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `icono` varchar(80) DEFAULT NULL,
  `tipo` varchar(40) NOT NULL DEFAULT 'native',
  `ruta` varchar(500) DEFAULT NULL,
  `entrada` varchar(255) DEFAULT NULL,
  `habilitado` tinyint(1) NOT NULL DEFAULT 1,
  `en_mantencion` tinyint(1) NOT NULL DEFAULT 0,
  `orden` int(11) NOT NULL DEFAULT 100,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_modulos_nova_clave` (`clave_modulo`),
  KEY `idx_modulos_nova_orden` (`orden`),
  KEY `idx_modulos_nova_tipo` (`tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `modulo_opciones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `modulo_id` bigint(20) unsigned NOT NULL,
  `tipo` varchar(40) NOT NULL,
  `id_externo` varchar(100) DEFAULT NULL,
  `nombre` varchar(255) NOT NULL,
  `predeterminado` tinyint(1) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `orden` int(10) unsigned NOT NULL DEFAULT 100,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_modulo_opcion_tipo_ext` (`modulo_id`,`tipo`,`id_externo`),
  KEY `idx_modulo_opciones_tipo` (`tipo`),
  CONSTRAINT `modulo_opciones_modulo_id_foreign` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_nova` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `monitoreo_alerta_usuarios` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `recibir_caidas` tinyint(1) NOT NULL DEFAULT 1,
  `recibir_recuperaciones` tinyint(1) NOT NULL DEFAULT 1,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_monitor_alerta_usuario` (`usuario_id`),
  CONSTRAINT `monitoreo_alerta_usuarios_usuario_id_foreign` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios_nova` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `monitoreo_servidores` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(160) NOT NULL,
  `host` varchar(255) NOT NULL,
  `tipo` varchar(20) NOT NULL DEFAULT 'tcp',
  `puerto` smallint(5) unsigned DEFAULT NULL,
  `ruta` varchar(500) DEFAULT NULL,
  `verificar_ssl` tinyint(1) NOT NULL DEFAULT 0,
  `intervalo_segundos` int(10) unsigned NOT NULL DEFAULT 60,
  `timeout_segundos` smallint(5) unsigned NOT NULL DEFAULT 5,
  `fallos_para_alertar` smallint(5) unsigned NOT NULL DEFAULT 3,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `mantenimiento_desde` datetime DEFAULT NULL,
  `mantenimiento_hasta` datetime DEFAULT NULL,
  `mantenimiento_motivo` varchar(255) DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'pendiente',
  `fallos_consecutivos` smallint(5) unsigned NOT NULL DEFAULT 0,
  `latencia_ms` int(10) unsigned DEFAULT NULL,
  `ultimo_error` text DEFAULT NULL,
  `ultimo_chequeo_at` datetime DEFAULT NULL,
  `ultima_respuesta_at` datetime DEFAULT NULL,
  `caido_desde` datetime DEFAULT NULL,
  `alertado_caida_at` datetime DEFAULT NULL,
  `creado_por` bigint(20) unsigned DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `monitoreo_servidores_creado_por_foreign` (`creado_por`),
  KEY `monitoreo_servidores_tipo_index` (`tipo`),
  KEY `monitoreo_servidores_activo_index` (`activo`),
  KEY `monitoreo_servidores_estado_index` (`estado`),
  KEY `monitoreo_servidores_ultimo_chequeo_at_index` (`ultimo_chequeo_at`),
  KEY `monitoreo_servidores_mantenimiento_hasta_index` (`mantenimiento_hasta`),
  CONSTRAINT `monitoreo_servidores_creado_por_foreign` FOREIGN KEY (`creado_por`) REFERENCES `usuarios_nova` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `monitoreo_servidor_eventos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `servidor_id` bigint(20) unsigned NOT NULL,
  `tipo` varchar(30) NOT NULL,
  `estado_anterior` varchar(20) DEFAULT NULL,
  `estado_nuevo` varchar(20) NOT NULL,
  `detalle` text DEFAULT NULL,
  `latencia_ms` int(10) unsigned DEFAULT NULL,
  `ocurrido_at` datetime NOT NULL,
  `notificado_at` datetime DEFAULT NULL,
  `destinatarios_notificados` smallint(5) unsigned NOT NULL DEFAULT 0,
  `fallos_notificacion` smallint(5) unsigned NOT NULL DEFAULT 0,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_monitor_evento_servidor_fecha` (`servidor_id`,`ocurrido_at`),
  KEY `monitoreo_servidor_eventos_tipo_index` (`tipo`),
  KEY `monitoreo_servidor_eventos_ocurrido_at_index` (`ocurrido_at`),
  CONSTRAINT `monitoreo_servidor_eventos_servidor_id_foreign` FOREIGN KEY (`servidor_id`) REFERENCES `monitoreo_servidores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `monitoreo_workers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `instancia` varchar(160) NOT NULL,
  `ultimo_ciclo_at` datetime DEFAULT NULL,
  `servidores_comprobados` int(10) unsigned NOT NULL DEFAULT 0,
  `ultimo_error` text DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `monitoreo_workers_instancia_unique` (`instancia`),
  KEY `monitoreo_workers_ultimo_ciclo_at_index` (`ultimo_ciclo_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `nova_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event` varchar(80) NOT NULL,
  `message` varchar(500) NOT NULL,
  `user_id` varchar(160) NOT NULL DEFAULT '',
  `user_name` varchar(255) NOT NULL DEFAULT '',
  `ip` varchar(45) NOT NULL DEFAULT '',
  `contexto` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`contexto`)),
  `registrado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `nova_audit_logs_event_index` (`event`),
  KEY `nova_audit_logs_registrado_at_index` (`registrado_at`),
  KEY `idx_audit_user_date` (`user_id`,`registrado_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `nova_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `clave` varchar(80) NOT NULL,
  `valor` text DEFAULT NULL,
  `tipo` varchar(20) NOT NULL DEFAULT 'string',
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `nova_settings_clave_unique` (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `permisos_usuario_modulo` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `modulo_id` bigint(20) unsigned NOT NULL,
  `permitido` tinyint(1) NOT NULL DEFAULT 0,
  `rol_modulo` varchar(40) DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permiso_usuario_modulo` (`usuario_id`,`modulo_id`),
  KEY `fk_permisos_modulo` (`modulo_id`),
  CONSTRAINT `fk_permisos_modulo` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_nova` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_permisos_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios_nova` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `redmine_mantencion_nextcloud_historial_lotes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `numero_lote` bigint(20) unsigned DEFAULT NULL,
  `solicitante_nombre` varchar(200) DEFAULT NULL,
  `solicitante_rut` varchar(20) DEFAULT NULL,
  `solicitante_correo` varchar(190) DEFAULT NULL,
  `created_at_cl` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rm_nextcloud_lotes_numero_unique` (`numero_lote`),
  KEY `redmine_mantencion_nextcloud_historial_lotes_created_at_cl_index` (`created_at_cl`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `redmine_mantencion_nextcloud_historial_usuarios` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lote_id` bigint(20) unsigned NOT NULL,
  `tipo` varchar(20) NOT NULL,
  `userid` varchar(255) DEFAULT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `grupo` varchar(255) DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `redmine_mantencion_nextcloud_historial_usuarios_lote_id_foreign` (`lote_id`),
  KEY `redmine_mantencion_nextcloud_historial_usuarios_tipo_index` (`tipo`),
  CONSTRAINT `redmine_mantencion_nextcloud_historial_usuarios_lote_id_foreign` FOREIGN KEY (`lote_id`) REFERENCES `redmine_mantencion_nextcloud_historial_lotes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `redmine_mantencion_reportes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `modulo_id` bigint(20) unsigned DEFAULT NULL,
  `fuente` varchar(40) DEFAULT NULL,
  `fuente_id` varchar(160) DEFAULT NULL,
  `id_core` varchar(160) DEFAULT NULL,
  `proyecto` varchar(180) DEFAULT NULL,
  `project_id` varchar(80) DEFAULT NULL,
  `tipo` varchar(120) DEFAULT NULL,
  `tipo_id` varchar(80) DEFAULT NULL,
  `asunto` text DEFAULT NULL,
  `descripcion` longtext DEFAULT NULL,
  `estado` varchar(80) DEFAULT NULL,
  `estado_redmine` varchar(120) DEFAULT NULL,
  `estado_id` varchar(80) DEFAULT NULL,
  `prioridad` varchar(80) DEFAULT NULL,
  `priority_id` varchar(80) DEFAULT NULL,
  `id_redmine_asignado` varchar(80) DEFAULT NULL,
  `asignado_nombre` varchar(180) DEFAULT NULL,
  `categoria_id` bigint(20) unsigned DEFAULT NULL,
  `solicitante` varchar(255) DEFAULT NULL,
  `anexo` varchar(120) DEFAULT NULL,
  `unidad_texto` varchar(255) DEFAULT NULL,
  `fecha_inicio` date DEFAULT NULL,
  `fecha_fin` date DEFAULT NULL,
  `fecha_reporte` date DEFAULT NULL,
  `hora_reporte` time DEFAULT NULL,
  `tiempo_estimado` decimal(10,2) DEFAULT NULL,
  `correo` varchar(255) DEFAULT NULL,
  `hora_extra` tinyint(1) NOT NULL DEFAULT 0,
  `numero_ticket_redmine` int(10) unsigned DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `redmine_mantencion_reportes_modulo_id_foreign` (`modulo_id`),
  KEY `redmine_mantencion_reportes_categoria_id_foreign` (`categoria_id`),
  KEY `redmine_mantencion_reportes_id_core_index` (`id_core`),
  KEY `redmine_mantencion_reportes_estado_index` (`estado`),
  KEY `redmine_mantencion_reportes_id_redmine_asignado_index` (`id_redmine_asignado`),
  KEY `redmine_mantencion_reportes_fecha_inicio_index` (`fecha_inicio`),
  KEY `redmine_mantencion_reportes_hora_extra_index` (`hora_extra`),
  KEY `redmine_mantencion_reportes_numero_ticket_redmine_index` (`numero_ticket_redmine`),
  KEY `idx_rm_reportes_fuente_id` (`fuente`,`fuente_id`),
  CONSTRAINT `redmine_mantencion_reportes_categoria_id_foreign` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE SET NULL,
  CONSTRAINT `redmine_mantencion_reportes_modulo_id_foreign` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_nova` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `redmine_tic_perfiles_usuario` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `rol` varchar(40) NOT NULL DEFAULT 'usuario',
  `estado_usuario` varchar(40) NOT NULL DEFAULT 'activo',
  `redmine_membership_id` int(10) unsigned DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_redmine_tic_perfil_usuario` (`usuario_id`),
  KEY `redmine_tic_perfiles_usuario_rol_index` (`rol`),
  KEY `redmine_tic_perfiles_usuario_estado_usuario_index` (`estado_usuario`),
  CONSTRAINT `redmine_tic_perfiles_usuario_usuario_id_foreign` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios_nova` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `redmine_tic_permisos_catalogo` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `clave` varchar(60) NOT NULL,
  `tipo` enum('bool','scope','scope_or_empty') NOT NULL DEFAULT 'bool',
  `descripcion` varchar(200) NOT NULL DEFAULT '',
  `orden` tinyint(3) unsigned NOT NULL DEFAULT 100,
  PRIMARY KEY (`id`),
  UNIQUE KEY `redmine_tic_permisos_catalogo_clave_unique` (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `redmine_tic_permisos_rol` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `modulo_id` bigint(20) unsigned NOT NULL,
  `rol` varchar(40) NOT NULL,
  `clave` varchar(60) NOT NULL,
  `valor` varchar(20) NOT NULL DEFAULT 'no',
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permiso_rol` (`modulo_id`,`rol`,`clave`),
  KEY `idx_pr_rol` (`modulo_id`,`rol`),
  CONSTRAINT `redmine_tic_permisos_rol_modulo_id_foreign` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_nova` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `redmine_tic_permisos_usuario` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `perfil_id` bigint(20) unsigned NOT NULL,
  `clave` varchar(60) NOT NULL,
  `valor` varchar(20) NOT NULL DEFAULT 'no',
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permiso_usuario` (`perfil_id`,`clave`),
  KEY `idx_pu_clave` (`clave`),
  CONSTRAINT `redmine_tic_permisos_usuario_perfil_id_foreign` FOREIGN KEY (`perfil_id`) REFERENCES `redmine_tic_perfiles_usuario` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `redmine_tic_reportes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `modulo_id` bigint(20) unsigned NOT NULL,
  `redmine_id` int(10) unsigned DEFAULT NULL,
  `estado` varchar(20) NOT NULL DEFAULT 'pendiente',
  `estado_redmine` varchar(40) DEFAULT NULL,
  `tipo` varchar(40) DEFAULT NULL,
  `prioridad` varchar(20) DEFAULT NULL,
  `categoria_catalogo_id` bigint(20) unsigned DEFAULT NULL,
  `unidad_catalogo_id` bigint(20) unsigned DEFAULT NULL,
  `unidad_texto` varchar(180) DEFAULT NULL,
  `unidad_solicitante_catalogo_id` bigint(20) unsigned DEFAULT NULL,
  `solicitante` varchar(255) DEFAULT NULL,
  `asunto` text DEFAULT NULL,
  `descripcion` longtext DEFAULT NULL,
  `fecha` date DEFAULT NULL,
  `hora` time DEFAULT NULL,
  `fecha_inicio` date DEFAULT NULL,
  `fecha_fin` date DEFAULT NULL,
  `chat_id_telegram` varchar(120) DEFAULT NULL,
  `mensaje` text DEFAULT NULL,
  `asignado_a` varchar(80) DEFAULT NULL,
  `hora_extra` tinyint(1) NOT NULL DEFAULT 0,
  `tiempo_estimado` decimal(10,2) DEFAULT NULL,
  `origen` varchar(40) DEFAULT NULL,
  `procesado_at` datetime DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_reportes_modulo_estado` (`modulo_id`,`estado`),
  KEY `idx_reportes_redmine_id` (`redmine_id`),
  KEY `idx_reportes_fecha` (`fecha`),
  KEY `idx_reportes_origen` (`origen`),
  KEY `idx_reportes_asignado` (`asignado_a`),
  KEY `idx_reportes_categoria` (`categoria_catalogo_id`),
  KEY `idx_reportes_unidad` (`unidad_catalogo_id`),
  KEY `idx_reportes_unidad_solicitante` (`unidad_solicitante_catalogo_id`),
  KEY `idx_reportes_modulo_asignado_estado` (`modulo_id`,`asignado_a`,`estado`),
  KEY `redmine_tic_reportes_fecha_inicio_index` (`fecha_inicio`),
  KEY `redmine_tic_reportes_fecha_fin_index` (`fecha_fin`),
  KEY `idx_reportes_modulo_estado_fecha` (`modulo_id`,`estado`,`fecha`),
  CONSTRAINT `fk_reportes_asignado` FOREIGN KEY (`asignado_a`) REFERENCES `usuarios_nova` (`redmine_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reportes_categoria` FOREIGN KEY (`categoria_catalogo_id`) REFERENCES `catalogos_modulo` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reportes_modulo` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_nova` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reportes_unidad` FOREIGN KEY (`unidad_catalogo_id`) REFERENCES `catalogos_modulo` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reportes_unidad_solicitante` FOREIGN KEY (`unidad_solicitante_catalogo_id`) REFERENCES `catalogos_modulo` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `telegram_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `evento` varchar(120) NOT NULL,
  `usuario_id` varchar(160) DEFAULT NULL,
  `detalle` text DEFAULT NULL,
  `contexto` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`contexto`)),
  `registrado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `telegram_log_evento_index` (`evento`),
  KEY `telegram_log_usuario_id_index` (`usuario_id`),
  KEY `telegram_log_registrado_at_index` (`registrado_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tic_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `modulo_id` bigint(20) unsigned NOT NULL,
  `evento` varchar(120) NOT NULL,
  `contexto` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`contexto`)),
  `linea` text DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_redmine_tic_activity_modulo_fecha` (`modulo_id`,`creado_at`),
  KEY `redmine_tic_activity_logs_evento_index` (`evento`),
  CONSTRAINT `redmine_tic_activity_logs_modulo_id_foreign` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_nova` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `unidades` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `modulo_id` bigint(20) unsigned DEFAULT NULL,
  `nombre` varchar(255) NOT NULL,
  `clave_externa` varchar(120) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `predeterminado` tinyint(1) NOT NULL DEFAULT 0,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `unidades_activo_index` (`activo`),
  KEY `unidades_modulo_id_index` (`modulo_id`),
  CONSTRAINT `unidades_modulo_id_foreign` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_nova` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `usuarios_nova` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `usuario` varchar(80) NOT NULL,
  `rut` varchar(20) DEFAULT NULL,
  `redmine_id` varchar(80) DEFAULT NULL,
  `nombre` varchar(120) NOT NULL,
  `apellido` varchar(160) NOT NULL,
  `rol` varchar(40) NOT NULL DEFAULT 'usuario',
  `estado` varchar(40) NOT NULL DEFAULT 'activo',
  `password` varchar(255) NOT NULL,
  `usuario_core` varchar(120) DEFAULT NULL,
  `telegram_id_chat` varchar(120) DEFAULT NULL,
  `ultimo_login_at` datetime DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_usuarios_nova_uuid` (`uuid`),
  UNIQUE KEY `uq_usuarios_nova_usuario` (`usuario`),
  UNIQUE KEY `uq_usuarios_nova_rut` (`rut`),
  UNIQUE KEY `uq_usuarios_nova_redmine_id` (`redmine_id`),
  KEY `idx_usuarios_nova_estado` (`estado`),
  KEY `idx_usuarios_nova_rol` (`rol`),
  KEY `idx_usuarios_nova_nombre` (`nombre`,`apellido`),
  KEY `idx_usuarios_nova_rol_estado` (`rol`,`estado`),
  KEY `usuarios_nova_telegram_id_chat_index` (`telegram_id_chat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
SET NAMES utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;
SET sql_mode='NO_AUTO_VALUE_ON_ZERO';
DELIMITER //
CREATE TRIGGER `trg_catalogos_modulo_actualizado` BEFORE UPDATE ON `catalogos_modulo` FOR EACH ROW BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//
DELIMITER ;
SET NAMES utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;
SET sql_mode='NO_AUTO_VALUE_ON_ZERO';
DELIMITER //
CREATE TRIGGER `trg_configuraciones_modulo_actualizado` BEFORE UPDATE ON `configuraciones_modulo` FOR EACH ROW BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//
DELIMITER ;
SET NAMES utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;
SET sql_mode='NO_AUTO_VALUE_ON_ZERO';
DELIMITER //
CREATE TRIGGER `trg_integraciones_usuario_actualizado` BEFORE UPDATE ON `integraciones_usuario` FOR EACH ROW BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//
DELIMITER ;
SET NAMES utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;
SET sql_mode='NO_AUTO_VALUE_ON_ZERO';
DELIMITER //
CREATE TRIGGER `trg_modulos_nova_actualizado` BEFORE UPDATE ON `modulos_nova` FOR EACH ROW BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//
DELIMITER ;
SET NAMES utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;
SET sql_mode='NO_AUTO_VALUE_ON_ZERO';
DELIMITER //
CREATE TRIGGER `trg_permisos_usuario_modulo_actualizado` BEFORE UPDATE ON `permisos_usuario_modulo` FOR EACH ROW BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//
DELIMITER ;
SET NAMES utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;
SET sql_mode='NO_AUTO_VALUE_ON_ZERO';
DELIMITER //
CREATE TRIGGER `trg_reportes_redmine_actualizado` BEFORE UPDATE ON `redmine_tic_reportes` FOR EACH ROW BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//
DELIMITER ;
SET NAMES utf8mb4 COLLATE utf8mb4_uca1400_ai_ci;
SET sql_mode='NO_AUTO_VALUE_ON_ZERO';
DELIMITER //
CREATE TRIGGER `trg_usuarios_nova_actualizado` BEFORE UPDATE ON `usuarios_nova` FOR EACH ROW BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//
DELIMITER ;
