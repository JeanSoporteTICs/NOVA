# LIMPIEZA_REDMINE_MANTENCION_DB.md
## Análisis y limpieza de `redmine_mantencion_reportes`

**Fecha:** 2026-06-15
**Sesión:** S29
**Entorno:** Laravel 12.62.0, PHP 8.2.12, MariaDB

---

## 1. Columnas actuales de `redmine_mantencion_reportes`

Schema después de Phase 2 (datos_extra ya eliminado en S27):

| Columna | Tipo | Nullable | Extra |
|---------|------|----------|-------|
| `id` | bigint PK | NO | auto_increment |
| `modulo_id` | bigint FK→modulos_nova | SÍ | nullOnDelete |
| `local_id` | varchar(120) | SÍ | UNIQUE |
| `id_core` | varchar(160) | SÍ | index |
| `proyecto` | varchar(180) | SÍ | — |
| `project_id` | varchar(80) | SÍ | index |
| `tipo` | varchar(120) | SÍ | — |
| `tipo_id` | varchar(80) | SÍ | — |
| `asunto` | text | SÍ | — |
| `descripcion` | longtext | SÍ | — |
| `estado` | varchar(80) | SÍ | index |
| `estado_redmine` | varchar(120) | SÍ | — |
| `prioridad` | varchar(80) | SÍ | — |
| `priority_id` | varchar(80) | SÍ | — |
| `id_redmine_asignado` | varchar(80) | SÍ | index |
| `asignado_nombre` | varchar(180) | SÍ | — |
| `categoria_id` | bigint FK→categorias | SÍ | nullOnDelete |
| `solicitante` | varchar(255) | SÍ | — |
| `anexo` | varchar(120) | SÍ | — |
| `unidad_id` | bigint FK→unidades | SÍ | nullOnDelete |
| `unidad_nombre` | varchar(255) | SÍ | — |
| `fecha_inicio` | date | SÍ | index |
| `fecha_fin` | date | SÍ | — |
| `fecha_reporte` | date | SÍ | — |
| `hora_reporte` | time | SÍ | — |
| `tiempo_estimado` | decimal(10,2) | SÍ | — |
| `correo` | varchar(255) | SÍ | — |
| `hora_extra` | boolean | NO | default false, index |
| `numero_ticket_redmine` | unsigned int | SÍ | index |
| `source_path` | varchar(255) | SÍ | index |
| `creado_at` | timestamp | NO | useCurrent |
| `actualizado_at` | timestamp | NO | useCurrent / onUpdate |

**Total: 31 columnas** (32 originales − `datos_extra` eliminada en S27 Phase 2)

---

## 2. Estado de uso real de la tabla

**Ningún código activo lee ni escribe `redmine_mantencion_reportes` en runtime.**

Evidencia:
- `grep -r 'redmine_mantencion_reportes' redmine-mantencion/ app/` → 0 resultados en código runtime
- La tabla solo aparece en migraciones (`2026_06_13_134500`, `2026_06_13_181500`, `2026_06_14_110000`, `2026_06_15_000002`)
- `redmine-mantencion/views/Historico/historico.php` llama `storage_json_by_prefix('reportes')` → `RedmineMantencionStorageRepository` → columna `payload_json` de `redmine_mantencion_storage` (NO la tabla de reportes)
- `redmine-mantencion/controllers/dashboard.php` llama `load_messages()` → lee `mensaje.json` vía `storage_read_json()`
- La tabla fue poblada en S13 con datos históricos migrados desde JSON. Los datos fueron vaciados en S27.

**Conclusión:** `redmine_mantencion_reportes` existe como bridge de migración normalizadora, en espera de que el módulo Mantención sea refactorizado para escribir en BD directamente.

---

## 3. Columnas usadas realmente

Ninguna columna es leída en runtime actualmente. Sin embargo, para el uso futuro normalizado del módulo, estas columnas representan datos de negocio válidos:

| Columna | Estado | Razón para conservar |
|---------|--------|---------------------|
| `id` | ✅ CONSERVAR | PK autoincremental |
| `modulo_id` | ✅ CONSERVAR | FK al módulo dueño |
| `id_core` | ✅ CONSERVAR | ID de la solicitud en sistema CORE (Mantención only) |
| `tipo` | ✅ CONSERVAR | Tipo de solicitud (texto) |
| `asunto` | ✅ CONSERVAR | Asunto del ticket |
| `descripcion` | ✅ CONSERVAR | Descripción larga |
| `estado` | ✅ CONSERVAR | Estado local (`pendiente`, `procesado`, `archivado`) |
| `estado_redmine` | ✅ CONSERVAR | Nombre del estado en Redmine (texto) |
| `prioridad` | ✅ CONSERVAR | Prioridad (texto) |
| `id_redmine_asignado` | ✅ CONSERVAR | ID Redmine del asignado |
| `asignado_nombre` | ✅ CONSERVAR | Nombre desnormalizado — práctico para display sin JOIN |
| `categoria_id` | ✅ CONSERVAR | FK → categorias |
| `solicitante` | ✅ CONSERVAR | Nombre del solicitante |
| `anexo` | ✅ CONSERVAR | Teléfono de contacto del solicitante |
| `unidad_id` | ✅ CONSERVAR | FK → unidades |
| `fecha_inicio` | ✅ CONSERVAR | Fecha de inicio del trabajo |
| `fecha_fin` | ✅ CONSERVAR | Fecha de fin del trabajo |
| `fecha_reporte` | ✅ CONSERVAR | Fecha del reporte (creación) |
| `hora_reporte` | ✅ CONSERVAR | Hora del reporte |
| `tiempo_estimado` | ✅ CONSERVAR | Tiempo estimado en horas |
| `correo` | ✅ CONSERVAR | Email del solicitante (específico de CORE) |
| `hora_extra` | ✅ CONSERVAR | Flag de hora extra |
| `numero_ticket_redmine` | ✅ CONSERVAR | ID del issue en Redmine |

