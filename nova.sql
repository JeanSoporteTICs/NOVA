-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Servidor: mariadb
-- Tiempo de generación: 11-06-2026 a las 20:58:29
-- Versión del servidor: 12.3.2-MariaDB-ubu2404
-- Versión de PHP: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `nova`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `alias_comando_telegram`
--

CREATE TABLE `alias_comando_telegram` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `comando_id` bigint(20) UNSIGNED NOT NULL,
  `alias` varchar(80) NOT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `alias_comando_telegram`
--

INSERT INTO `alias_comando_telegram` (`id`, `comando_id`, `alias`, `creado_at`, `actualizado_at`) VALUES
(1, 1, '/start', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(2, 1, '/help', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(3, 2, '/status', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(4, 4, '/reporte', '2026-06-06 20:19:17', '2026-06-06 20:19:17');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `auditoria_nova`
--

CREATE TABLE `auditoria_nova` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `evento` varchar(120) NOT NULL,
  `mensaje` text NOT NULL,
  `usuario_id` bigint(20) UNSIGNED DEFAULT NULL,
  `ip` varchar(80) DEFAULT NULL,
  `contexto` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`contexto`)),
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `comandos_telegram`
--

CREATE TABLE `comandos_telegram` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `clave_comando` varchar(80) NOT NULL,
  `comando` varchar(80) NOT NULL,
  `modulo_id` bigint(20) UNSIGNED DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `ejemplo_entrada` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `orden` int(11) NOT NULL DEFAULT 100,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `comandos_telegram`
--

INSERT INTO `comandos_telegram` (`id`, `clave_comando`, `comando`, `modulo_id`, `descripcion`, `ejemplo_entrada`, `activo`, `orden`, `creado_at`, `actualizado_at`) VALUES
(1, 'help', '/ayuda', 4, 'Muestra los comandos disponibles para el usuario.', 'Sin parametros.', 1, 10, '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(2, 'status', '/estado', 4, 'Confirma que el listener Telegram esta activo.', 'Sin parametros.', 1, 20, '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(3, 'emach', '/emach', 3, 'Consulta la ultima marcacion EMACH del usuario asociado al Chat ID.', 'Sin parametros.', 1, 30, '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(4, 'tic', '/tic', 1, 'Crea un reporte TIC pendiente desde Telegram.', '/tic problema, unidad, solicitante', 1, 40, '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(5, 'test', '/test', 4, 'Devuelve una respuesta simple para probar el bot.', 'Sin parametros.', 1, 50, '2026-06-06 20:19:17', '2026-06-06 20:19:17');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `configuraciones_nova`
--

CREATE TABLE `configuraciones_nova` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `clave` varchar(120) NOT NULL,
  `valor` text DEFAULT NULL,
  `tipo` varchar(30) NOT NULL DEFAULT 'string',
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `configuraciones_nova`
--

INSERT INTO `configuraciones_nova` (`id`, `clave`, `valor`, `tipo`, `creado_at`, `actualizado_at`) VALUES
(1, 'session_timeout', '3600', 'int', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(2, 'notification_enabled', '0', 'bool', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(3, 'health_warning_threshold', '1', 'int', '2026-06-06 20:19:17', '2026-06-06 20:19:17');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `integraciones_usuario`
--

CREATE TABLE `integraciones_usuario` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `usuario_id` bigint(20) UNSIGNED NOT NULL,
  `tipo` varchar(40) NOT NULL,
  `usuario_externo` varchar(180) DEFAULT NULL,
  `valor_secreto` text DEFAULT NULL,
  `chat_id` varchar(120) DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `mantenciones_modulo`
--

CREATE TABLE `mantenciones_modulo` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `modulo_id` bigint(20) UNSIGNED NOT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT 0,
  `hasta` datetime DEFAULT NULL,
  `motivo` text DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `mantenciones_modulo`
--

INSERT INTO `mantenciones_modulo` (`id`, `modulo_id`, `activa`, `hasta`, `motivo`, `creado_at`, `actualizado_at`) VALUES
(1, 1, 0, NULL, NULL, '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(2, 2, 0, NULL, NULL, '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(3, 3, 0, NULL, NULL, '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(4, 4, 0, NULL, NULL, '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(5, 5, 0, NULL, NULL, '2026-06-06 20:19:17', '2026-06-06 20:19:17');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `mensajes_telegram`
--

CREATE TABLE `mensajes_telegram` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `clave_mensaje` varchar(100) NOT NULL,
  `etiqueta` varchar(160) NOT NULL,
  `cuerpo` text NOT NULL,
  `descripcion` text DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `mensajes_telegram`
--

INSERT INTO `mensajes_telegram` (`id`, `clave_mensaje`, `etiqueta`, `cuerpo`, `descripcion`, `creado_at`, `actualizado_at`) VALUES
(1, 'help_header', 'Encabezado de ayuda', 'Comandos Telegram NOVA:', 'Primera linea cuando alguien pide ayuda.', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(2, 'status', 'Respuesta de /estado', 'Servicio Telegram NOVA activo\nFecha: {fecha}', 'Confirma que el bot esta activo.', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(3, 'test', 'Respuesta de /test', 'Mensaje de prueba desde Telegram NOVA: {fecha}', 'Mensaje simple de prueba.', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(4, 'tic_success', 'Reporte TIC creado', 'Reporte TIC recibido\nAsunto: {asunto}\nCategoria: {categoria}\nUnidad: {unidad}\nEstado: pendiente', 'Confirmacion cuando se crea un reporte TIC.', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(5, 'tic_unavailable', 'TIC no disponible', 'No pude cargar Redmine TIC desde el listener Telegram.', 'Error al cargar TIC.', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(6, 'tic_error', 'Error TIC', 'No pude crear el reporte TIC: {error}', 'Error creando reporte TIC.', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(7, 'emach_success', 'Marcacion EMACH', 'Ultima marcacion EMACH\nFecha: {fecha}\nHora: {hora}\nTipo: {tipo}\nReloj: {reloj}', 'Respuesta con ultima marcacion.', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(8, 'emach_missing_credentials', 'EMACH sin credenciales', 'No tienes credenciales EMACH guardadas en NOVA.', 'Faltan credenciales EMACH.', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(9, 'emach_empty', 'EMACH sin marcaciones', 'No encontre marcaciones EMACH para el mes actual.', 'Sin marcaciones.', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(10, 'emach_error', 'Error EMACH', 'No pude consultar EMACH: {error}', 'Error consultando EMACH.', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(11, 'disabled', 'Comando desactivado', 'Comando desactivado.', 'Comando apagado.', '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(12, 'unknown', 'Comando desconocido', 'No entendi ese comando. Usa /ayuda.', 'Comando no reconocido.', '2026-06-06 20:19:17', '2026-06-06 20:19:17');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `modulos_nova`
--

CREATE TABLE `modulos_nova` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `clave_modulo` varchar(80) NOT NULL,
  `nombre` varchar(160) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `icono` varchar(80) DEFAULT NULL,
  `tipo` varchar(40) NOT NULL DEFAULT 'native',
  `ruta` varchar(500) DEFAULT NULL,
  `entrada` varchar(255) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `orden` int(11) NOT NULL DEFAULT 100,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `modulos_nova`
--

INSERT INTO `modulos_nova` (`id`, `clave_modulo`, `nombre`, `descripcion`, `icono`, `tipo`, `ruta`, `entrada`, `activo`, `orden`, `creado_at`, `actualizado_at`) VALUES
(1, 'redmine_tic', 'Redmine TICS', 'Captura, procesa y envia reportes del proyecto Redmine TICS.', 'bi-kanban', 'native', 'redmine_tic', 'laravel:redmine.native.dashboard', 1, 10, '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(2, 'redmine-mantencion', 'Redmine Mantencion', 'Gestiona reportes, pendientes, procedimientos e integraciones de mantencion.', 'bi-tools', 'native', 'redmine-mantencion', 'laravel:redmine.mantencion.dashboard', 1, 20, '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(3, 'emach', 'EMACH', 'Consulta marcaciones EMACH.', 'bi-heart-pulse', 'legacy', 'emach', 'index.php', 1, 30, '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(4, 'telegram', 'Telegram', 'Centraliza mensajes y comandos de Telegram para NOVA.', 'bi-telegram', 'native', 'telegram', 'laravel:telegram.index', 1, 40, '2026-06-06 20:19:17', '2026-06-06 20:19:17'),
(5, 'administracion', 'Administracion', 'Configuracion global y usuarios de NOVA.', 'bi-person-gear', 'native', 'storage/app/nova', 'laravel:administracion.index', 1, 50, '2026-06-06 20:19:17', '2026-06-06 20:19:17');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `permisos_usuario_modulo`
--

CREATE TABLE `permisos_usuario_modulo` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `usuario_id` bigint(20) UNSIGNED NOT NULL,
  `modulo_id` bigint(20) UNSIGNED NOT NULL,
  `permitido` tinyint(1) NOT NULL DEFAULT 0,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios_nova`
--

CREATE TABLE `usuarios_nova` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` char(36) NOT NULL,
  `usuario` varchar(80) NOT NULL,
  `rut` varchar(20) DEFAULT NULL,
  `redmine_id` varchar(80) DEFAULT NULL,
  `nombre` varchar(120) NOT NULL,
  `apellido` varchar(160) NOT NULL,
  `email` varchar(180) DEFAULT NULL,
  `rol` varchar(40) NOT NULL DEFAULT 'usuario',
  `estado` varchar(40) NOT NULL DEFAULT 'activo',
  `password` varchar(255) NOT NULL,
  `usuario_core` varchar(120) DEFAULT NULL,
  `ultimo_login_at` datetime DEFAULT NULL,
  `creado_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `actualizado_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Volcado de datos para la tabla `usuarios_nova`
--

INSERT INTO `usuarios_nova` (`id`, `uuid`, `usuario`, `rut`, `redmine_id`, `nombre`, `apellido`, `email`, `rol`, `estado`, `password`, `usuario_core`, `ultimo_login_at`, `creado_at`, `actualizado_at`) VALUES
(1, '49f047bc-61e5-11f1-9a41-f6e8c8121a9b', 'admin', NULL, '42', 'Administrador', 'NOVA', NULL, 'admin', 'activo', '$2y$10$dR0QWsbfO9p5x8bHIzTgTu2WXwQaKbM4nJg0RJ0lArdPBi3swsqT6', NULL, NULL, '2026-06-06 20:21:23', '2026-06-06 20:21:23');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `alias_comando_telegram`
--
ALTER TABLE `alias_comando_telegram`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_alias_comando_telegram` (`alias`),
  ADD KEY `idx_alias_comando_id` (`comando_id`);

--
-- Indices de la tabla `auditoria_nova`
--
ALTER TABLE `auditoria_nova`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_auditoria_evento` (`evento`),
  ADD KEY `idx_auditoria_usuario` (`usuario_id`),
  ADD KEY `idx_auditoria_creado` (`creado_at`);

--
-- Indices de la tabla `comandos_telegram`
--
ALTER TABLE `comandos_telegram`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_comandos_telegram_clave` (`clave_comando`),
  ADD UNIQUE KEY `uq_comandos_telegram_comando` (`comando`),
  ADD KEY `idx_comandos_telegram_activo` (`activo`),
  ADD KEY `idx_comandos_telegram_modulo` (`modulo_id`);

--
-- Indices de la tabla `configuraciones_nova`
--
ALTER TABLE `configuraciones_nova`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_configuraciones_nova_clave` (`clave`);

--
-- Indices de la tabla `integraciones_usuario`
--
ALTER TABLE `integraciones_usuario`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_integracion_usuario_tipo` (`usuario_id`,`tipo`),
  ADD KEY `idx_integraciones_tipo` (`tipo`),
  ADD KEY `idx_integraciones_chat_id` (`chat_id`),
  ADD KEY `idx_integraciones_usuario_externo` (`usuario_externo`);

--
-- Indices de la tabla `mantenciones_modulo`
--
ALTER TABLE `mantenciones_modulo`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mantencion_modulo` (`modulo_id`);

--
-- Indices de la tabla `mensajes_telegram`
--
ALTER TABLE `mensajes_telegram`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_mensajes_telegram_clave` (`clave_mensaje`),
  ADD KEY `idx_mensajes_telegram_etiqueta` (`etiqueta`);

--
-- Indices de la tabla `modulos_nova`
--
ALTER TABLE `modulos_nova`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_modulos_nova_clave` (`clave_modulo`),
  ADD KEY `idx_modulos_nova_activo` (`activo`),
  ADD KEY `idx_modulos_nova_orden` (`orden`),
  ADD KEY `idx_modulos_nova_tipo` (`tipo`);

--
-- Indices de la tabla `permisos_usuario_modulo`
--
ALTER TABLE `permisos_usuario_modulo`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_permiso_usuario_modulo` (`usuario_id`,`modulo_id`),
  ADD KEY `fk_permisos_modulo` (`modulo_id`);

--
-- Indices de la tabla `usuarios_nova`
--
ALTER TABLE `usuarios_nova`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_usuarios_nova_uuid` (`uuid`),
  ADD UNIQUE KEY `uq_usuarios_nova_usuario` (`usuario`),
  ADD UNIQUE KEY `uq_usuarios_nova_rut` (`rut`),
  ADD UNIQUE KEY `uq_usuarios_nova_redmine_id` (`redmine_id`),
  ADD KEY `idx_usuarios_nova_estado` (`estado`),
  ADD KEY `idx_usuarios_nova_rol` (`rol`),
  ADD KEY `idx_usuarios_nova_nombre` (`nombre`,`apellido`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `alias_comando_telegram`
--
ALTER TABLE `alias_comando_telegram`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `auditoria_nova`
--
ALTER TABLE `auditoria_nova`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `comandos_telegram`
--
ALTER TABLE `comandos_telegram`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `configuraciones_nova`
--
ALTER TABLE `configuraciones_nova`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `integraciones_usuario`
--
ALTER TABLE `integraciones_usuario`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `mantenciones_modulo`
--
ALTER TABLE `mantenciones_modulo`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `mensajes_telegram`
--
ALTER TABLE `mensajes_telegram`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de la tabla `modulos_nova`
--
ALTER TABLE `modulos_nova`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `permisos_usuario_modulo`
--
ALTER TABLE `permisos_usuario_modulo`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `usuarios_nova`
--
ALTER TABLE `usuarios_nova`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `alias_comando_telegram`
--
ALTER TABLE `alias_comando_telegram`
  ADD CONSTRAINT `fk_alias_comando_telegram` FOREIGN KEY (`comando_id`) REFERENCES `comandos_telegram` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `auditoria_nova`
--
ALTER TABLE `auditoria_nova`
  ADD CONSTRAINT `fk_auditoria_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios_nova` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `comandos_telegram`
--
ALTER TABLE `comandos_telegram`
  ADD CONSTRAINT `fk_comandos_telegram_modulo` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_nova` (`id`) ON DELETE SET NULL;

--
-- Filtros para la tabla `integraciones_usuario`
--
ALTER TABLE `integraciones_usuario`
  ADD CONSTRAINT `fk_integraciones_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios_nova` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `mantenciones_modulo`
--
ALTER TABLE `mantenciones_modulo`
  ADD CONSTRAINT `fk_mantenciones_modulo` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_nova` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `permisos_usuario_modulo`
--
ALTER TABLE `permisos_usuario_modulo`
  ADD CONSTRAINT `fk_permisos_modulo` FOREIGN KEY (`modulo_id`) REFERENCES `modulos_nova` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_permisos_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios_nova` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
