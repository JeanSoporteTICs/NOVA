# Plan de Eliminación JSON — NOVA S30

Fecha: 2026-06-16
Objetivo: NOVA sin JSON de negocio/runtime, usando solo Laravel MVC + MariaDB + Bootstrap.

---

## 1. JSON detectados (inventario completo)

| Archivo / tipo | Categoría | Lector activo | Acción |
|---|---|---|---|
| `storage/app/nova/audit.json` | audit log | `NovaAuditRepository` | Migrado → `nova_audit_logs` |
| `storage/app/nova/settings.json` | config global | `NovaNotificationService` | Migrado → `nova_settings` |
| `storage/app/nova/access.json` | acceso módulos | ninguno | Eliminado (stale) |
| `storage/app/modules/state.json` | estado módulos | `ModuleRegistry` | Migrado → `modulos_nova.habilitado/en_mantencion` |
| `storage/app/telegram/command_settings.json` | config Telegram | `TelegramCommandSettingsRepository` | Migrado → `configuraciones_modulo` (clave_modulo=telegram) |
| `redmine_mantencion_storage` fila `configuracion.json` | config Mantención | `auth.php`, `nextcloud.php`, `onlyoffice.php`, `configuracion.php` | Migrado → `configuraciones_modulo` (modulo_id=2, desde S24); bridge eliminado S30 |
| `redmine_mantencion_storage` fila `roles.json` (payload_json blob) | permisos Mantención | `auth_load_roles()` | Migrado → `mantencion_permisos_rol`; fila eliminada |
| `emach/data/usuarios.json` | usuarios EMACH | ninguno | Eliminado (sombra stale de `usuarios_nova`) |
| `redmine_tic/data/estadisticas_api_cache.json` | cache estadísticas | ninguno | Eliminado (cache en `configuraciones_modulo`) |
| `redmine_tic/data/estadisticas_manual.json` | estadísticas históricas | ninguno | Eliminado (sin lector runtime) |

### JSON pendiente (no implementado en S30, documentado)

| Archivo | Razón del defer |
|---|---|
| `nextcloud_created_history.json` | Requiere tabla `redmine_mantencion_nextcloud_historial` (complejo, historial de operaciones WebDAV) |
| `procedimientos/index.json` | Sistema de gestión de procedimientos con uploads/Nextcloud; refactor complejo |
| `telegram/data/config.json` | Bot token y config; Docker-sensitive — requiere coordinación con docker-compose |
| `redmine_tic/data/*.json` | Archivos de importación histórica; stale si el comando `redmine:tic-import-json` ya no se necesita |
| `redmine-mantencion/data/backups/` | Datos archivales de backup; conservar como archival |
| `redmine-mantencion/data/usuarios.json` | Interceptado por `storage_read_json()` → `auth_central_users_for_mantencion()` → DB; archivo es carta muerta |
| `redmine-mantencion/data/mensaje.json` | Write path ya va a DB; archivo legacy data |
| `redmine-mantencion/data/reportes/` y `horasExtras/` | Datos operacionales históricos; `storage_json_by_prefix` retorna `[]` (no hay filas en DB para esos prefijos) |

---

## 2. Reemplazo aplicado (mapa JSON → BD)

| JSON | Tabla/columna destino | Mecanismo |
|---|---|---|
| `audit.json` | `nova_audit_logs` | `NovaAuditRepository::record()` / `recent()` |
| `settings.json` | `nova_settings` | `NovaSettingsRepository::get()` / `set()` / `all()` |
| `state.json` | `modulos_nova.habilitado`, `modulos_nova.en_mantencion` | `ModuleRegistry::state()` / `saveState()` |
| `roles.json` (storage) | `mantencion_permisos_rol (rol, permiso, valor)` | `auth_load_roles()` |
| `configuracion.json` (storage) | `configuraciones_modulo` (modulo_id=2) | `MantencionConfigRepository::loadAll()` / `saveAll()` |
| `command_settings.json` | `configuraciones_modulo` (modulo_id=telegram) | `TelegramCommandSettingsRepository::all()` / `save()` |
| `users.json` / Telegram lookup | `usuarios_nova.telegram_id_chat` | `telegram_user_by_chat_id()` vía Laravel DB |
| `categorias.json` / `unidades.json` | `categorias`, `unidades` | `dashboard_catalog_names()` vía DB |

---

## 3. Archivos eliminados

| Archivo | Motivo |
|---|---|
| `storage/app/nova/access.json` | Stale — acceso ya en `permisos_usuario_modulo` |
| `redmine_tic/data/estadisticas_api_cache.json` | Cache migrado a `configuraciones_modulo` |
| `redmine_tic/data/estadisticas_manual.json` | Sin lector runtime activo |
| `emach/data/usuarios.json` | Sombra stale de `usuarios_nova` |

Los archivos `audit.json`, `settings.json` y `state.json` son eliminados automáticamente por las migraciones S30 si existían en filesystem.

---

## 4. Tablas creadas / modificadas

### Tablas nuevas