---

## 4. Columnas candidatas a eliminar

### 4a. Artefactos de migración (sin valor de negocio)

| Columna | Razón | Analogía |
|---------|-------|---------|
| `local_id` | Clave de deduplicación durante migración JSON S13. Ningún código la lee. Con la tabla vacía y sin escritores, es equivalente a `reporte_local_id` que se dropó con `horas_extras`. | `reporte_local_id` en `horas_extras` (eliminada S28) |
| `source_path` | Ruta del archivo JSON de origen. Puro artefacto de migración. Sin valor una vez migrados los datos. | `source_path` en `horas_extras` (eliminada S28) |

### 4b. Redundantes con `configuraciones_modulo` (modulo_id=2)

Para el módulo Mantención, todos los reportes pertenecen al mismo proyecto Redmine con el mismo tracker y prioridad predeterminados. Estos valores están en `configuraciones_modulo` y no deben ser columnas por reporte:

| Columna | Derivable desde | Clave config |
|---------|-----------------|--------------|
| `proyecto` | `configuraciones_modulo` donde `modulo_id=2, clave='project_name'` | `project_name` |
| `project_id` | `configuraciones_modulo` donde `modulo_id=2, clave='project_id'` | `project_id` |
| `tipo_id` | `configuraciones_modulo` donde `modulo_id=2, clave='tracker_id'` | `tracker_id` |
| `priority_id` | `configuraciones_modulo` donde `modulo_id=2, clave='priority_id'` | `priority_id` |

### 4c. Redundantes con FK existente

| Columna | Redundante con | Por qué eliminar |
|---------|---------------|-----------------|
| `unidad_nombre` | `unidad_id` FK → `unidades.nombre` | Viola 3FN; el nombre se obtiene con un JOIN. Si `unidad_id` está poblado, `unidad_nombre` es derivable. |

**Total candidatas a eliminar: 7 columnas**
(`local_id`, `source_path`, `proyecto`, `project_id`, `tipo_id`, `priority_id`, `unidad_nombre`)

---

## 5. Comparación con `redmine_tic_reportes`

`redmine_tic_reportes` fue normalizada progresivamente en S12–S22. Schema actual:

| Columna TIC | Equivalente Mantención | Diferencia |
|-------------|----------------------|------------|
| `id` | `id` | Igual |
| `modulo_id` | `modulo_id` | Igual |
| `redmine_id` | `numero_ticket_redmine` | **Nombre distinto** |
| `estado` | `estado` | Igual |
| `estado_redmine` | `estado_redmine` | Igual |
| `tipo` | `tipo` | Igual |
| `prioridad` | `prioridad` | Igual |
| `categoria_catalogo_id` FK→catalogos_modulo | `categoria_id` FK→categorias | **FK target distinto** |
| `unidad_catalogo_id` FK→catalogos_modulo | `unidad_id` FK→unidades | **FK target distinto** |
| `unidad_solicitante_catalogo_id` FK→catalogos_modulo | _(sin equivalente)_ | Mantención usa `core_establecimiento` en descripción |
| `solicitante` | `solicitante` | Igual |
| `asunto` | `asunto` | Igual |
| `descripcion` | `descripcion` | Igual |
| `fecha` | `fecha_reporte` | **Nombre distinto** |
| `hora` | `hora_reporte` | **Nombre distinto** |
| `fecha_inicio` | `fecha_inicio` | Igual |
| `fecha_fin` | `fecha_fin` | Igual |
| `chat_id_telegram` | _(sin equivalente)_ | Solo TIC usa Telegram |
| `mensaje` | _(sin equivalente)_ | Solo TIC tiene mensaje Telegram |
| `asignado_a` (uint) | `id_redmine_asignado` (varchar) + `asignado_nombre` | **Tipo y estructura distintos** |
| `hora_extra` | `hora_extra` | Igual |
| `tiempo_estimado` | `tiempo_estimado` | Igual |
| `origen` | _(sin equivalente)_ | Solo TIC diferencia `api`/`manual` |
| `procesado_at` | _(sin equivalente)_ | Solo TIC registra el timestamp de envío |
| _(sin equivalente)_ | `id_core` | Solo Mantención tiene integración CORE |
| _(sin equivalente)_ | `correo` | Solo Mantención almacena email del solicitante |
| _(sin equivalente)_ | `anexo` | Solo Mantención almacena teléfono del solicitante |

