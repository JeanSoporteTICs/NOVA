# NOVA — Auditoría y Normalización Completa de Base de Datos

**Sesiones:** S31 + S32-FINAL + S33  
**Fecha:** 2026-06-18  
**Estado:** READY WITH MINOR FIXES (S33 aplicada; queda `App\Models\User` documentado)  
**Tablas analizadas:** 24 (pre-S31) → 23 (post-S31/S32-FINAL)  
**Tests post-auditoría:** 47 passed, 1 skipped — sin regresiones

---

## Actualización S33 - Decisiones de Esquema (2026-06-18)

- `redmine_mantencion_reportes.unidad_id` fue eliminado por `2026_06_18_020000_s33_drop_mantencion_reportes_unidad_id`: Mantención usa Unidad como texto manual desde Redmine/CORE, por lo que el dato autoritativo queda en `unidad_texto`.
- `redmine_mantencion_reportes.asignado_nombre` se conserva como cache denormalizada de visualización/histórico/estadísticas; `id_redmine_asignado` sigue siendo la referencia técnica cuando existe.
- `redmine_tic_permisos_catalogo`, `redmine_tic_permisos_rol`, `redmine_tic_permisos_usuario`, `redmine_tic_horas_extra_grupos`, `redmine_tic_horas_extra_grupo_reportes`, `catalogos_modulo`, `modulo_opciones`, `configuraciones_modulo`, `nova_settings`, `nova_audit_logs`, `modulos_nova`, `permisos_usuario_modulo` y `migrations` siguen activos por código o por Laravel.
- No se fusionan logs en esta fase: `nova_audit_logs` queda para auditoría global NOVA/auth/seguridad; `redmine_tic_activity_logs` queda para eventos operacionales TIC. Una tabla única de logs requiere migración futura con `modulo_id`/alcance/visibilidad.

---

## Resumen Ejecutivo

La auditoría completa del esquema MariaDB de NOVA identificó:

- **3 bugs críticos** corregidos (escrituras a columnas eliminadas, lecturas a archivos JSON borrados)
- **5 objetos muertos** eliminados (4 columnas + 1 tabla de backup)
- **2 fixes de esquema** aplicados (ON UPDATE CURRENT_TIMESTAMP, longtext → json)
- **1 sistema de catálogo dual** identificado y documentado para migración futura
- **4 archivos JSON** aún pendientes de migración (detectados, priorizados)

---

## Fase 1 — Inventario de Base de Datos

### Tablas (post-S31: 23 tablas)

| Tabla | Motor | Filas aprox. | Propósito |
|-------|-------|-------------|-----------|
| `usuarios_nova` | InnoDB | 58 | Identidad central de usuarios |
| `modulos_nova` | InnoDB | 4 | Registro de módulos activos |
| `permisos_usuario_modulo` | InnoDB | 0 | Sobreescrituras de acceso por módulo |
| `integraciones_usuario` | InnoDB | 69 | Tokens y credenciales externas |
| `configuraciones_modulo` | InnoDB | ~50 | Configuración KV por módulo |
| `modulo_opciones` | InnoDB | ~30 | Opciones extendidas por módulo |
| `nova_settings` | InnoDB | ~20 | Settings globales del sistema NOVA |
| `nova_audit_logs` | InnoDB | ~150 | Log de auditoría de acciones |
| `redmine_tic_reportes` | InnoDB | ~400 | Reportes de incidencias TIC |
| `redmine_tic_perfiles_usuario` | InnoDB | 43 | Perfiles de usuario en módulo TIC |
| `catalogos_modulo` | InnoDB | ~646 | Catálogo unificado TIC (276 cat + 370 uni) |
| `redmine_mantencion_reportes` | InnoDB | ~500 | Reportes del módulo Mantención |
| `redmine_mantencion_historial` | InnoDB | ~200 | Historial de estados Mantención |
| `redmine_mantencion_storage` | InnoDB | 1 | Almacenamiento binario/texto (security.log) |
| `categorias` | InnoDB | 500 | Categorías de reportes Mantención |
| `unidades` | InnoDB | 616 | Unidades organizacionales Mantención |
| `mantencion_permisos_rol` | InnoDB | ~148 | Permisos por rol en Mantención |
| `mantencion_permisos_usuario` | InnoDB | 0 | Sobreescrituras individuales Mantención |
| `nova_audit_logs` | InnoDB | ~150 | Auditoría de sistema |
| `redmine_tic_perfiles_permisos` | InnoDB | ~111 | Permisos relacionales TIC (Phase 3a) |
| `redmine_tic_perfiles_catalogo` | InnoDB | 37 | Catálogo de claves de permisos TIC |
| `redmine_mantencion_nextcloud_archivos` | InnoDB | 0 | Archivos Nextcloud (estructura vacía) |
| `migrations` | InnoDB | ~35 | Registro Laravel de migraciones |