| Tabla | Columnas clave | Propósito |
|---|---|---|
| `nova_audit_logs` | id, event, message, user_id, user_name, ip, contexto (JSON), registrado_at | Log de auditoría (reemplaza audit.json) |
| `nova_settings` | id, clave UNIQUE, valor, tipo, actualizado_at | Configuración global clave/valor (reemplaza settings.json) |
| `mantencion_permisos_rol` | id, rol, permiso, valor, UNIQUE(rol,permiso) | Permisos Mantención por rol (reemplaza roles.json blob) |

### Tablas modificadas

| Tabla | Cambio |
|---|---|
| `modulos_nova` | Agregadas columnas `habilitado` (bool, default 1) y `en_mantencion` (bool, default 0) |
| `redmine_mantencion_storage` | Eliminadas filas `configuracion.json` y `roles.json`; tabla queda **vacía** |

### Conteo total

- **Antes S30:** 21 tablas
- **Después S30:** 24 tablas (+3: nova_audit_logs, nova_settings, mantencion_permisos_rol)

---

## 5. Columnas / código JSON eliminado

### Columnas JSON tipo persistencia eliminadas (S30)

| Tabla / campo | Reemplazado por |
|---|---|
| `redmine_mantencion_storage.payload_json` para `configuracion.json` | `configuraciones_modulo` (ya desde S24) |
| `redmine_mantencion_storage.payload_json` para `roles.json` | `mantencion_permisos_rol` |

### Funciones / código eliminado

| Función / patrón | Archivo | Reemplazado por |
|---|---|---|
| `auth_roles_file()` | `auth.php` | Eliminada; roles vienen de `mantencion_permisos_rol` |
| `telegram_storage_users_path()` | `listen.php` | Eliminada |
| Lectura `file_get_contents(settings.json)` | `NovaNotificationService` | `NovaSettingsRepository->all()` |
| `file_put_contents(command_settings.json)` | `TelegramCommandSettingsRepository` | `DB::table('configuraciones_modulo')->updateOrInsert(...)` |
| `storage_write_json()` en `save_config()` | `configuracion.php` | Eliminado; solo escribe a `configuraciones_modulo` |
| `$GLOBALS['NEXTCLOUD_CONFIG_FILE']` | `nextcloud.php` | Eliminado |
| Lectura `file_get_contents(storage/app/nova/access.json)` | ningún lector activo | Archivo eliminado |

---

## 6. Riesgos pendientes

### Medio
- `nextcloud_created_history.json` — historial de uploads Nextcloud. No tiene tabla. Si la vista `NextcloudHistorial.php` lee este archivo, mostrará datos históricos que se perderán al eliminarlo. **No eliminar sin crear tabla `redmine_mantencion_nextcloud_historial` y migrar datos.**
- `procedimientos/index.json` — índice de procedimientos documentales. Requiere refactor completo del módulo de procedimientos antes de eliminar.

### Bajo
- `telegram/data/config.json` — contiene `bot_token`. Al migrar a DB se debe coordinar con Docker (el listener lee el archivo directamente). Requiere actualizar `telegram_read_config()` en `lib/telegram.php` para leer de `configuraciones_modulo` con fallback al archivo.
- `redmine-mantencion/data/mensaje.json` — write path ya va a `redmine_mantencion_storage` vía DB. El archivo en filesystem es letra muerta. Se puede eliminar sin impacto, pero primero confirmar que no hay código que lo lea directamente (fuera de `storage_read_json()`).
- `redmine-mantencion/data/usuarios.json` — `storage_read_json()` intercepta la ruta y devuelve usuarios desde `usuarios_nova` vía `auth_central_users_for_mantencion()`. El archivo en filesystem es carta muerta.

### Informativo
- `json_encode` / `json_decode` permanecen legítimamente en código para: columna `contexto` de `nova_audit_logs` (JSON por diseño), tokens API y payloads HTTP, respuestas AJAX, encode/decode en validaciones de importación. No son persistencia JSON de negocio.

---

## 7. Estado final post-S30

### Cumplido ✓
- ~~archivos .json de datos en `storage/app/nova/`~~ → eliminados o migrados a BD
- ~~columnas `payload_json` como fuente viva~~ → vacías / eliminadas
- ~~roles.json blob~~ → `mantencion_permisos_rol` relacional
- ~~audit.json~~ → `nova_audit_logs`
- ~~settings.json~~ → `nova_settings`
- ~~state.json~~ → `modulos_nova.habilitado/en_mantencion`
- ~~command_settings.json~~ → `configuraciones_modulo` (clave_modulo=telegram)
- ~~users.json Telegram lookup~~ → `usuarios_nova.telegram_id_chat`
- ~~categorias.json / unidades.json como fuente viva~~ → tablas `categorias` / `unidades`
- ~~dual-write `configuracion.php` a storage~~ → solo `configuraciones_modulo`
- ~~lectores directos de configuracion.json (auth, nextcloud, onlyoffice)~~ → `MantencionConfigRepository`

### Pendiente (fuera de S30)
- `nextcloud_created_history.json` → tabla historial (S31+)
- `procedimientos/index.json` → refactor módulo procedimientos (S31+)
- `telegram/data/config.json` → `configuraciones_modulo` con cuidado Docker (S31+)
- `redmine_tic/data/*.json` → archivo si se confirma no necesario (S31+)

### Resultado de tests
```
Tests: 1 skipped, 47 passed (119 assertions)
Duration: 2.88s
```
Sin regresiones tras S30.