**Columnas exclusivas de Mantención (tras limpieza):** `id_core`, `correo`, `anexo`
**Columnas de TIC sin equivalente en Mantención:** `chat_id_telegram`, `mensaje`, `asignado_a` (int FK), `origen`, `procesado_at`, `unidad_solicitante_catalogo_id`

---

## 6. Análisis de `redmine_mantencion_storage.payload_json`

### Estado actual

La tabla `redmine_mantencion_storage` tiene **2 filas activas**:

| path | Uso |
|------|-----|
| `configuracion.json` | Leído por `load_platform_config()` → `MantencionConfigRepository::loadAll()` → `configuraciones_modulo` (primario) con fallback JSON |
| `roles.json` | Leído por `auth_load_roles()` en Mantención para gestionar roles de usuario |

### Flujo actual de `payload_json`

```
storage_read_json($path)
    → storage_db_repository()
    → RedmineMantencionStorageRepository::readJson($rel)
    → SELECT payload_json FROM redmine_mantencion_storage WHERE path = ?
```

### ¿Se puede eliminar `payload_json`?

**No.** `payload_json` es el mecanismo de almacenamiento activo de `redmine_mantencion_storage`. Sin él, la lectura de `configuracion.json` y `roles.json` falla, dejando a Mantención sin configuración ni control de roles.

**Pendiente a futuro:** Migrar `roles.json` a una tabla relacional (similar a como `configuracion.json` fue migrado a `configuraciones_modulo` en S27). Una vez hecho:
- `redmine_mantencion_storage` quedaría con 0 filas necesarias
- La tabla podría eventualmente eliminarse cuando los controladores lean de `configuraciones_modulo` directamente

---

## 7. Cambios aplicados en S29

### Migración creada

**Archivo:** `database/migrations/2026_06_15_200000_cleanup_mantencion_reportes_columns.php`

**Columnas eliminadas (7):**
1. `local_id` (+ índice UNIQUE)
2. `source_path` (+ índice)
3. `proyecto`
4. `project_id` (+ índice)
5. `tipo_id`
6. `priority_id`
7. `unidad_nombre`

**`down()` restaura** las 7 columnas y sus índices originales.

---

## 8. Migraciones creadas

| Migración | Tipo | Resultado |
|-----------|------|-----------|
| `2026_06_15_200000_cleanup_mantencion_reportes_columns` | DROP 7 columnas | ✅ 114ms |

---

## 9. Tests ejecutados

```
Tests: 1 skipped, 47 passed (119 assertions)
Duration: 3.17s
```

Sin regresiones. El 1 skipped es contextual (Phase 3c — columna `permisos` eliminada).

**Schema final de `redmine_mantencion_reportes` (24 columnas):**

| Columna | Tipo |
|---------|------|
| `id` | bigint PK |
| `modulo_id` | bigint FK→modulos_nova |
| `id_core` | varchar(160) |
| `tipo` | varchar(120) |
| `asunto` | text |
| `descripcion` | longtext |
| `estado` | varchar(80) index |
| `estado_redmine` | varchar(120) |
| `prioridad` | varchar(80) |
| `id_redmine_asignado` | varchar(80) index |
| `asignado_nombre` | varchar(180) |
| `categoria_id` | bigint FK→categorias |
| `solicitante` | varchar(255) |
| `anexo` | varchar(120) |
| `unidad_id` | bigint FK→unidades |
| `fecha_inicio` | date index |
| `fecha_fin` | date |
| `fecha_reporte` | date |
| `hora_reporte` | time |
| `tiempo_estimado` | decimal(10,2) |
| `correo` | varchar(255) |
| `hora_extra` | boolean index |
| `numero_ticket_redmine` | unsigned int index |
| `creado_at` / `actualizado_at` | timestamp |

---

## 10. Pendientes

| Pendiente | Prioridad | Descripción |
|-----------|-----------|-------------|
| Migrar `roles.json` a tabla relacional | Bajo | Definir esquema de roles para Mantención y crear tabla similar a `redmine_tic_permisos_rol` para `modulo_id=2` |
| Actualizar controladores Mantención para leer de BD | Medio | `auth.php`, `nextcloud.php`, `onlyoffice.php` aún leen de `configuracion.json` vía `load_platform_config()` → bridge activo |
| Renombrar `numero_ticket_redmine` → `redmine_id` | Info | Para alinear naming con `redmine_tic_reportes` (requiere actualizar importadores si los hay) |
| Renombrar `fecha_reporte` → `fecha` | Info | Para alinear con `redmine_tic_reportes.fecha` |
| Activar escritura en `redmine_mantencion_reportes` | Bajo | El módulo sigue usando JSON para nuevos reportes. Cuando se refactorice dashboard.php, los reportes procesados deben escribirse en esta tabla. |

---

*Análisis y limpieza realizada en S29*