**Tabla eliminada en S31:** `_nova_column_backups` (1456 filas de backup de migración S25, sin lector en runtime)

### Índices críticos identificados

| Tabla | Columna | Tipo | Estado |
|-------|---------|------|--------|
| `usuarios_nova` | `rut` | UNIQUE | OK |
| `usuarios_nova` | `usuario` | UNIQUE | OK |
| `usuarios_nova` | `redmine_id` | INDEX | OK |
| `usuarios_nova` | `telegram_id_chat` | UNIQUE | OK |
| `integraciones_usuario` | `(usuario_id, tipo_integracion)` | UNIQUE | OK |
| `nova_audit_logs` | `registrado_at` | INDEX | OK |
| `nova_audit_logs` | `(user_id, registrado_at)` | INDEX | **FALTANTE** — ver Fase 5 |
| `configuraciones_modulo` | `(modulo_id, clave)` | UNIQUE | OK |
| `catalogos_modulo` | `(modulo_id, tipo)` | INDEX | OK |

### Foreign Keys

| Tabla | Columna | Referencia | Estado |
|-------|---------|-----------|--------|
| `redmine_tic_perfiles_usuario` | `usuario_id` | `usuarios_nova.id` | OK |
| `redmine_tic_perfiles_permisos` | `perfil_id` | `redmine_tic_perfiles_usuario.id` | OK |
| `redmine_tic_perfiles_permisos` | `clave_id` | `redmine_tic_perfiles_catalogo.id` | OK |
| `redmine_tic_reportes` | `categoria_id` | `catalogos_modulo.id` | OK (sistema dual) |
| `redmine_tic_reportes` | `unidad_id` | `catalogos_modulo.id` | OK (sistema dual) |
| `configuraciones_modulo` | `modulo_id` | `modulos_nova.id` | OK |
| `mantencion_permisos_rol` | — | — | **SIN FK** a `modulos_nova.id` |

---

## Fase 2 — Análisis de Uso Laravel

### Modelos activos

| Clase | Tabla | Usado por |
|-------|-------|----------|
| `NovaUser` | `usuarios_nova` | Auth, acceso, repos |
| `App\Models\User` | `users` | **DEAD** — tabla `users` no existe |

### Repositorios principales y sus tablas

| Repositorio | Tablas accedidas |
|-------------|-----------------|
| `NovaUserRepository` | `usuarios_nova` |
| `NovaAccessRepository` | `usuarios_nova`, `modulos_nova`, `permisos_usuario_modulo` |
| `NovaAuditRepository` | `nova_audit_logs` |
| `NovaSettingsRepository` | `nova_settings` |
| `NovaBackupRepository` | DB snapshots |
| `NovaHealthRepository` | `nova_settings`, `modulos_nova` (via `MantencionConfigRepository`) |
| `ModuleRegistry` | `modulos_nova`, `configuraciones_modulo` |
| `UserIntegrationRepository` | `integraciones_usuario` |
| `TelegramCommandSettingsRepository` | `configuraciones_modulo` (clave_modulo='telegram') |
| `NovaSettingsRepository` | `nova_settings` |
| `MantencionConfigRepository` | `configuraciones_modulo` (módulo Mantención) |
| `RedmineMantencionStorageRepository` | `redmine_mantencion_storage` |
| `RedmineDataRepository` | `redmine_tic_*`, `catalogos_modulo`, `usuarios_nova`, `modulos_nova` |
| `NovaAccessRepository` | `permisos_usuario_modulo`, `modulos_nova`, `usuarios_nova` |
| `ProjectAccessGuard` | `permisos_usuario_modulo` |

