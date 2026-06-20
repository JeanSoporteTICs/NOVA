# Normalización de Base de Datos — NOVA

Fecha de análisis: 2026-06-14  
Revisor: Claude Sonnet 4.6

---

## 1. Columnas JSON detectadas

Tablas activas analizadas: 16 (verificado con `SHOW TABLES` contra la BD real).

| Tabla | Columna | Tipo MariaDB | Contenido real | Filas con datos | Prioridad |
|-------|---------|-------------|----------------|-----------------|-----------|
| `redmine_tic_horas_extra_grupos` | `report_ids` | `longtext` | Array de enteros FK a `redmine_tic_reportes.id` | 37/37 | **ALTA** |
| `redmine_tic_perfiles_usuario` | `permisos` | `longtext` | Objeto ~22 claves bool/enum por usuario | 43/43 | MEDIA |
| `redmine_tic_activity_logs` | `contexto` | `longtext` | Objeto libre de contexto de auditoría | variable | CONSERVAR JSON |
| `categorias` | `datos_extra` | `longtext` | `{catalogo_id, predeterminado}` o item raw | 500/500 | **ALTA** |
| `unidades` | `datos_extra` | `longtext` | `{catalogo_id, predeterminado}` o item raw | 616/616 | **ALTA** |
| `redmine_mantencion_reportes` | `datos_extra` | `longtext` | Reporte original completo (red de seguridad) | 275/275 | BAJA |
| `horas_extras` | `datos_extra` | `longtext` | Grupo/reporte original (red de seguridad) | 25/25 | BAJA |
| `configuraciones_modulo` | `valor` (tipo=`json`) | `text` | Arrays `trackers`, `prioridades`, `estados`, `roles` | 4 filas TIC | MEDIA |

---

## 2. Análisis detallado por columna

### 2.1 `redmine_tic_horas_extra_grupos.report_ids` — ALTA prioridad

**Contenido real:**
```json
["660","661","662","663","664"]
```
Array de strings numéricos referenciando `redmine_tic_reportes.id`.

**Problema:** Relación muchos-a-muchos modelada como array JSON. No es posible crear FK, hacer JOINs eficientes ni controlar integridad referencial.

**Solución:** Tabla pivot `redmine_tic_horas_extra_grupo_reportes`.

**Código afectado:** `RedmineDataRepository` — métodos que usan `report_ids`:
- líneas ~2096, 2107, 2126, 2136, 2191, 2255

---

### 2.2 `redmine_tic_perfiles_usuario.permisos` — MEDIA prioridad

**Contenido real (muestra):**
```json
{
  "mensajes": "asignados",
  "mensajes_acceso": true,
  "horas_extra": "asignados",
  "historico": true,
  "historico_acciones": true,
  "historico_scope": "asignados",
  "configuracion": true,
  "estadisticas": true,
  "estadisticas_manual": true,
  "usuarios": true,
  "categorias": true,
  "unidades": true,
  "simulador": true,
  "cfg_conexion": true,
  "cfg_proyecto": true,
  "cfg_retencion": true,
  "cfg_sesion": true,
  "cfg_trackers": true,
  "cfg_prioridades": true,
  "cfg_estados": true,
  "cfg_roles": true,
  "cfg_usuarios": true,
  "actividad": true
}
```
~22 claves fijas. Dos tipos de valor: `boolean` (mayoría) y `enum string` (e.g. `"asignados"`, `"todos"`).

**Problema:** No se puede filtrar por permiso específico con índice. Actualizar un permiso requiere deserializar todo el objeto.

**Solución:** Tabla `redmine_tic_permisos_usuario(id, perfil_id, clave, valor_bool, valor_texto)`.

**Nota:** La tabla `configuraciones_modulo` guarda `roles` con la misma estructura pero por rol (plantilla), no por usuario. Ambas requieren el mismo tratamiento en una migración coordinada.

**Código afectado:** `RedmineDataRepository` — lectura/escritura de permisos (~5 puntos).

---

### 2.3 `redmine_tic_activity_logs.contexto` — CONSERVAR JSON

**Justificación:** Los logs de auditoría tienen esquema variable según el tipo de evento. El uso de JSON aquí es correcto por diseño. No normalizar.

---

### 2.4 `categorias.datos_extra` / `unidades.datos_extra` — ALTA prioridad

**Contenido real:**
```json
{"catalogo_id": 1, "predeterminado": false}
```
Para ítems procedentes de `catalogos_modulo`. Para ítems de `redmine_mantencion_storage` contiene el objeto original completo (nombre, id, etc.).

**Problema:** `predeterminado` es un campo de negocio que debería ser columna real consultable. El resto es artefacto de migración.

**Solución:**
1. Agregar columna `predeterminado BOOLEAN DEFAULT FALSE` a `categorias` y `unidades`
2. Poblar desde `datos_extra->predeterminado`
3. Eliminar `datos_extra` en Phase 2 (tras verificación)

