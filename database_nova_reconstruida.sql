-- database_nova_reconstruida.sql
-- Esquema recomendado para NOVA en MariaDB/MySQL.
-- Objetivo: reemplazar gradualmente JSON por tablas normalizadas.
-- Motor recomendado: MariaDB 10.4+ / MySQL 8+

CREATE DATABASE IF NOT EXISTS nova
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE nova;

SET FOREIGN_KEY_CHECKS = 0;

-- Borra tablas antiguas del borrador previo y tablas nuevas, para reconstruir limpio.
DROP TABLE IF EXISTS
  alias_comando_telegram,
  comandos_telegram,
  mensajes_telegram,
  auditoria_nova,
  configuraciones_nova,
  permisos_usuario_modulo,
  mantenciones_modulo,
  integraciones_usuario,
  reportes_redmine,
  catalogos_modulo,
  configuraciones_modulo,
  modulos_nova,
  usuarios_nova,
  telegram_command_settings,
  telegram_updates,
  telegram_config,
  emach_monitor_state,
  emach_monitor_config,
  procedimientos,
  overtime_entries,
  redmine_reports,
  module_catalog_items,
  module_configurations,
  module_roles,
  module_users,
  modules,
  nova_access_overrides,
  nova_module_state,
  nova_audit_logs,
  nova_settings,
  nova_users;

SET FOREIGN_KEY_CHECKS = 1;

-- =========================
-- USUARIOS E INTEGRACIONES
-- =========================