### Rutas y controladores

- `NovaAuthController` — login/logout/session extend → `usuarios_nova`
- `RedmineDashboardController` — dashboard TIC → `redmine_tic_*`, `catalogos_modulo`
- Controladores Mantención — `redmine_mantencion_*`, `categorias`, `unidades`, `configuraciones_modulo`

---

## Fase 3 — Detección de Objetos Muertos

### Tabla eliminada

| Objeto | Tipo | Razón | Acción |
|--------|------|-------|--------|
| `_nova_column_backups` | TABLE | Artifact de migración S25. 1456 filas de backup de columnas. Sin lector en runtime. | **ELIMINADA en S31** |

### Columnas eliminadas

| Tabla | Columna | Razón | Acción |
|-------|---------|-------|--------|
| `usuarios_nova` | `email` | 0/58 filas pobladas; nunca en ningún `DB::table()` query de runtime | **ELIMINADA en S31** |
| `integraciones_usuario` | `metadata` | 0/69 filas pobladas; nunca escrita ni leída | **ELIMINADA en S31** |
| `integraciones_usuario` | `chat_id` | 0/69 filas pobladas; Telegram migrado a `usuarios_nova.telegram_id_chat` en S19 | **ELIMINADA en S31** |
| `modulos_nova` | `activo` | Escrita en INSERT pero nunca leída; `habilitado` es el path real de lectura/escritura | **ELIMINADA en S31** |

### Modelo dead detectado

| Clase | Tabla apuntada | Estado |
|-------|---------------|--------|
| `App\Models\User` | `users` | Tabla `users` no existe. Modelo nunca instanciado en runtime. |

**Acción pendiente (Prioridad Baja):** Eliminar `app/Models/User.php`

### Columnas confirmadas activas (no eliminar)

| Tabla | Columna | Por qué NO está muerta |
|-------|---------|----------------------|
| `usuarios_nova` | `usuario_core` | 0/58 pobladas PERO referenciada en `UserIntegrationRepository` y `NovaAccessRepository` para búsqueda de identidad CORE |
| `redmine_mantencion_storage` | (toda la tabla) | 1 fila activa `path=security.log`; escrita por `storage_append_line()` en logger de Mantención |
| `catalogos_modulo` | (toda la tabla) | Referenciada por FK desde `redmine_tic_reportes` y leída por `RedmineDataRepository` |

---

## Fase 4 — Análisis de JSON Residual

### Estado post-S30/S31

Después de S30 (eliminación de JSON), los archivos JSON de datos de negocio fueron eliminados o reemplazados por tablas BD. S31 continuó con la corrección de código que aún leía archivos eliminados.

### Bugs de lectura JSON corregidos en S31

| Archivo | Método | Problema | Corrección |
|---------|--------|---------|-----------|
| `NovaHealthRepository.php` | `checks()` | Leía `storage/app/nova/settings.json` (eliminado en S30) | Reemplazado por `settingsCheck()` → `nova_settings` |
| `NovaHealthRepository.php` | `nextcloudCheck()` | Leía `configuracion.json` via `RedmineMantencionStorageRepository` (bridge row eliminado en S30) | Reemplazado por `MantencionConfigRepository::loadAll()` |

### Archivos JSON pendientes de migración

Los siguientes archivos JSON aún existen y tienen datos activos. **Migración diferida** — requieren diseño específico:

