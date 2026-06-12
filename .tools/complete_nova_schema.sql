USE nova;

CREATE TABLE IF NOT EXISTS configuraciones_modulo (
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

CREATE TABLE IF NOT EXISTS catalogos_modulo (
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

CREATE TABLE IF NOT EXISTS reportes_redmine (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  modulo_id BIGINT UNSIGNED NOT NULL,
  local_id CHAR(36) NULL,
  redmine_id INT UNSIGNED NULL,
  estado VARCHAR(20) NULL,
  estado_redmine VARCHAR(40) NULL,
  tipo VARCHAR(40) NULL,
  prioridad VARCHAR(20) NULL,
  categoria_catalogo_id BIGINT UNSIGNED NULL,
  unidad_catalogo_id BIGINT UNSIGNED NULL,
  unidad_solicitante_catalogo_id BIGINT UNSIGNED NULL,
  solicitante VARCHAR(255) NULL,
  asunto TEXT NULL,
  descripcion LONGTEXT NULL,
  fecha DATE NULL,
  hora TIME NULL,
  asignado_a VARCHAR(80) NULL,
  hora_extra TINYINT(1) NULL,
  tiempo_estimado DECIMAL(10,2) NULL,
  origen VARCHAR(40) NULL,
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
  KEY idx_reportes_asignado (asignado_a),
  KEY idx_reportes_categoria (categoria_catalogo_id),
  KEY idx_reportes_unidad (unidad_catalogo_id),
  KEY idx_reportes_unidad_solicitante (unidad_solicitante_catalogo_id),

  CONSTRAINT fk_reportes_modulo
    FOREIGN KEY (modulo_id) REFERENCES modulos_nova(id)
    ON DELETE CASCADE,

  CONSTRAINT fk_reportes_asignado
    FOREIGN KEY (asignado_a) REFERENCES usuarios_nova(redmine_id)
    ON DELETE SET NULL,

  CONSTRAINT fk_reportes_categoria
    FOREIGN KEY (categoria_catalogo_id) REFERENCES catalogos_modulo(id)
    ON DELETE SET NULL,

  CONSTRAINT fk_reportes_unidad
    FOREIGN KEY (unidad_catalogo_id) REFERENCES catalogos_modulo(id)
    ON DELETE SET NULL,

  CONSTRAINT fk_reportes_unidad_solicitante
    FOREIGN KEY (unidad_solicitante_catalogo_id) REFERENCES catalogos_modulo(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //

DROP TRIGGER IF EXISTS trg_usuarios_nova_actualizado//
CREATE TRIGGER trg_usuarios_nova_actualizado
BEFORE UPDATE ON usuarios_nova
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

DROP TRIGGER IF EXISTS trg_integraciones_usuario_actualizado//
CREATE TRIGGER trg_integraciones_usuario_actualizado
BEFORE UPDATE ON integraciones_usuario
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

DROP TRIGGER IF EXISTS trg_modulos_nova_actualizado//
CREATE TRIGGER trg_modulos_nova_actualizado
BEFORE UPDATE ON modulos_nova
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

DROP TRIGGER IF EXISTS trg_mantenciones_modulo_actualizado//
CREATE TRIGGER trg_mantenciones_modulo_actualizado
BEFORE UPDATE ON mantenciones_modulo
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

DROP TRIGGER IF EXISTS trg_permisos_usuario_modulo_actualizado//
CREATE TRIGGER trg_permisos_usuario_modulo_actualizado
BEFORE UPDATE ON permisos_usuario_modulo
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

DROP TRIGGER IF EXISTS trg_configuraciones_nova_actualizado//
CREATE TRIGGER trg_configuraciones_nova_actualizado
BEFORE UPDATE ON configuraciones_nova
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

DROP TRIGGER IF EXISTS trg_configuraciones_modulo_actualizado//
CREATE TRIGGER trg_configuraciones_modulo_actualizado
BEFORE UPDATE ON configuraciones_modulo
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

DROP TRIGGER IF EXISTS trg_catalogos_modulo_actualizado//
CREATE TRIGGER trg_catalogos_modulo_actualizado
BEFORE UPDATE ON catalogos_modulo
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

DROP TRIGGER IF EXISTS trg_reportes_redmine_actualizado//
CREATE TRIGGER trg_reportes_redmine_actualizado
BEFORE UPDATE ON reportes_redmine
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

DROP TRIGGER IF EXISTS trg_comandos_telegram_actualizado//
CREATE TRIGGER trg_comandos_telegram_actualizado
BEFORE UPDATE ON comandos_telegram
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

DROP TRIGGER IF EXISTS trg_alias_comando_telegram_actualizado//
CREATE TRIGGER trg_alias_comando_telegram_actualizado
BEFORE UPDATE ON alias_comando_telegram
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

DROP TRIGGER IF EXISTS trg_mensajes_telegram_actualizado//
CREATE TRIGGER trg_mensajes_telegram_actualizado
BEFORE UPDATE ON mensajes_telegram
FOR EACH ROW
BEGIN
  SET NEW.actualizado_at = CURRENT_TIMESTAMP;
END//

DELIMITER ;