**Código afectado:** `RedmineDataRepository` y código legacy de Mantención que consulta categorías/unidades.

---

### 2.5 `redmine_mantencion_reportes.datos_extra` / `horas_extras.datos_extra` — BAJA prioridad

**Contenido:** Reporte/grupo original completo como JSON de seguridad. No se consulta en runtime.

**Problema:** Columna de 275/25 filas con blobs pesados que nunca se leen en tiempo real.

**Solución:** Verificar que todos los campos útiles estén en columnas reales → eliminar en Phase 2 (requiere confirmación).

**Riesgo:** Posible pérdida de datos históricos si hay campos no mapeados. Revisar antes de eliminar.

---

### 2.6 `configuraciones_modulo` tipo=`json` — MEDIA prioridad

**Filas actuales (módulo TIC):**

| clave | contenido |
|-------|-----------|
| `trackers` | `[{"id":2,"nombre":"Tareas","default":false}, ...]` |
| `prioridades` | `[{"id":1,"nombre":"Baja","default":false}, ...]` |
| `estados` | `[{"id":1,"nombre":"Nueva","default":true}, ...]` |
| `roles` | `{"root":{permisos...}, "admin":{...}, "usuario":{...}}` — diferente estructura |

`trackers`, `prioridades` y `estados` tienen estructura uniforme `{id, nombre, default}` → candidates directos para tabla relacional.

`roles` tiene estructura diferente (dict de rol → objeto de permisos) → tratar junto con `permisos` en perfiles.

**Solución para trackers/prioridades/estados:** Tabla `modulo_opciones`.

**Código afectado:** `RedmineDataRepository::configuration()`, `configuracion.php` de Mantención, `MantencionConfigRepository`.

---

## 3. Nueva estructura propuesta

### 3.1 Tabla pivot: `redmine_tic_horas_extra_grupo_reportes`