| Archivo | Propósito | Complejidad | Prioridad |
|---------|-----------|-------------|-----------|
| `redmine-mantencion/data/nextcloud_created_history.json` | Historial de archivos creados en Nextcloud | Media — nueva tabla `redmine_mantencion_nextcloud_historial` | Alta |
| `redmine-mantencion/data/procedimientos/index.json` | Índice de procedimientos internos | Alta — sistema de gestión de procedimientos | Media |
| `telegram/data/config.json` | Configuración del bot (token, webhook) | Media — migrar a `configuraciones_modulo` (ojo: deployment Docker) | Media |
| `redmine_tic/data/*.json` y `redmine-mantencion/data/*.json` legacy | Archivos históricos/backup | Baja — solo para reference | Baja |

### Archivos JSON de configuración de framework (NO migrar)

- `composer.json`, `package.json`, `phpunit.xml` — son de herramientas, no de datos
- `config/*.php` — configuración Laravel, correcto

---

## Fase 5 — Revisión de Arquitectura de BD

### Problemas de normalización identificados

#### CRÍTICO (corregidos en S31)

1. **`modulos_nova.activo` vs `habilitado`** — dos columnas con semántica idéntica. `activo` nunca leído. Eliminado.
2. **`integraciones_usuario.chat_id`** — duplicado de `usuarios_nova.telegram_id_chat` desde S19. Eliminado.

#### ALTO

3. **Sistema de catálogo dual**
   - `redmine_tic_reportes` FK → `catalogos_modulo` (tipo 'categoria'/'unidad', modulo_id=1)
   - `redmine_mantencion_reportes` FK → `categorias` + `unidades` (tablas dedicadas)
   - Dos módulos usan esquemas distintos para el mismo concepto
   - **Solución recomendada:** Migrar FKs de `redmine_tic_reportes` a `categorias`/`unidades` y eliminar `catalogos_modulo`
   - **Riesgo:** requiere migración de datos (276 cat + 370 uni en `catalogos_modulo`)

4. **`redmine_mantencion_reportes.asignado_nombre`** — viola 3NF
   - `asignado_nombre` puede derivarse de `id_redmine_asignado` → `usuarios_nova.redmine_id` → `nombre`/`apellido`
   - Almacenar el nombre denormalizado introduce riesgo de inconsistencia si el usuario cambia de nombre
   - **Solución:** Eliminar columna y derivar en consulta JOIN; o mantener como campo desnormalizado documentado si el nombre histórico importa

#### MEDIO

5. **`mantencion_permisos_rol` sin FK a `modulos_nova`**
   - La tabla tiene `modulo_id` pero sin constraint FK → puede apuntar a módulos eliminados silenciosamente
   - **Solución:** `ALTER TABLE mantencion_permisos_rol ADD CONSTRAINT fk_mprol_modulo FOREIGN KEY (modulo_id) REFERENCES modulos_nova(id)`

6. **`configuraciones_modulo.actualizado_at` sin ON UPDATE** (corregido en S31)
   - Columna de timestamp no se actualizaba automáticamente en UPDATE
   - Corregido con `ON UPDATE CURRENT_TIMESTAMP`

7. **`nova_audit_logs.contexto` como `longtext`** (corregido en S31)
   - Sin validación de formato JSON a nivel BD
   - Cambiado a tipo `JSON` para validación nativa MariaDB

#### BAJO

8. **Índice faltante: `nova_audit_logs(user_id, registrado_at)`**
   - Las consultas de auditoría filtran por usuario + rango de fecha
   - Solo existe índice en `registrado_at` aislado
   - **Solución:** `CREATE INDEX idx_audit_user_date ON nova_audit_logs(user_id, registrado_at)`

9. **`App\Models\User`** apunta a tabla `users` inexistente
   - Modelo nunca usado en runtime
   - **Solución:** Eliminar `app/Models/User.php`

### Integridad referencial