CREATE TABLE usuarios_nova (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  usuario VARCHAR(80) NOT NULL,
  rut VARCHAR(20) NULL,
  redmine_id VARCHAR(80) NULL,
  nombre VARCHAR(120) NOT NULL,
  apellido VARCHAR(160) NOT NULL,
  email VARCHAR(180) NULL,
  rol VARCHAR(40) NOT NULL DEFAULT 'usuario',
  estado VARCHAR(40) NOT NULL DEFAULT 'activo',
  password VARCHAR(255) NOT NULL,
  usuario_core VARCHAR(120) NULL,
  ultimo_login_at DATETIME NULL,
  creado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_usuarios_nova_uuid (uuid),
  UNIQUE KEY uq_usuarios_nova_usuario (usuario),
  UNIQUE KEY uq_usuarios_nova_rut (rut),
  UNIQUE KEY uq_usuarios_nova_redmine_id (redmine_id),
  KEY idx_usuarios_nova_estado (estado),
  KEY idx_usuarios_nova_rol (rol),
  KEY idx_usuarios_nova_nombre (nombre, apellido),
  KEY idx_usuarios_nova_core (usuario_core)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE integraciones_usuario (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id BIGINT UNSIGNED NOT NULL,
  sistema VARCHAR(40) NOT NULL,
  usuario_externo VARCHAR(180) NULL,
  identificador_externo VARCHAR(180) NULL,
  secreto_cifrado TEXT NULL,
  configuracion JSON NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_integracion_usuario_sistema (usuario_id, sistema),
  UNIQUE KEY uq_integracion_sistema_identificador (sistema, identificador_externo),
  KEY idx_integraciones_sistema (sistema),
  KEY idx_integraciones_usuario_externo (usuario_externo),
  KEY idx_integraciones_identificador (identificador_externo),

  CONSTRAINT fk_integraciones_usuario
    FOREIGN KEY (usuario_id) REFERENCES usuarios_nova(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- MODULOS, PERMISOS Y CONFIGURACION
-- =========================

CREATE TABLE modulos_nova (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave_modulo VARCHAR(80) NOT NULL,
  nombre VARCHAR(160) NOT NULL,
  descripcion TEXT NULL,
  icono VARCHAR(80) NULL,
  tipo VARCHAR(40) NOT NULL DEFAULT 'native',
  ruta VARCHAR(500) NULL,
  entrada VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  orden INT NOT NULL DEFAULT 100,
  creado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_modulos_nova_clave (clave_modulo),
  KEY idx_modulos_nova_activo (activo),
  KEY idx_modulos_nova_orden (orden),
  KEY idx_modulos_nova_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mantenciones_modulo (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  modulo_id BIGINT UNSIGNED NOT NULL,
  activa TINYINT(1) NOT NULL DEFAULT 0,
  hasta DATETIME NULL,
  motivo TEXT NULL,
  creado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_mantencion_modulo (modulo_id),

  CONSTRAINT fk_mantenciones_modulo
    FOREIGN KEY (modulo_id) REFERENCES modulos_nova(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permisos_usuario_modulo (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id BIGINT UNSIGNED NOT NULL,
  modulo_id BIGINT UNSIGNED NOT NULL,
  permitido TINYINT(1) NOT NULL DEFAULT 0,
  creado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_permiso_usuario_modulo (usuario_id, modulo_id),

  CONSTRAINT fk_permisos_usuario
    FOREIGN KEY (usuario_id) REFERENCES usuarios_nova(id)
    ON DELETE CASCADE,

  CONSTRAINT fk_permisos_modulo
    FOREIGN KEY (modulo_id) REFERENCES modulos_nova(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configuraciones_nova (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave VARCHAR(120) NOT NULL,
  valor TEXT NULL,
  tipo VARCHAR(30) NOT NULL DEFAULT 'string',
  creado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_configuraciones_nova_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE configuraciones_modulo (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  modulo_id BIGINT UNSIGNED NOT NULL,
  clave VARCHAR(120) NOT NULL,
  valor TEXT NULL,
  tipo VARCHAR(30) NOT NULL DEFAULT 'string',
  creado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_configuracion_modulo_clave (modulo_id, clave),
  KEY idx_configuraciones_modulo_clave (clave),

  CONSTRAINT fk_configuraciones_modulo
    FOREIGN KEY (modulo_id) REFERENCES modulos_nova(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auditoria_nova (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evento VARCHAR(120) NOT NULL,
  mensaje TEXT NOT NULL,
  usuario_id BIGINT UNSIGNED NULL,
  ip VARCHAR(80) NULL,
  contexto JSON NULL,
  creado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_auditoria_evento (evento),
  KEY idx_auditoria_usuario (usuario_id),
  KEY idx_auditoria_creado (creado_at),

  CONSTRAINT fk_auditoria_usuario
    FOREIGN KEY (usuario_id) REFERENCES usuarios_nova(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- CATALOGOS Y REPORTES REDMINE
-- =========================

CREATE TABLE catalogos_modulo (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  modulo_id BIGINT UNSIGNED NOT NULL,
  tipo VARCHAR(40) NOT NULL,
  clave_externa VARCHAR(100) NULL,
  nombre VARCHAR(255) NOT NULL,
  predeterminado TINYINT(1) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_catalogo_modulo_item (modulo_id, tipo, clave_externa),
  KEY idx_catalogos_tipo (tipo),
  KEY idx_catalogos_nombre (nombre),

  CONSTRAINT fk_catalogos_modulo
    FOREIGN KEY (modulo_id) REFERENCES modulos_nova(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reportes_redmine (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  modulo_id BIGINT UNSIGNED NOT NULL,
  local_id VARCHAR(100) NULL,
  redmine_id VARCHAR(100) NULL,
  estado VARCHAR(100) NULL,
  estado_redmine VARCHAR(100) NULL,
  tipo VARCHAR(150) NULL,
  prioridad VARCHAR(150) NULL,
  categoria VARCHAR(255) NULL,
  unidad VARCHAR(255) NULL,
  unidad_solicitante VARCHAR(255) NULL,
  solicitante VARCHAR(255) NULL,
  asunto TEXT NULL,
  descripcion LONGTEXT NULL,
  fecha DATE NULL,
  hora TIME NULL,
  asignado_a VARCHAR(100) NULL,
  asignado_nombre VARCHAR(255) NULL,
  hora_extra TINYINT(1) NULL,
  tiempo_estimado DECIMAL(10,2) NULL,
  origen VARCHAR(100) NULL,
  procesado_at DATETIME NULL,
  archivado_por VARCHAR(255) NULL,
  archivado_at DATETIME NULL,
  datos_extra JSON NULL,
  creado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_reporte_modulo_local (modulo_id, local_id),
  KEY idx_reportes_modulo_estado (modulo_id, estado),
  KEY idx_reportes_redmine_id (redmine_id),
  KEY idx_reportes_fecha (fecha),
  KEY idx_reportes_origen (origen),

  CONSTRAINT fk_reportes_modulo
    FOREIGN KEY (modulo_id) REFERENCES modulos_nova(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- TELEGRAM
-- =========================

CREATE TABLE comandos_telegram (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave_comando VARCHAR(80) NOT NULL,
  comando VARCHAR(80) NOT NULL,
  modulo_id BIGINT UNSIGNED NULL,
  descripcion TEXT NULL,
  ejemplo_entrada VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  orden INT NOT NULL DEFAULT 100,
  creado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_comandos_telegram_clave (clave_comando),
  UNIQUE KEY uq_comandos_telegram_comando (comando),
  KEY idx_comandos_telegram_activo (activo),
  KEY idx_comandos_telegram_modulo (modulo_id),

  CONSTRAINT fk_comandos_telegram_modulo
    FOREIGN KEY (modulo_id) REFERENCES modulos_nova(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alias_comando_telegram (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comando_id BIGINT UNSIGNED NOT NULL,
  alias VARCHAR(80) NOT NULL,
  creado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_alias_comando_telegram (alias),
  KEY idx_alias_comando_id (comando_id),

  CONSTRAINT fk_alias_comando_telegram
    FOREIGN KEY (comando_id) REFERENCES comandos_telegram(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mensajes_telegram (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave_mensaje VARCHAR(100) NOT NULL,
  etiqueta VARCHAR(160) NOT NULL,
  cuerpo TEXT NOT NULL,
  descripcion TEXT NULL,
  creado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  UNIQUE KEY uq_mensajes_telegram_clave (clave_mensaje),
  KEY idx_mensajes_telegram_etiqueta (etiqueta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =========================
-- DATOS INICIALES
-- =========================

INSERT INTO modulos_nova
(clave_modulo, nombre, descripcion, icono, tipo, ruta, entrada, activo, orden)
VALUES
('redmine_tic', 'Redmine TICS', 'Captura, procesa y envia reportes del proyecto Redmine TICS.', 'bi-kanban', 'native', 'redmine_tic', 'laravel:redmine.native.dashboard', 1, 10),
('redmine-mantencion', 'Redmine Mantencion', 'Gestiona reportes, pendientes, procedimientos e integraciones de mantencion.', 'bi-tools', 'native', 'redmine-mantencion', 'laravel:redmine.mantencion.dashboard', 1, 20),
('emach', 'EMACH', 'Consulta marcaciones EMACH.', 'bi-heart-pulse', 'legacy', 'emach', 'index.php', 1, 30),
('telegram', 'Telegram', 'Centraliza mensajes y comandos de Telegram para NOVA.', 'bi-telegram', 'native', 'telegram', 'laravel:telegram.index', 1, 40),
('administracion', 'Administracion', 'Configuracion global y usuarios de NOVA.', 'bi-person-gear', 'native', 'storage/app/nova', 'laravel:administracion.index', 1, 50);

INSERT INTO mantenciones_modulo (modulo_id, activa, hasta, motivo)
SELECT id, 0, NULL, NULL
FROM modulos_nova;

INSERT INTO configuraciones_nova (clave, valor, tipo)
VALUES
('session_timeout', '3600', 'int'),
('notification_enabled', '0', 'bool'),
('health_warning_threshold', '1', 'int');

INSERT INTO comandos_telegram
(clave_comando, comando, modulo_id, descripcion, ejemplo_entrada, activo, orden)
SELECT 'help', '/ayuda', id, 'Muestra los comandos disponibles para el usuario.', 'Sin parametros.', 1, 10
FROM modulos_nova WHERE clave_modulo = 'telegram';

INSERT INTO comandos_telegram
(clave_comando, comando, modulo_id, descripcion, ejemplo_entrada, activo, orden)
SELECT 'status', '/estado', id, 'Confirma que el listener Telegram esta activo.', 'Sin parametros.', 1, 20
FROM modulos_nova WHERE clave_modulo = 'telegram';

INSERT INTO comandos_telegram
(clave_comando, comando, modulo_id, descripcion, ejemplo_entrada, activo, orden)
SELECT 'emach', '/emach', id, 'Consulta la ultima marcacion EMACH del usuario asociado al Chat ID.', 'Sin parametros.', 1, 30
FROM modulos_nova WHERE clave_modulo = 'emach';

INSERT INTO comandos_telegram
(clave_comando, comando, modulo_id, descripcion, ejemplo_entrada, activo, orden)
SELECT 'tic', '/tic', id, 'Crea un reporte TIC pendiente desde Telegram.', '/tic problema, unidad, solicitante', 1, 40
FROM modulos_nova WHERE clave_modulo = 'redmine_tic';

INSERT INTO comandos_telegram
(clave_comando, comando, modulo_id, descripcion, ejemplo_entrada, activo, orden)
SELECT 'test', '/test', id, 'Devuelve una respuesta simple para probar el bot.', 'Sin parametros.', 1, 50
FROM modulos_nova WHERE clave_modulo = 'telegram';

INSERT INTO alias_comando_telegram (comando_id, alias)
SELECT id, '/start' FROM comandos_telegram WHERE clave_comando = 'help';

INSERT INTO alias_comando_telegram (comando_id, alias)
SELECT id, '/help' FROM comandos_telegram WHERE clave_comando = 'help';

INSERT INTO alias_comando_telegram (comando_id, alias)
SELECT id, '/status' FROM comandos_telegram WHERE clave_comando = 'status';

INSERT INTO alias_comando_telegram (comando_id, alias)
SELECT id, '/reporte' FROM comandos_telegram WHERE clave_comando = 'tic';

INSERT INTO mensajes_telegram
(clave_mensaje, etiqueta, cuerpo, descripcion)
VALUES
('help_header', 'Encabezado de ayuda', 'Comandos Telegram NOVA:', 'Primera linea cuando alguien pide ayuda.'),
('status', 'Respuesta de /estado', 'Servicio Telegram NOVA activo\nFecha: {fecha}', 'Confirma que el bot esta activo.'),
('test', 'Respuesta de /test', 'Mensaje de prueba desde Telegram NOVA: {fecha}', 'Mensaje simple de prueba.'),
('tic_success', 'Reporte TIC creado', 'Reporte TIC recibido\nAsunto: {asunto}\nCategoria: {categoria}\nUnidad: {unidad}\nEstado: pendiente', 'Confirmacion cuando se crea un reporte TIC.'),
('tic_unavailable', 'TIC no disponible', 'No pude cargar Redmine TIC desde el listener Telegram.', 'Error al cargar TIC.'),
('tic_error', 'Error TIC', 'No pude crear el reporte TIC: {error}', 'Error creando reporte TIC.'),
('emach_success', 'Marcacion EMACH', 'Ultima marcacion EMACH\nFecha: {fecha}\nHora: {hora}\nTipo: {tipo}\nReloj: {reloj}', 'Respuesta con ultima marcacion.'),
('emach_missing_credentials', 'EMACH sin credenciales', 'No tienes credenciales EMACH guardadas en NOVA.', 'Faltan credenciales EMACH.'),
('emach_empty', 'EMACH sin marcaciones', 'No encontre marcaciones EMACH para el mes actual.', 'Sin marcaciones.'),
('emach_error', 'Error EMACH', 'No pude consultar EMACH: {error}', 'Error consultando EMACH.'),
('disabled', 'Comando desactivado', 'Comando desactivado.', 'Comando apagado.'),
('unknown', 'Comando desconocido', 'No entendi ese comando. Usa /ayuda.', 'Comando no reconocido.');

-- =========================
-- TRIGGERS DE ACTUALIZACION
-- =========================

DELIMITER //

CREATE TRIGGER trg_usuarios_nova_actualizado
BEFORE UPDATE ON usuarios_nova
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

CREATE TRIGGER trg_integraciones_usuario_actualizado
BEFORE UPDATE ON integraciones_usuario
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

CREATE TRIGGER trg_modulos_nova_actualizado
BEFORE UPDATE ON modulos_nova
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

CREATE TRIGGER trg_mantenciones_modulo_actualizado
BEFORE UPDATE ON mantenciones_modulo
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

CREATE TRIGGER trg_permisos_usuario_modulo_actualizado
BEFORE UPDATE ON permisos_usuario_modulo
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

CREATE TRIGGER trg_configuraciones_nova_actualizado
BEFORE UPDATE ON configuraciones_nova
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

CREATE TRIGGER trg_configuraciones_modulo_actualizado
BEFORE UPDATE ON configuraciones_modulo
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

CREATE TRIGGER trg_catalogos_modulo_actualizado
BEFORE UPDATE ON catalogos_modulo
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

CREATE TRIGGER trg_reportes_redmine_actualizado
BEFORE UPDATE ON reportes_redmine
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

CREATE TRIGGER trg_comandos_telegram_actualizado
BEFORE UPDATE ON comandos_telegram
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

CREATE TRIGGER trg_alias_comando_telegram_actualizado
BEFORE UPDATE ON alias_comando_telegram
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

CREATE TRIGGER trg_mensajes_telegram_actualizado
BEFORE UPDATE ON mensajes_telegram
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

DELIMITER ;