```sql
CREATE TABLE redmine_tic_horas_extra_grupo_reportes (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    grupo_id      BIGINT UNSIGNED NOT NULL,
    reporte_id    BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uq_hegr_grupo_reporte (grupo_id, reporte_id),
    KEY idx_hegr_grupo   (grupo_id),
    KEY idx_hegr_reporte (reporte_id),
    CONSTRAINT fk_hegr_grupo    FOREIGN KEY (grupo_id)   REFERENCES redmine_tic_horas_extra_grupos(id) ON DELETE CASCADE,
    CONSTRAINT fk_hegr_reporte  FOREIGN KEY (reporte_id) REFERENCES redmine_tic_reportes(id)           ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.2 Columna en catálogos

```sql
ALTER TABLE categorias ADD COLUMN predeterminado TINYINT(1) NOT NULL DEFAULT 0 AFTER activo;
ALTER TABLE unidades   ADD COLUMN predeterminado TINYINT(1) NOT NULL DEFAULT 0 AFTER activo;
```

### 3.3 Tabla de opciones por módulo: `modulo_opciones`

```sql
CREATE TABLE modulo_opciones (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    modulo_id     BIGINT UNSIGNED NOT NULL,
    tipo          VARCHAR(40) NOT NULL,
    id_externo    VARCHAR(100) NULL,
    nombre        VARCHAR(255) NOT NULL,
    predeterminado TINYINT(1) NOT NULL DEFAULT 0,
    activo        TINYINT(1) NOT NULL DEFAULT 1,
    orden         INT UNSIGNED NOT NULL DEFAULT 100,
    creado_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_modulo_opcion_tipo_ext (modulo_id, tipo, id_externo),
    KEY idx_modulo_opciones_tipo (tipo),
    CONSTRAINT fk_modulo_opciones_modulo FOREIGN KEY (modulo_id) REFERENCES modulos_nova(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Tipos representados:** `tracker`, `prioridad`, `estado` (por módulo).  
**Uso futuro:** también `nextcloud_group` (lista de grupos Nextcloud de Mantención).

### 3.4 Tabla de permisos TIC por usuario: `redmine_tic_permisos_usuario` (Phase 2)

```sql
CREATE TABLE redmine_tic_permisos_usuario (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    perfil_id    BIGINT UNSIGNED NOT NULL,
    clave        VARCHAR(80) NOT NULL,
    valor_bool   TINYINT(1) NULL,
    valor_texto  VARCHAR(80) NULL,
    UNIQUE KEY uq_perfil_clave (perfil_id, clave),
    KEY idx_permisos_clave (clave),
    CONSTRAINT fk_permisos_perfil FOREIGN KEY (perfil_id) REFERENCES redmine_tic_perfiles_usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 4. Migraciones creadas (Phase 1 — no destructivas)

| Archivo | Acción | Destructiva |
|---------|--------|-------------|
| `2026_06_14_100000_create_horas_extra_grupo_reportes_pivot.php` | Crea pivot + pobla desde `report_ids` | NO |
| `2026_06_14_100001_promote_predeterminado_in_catalogs.php` | Agrega columna `predeterminado` + pobla | NO |
| `2026_06_14_100002_create_modulo_opciones.php` | Crea `modulo_opciones` + pobla desde config | NO |

Ninguna migración de Phase 1 elimina columnas ni modifica datos existentes.

---

## 5. Plan de migración de datos

### Phase 1 — Ejecutable ahora (no destructivo)

1. Aplicar las 3 migraciones: `php artisan migrate`
2. Verificar conteos:
   - `redmine_tic_horas_extra_grupo_reportes`: debe tener ≥ suma de IDs en todos los `report_ids`
   - `categorias.predeterminado`: verificar valores contra `datos_extra->predeterminado`
   - `modulo_opciones`: deben aparecer ítems de trackers, prioridades y estados de TIC
3. Actualizar código en `RedmineDataRepository` para leer `report_ids` desde el pivot (manteniendo escritura dual temporalmente)

### Phase 2 — Requiere confirmación antes de ejecutar

> ⚠️ **Estas operaciones son irreversibles sin backup.**

Antes de ejecutar Phase 2: generar backup SQL completo.

| Paso | Tabla | Operación | Prerequisito |
|------|-------|-----------|--------------|
| 2a | `redmine_tic_horas_extra_grupos` | DROP COLUMN `report_ids` | Pivot verificado + código migrado |
| 2b | `categorias` | DROP COLUMN `datos_extra` | `predeterminado` verificado |
| 2c | `unidades` | DROP COLUMN `datos_extra` | `predeterminado` verificado |
| 2d | `configuraciones_modulo` | DELETE rows donde tipo=`json` y clave IN (trackers, prioridades, estados) | `modulo_opciones` verificada + código migrado |
| 2e | `redmine_mantencion_reportes` | DROP COLUMN `datos_extra` | Auditoría de campos faltantes completa |
| 2f | `horas_extras` | DROP COLUMN `datos_extra` | Auditoría de campos faltantes completa |

### Phase 3 — Mediano plazo (requiere diseño de código)

- Normalizar `permisos` (perfiles TIC) y `roles` (configuraciones) a `redmine_tic_permisos_usuario` y tabla equivalente
- Actualizar `RedmineDataRepository` y vistas de permisos
- Migrar lectores directos de `configuraciones_modulo tipo=json` al nuevo modelo

---

## 6. Relaciones Eloquent actualizadas

### Nuevas relaciones necesarias

**`RedmineTicHorasExtraGrupo` (futuro Model)**
```php
public function reportes(): BelongsToMany
{
    return $this->belongsToMany(
        RedmineTicReporte::class,
        'redmine_tic_horas_extra_grupo_reportes',
        'grupo_id',
        'reporte_id'
    );
}
```

**`Categoria` / `Unidad` (modelos existentes)**
```php
// columna predeterminado ya es campo real, accesible directo: $categoria->predeterminado
```

**`ModuloOpcion` (nuevo Model)**
```php
public function modulo(): BelongsTo
{
    return $this->belongsTo(ModuloNova::class, 'modulo_id');
}
```

---

## 7. Riesgos pendientes

| Riesgo | Severidad | Mitigación |
|--------|-----------|-----------|
| `redmine_mantencion_reportes.datos_extra` contiene campos no mapeados a columnas | MEDIO | Auditoría de claves presentes en datos_extra vs columnas de la tabla antes de eliminar |
| `horas_extras.datos_extra` contiene estructura anidada compleja (`group.reports[]`) | MEDIO | Verificar que `hora_inicio`, `hora_termino`, `fecha` estén correctamente pobladas |
| Código que lee `report_ids` directamente fallará si se elimina antes de migrar | ALTO | Migrar código primero; mantener escritura dual en transición |
| `modulo_opciones` duplica datos de Mantención cuando el módulo sincroniza | BAJO | La unicidad (modulo_id, tipo, id_externo) previene duplicados |
| `roles` en `configuraciones_modulo` tiene estructura diferente a `trackers`/etc. | BAJO | NO incluido en Phase 1; requiere diseño separado |
| La tabla `redmine_mantencion_storage` sigue siendo storage puente activo | MEDIO | No eliminar hasta que todos los controladores legacy usen tablas nativas |
| `catalogos_modulo` sigue siendo referenciada por TIC | ALTO | No eliminar hasta migrar `RedmineDataRepository` a `categorias`/`unidades` |

---

## 8. Columnas JSON justificadas (no normalizar)

| Tabla | Columna | Justificación |
|-------|---------|---------------|
| `redmine_tic_activity_logs` | `contexto` | Esquema libre por diseño de auditoría; distintos eventos tienen distintos campos |
| `configuraciones_modulo` | `valor` (tipo=`string`/`int`/`bool`) | Es key-value tipado, no JSON estructurado |
| `configuraciones_modulo` | `valor` tipo=`roles` | Estructura compleja mixta; planificar por separado en Phase 3 |