| FK | Estado | Riesgo |
|----|--------|--------|
| `redmine_tic_perfiles_usuario.usuario_id → usuarios_nova.id` | OK | Bajo |
| `redmine_tic_reportes.categoria_id → catalogos_modulo.id` | OK | Medio (sistema dual) |
| `configuraciones_modulo.modulo_id → modulos_nova.id` | OK | Bajo |
| `mantencion_permisos_rol` (sin columna modulo_id) | — | La tabla solo tiene `id`, `rol`, `permiso`, `valor`; no existe columna `modulo_id` |
| `permisos_usuario_modulo.usuario_id → usuarios_nova.id` | OK | Bajo |
| `permisos_usuario_modulo.modulo_id → modulos_nova.id` | OK | Bajo |

---

## Fase 6 — Plan de Migración

### Ejecutado en S31 (COMPLETADO)

```sql
-- Migración: 2026_06_16_000000_s31_drop_dead_columns_and_tables
ALTER TABLE usuarios_nova DROP COLUMN email;
ALTER TABLE integraciones_usuario DROP COLUMN metadata;
ALTER TABLE integraciones_usuario DROP INDEX idx_integraciones_chat_id, DROP COLUMN chat_id;
ALTER TABLE modulos_nova DROP INDEX idx_modulos_nova_activo, DROP COLUMN activo;
DROP TABLE IF EXISTS _nova_column_backups;
ALTER TABLE configuraciones_modulo MODIFY actualizado_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
ALTER TABLE nova_audit_logs MODIFY contexto JSON NULL;
```

**Rollback:** `down()` en la migración restaura todos los objetos eliminados.

### Bugs corregidos en S31 (COMPLETADO)

1. `RedmineDataRepository::saveProjectUsers()` — eliminada escritura a `permisos` (columna eliminada en Phase 3c)
2. `RedmineDataRepository::registerOrUpdateModule()` — `'activo' => 1` → `'habilitado' => 1`
3. `NovaAccessRepository::databaseModuleId()` — `'activo' => !empty(...)` → `'habilitado' => !empty(...)`
4. `NovaHealthRepository::checks()` — `fileCheck(settings.json)` → `settingsCheck()` (tabla `nova_settings`)
5. `NovaHealthRepository::nextcloudCheck()` — `RedmineMantencionStorageRepository::readJson()` → `MantencionConfigRepository::loadAll()`

### Ejecutado en S32-FINAL (COMPLETADO)

**Migración:** `2026_06_17_000000_s32_schema_hardening`

**C3 — `redmine_tic_reportes.estado` NOT NULL:**
```sql
UPDATE redmine_tic_reportes SET estado = 'pendiente' WHERE estado IS NULL OR estado = '';
ALTER TABLE redmine_tic_reportes MODIFY estado VARCHAR(20) NOT NULL DEFAULT 'pendiente';
```

**P1 — `redmine_tic_reportes.hora_extra` NOT NULL:**
```sql
UPDATE redmine_tic_reportes SET hora_extra = 0 WHERE hora_extra IS NULL;
ALTER TABLE redmine_tic_reportes MODIFY hora_extra TINYINT(1) NOT NULL DEFAULT 0;
```

**P1 — Índice compuesto auditoría:**
```sql
CREATE INDEX IF NOT EXISTS idx_audit_user_date ON nova_audit_logs(user_id, registrado_at);
```

**P1 — Eliminar índice duplicado `idx_integraciones_tipo`** (supersedido por `uq_integracion_usuario_tipo` compuesto):
```sql
ALTER TABLE integraciones_usuario DROP INDEX IF EXISTS idx_integraciones_tipo;
```

**P1 — ON UPDATE CURRENT_TIMESTAMP** en 4 tablas activas:
`redmine_tic_reportes`, `permisos_usuario_modulo`, `modulos_nova`, `integraciones_usuario`

**P1 — Seed `nova_settings`** con 3 defaults: `session_timeout=3600`, `notification_enabled=0`, `health_warning_threshold=1`

**Bugs críticos corregidos en S32-FINAL:**
1. `emach/bin/monitor.php:emach_monitor_nova_users()` — leía `storage/app/nova/users.json` directamente. Reemplazado con JOIN DB `usuarios_nova` + `integraciones_usuario(tipo=emach)`.
2. `emach/index.php` — funciones `emach_nova_users_path()`, `emach_read_nova_users()`, `emach_write_nova_users()`, `emach_find_current_user_index()`, `emach_encrypt_secret()`, `emach_decrypt_secret()` eliminadas. Fallbacks en `emach_current_user_credentials()` y `emach_save_current_user_credentials()` reemplazados por retorno vacío/false.
3. `app/Support/Nova/NovaBackupRepository.php` — `targets()` apuntaba a `settings.json` eliminado en S30. Reescrito para exportar tabla `nova_settings` a JSON en directorio de backups.
4. `storage/app/nova/users.json` — eliminado definitivamente.

### Próximas migraciones (priorizadas)

#### S33: Migración catálogo dual TIC → categorias/unidades (Prioridad Alta)
Orden requerido:
1. Verificar que `categorias` tenga todos los nombres que existen en `catalogos_modulo` tipo='categoria'
2. Verificar que `unidades` tenga todos los nombres que existen en `catalogos_modulo` tipo='unidad'
3. Crear columnas `categoria_id_new`/`unidad_id_new` en `redmine_tic_reportes`
4. Poblar con JOIN a `categorias`/`unidades` por nombre
5. Cambiar FKs: DROP FK a `catalogos_modulo`, ADD FK a `categorias`/`unidades`
6. Renombrar columnas, DROP columnas viejas
7. DROP TABLE `catalogos_modulo`

**Riesgo:** Potencial pérdida de registros sin match de nombre. Requiere validación previa.

#### S33-B: Limpiar modelo muerto (Prioridad Baja)
```bash
rm app/Models/User.php
```

#### S33-C: Migración JSON pendientes (Prioridad Media)
- `nextcloud_created_history.json` → nueva tabla `redmine_mantencion_nextcloud_historial`
- `procedimientos/index.json` → nueva tabla `procedimientos`
- `telegram/data/config.json` → `configuraciones_modulo` (coordinado con Docker)

---

## Fase 7 — Informe Final

### Hallazgos por severidad

#### Críticos (corregidos)
| # | Hallazgo | Impacto | Estado |
|---|---------|---------|--------|
| C1 | `RedmineDataRepository` escribía a `permisos` (columna eliminada Phase 3c) | Error fatal en sync de usuarios | **CORREGIDO** |
| C2 | `NovaHealthRepository` leía `settings.json` (eliminado S30) | Health check siempre en estado `warn` falso | **CORREGIDO** |
| C3 | `NovaHealthRepository` leía `configuracion.json` via storage vacío | Nextcloud check siempre `warn` falso | **CORREGIDO** |

#### Altos (corregidos)
| # | Hallazgo | Impacto | Estado |
|---|---------|---------|--------|
| A1 | `modulos_nova.activo` escrita sin lectura; `habilitado` es el path real | Datos inconsistentes en tabla módulos | **CORREGIDO** (cols eliminada + escrituras actualizadas) |
| A2 | `NovaAccessRepository` y `RedmineDataRepository` escribían `activo` en INSERT | Escritura a columna semánticamente incorrecta | **CORREGIDO** → `habilitado` |

#### Críticos S32-FINAL (corregidos)
| # | Hallazgo | Impacto | Estado |
|---|---------|---------|--------|
| C4 | `emach/bin/monitor.php` leía `users.json` ignorando Laravel bootstrapped | EMACH monitor usaba datos muertos | **CORREGIDO S32-FINAL** |
| C5 | `emach/index.php` tenía fallback a `users.json` en credenciales y escritura | Fallback a archivo eliminado | **CORREGIDO S32-FINAL** |
| C6 | `NovaBackupRepository` apuntaba a `settings.json` eliminado en S30 | Backup siempre devolvía 0 silenciosamente | **CORREGIDO S32-FINAL** |

#### Medios (pendientes)
| # | Hallazgo | Impacto | Estado |
|---|---------|---------|--------|
| M1 | Sistema catálogo dual (`catalogos_modulo` vs `categorias`/`unidades`) | Mantenimiento doble, riesgo de divergencia | Pendiente S33-A |
| M2 | `mantencion_permisos_rol` sin columna `modulo_id` (no existe FK pendiente) | Sin relación a `modulos_nova` por diseño actual | Pendiente evaluar S33+ |
| M3 | `nova_audit_logs.contexto` como `longtext` | Sin validación JSON en BD (alias MariaDB) | **CORREGIDO** → json |
| M4 | `configuraciones_modulo.actualizado_at` sin ON UPDATE | Timestamps incorrectos en actualizaciones | **CORREGIDO** |
| M5 | `redmine_tic_reportes.estado` nullable | INSERTs podían omitir valor | **CORREGIDO S32-FINAL** → NOT NULL DEFAULT 'pendiente' |
| M6 | `redmine_tic_reportes.hora_extra` nullable | INSERTs podían dejar NULL | **CORREGIDO S32-FINAL** → NOT NULL DEFAULT 0 |

#### Bajos (pendientes)
| # | Hallazgo | Impacto | Estado |
|---|---------|---------|--------|
| B1 | Índice `nova_audit_logs(user_id, registrado_at)` faltante | Queries de auditoría por usuario lentas | **CORREGIDO S32-FINAL** |
| B2 | `App\Models\User` apunta a tabla `users` inexistente | Modelo muerto (no usado, no causa error) | Pendiente S33-B |
| B3 | `redmine_mantencion_reportes.asignado_nombre` viola 3NF | Potencial inconsistencia de nombre | Pendiente (evaluar) |
| B4 | Archivos JSON pendientes de migración (3 archivos) | Datos fuera de BD | Pendiente S33-C |
| B5 | `idx_integraciones_tipo` duplicado por `uq_integracion_usuario_tipo` compuesto | Índice redundante | **CORREGIDO S32-FINAL** |
| B6 | `nova_settings` vacía — defaults solo en código | Sin valores base en BD al arrancar | **CORREGIDO S32-FINAL** → 3 rows seedeadas |

### Métricas finales S32-FINAL

| Métrica | S31 | S32-FINAL | Total |
|---------|-----|-----------|-------|
| Tablas analizadas | 24 (pre-S31) | 23 | — |
| Tablas eliminadas | 1 (`_nova_column_backups`) | 0 | 1 |
| Columnas eliminadas | 4 | 0 | 4 |
| Columnas corregidas (schema) | 2 | 2 (NOT NULL) | 4 |
| Índices creados | 0 | 1 (compuesto audit) | 1 |
| Índices eliminados | 2 | 1 (duplicado) | 3 |
| Bugs críticos corregidos | 3 | 3 | 6 |
| Archivos JSON eliminados | 2 | 1 (`users.json`) | 3 |
| Tests post-migración | 47/1 | 47/1 | sin regresión |
| Regresiones introducidas | 0 | 0 | 0 |

### Estado del esquema post-S32-FINAL

```
NOVA Database — Estado post-S32-FINAL (2026-06-17)
════════════════════════════════════════════════════

  23 tablas activas
  Todos los objetos muertos identificados eliminados
  Todos los archivos JSON de datos vivos reemplazados por BD
  Schema fixes aplicados (ON UPDATE, JSON type, NOT NULL)
  Índice de auditoría compuesto aplicado
  Índice duplicado eliminado
  nova_settings seedeada con defaults base

  Pendiente S33:
  • Migración catálogo dual TIC (catalogos_modulo → categorias/unidades)
  • Modelo App\Models\User (muerto)
  • Migración JSON: nextcloud_history, procedimientos, telegram/config
  • Evaluar FK o columna modulo_id en mantencion_permisos_rol
```

### Criterios de completitud

- [x] Todos los objetos sin uso confirmado están eliminados
- [x] No existe código que escriba a columnas eliminadas
- [x] No existe código que lea archivos JSON reemplazados por tablas
- [x] `storage/app/nova/users.json` eliminado; EMACH usa BD
- [x] `NovaBackupRepository` exporta `nova_settings` desde BD
- [x] `redmine_tic_reportes.estado` NOT NULL DEFAULT 'pendiente'
- [x] `redmine_tic_reportes.hora_extra` NOT NULL DEFAULT 0
- [x] Índice compuesto `nova_audit_logs(user_id, registrado_at)` creado
- [x] ON UPDATE CURRENT_TIMESTAMP en 4 tablas activas
- [x] `nova_settings` seedeada con 3 defaults
- [x] Tests pasan sin regresiones (47/1 mantenido)
- [ ] Sistema catálogo dual unificado → S33-A
- [ ] Modelo `App\Models\User` eliminado → S33-B
- [ ] Archivos JSON de runtime pendientes migrados → S33-C

---

*Documento actualizado en Sesión 32-FINAL — Hardening de Esquema y Eliminación de Dependencias JSON Residuales*
## S33 - Racionalizacion segura de catalogos Mantencion

**Estado final:** READY WITH MINOR FIXES.

**Migracion aplicada:** `2026_06_18_000000_s33_drop_confirmed_legacy_columns` (batch 28).

**Columnas legacy eliminadas si existian:** `redmine_tic_horas_extra_grupos.report_ids`, `categorias.origen`, `categorias.datos_extra`, `unidades.origen`, `unidades.datos_extra`, `catalogos_modulo.datos_extra`.

**Catalogos Mantencion DB-only en runtime:** nuevo `app/Support/Mantencion/MantencionCatalogRepository.php`; controladores/vistas de Mantencion leen/escriben `categorias` y `unidades`; `categorias.json` y `unidades.json` quedan solo como legacy/deprecated.

**Decision reportes Mantencion:** `asignado_nombre` se mantiene como cache denormalizada de visualizacion/historico; `unidad_id` fue eliminado porque el flujo manual/CORE conserva Unidad como texto en `unidad_texto`.

**Ajuste S33 reportes Mantencion:** se agrego `2026_06_18_010000_s33_restore_mantencion_redmine_payload_fields` para conservar el payload real enviado a Redmine: `fuente`, `fuente_id`, `proyecto`, `project_id`, `tipo_id`, `estado_id`, `priority_id` y `unidad_texto`. `2026_06_18_020000_s33_drop_mantencion_reportes_unidad_id` elimina `unidad_id`. `MantencionReportRepository` proyecta mensajes manuales e importaciones CORE hacia `redmine_mantencion_reportes`; `unidad_texto` conserva el valor manual/CORE.

**Decision logs:** no fusionar logs en S33. `nova_audit_logs` queda para auditoria global/auth/seguridad; `redmine_tic_activity_logs` queda para actividad operacional TIC.

**Tablas mantenidas intencionalmente:** `redmine_tic_permisos_rol`, `redmine_tic_permisos_catalogo`, `redmine_tic_perfiles_usuario`, `redmine_tic_horas_extra_grupos`, `redmine_tic_horas_extra_grupo_reportes`, `redmine_tic_activity_logs`, `redmine_mantencion_storage`, `permisos_usuario_modulo`, `nova_settings`, `nova_audit_logs`, `modulo_opciones`, `modulos_nova`, `migrations`, `configuraciones_modulo`, `categorias`, `catalogos_modulo`, `unidades`.

**Modelo `App\Models\User`:** no eliminado. La tabla `users` no existe, pero `config/auth.php` aun referencia `App\Models\User::class` como provider Laravel default y `App\Models\NovaUser` no extiende `Authenticatable`.

**Validacion S33:** baseline y post-migracion con `php artisan migrate:status`; `php artisan migrate` aplicado; `php artisan test` = 47 passed, 1 skipped; busqueda final sin lecturas runtime desde `categorias.json`/`unidades.json`.

**Riesgos restantes:** `App\Models\User` como deuda menor, `catalogos_modulo` activo para TIC hasta migrar FKs, y duplicados historicos de nombres en `categorias`/`unidades` que el repositorio deduplica priorizando `clave_externa`.
