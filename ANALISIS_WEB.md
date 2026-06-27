# Analisis Web NOVA

Fecha de revision: 2026-06-13

## JSON Eradication — 2026-06-21

Estado: **RUNTIME 100% DATABASE**

### Objetivo

Eliminar toda dependencia runtime en archivos JSON. La base de datos es la unica fuente de verdad.

### Resultado

| Dimension | Antes | Despues |
|---|---|---|
| Archivos JSON data redmine_tic | 18 | 0 |
| Archivos JSON data mantencion (datos) | 21 | 0 |
| Archivos JSON backups auto | 135 | 0 |
| Archivos JSON conservados | — | 0 (solo documentos/images/logs) |
| Comandos artisan JSON | 2 | 0 |
| Metodos JSON-file en RedmineDataRepository | 6 | 0 |
| Fallback filesystem en TelegramCommandSettingsRepository | 3 | 0 |
| Funciones backup filesystem en storage.php | 4 | 0 |
| Config EMACH (archivo) | `storage/app/emach/monitor_config.json` | `nova_settings:emach_monitor_config` |

### Archivos modificados

| Archivo | Cambio |
|---|---|
| `redmine-mantencion/controllers/storage.php` | Eliminados: `storage_backup_file()`, `storage_run_auto_backup()`, `storage_prune_backups()`, `storage_copy_recursive()`. Removida logica `REDMINE_MANTENCION_JSON_FALLBACK` de `storage_read_text()` y `storage_write_text()`. Removido parametro `$backup` de `storage_write_file_locked()`. |
| `redmine-mantencion/controllers/auth.php` | Removida llamada `storage_run_auto_backup()` en bootstrap. |
| `redmine-mantencion/app/Support/helpers.php` | Removida llamada `storage_run_auto_backup()` en `bootstrap_app()`. |
| `app/Repositories/Integrations/TelegramCommandSettingsRepository.php` | Eliminados `loadFromFileSystem()`, `filesystemPath()`. Removidos 3 fallbacks a archivo en `loadFromDb()`. `path()` retorna siempre `configuraciones_modulo:telegram`. |
| `app/Http/Controllers/NovaAdministrationController.php` | `readEmachMonitorConfig()` / `writeEmachMonitorConfig()` / `emachMonitorConfigPath()` reemplazados por lectura/escritura en `nova_settings` (clave `emach_monitor_config`). Vista recibe `emachConfigPath = 'nova_settings:emach_monitor_config'`. |
| `routes/console.php` | Eliminados: comando `redmine:tic-import-json`, comando `redmine:mantencion-import-json`, flag `--write-json`, bloque de escritura `usuarios.json`, bloque `storage/app/nova/users.json`, referencia `git show HEAD:...usuarios.json`. Import `RedmineMantencionStorageRepository` eliminado. |
| `redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php` | Eliminados: `importJsonDataToDatabase()`, `archivedReportsFromFiles()`, `dataPath()`, `readList()`, `readJsonMap()`, `readJsonTree()`, `assertInsideDataRoot()`. Import `Illuminate\Support\Facades\File` eliminado. |
| `emach/bin/monitor.php` | `emach_monitor_read_config()` reemplazada: lee desde `nova_settings` (clave `emach_monitor_config`) via `DB::table()`. |

### Archivos/directorios eliminados

| Ruta | Contenido | Motivo |
|---|---|---|
| `redmine_tic/data/` | 18 archivos JSON (categorias, configuracion, horasExtras, mensaje, reportes, roles, unidades, usuarios) | Datos migrados a BD. Solo usados por comando de importacion (eliminado). |
| `redmine-mantencion/data/*.json` | categorias, configuracion, horasExtras, mensaje, nextcloud_created_history, procedimientos/index, reportes, roles, unidades, usuarios (21 archivos) | Datos en `redmine_mantencion_storage` BD. |
| `redmine-mantencion/data/backups/auto/` | 135 archivos backup auto (2026-06-11 a 2026-06-18) | Backups de datos JSON ahora en BD. |

### Conservados (no JSON de negocio)

- `redmine-mantencion/data/procedimientos/documentos/` — 13 documentos binarios (docx, pdf, xlsx)
- `redmine-mantencion/data/procedimientos/imagenes/` — 4 imagenes PNG
- `redmine-mantencion/data/logs/php-error.log` — log de errores PHP
- `redmine-mantencion/data/security.log` — log de seguridad

### Tests

- 47 passed, 1 skipped, 118 assertions
- 66 rutas
- 32 migraciones ran, 0 pending

## Actualizacion Production Hardening - 2026-06-21

Estado: **READY WITH MINOR FIXES**.

### Objetivo

Resolver todos los bloqueantes de produccion identificados en la auditoria de readiness.

### Cambios aplicados

#### Phase 1 — Environment

| Archivo | Cambio |
|---|---|
| `.env.example` | `APP_NAME=NOVA`, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` con placeholder real, `LOG_LEVEL=error`, `DB_DATABASE=nova`, `DB_USERNAME=nova_app`, `SESSION_SECURE_COOKIE=false` con comentario, `APP_TIMEZONE=America/Santiago` |
| `config/app.php` | `'timezone' => env('APP_TIMEZONE', 'America/Santiago')` — configurable via `.env` |

#### Phase 2 — Logout Hardening

| Archivo | Cambio |
|---|---|
| `routes/web.php` | `Route::match(['GET', 'POST'], '/logout')` → `Route::post('/logout')` |
| `app/Http/Controllers/LegacyProjectController.php` | `passthrough()` maneja `logout.php`: en vez de `redirect()->route('logout')` (GET), devuelve form auto-submitting POST con CSRF |
| `tests/Feature/AuthTest.php` | `test_logout_requires_post` actualizado: `assertStatus(405)` en vez de `assertRedirect(route('login'))` |

#### Phase 3 — SecurityHeaders

| Archivo | Cambio |
|---|---|
| `app/Http/Middleware/SecurityHeaders.php` | Agregado `X-Frame-Options: SAMEORIGIN` |

#### Phase 4 — DB User (documentacion)

Ver seccion "Despliegue en Produccion" de `AGENTS.md`. No se requiere cambio de codigo.

#### Phase 5 — Backup

`NovaBackupRepository::backupDbTable()` ya usa `mkdir($dir, 0777, true)` recursivo — crea `storage/app/nova/backups/YYYY-MM-DD/` automaticamente en primer uso. No se requiere cambio de codigo.

**Gap documentado:** La copia de seguridad automatica de la aplicacion solo exporta `nova_settings` (3 filas). El dump completo de las tablas operacionales (`usuarios_nova`, `redmine_tic_reportes`, `redmine_tic_perfiles_usuario`, `configuraciones_modulo`, `redmine_tic_permisos_*`, `mantencion_permisos_rol`) debe hacerse con `mysqldump` externo via cron. Comando y restauracion documentados en `AGENTS.md`.

#### Phase 6 — Telegram Security

Auditoria verifico que `telegram/data/config.json` (cuando existe) NO es accesible publicamente:
- El directorio `telegram/` no esta dentro de `public/`
- La ruta `/telegram/assets/{path}` pasa por `assertAllowedRoot($path, [])` con `allowed_static_roots=[]` → abort(404)
- No existe ruta wildcard `GET /telegram/{path}`
- Sin cambio de codigo requerido ✓

### CSP Recommendation Report (Phase 3)

No se implemento CSP todavia porque los modulos legacy (Mantención, EMACH, TIC) usan scripts y estilos inline extensos. Una CSP restrictiva romperia la UI. Punto de partida recomendado cuando se decida implementar:

```
Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; frame-ancestors 'self';
```

Para CSP estricta sin `unsafe-inline` se requiere: (1) auditar todos los `<script>` y `<style>` inline en modulos legacy, (2) extraer a archivos externos o agregar nonces, (3) auditar los `eval()` en librerias JS legacy. Estimacion: 2-3 dias de trabajo.

### Validacion Production Hardening

- `php artisan test`: **47 passed, 1 skipped** (118 assertions).
- `php artisan route:list`: **66 rutas** (logout POST-only, sin cambio de conteo).
- `php artisan migrate:status`: **0 pending**, 44 migraciones en 32 batches.
- `composer dump-autoload`: OK.

---

## Actualizacion Step 6 — Core/TIC Decoupling - 2026-06-21

Estado: **COMPLETE**.

### Objetivo

Eliminar todas las importaciones directas de clases `RedmineTic\` desde `app/` (Core). Crear una capa de contratos que permita al Core funcionar aunque el modulo TIC no este instalado.

### Hallazgo Phase 1 — Dependencias Core→TIC antes de Step 6

| Archivo | Dependencia TIC | Tipo |
|---|---|---|
| `app/Services/Nova/ProjectAccessGuard.php` | `RedmineTic\Support\Redmine\RedmineDataRepository` | `use` import directo |
| `app/Models/NovaUser.php` | `RedmineTic\Models\RedmineTicPerfil` | `HasOne` relation (nunca usada) |
| `app/Console/Commands/ValidatePhase3aPermisos.php` | `RedmineDataRepository` via reflection | Comando one-time, riesgo bajo |
| `routes/console.php` | `RedmineDataRepository` en closures | Capa de integracion, aceptable |
| `routes/web.php` | `RedmineDashboardController` | Requerido, sin riesgo |

### Archivos creados

| Archivo | Descripcion |
|---|---|
| `app/Contracts/ProjectUserProviderInterface.php` | Interfaz `projectUsers(string $projectKey): array`; contrato Core puro sin TIC |
| `redmine_tic/nova/app/Services/Redmine/RedmineProjectUserProvider.php` | Implementacion TIC; solo responde a `projectKey='redmine_tic'`; delega a `RedmineDataRepository` |

### Archivos modificados

| Archivo | Cambio |
|---|---|
| `app/Providers/AppServiceProvider.php` | `register()` enlaza `ProjectUserProviderInterface` → `RedmineProjectUserProvider` con guard `class_exists` |
| `app/Services/Nova/ProjectAccessGuard.php` | `use RedmineTic\...` eliminado; `findProjectUser()` usa `app()->bound(Interface::class)` con null-object fallback |
| `app/Models/NovaUser.php` | `perfilTic()` HasOne eliminada; import `HasOne` eliminado |

### Validacion Step 6

- `composer dump-autoload`: 6576 clases (2 nuevas: interfaz + provider).
- `php artisan test`: **47 passed, 1 skipped** (119 assertions) — sin regresiones.
- `php artisan route:list`: **66 rutas** — sin cambios.
- `php artisan migrate:status`: **32 batches aplicados**, ninguno pendiente (44 migration files).

### Deuda restante aceptada

| Componente | Razon para mantener |
|---|---|
| `ValidatePhase3aPermisos` usa `RedmineDataRepository` via reflection | Comando one-time de validacion de migracion, no runtime |
| `routes/console.php` — `redmine:archive-processed`, `redmine:tic-import-json` | Capa de integracion TIC, aceptable |
| `routes/web.php` — `RedmineDashboardController` | Punto de entrada requerido del modulo TIC |
| `RedmineDataRepository` (>4000 lineas) | Cleanup de metodos individuales diferido; alto riesgo de regresion sin tests de integracion |

---

## Nextcloud Browser Personal — Procedimientos - 2026-06-21

Estado: **COMPLETE**.

### Objetivo

Convertir el modulo Procedimientos de Redmine Mantencion en un navegador personal de Nextcloud. La fuente de verdad de archivos pasa a ser 100% la API Nextcloud por usuario. No se crea almacenamiento local ni JSON nuevo.

### Antes / Despues

| Dimension | Antes | Despues |
|---|---|---|
| Fuente de archivos | Archivos locales en `data/procedimientos/documentos/` | API Nextcloud WebDAV / OCS por usuario |
| Credenciales | Config global admin de Nextcloud | `integraciones_usuario` (tipo=nextcloud) por usuario |
| Upload | `move_uploaded_file()` → disco local | PUT WebDAV directo a Nextcloud; tmp de PHP descartado |
| Crear doc Office | Binario local en disco | PUT WebDAV en Nextcloud |
| Fallback sin Nextcloud | Storage local | Error guiado: configurar credenciales |
| Tablas nuevas | — | Ninguna (reusar `integraciones_usuario`) |

### Archivos creados

| Archivo | Descripcion |
|---|---|
| `redmine-mantencion/controllers/nc_browser.php` | Dispatcher AJAX: `nc_browser_user_cfg()`, `nc_browser_safe_name()`, `nc_browser_json()`, `nc_browser_handle()` |
| `redmine-mantencion/views/Procedimientos/nc_browser_ajax.php` | Entry point PHP: `require nc_browser.php; nc_browser_handle();` |
| `redmine-mantencion/views/Procedimientos/_nc_browser.php` | UI parcial: puerta de credenciales o browser completo con tabs Mis archivos / Compartidos conmigo, toolbar, modales, JS IIFE |

### Archivos modificados

| Archivo | Cambio |
|---|---|
| `redmine-mantencion/controllers/nextcloud.php` | Agregadas al final: `nextcloud_path_safe()`, `nextcloud_propfind_parse()`, `nextcloud_list_directory()`, `nextcloud_shares_with_me()` |
| `redmine-mantencion/controllers/procedimientos.php` | Bug fix: `storage_write_file_locked($absolutePath, $binary, 0, false)` → 2 args (parametro `$backup` eliminado en JSON Eradication). Rama local de upload y de creacion de doc Office reemplazadas por mensajes de error guiados. |
| `redmine-mantencion/views/Procedimientos/procedimientos.php` | Agrega `require_once nc_browser.php` al tope; incluye `_nc_browser.php` en la vista lista (solo `!$isPublicShare && !$showEditor && !$showDetail`). |

### Acciones AJAX (nc_browser_ajax.php)

| Metodo | Accion | Backend |
|---|---|---|
| GET | `list` | PROPFIND WebDAV Depth:1 → `nextcloud_list_directory()` |
| GET | `shares_with_me` | OCS GET /shares?shared_with_me=true → `nextcloud_shares_with_me()` |
| GET | `download` | GET WebDAV → streaming (inline para PDF/imagen, attachment el resto) |
| POST | `mkdir` | MKCOL via `nextcloud_ensure_directory()` |
| POST | `rename` | MOVE WebDAV con header Destination |
| POST | `delete` | DELETE WebDAV |
| POST | `upload` | PUT WebDAV; tmp PHP descartado tras PUT |
| POST | `share_link` | OCS POST /shares (shareType=3, link publico) |
| POST | `share_user` | OCS POST /shares (shareType=0, usuario) |
| POST | `share_delete` | OCS DELETE /shares/{id} |

### Seguridad aplicada

- Auth requerida antes de cualquier accion (`auth_require_login`).
- Permiso `procedimientos` verificado (`auth_can`).
- CSRF validado en todos los POST (`csrf_validate()` — acepta `$_POST['csrf_token']` o header `X-CSRF-Token`).
- Toda ruta pasa por `nextcloud_path_safe()` (elimina `..`, `.`, segmentos vacios).
- Nombres de archivo/carpeta pasan por `nc_browser_safe_name()` (elimina `..`, `\0`, `<>:"|?*`).
- Sin almacenamiento persistente de credenciales en el request — leidas desde BD encriptada en cada request.
- Sin copias locales de archivos salvo tmp PHP durante upload (eliminado automaticamente por PHP al terminar el request).

### Tablas usadas (sin cambios de schema)

| Tabla | Uso |
|---|---|
| `integraciones_usuario` | Credenciales Nextcloud por usuario (tipo=nextcloud, `valor_secreto` encriptado con `encrypt()`) |
| `configuraciones_modulo` | URL Nextcloud global y config del modulo (modulo_id=2) |
| `redmine_mantencion_procedimientos` | Sin cambio de uso — columnas `nextcloud_path`, `storage_driver`, `nextcloud_share_id`, `nextcloud_share_url` ya existian |

### Validacion

- `php -l` en 4 archivos modificados/creados: **OK (sin errores de sintaxis)**.
- `php artisan test`: **47 passed, 1 skipped** (118 assertions) — sin regresiones.
- `php artisan migrate:status`: **0 pending**, 32 batches aplicados.

---

## Actualizacion Step 5A — Namespace Normalizacion - 2026-06-20

Estado: **COMPLETE**.

### Objetivo

Eliminar namespaces no-canonicos (`App\Support\Auth\*`, `App\Support\Modules\*`, `App\Support\Nova\*`, `App\Support\Integrations\*`) moviendo 11 clases a paths estandar Laravel y extrayendo servicios nuevos (`TelegramService`, `LegacyLoggerService`, `StringNormalizer`).

### Clases que deben PERMANECER en App\Support\*

Las siguientes 8 clases NO se pueden mover porque `redmine-mantencion/controllers/storage.php` y `emach/index.php` las referencian por FQN string en llamadas `app()` en runtime legacy:

`RedmineMantencionStorageRepository`, `MantencionConfigRepository`, `MantencionCatalogRepository`, `MantencionReportRepository`, `NovaSettingsRepository`, `UserIntegrationRepository`, `NovaHealthRepository`, `TelegramLibrary`.

### Nuevos archivos (11 clases en namespaces canonicos)

| Nuevo path | Namespace | Origen |
|---|---|---|
| `app/Support/StringNormalizer.php` | `App\Support` | Nuevo; `static normalize(string): string` — elimina 4 metodos `normalize()` duplicados |
| `app/Services/Nova/LegacyLoggerService.php` | `App\Services\Nova` | Nuevo; wraps `require_once logger.php` + `log_security_event()` |
| `app/Services/Telegram/TelegramService.php` | `App\Services\Telegram` | Nuevo; unico punto de entrada Telegram (`load`, `readConfig`, `isConfigured`, `saveConfig`, `deleteWebhook`, `listenerStatus`, `sendConfiguredMessage`, `notify`, `healthCheck`) |
| `app/Services/Auth/LegacyUserProvider.php` | `App\Services\Auth` | Movido desde `App\Support\Auth\LegacyUserProvider` |
| `app/Repositories/Modules/ModuleRegistry.php` | `App\Repositories\Modules` | Movido desde `App\Support\Modules\ModuleRegistry` |
| `app/Services/Nova/ProjectAccessGuard.php` | `App\Services\Nova` | Movido desde `App\Support\Modules\ProjectAccessGuard` |
| `app/Repositories/Nova/NovaUserRepository.php` | `App\Repositories\Nova` | Movido desde `App\Support\Auth\NovaUserRepository` |
| `app/Repositories/Nova/NovaAccessRepository.php` | `App\Repositories\Nova` | Movido desde `App\Support\Nova\NovaAccessRepository` |
| `app/Repositories/Nova/NovaAuditRepository.php` | `App\Repositories\Nova` | Movido desde `App\Support\Nova\NovaAuditRepository` |
| `app/Repositories/Nova/NovaBackupRepository.php` | `App\Repositories\Nova` | Movido desde `App\Support\Nova\NovaBackupRepository` |
| `app/Repositories/Nova/NovaHealthRepository.php` | `App\Repositories\Nova` | Movido; inyecta `TelegramService` en vez de llamar `TelegramLibrary` directamente |
| `app/Services/Nova/NovaNotificationService.php` | `App\Services\Nova` | Movido desde `App\Support\Nova\NovaNotificationService`; usa `TelegramService::notify()` |
| `app/Repositories/Integrations/TelegramCommandSettingsRepository.php` | `App\Repositories\Integrations` | Movido desde `App\Support\Integrations\*` |
| `app/Repositories/Integrations/TelegramCommandCatalog.php` | `App\Repositories\Integrations` | Movido desde `App\Support\Integrations\*` |

### Archivos con imports actualizados (9 + 2 auxiliares)

`NovaAuthController`, `NovaAdministrationController`, `TelegramController`, `LegacyProjectController`, `ModuleAdminController`, `UserIntegrationController`, `EnsureNovaAuthenticated`, `routes/web.php`, `RedmineDashboardController` (TIC), `telegram/bin/listen.php` (FQN strings), `tests/Unit/NovaAuditRepositoryTest.php`.

### Validacion Step 5A (Phase 4)

- `composer dump-autoload`: 6574 clases.
- `php artisan test`: **47 passed, 1 skipped** (119 assertions).
- `php artisan route:list`: **66 rutas** — sin cambios.
- `php artisan migrate:status`: 32 batches, 0 pendientes.

---

## Actualizacion S36 Arquitectura Step 4 — Dead Code Removal - 2026-06-20

Estado: **COMPLETE**.

### Archivos eliminados

| Archivo | Razon |
|---|---|
| `app/Models/User.php` | Modelo sin tabla `users`. Nunca instanciado en runtime. La referencia en `config/auth.php` actualizada a `App\Models\NovaUser`. |
| `database/factories/UserFactory.php` | Solo creaba instancias de `User`. Seeder tiene el codigo comentado. Nunca usada en tests. |
| `app/Http/Controllers/NovaUserController.php` | Cero rutas activas. El import en `routes/web.php` era muerto. |
| `resources/views/nova/users/index.blade.php` | Unica vista del directorio; solo renderizable desde el controlador eliminado. |

### Cambios en archivos existentes

| Archivo | Cambio |
|---|---|
| `routes/web.php` | Eliminado import muerto `use App\Http\Controllers\NovaUserController`. |
| `config/auth.php` | Provider `users` cambiado de `App\Models\User::class` a `App\Models\NovaUser::class`. |
| `app/Http/Controllers/NovaAdministrationController.php` | `$this->loadTelegramLibrary()` reemplazado por `TelegramLibrary::load()`. Metodo privado eliminado. |
| `app/Http/Controllers/TelegramController.php` | Igual: delegacion a `TelegramLibrary::load()`. Metodo privado eliminado. |

### Archivo nuevo

`app/Support/Telegram/TelegramLibrary.php` — helper estatico con metodo `load()` que consolida la logica de `require_once` de la libreria Telegram (extraido de dos controladores que tenian la misma implementacion privada).

### Candidatos auditados y NO eliminados

| Candidato | Razon para conservar |
|---|---|
| `redmine-mantencion/login.php` | Usado como URL de redirect por `auth_require_login()` en 14+ vistas legacy |
| `redmine-mantencion/logout.php` | Usado como fallback en `navbar.php` cuando `route()` no esta disponible |
| `redmine-mantencion/app/` | Bootstrapped por `login.php` y `logout.php`; contiene `AuthController` activo |
| `NovaAccessRepository::projectUserExists()` | Stub activo — llamado por `defaultAccess()`; retorna `false` por diseno (acceso controlado por overrides) |
| `emach/lib/client.php` | CLI activo; documentado en `emach/README.md` |
| `emach/bin/monitor.php` | CLI activo; documentado en `emach/README.md` |
| `emach/views/partials/navbar.php` | Vista activa del modulo EMACH |

### Validacion

- `composer dump-autoload`: OK, 6571 clases generadas.
- `php artisan test`: **47 passed, 1 skipped** (119 assertions) — sin regresiones.
- `php artisan route:list`: **66 rutas** — sin cambios.
- `php artisan migrate:status`: 32 migraciones aplicadas, ninguna pendiente.

## Actualizacion S35 Arquitectura Step 3 — NovaUserService - 2026-06-20

Estado: **COMPLETE**.

### Objetivo

Extraer la logica de dominio de usuarios de `NovaUserRepository` hacia un nuevo servicio de aplicacion, dejando el repositorio enfocado solo en acceso a datos.

### Nuevo archivo

`app/Services/NovaUserService.php` (namespace `App\Services`).

Responsabilidades del servicio:

| Metodo | Descripcion |
|---|---|
| `normalizeIdentity(string)` | Normaliza cadenas a minusculas alfanumericas para comparaciones |
| `normalizeRutUsername(string)` | Extrae la parte numerica del RUT para usar como username de acceso |
| `isValidRut(string)` | Valida RUT chileno con algoritmo de digito verificador |
| `normalizeNovaRole(string)` | Colapsa variantes de admin a `admin`, resto a `usuario` |
| `normalizeStatus(string)` | Colapsa variantes de bloqueo a `baneado`, resto a `activo` |
| `isBlocked(array)` | Detecta si un usuario esta bloqueado/baneado/inactivo |
| `loginCandidates(array)` | Retorna campos por los que un usuario puede identificarse en login |
| `verifyCredentials(array, string, bool)` | Verifica hash Bcrypt, credencial simple o API token |
| `hashPassword(string)` | Genera hash Bcrypt con `password_hash(PASSWORD_DEFAULT)` |
| `toSessionUser(array)` | Proyecta usuario interno a sesion segura (con estado de integraciones) |
| `fullName(array)` | Nombre completo para deduplicacion y display |
| `identityKeys(array)` | Genera claves de identidad canonicas desde valores crudos |
| `identityKeysForUser(array)` | Version tipada para usuario NOVA (rut/rut_sin_dv/core_user) |
| `deduplicateUsers(array)` | Elimina duplicados por clave de identidad |
| `dedupeKey(array)` | Calcula clave de deduplicacion para un usuario |
| `mergeDuplicateUsers(array, array)` | Fusiona dos registros con reglas: admin gana, activo gana |
| `mergeSources(string, string)` | Combina etiquetas de origen (csv) |
| `splitSources(string)` | Divide etiquetas de origen |

Dependencia interna: `UserIntegrationRepository` (para `hasEmach`/`hasTelegram` en `toSessionUser`).

### Cambios en `NovaUserRepository`

- Se agrego `NovaUserService` al constructor (auto-wired por el contenedor).
- Se eliminaron 16 metodos privados de logica de dominio — ahora delegados al servicio.
- Se elimino codigo muerto: `databaseIntegrationsForUser()` (nunca llamado), `databaseMergeIdentities()` (resultado descartado), mapa `$known` en `usersFromDatabase()`.
- Metodos publicos sin cambio de firma: `all()`, `find()`, `attempt()`, `save()`, `delete()`, `changePassword()`, `activate()`.
- Metodos privados que se mantienen en el repositorio: `write`, `usersFromDatabase`, `writeUsersToDatabase`, `databaseIntegrationsByUserId`, `writeDatabaseIntegrations`, `usersTableAvailable`, `integrationsTableAvailable`, `markLastLogin`, `setStatus`, `unsignedIntegerOrNull`.

### Conteo de lineas

| Archivo | Antes | Despues | Delta |
|---|---|---|---|
| `app/Support/Auth/NovaUserRepository.php` | 812 | ~320 | −492 |
| `app/Services/NovaUserService.php` | — | ~290 (nuevo) | +290 |

### Controladores

Sin cambios. `NovaAdministrationController`, `NovaUserController`, `LegacyUserProvider`, `EnsureNovaAuthenticated` y `NovaAccessRepository` siguen usando `NovaUserRepository` con la misma interfaz publica.

### Validacion

- `php artisan test`: **47 passed, 1 skipped** (119 assertions) — sin regresiones.
- `php artisan route:list`: **66 rutas** — sin cambios.

## Actualizacion S33 - 2026-06-18

Estado final de BD: **READY WITH MINOR FIXES**.

Cambios aplicados:
- Nuevo `app/Support/Mantencion/MantencionCatalogRepository.php` para catálogos de Mantención en BD.
- Mantención deja de leer/escribir `redmine-mantencion/data/categorias.json` y `redmine-mantencion/data/unidades.json` como fuente runtime; esos archivos quedan legacy/deprecated.
- Actualizados controladores/vistas: `categorias.php`, `unidades.php`, `dashboard.php`, `pendiente_manual.php`, `views/Dashboard/dashboard.php`, `views/dashboard.php`, `views/Estadisticas/estadisticas.php`, `views/Configuracion/configuracion.php`, `views/Configuracion/unidades_cf.php`.
- Migración `2026_06_18_000000_s33_drop_confirmed_legacy_columns` aplicada; elimina si existen `report_ids`, `origen` y `datos_extra` legacy en las tablas confirmadas.
- Migración `2026_06_18_010000_s33_restore_mantencion_redmine_payload_fields` aplicada; `redmine_mantencion_reportes` vuelve a conservar el payload real del ticket Redmine (`proyecto/project_id`, `tipo/tipo_id`, `estado_redmine/estado_id`, `prioridad/priority_id`, `unidad_texto`).
- Nuevo `app/Support/Mantencion/MantencionReportRepository.php`; `save_messages()` proyecta creaciones manuales e importaciones CORE hacia `redmine_mantencion_reportes`.
- `asignado_nombre` se mantiene en `redmine_mantencion_reportes` como cache denormalizada de visualización/histórico; `unidad_texto` conserva la unidad manual/CORE.
- Migración `2026_06_18_020000_s33_drop_mantencion_reportes_unidad_id` aplicada; elimina `unidad_id` de `redmine_mantencion_reportes` porque Mantención registra Unidad como texto manual y no como FK a catálogo.
- No se fusionan logs: `nova_audit_logs` queda global/auth/seguridad y `redmine_tic_activity_logs` queda operacional TIC.
- No se elimina `App\Models\User` porque `config/auth.php` aún lo referencia como provider Laravel default y `NovaUser` no es `Authenticatable`.

Validación:
- Baseline `php artisan migrate:status` OK.
- Baseline `php artisan test`: 47 passed, 1 skipped.
- Post `php artisan migrate`: OK, S33 batches 28, 29 y 30.
- Post `php artisan migrate:status`: OK.
- Post `php artisan test`: 47 passed, 1 skipped.

Riesgos restantes:
- `catalogos_modulo` sigue activo para TIC hasta migrar FKs.
- `App\Models\User` queda como deuda menor documentada.
- Hay duplicados históricos por nombre en `categorias`/`unidades`; el repositorio deduplica la salida priorizando `clave_externa`.

## Actualizacion S34 UI - 2026-06-18

Estado visual: **READY WITH MINOR FIXES**.

Cambios aplicados:
- `public/assets/nova-ui.css` suma la capa "NOVA Unified TIC Reference System", usando Redmine TIC como referencia global: navbar navy, hero azul profundo, cards blancas, tablas con encabezado azul claro, formularios de 42px, botones con iconos, badges semanticos, modales y logs oscuros.
- Nuevas clases compartidas: `.nova-system-shell`, `.nova-system-hero`, `.nova-system-head`, `.nova-system-icon`, `.nova-system-meter`, `.nova-system-grid`, `.nova-system-card`, `.nova-system-toolbar`, `.nova-filter-panel`, `.nova-status-badge` y `.nova-log-panel`.
- Alias globales para cabeceras operativas ya usadas en TIC: `.rm-module-head`, `.rm-module-head-icon`, `.rm-module-meter`, `.rm-module-grid`, `.rm-info-card` y `.rm-form-shell`.
- NOVA Home y Modulos ahora usan `.nova-system-head` para encabezados operativos y metricas.
- Mantencion, EMACH y Telegram agregan `.nova-system-hero` a sus heroes principales, incluyendo entrypoints legacy donde existen.

No cambiado:
- Sin cambios de rutas, base de datos, permisos ni logica de negocio.
- Se mantienen bloques `<style>` complejos existentes en Administracion/TIC/Mantencion cuando aun contienen layout local no trivial; quedan como deuda visual controlada.

Validacion S34:
- `php artisan test`: 47 passed, 1 skipped.
- `php artisan route:list`: OK, 60 rutas listadas.
- `npm run build`: OK, Vite 4.5.14 genero `public/build/manifest.json` y assets JS.
- Busqueda visual: aun existen bloques `<style>` locales complejos en Administracion, TIC nativo y varias vistas Mantencion; se documentan como deuda controlada porque incluyen layout especifico no trivial.

## Actualizacion S34 Credenciales personales - 2026-06-18

Decision: las credenciales de plataformas integradas son personales y las administra cada usuario desde el modulo correspondiente.

Cambios aplicados:
- Administracion NOVA y Usuarios NOVA ya no muestran campos para editar credenciales EMACH ni Chat ID Telegram de otros usuarios; solo informan que las integraciones son personales.
- `NovaUserRepository::save()` preserva credenciales existentes, pero no acepta payload administrativo para EMACH/Telegram.
- Mantencion Usuarios ya no permite crear/editar token Redmine API, CORE ni Nextcloud desde la ficha administrativa de otro usuario; conserva indicadores de configurado/pendiente.
- `redmine-mantencion/controllers/usuarios.php` crea usuarios sin secretos y en actualizaciones preserva credenciales existentes.
- EMACH Mantenedor queda reemplazado por configuracion personal de integraciones; el monitor global queda separado como configuracion tecnica.
- Se eliminaron helpers de payload administrativo sin uso en `UserIntegrationRepository` para evitar reintroducir escritura de secretos desde administracion.

Riesgo restante:
- Redmine TIC/Mantencion y CORE ya tienen flujos parciales de autoservicio, pero conviene consolidarlos en una vista "Mis integraciones" para que el usuario tenga un unico lugar para Redmine API, CORE, Nextcloud, EMACH y Telegram.

## Actualizacion S35 Integraciones de usuario - 2026-06-18

Estado: **READY WITH MINOR FIXES**.

Cambios aplicados:
- Nueva pagina compartida `resources/views/nova/integrations/user-config.blade.php` para credenciales personales, con estilo Redmine TIC.
- Nuevo `UserIntegrationController` para EMACH, Redmine Mantencion y Redmine TIC.
- Rutas nuevas:
  - `GET/POST /emach/configuracion`
  - `GET/POST /redmine-mantencion/app/mis-integraciones`
  - `GET/POST /redmine_tic/app/mis-integraciones`
- El mantenedor legacy de EMACH fue eliminado; la URL antigua `/emach/views/Mantenedor/mantenedor.php` redirige a `/emach/configuracion`.
- `integraciones_usuario` se mantiene como unica tabla para secretos personales: `emach`, `redmine_mantencion`, `core`, `nextcloud`, `redmine_tic` y `tic_personal`.
- Mantencion `core_credentials.php` ahora lee/escribe CORE y Nextcloud desde `integraciones_usuario` cuando hay usuario central, dejando el JSON legacy solo como fallback.
- Redmine TIC mantiene `redmine_tic` como tipo de API key y evita escribir `chat_id` en `integraciones_usuario` cuando la columna ya no existe.

Seguridad:
- Las paginas guardan/eliminan solo credenciales del usuario autenticado; el `usuario_id` se resuelve desde sesion NOVA.
- Los secretos se cifran con `encrypt()`.
- No se muestran passwords ni API keys en claro; solo estado guardado/pendiente y usuario externo enmascarado.

Migraciones:
- No se crearon migraciones ni tablas nuevas.

## Resumen ejecutivo

NOVA es una plataforma Laravel 12 que centraliza autenticacion, administracion y acceso a modulos nativos/legacy: Redmine TIC, Redmine Mantencion, Telegram y EMACH. El proyecto esta en una etapa de transicion correcta hacia BD central, especialmente con `usuarios_nova` como identidad unica y tablas de permisos/integraciones.

La revision priorizo seguridad, errores criticos, rendimiento, arquitectura y UI/UX. Se aplicaron mejoras inmediatas de seguridad y rendimiento sin eliminar funcionalidades: throttling en login y extension de sesion, cabeceras HTTP defensivas, validacion de importaciones JSON y optimizacion de lectura de integraciones de usuario.

Estado verificado:

- Migraciones aplicadas.
- BD activa con 16 tablas tras normalizacion inicial de Mantencion.
- Conteos principales al 2026-06-13: `usuarios_nova=58`, `permisos_usuario_modulo=69`, `integraciones_usuario=69`, `redmine_tic_reportes=727`, `redmine_mantencion_reportes=275`, `categorias=500`, `unidades=616`, `horas_extras=25`, `redmine_mantencion_storage=19`.
- Pruebas Laravel: 5 pasadas.

## Comprension del proyecto

NOVA funciona como shell Laravel y centro de identidad. Los modulos se registran en `config/modules.php`, y el acceso se resuelve por sesion NOVA, roles globales y permisos por modulo.

Componentes principales:

- Laravel HTTP: `routes/web.php`, `public/index.php`.
- Login y sesion: `NovaAuthController`, `EnsureNovaAuthenticated`.
- Administracion: `NovaAdministrationController`, vistas `resources/views/nova/admin`.
- Identidad central: `usuarios_nova`, `integraciones_usuario`, `modulos_nova`, `permisos_usuario_modulo`.
- Redmine TIC: capa nativa Laravel con `RedmineDataRepository` y vista `redmine_tic::native`.
- Redmine Mantencion: modulo legacy servido por `LegacyProjectController`, con storage en BD mediante `RedmineMantencionStorageRepository`.

## Hallazgos QA

- Cobertura automatizada baja: solo 5 pruebas y principalmente unitarias simples. Falta cobertura para login, permisos por modulo, importaciones, sincronizacion Redmine, guard legacy y flujos criticos de administracion.
- No hay `npm test` ni `npm lint`; solo existe `npm run build`.
- Los flujos legacy tienen validaciones CSRF propias y heterogeneas. Algunas vistas validan, pero el riesgo debe probarse con matriz de endpoints POST.
- La matriz de permisos central ya devuelve el mismo universo de usuarios que `usuarios_nova`, evitando repoblacion desde JSON.

## Hallazgos de codigo

- Los controladores Laravel coordinan razonablemente, pero `RedmineDataRepository` y controladores legacy concentran mucha logica de negocio.
- Existian consultas repetidas de integraciones por cada usuario en `NovaUserRepository::usersFromDatabase`; se optimizo a carga por lote.
- Hay codigo privado muerto o residual en repositorios (`projectUserExistsInRows`, `novaProjectUserExists`, rutas historicas JSON en comandos de reparacion). No rompe runtime, pero aumenta ruido y riesgo de reactivacion accidental.
- La configuracion de administracion aun usa algunos JSON locales para ajustes no identitarios (`settings.json`, auditoria, Telegram/EMACH). Esto no contradice la identidad central, pero debe migrarse gradualmente si se quiere DB-only total.

## Hallazgos de base de datos

Tablas activas:

- `usuarios_nova`
- `integraciones_usuario`
- `modulos_nova`
- `permisos_usuario_modulo`
- `redmine_tic_reportes`
- `redmine_tic_perfiles_usuario`
- `redmine_tic_horas_extra_grupos`
- `redmine_tic_activity_logs`
- `redmine_mantencion_storage`
- `redmine_mantencion_reportes`
- `categorias`
- `unidades`
- `horas_extras`
- `catalogos_modulo`
- `configuraciones_modulo`
- `migrations`

Fortalezas:

- `usuarios_nova` tiene claves unicas para `uuid`, `usuario`, `rut` y `redmine_id`.
- `permisos_usuario_modulo` tiene unique compuesto por usuario/modulo.
- `redmine_tic_reportes` tiene indices por modulo/estado, fecha, redmine_id, origen y asignado.

Riesgos:

- Las migraciones originales de Laravel siguen marcadas como ejecutadas aunque tablas como `users`, `failed_jobs` y `personal_access_tokens` fueron eliminadas por migracion posterior. Es coherente con la decision del proyecto, pero debe quedar documentado para despliegues nuevos.
- `redmine_mantencion_storage` guarda payloads JSON/texto; es DB, pero sigue modelando archivos por `path`. Ya existe primera normalizacion hacia `redmine_mantencion_reportes`, `categorias`, `unidades` y `horas_extras`, pero el codigo legacy aun usa el storage puente.
- Auditoria contra el diseno objetivo propuesto: no existen `users` ni `redmine_tic_usuarios`, por lo que esos duplicados ya no estan presentes. Las tablas que no calzan con el modelo final (`redmine_mantencion_storage`, `catalogos_modulo`, `configuraciones_modulo`, `redmine_tic_perfiles_usuario`, `redmine_tic_horas_extra_grupos`, `redmine_tic_activity_logs`) siguen siendo usadas por codigo activo y no deben eliminarse sin migracion previa.
- Datos Mantencion parcialmente normalizados: se migraron `reportes/YYYY/*.json` a `redmine_mantencion_reportes`, `categorias.json`/catalogos a `categorias`, `unidades.json`/catalogos a `unidades`, y `horasExtras/YYYY/*.json` a `horas_extras`. Quedan en `redmine_mantencion_storage` configuracion, usuarios legacy, logs y soporte puente mientras las vistas/controladores se adaptan.
- `catalogos_modulo` queda como tabla activa de compatibilidad para TIC, pero sus categorias/unidades ya fueron copiadas a tablas explicitas. Se recomienda migrar `RedmineDataRepository` a `categorias`/`unidades` antes de borrar `catalogos_modulo`.
- `redmine_tic_horas_extra_grupos` guarda agrupaciones por fecha y las referencias a reportes viven en el pivot `redmine_tic_horas_extra_grupo_reportes`; `report_ids` ya no existe tras S33. El diseno objetivo de horas extra por proyecto/usuario/ticket/hora inicio/hora termino/cantidad queda como refactor futuro de datos/UI.

## Hallazgos de arquitectura

- La direccion correcta es identidad central + proyecciones por modulo.
- `LegacyProjectController` restringe roots y normaliza paths, reduciendo riesgo de path traversal.
- El puente legacy sigue siendo una superficie amplia: ejecuta PHP historico dentro del proceso Laravel y requiere reglas de seguridad claras.
- Redmine TIC ya esta mayoritariamente en arquitectura nativa; Mantencion todavia depende de procedural PHP.

## Hallazgos de seguridad

Aplicado:

- Throttling en `POST /login` y `POST /session/extend` con `throttle:5,1`.
- Limites de longitud para credenciales de login y extension de sesion.
- Middleware global `SecurityHeaders` con `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` y `X-Permitted-Cross-Domain-Policies`.
- Validacion de importaciones de respaldo Redmine: archivo requerido, maximo 5 MB y extension `.json`.

Riesgos pendientes:

- `VerifyCsrfToken` excluye rutas de modulos legacy completos. Aunque muchos formularios usan `legacy_csrf_token`, se requiere auditoria endpoint por endpoint para cerrar excepciones sin romper callbacks externos.
- `GET /logout` sigue disponible por compatibilidad con enlaces existentes. Deberia migrarse a POST con formulario/JS progresivamente.
- Algunos modulos legacy escriben logs/configs en archivos locales; revisar permisos y exposicion por Apache.
- La autenticacion acepta compatibilidad con passwords legacy y tokens API como password en algunos flujos. Debe migrarse a hashes obligatorios y tokens solo para API.

## Hallazgos UI/UX

- La UI usa Bootstrap/Bootstrap Icons de forma consistente.
- La administracion tiene alta densidad de informacion, apropiada para herramienta operacional.
- Hay estilos inline extensos en Blade; esto complica mantenimiento y pruebas visuales.
- Logout ya fue migrado progresivamente a botones/form POST en NOVA, TIC, Mantencion y EMACH; mantener esa convencion en nuevas vistas.
- La paleta es profesional, aunque varias pantallas usan gradientes azulados similares. A futuro conviene consolidar tokens visuales en `assets/nova-ui.css`.

## Archivos modificados

### Sesion 1 — auditoria inicial
- `app/Http/Middleware/SecurityHeaders.php` — NUEVO, cabeceras HTTP defensivas.
- `app/Http/Kernel.php` — registro global de `SecurityHeaders`.
- `routes/web.php` — throttling en `/login` y `/session/extend`.
- `app/Http/Controllers/NovaAuthController.php` — limites de longitud en credenciales.
- `redmine_tic/nova/app/Http/Controllers/RedmineDashboardController.php` — validacion importacion JSON.
- `app/Support/Auth/NovaUserRepository.php` — carga en lote de integraciones.

### Sesion 2 — P0 Seguridad
- `resources/views/nova/home.blade.php` — logout a form POST con CSRF; bloque `<style>` eliminado.
- `resources/views/nova/modules/index.blade.php` — logout a form POST.
- `resources/views/nova/admin/index.blade.php` — logout a form POST.
- `resources/views/nova/users/index.blade.php` — logout a form POST.
- `resources/views/nova/telegram/index.blade.php` — logout a form POST.
- `resources/views/nova/partials/session-control.blade.php` — JS logout usa form POST dinamico.
- `app/Support/Auth/NovaUserRepository.php` — token API en login interactivo requiere `$allowApiToken=true`.
- `app/Support/Auth/LegacyUserProvider.php` — firma `attempt()` propagada con `$allowApiToken`.

### Sesion 2 — P1 Errores criticos
- `tests/Feature/AuthTest.php` — NUEVO, 14 tests de autenticacion y sesion.
- `tests/Feature/ModuleAccessTest.php` — NUEVO, 11 tests de acceso a modulos.
- `routes/console.php` — `redmine:mantencion-repair-user-names` restringido con `--write-json`.

### Sesion 2 — P2 Rendimiento
- `app/Support/Modules/ModuleRegistry.php` — `state()` cacheada 5 min, invalidada en escritura.
- `database/migrations/2026_06_12_100001_add_composite_indexes_for_performance.php` — NUEVO, indices compuestos.

### Sesion 2 — P3 Arquitectura
- `app/Support/Auth/NovaUserRepository.php` — ~15 metodos privados JSON-era eliminados.
- `app/Support/Nova/NovaAccessRepository.php` — 2 metodos privados muertos eliminados.

### Sesion 2 — P4 UI/UX
- `public/assets/nova-ui.css` — 480 lineas de estilos de home migradas.
- `resources/views/nova/home.blade.php` — bloque `<style>` de 480 lineas eliminado.

### Sesion 3 — UI/UX Visual Estandarizacion
- `redmine_tic/nova/resources/views/native.blade.php` — logout GET→POST con CSRF; page loader JS agregado.
- `redmine-mantencion/views/partials/navbar.php` — logout GET→POST con CSRF; page loader HTML+JS implementado (usa `.app-page-loader` de theme.css con `appUi.setLoading`).
- `public/assets/nova-ui.css` — componentes globales nuevos: `.nova-page-loader`, `.nova-integration-overlay`, `.nova-integration-card`, `.nova-empty-state`, `.nova-integration-status`, `.nova-toast` (completo), `.nova-spinner`, `.nova-alert-warning/info`, `.rm-empty-state`.
- `redmine-mantencion/views/Integraciones/NextcloudHistorial.php` — empty state visual mejorado con `.nova-empty-state`.
- `redmine-mantencion/views/Usuarios/usuarios.php` — empty state agregado cuando no hay usuarios.
- `redmine-mantencion/views/Dashboard/dashboard.php` — empty state visual agregado cuando no hay mensajes.
- `redmine-mantencion/views/Historico/historico.php` — empty state mejorado con `.nova-empty`.
- `redmine_tic/nova/resources/views/native-sections/dashboard.blade.php` — empty state mejorado con `.nova-empty`.
- `redmine_tic/nova/resources/views/native-sections/history.blade.php` — empty state mejorado con `.nova-empty`.
- `redmine_tic/nova/resources/views/native-sections/hours.blade.php` — empty state mejorado con `.nova-empty`.
- `redmine_tic/nova/resources/views/native-sections/users.blade.php` — empty state mejorado con `.nova-empty`.

### Sesion 4 — Rendimiento TIC + Unificacion Visual Global
- `redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php` — memoizacion de `activeReports()`, `archivedReports()` y `configuration()` mediante propiedades de instancia (`$activeReportsCache`, `$archivedReportsCache`, `$configurationCache`); debounce de 5 minutos para `archiveExpiredProcessedReports()` via `Cache::put`; `saveActiveReports()` y `archiveReport()` invalidan los caches correspondientes; `forProject()` resetea todos los caches al cambiar de proyecto.
- `public/assets/nova-ui.css` — seccion "NOVA Unified Design System": gradiente unificado para `nav.sb-navbar` (Mantencion igual a TIC), gradiente mejorado para `card.card-hero` y `.rm-hero`, `.rm-hero-icon` y `.sb-brand-mark` estandarizados, seccion nav de Mantencion mejorada, estados de reporte estandarizados (`.nova-estado-*`), tipografia y jerarquia visual unificada, mejoras de tablas, dropdowns y inputs, scrollbar refinado, mejoras mobile para hero y seccion nav.

### Sesion 6 — Migracion Laravel 9 → 12
- `composer.json` — `config.platform.php` corregido de `8.0.30` a `8.2.12`; versiones actualizadas progresivamente en 3 etapas (L9→L10→L11→L12); `spatie/laravel-ignition` removido (incompatible con L12).
- `app/Http/Kernel.php` — `$routeMiddleware` renombrado a `$middlewareAliases` (L10 breaking change).
- `phpunit.xml` — `<coverage>` actualizado a `<source>` (formato PHPUnit 10/11).
- `MIGRACION_LARAVEL_12.md` — NUEVO, documento completo del proceso con tablas de versiones, problemas y soluciones.

### Sesion 5 - Inspeccion visual NOVA + EMACH
- `public/assets/nova-ui.css` - capa "NOVA/EMACH visual hardening": reglas especificas para `.emach-hero`, `.telegram-hero` y `.card.card-hero`; contraste reforzado en textos `text-white-50`, botones `btn-outline-light` sobre navbars oscuros, pills de estado y layout responsive de `card-body p-4 d-flex align-items-center justify-content-between gap-3 flex-wrap`.
- `emach/views/partials/navbar.php` - logout GET reemplazado por form POST con token CSRF; el modal de expiracion de sesion ahora cierra sesion enviando un form POST dinamico.

### Sesion 7 - Sistema visual global NOVA
- `public/assets/nova-ui.css` - nueva seccion "NOVA Visual System" basada en Redmine TIC: variables globales, gradiente de marca, navbar global, hero reutilizable, nav seccional, cards/paneles, tablas, formularios, botones, badges, alertas, modales, toasts, indicadores de carga, responsive desktop/tablet/mobile y clases especificas para login, modulos, usuarios y Telegram.
- `resources/views/nova/auth/login.blade.php` - bloque `<style>` inline eliminado; usa `.nova-login-page`, `.login`, `.login-hero`, `.login-mark` desde `nova-ui.css`.
- `resources/views/nova/modules/index.blade.php` - bloque `<style>` inline eliminado; tabla marcada como `.modules-table` para responsive global.
- `resources/views/nova/users/index.blade.php` - bloque `<style>` inline eliminado; filtros, tablas, modales, formularios, badges y toasts dependen de `nova-ui.css`.
- `resources/views/nova/telegram/index.blade.php` - bloque `<style>` inline eliminado; topbar, hero, cards, tablas, metricas y logs dependen de `nova-ui.css`.
- `resources/views/nova/partials/session-control.blade.php` - bloque `<style>` inline eliminado; badge y modal de sesion dependen del sistema visual global.
- `telegram/views/partials/navbar.php` - logout GET reemplazado por form POST con token CSRF.
- `telegram/views/partials/session-control.php` - cierre desde modal de sesion convertido a form POST dinamico.
- `redmine-mantencion/views/partials/navbar.php` - cierre desde modal de sesion convertido a form POST dinamico.

### Sesion 8 - Navbar y hero global con estilo TIC
- `public/assets/nova-ui.css` - navbar global ajustado al estilo Redmine TIC: fondo `#102033`, borde inferior redondeado, sombra profunda y brand mark translucido. Hero global ajustado a `linear-gradient(135deg, #1f4f7e, #244a75)` con circulo decorativo, borde 0, radio 12px y tabs activas `#2563eb`.

### Sesion 10 - Responsividad global
- `public/assets/nova-ui.css` - nueva capa final "NOVA Global Responsive Layer": evita overflow horizontal, normaliza contenedores, navbars, acciones, tabs con scroll horizontal, heroes, grids, tablas, modales, formularios y botones para desktop, tablet, movil y pantallas angostas.

### Sesion 9 - Design System Global — completar unificacion de modulos
- `public/assets/nova-ui.css` - nueva seccion "Skeleton Loading + Connecting State": componentes `.nova-skeleton`, `.nova-skeleton-line` (variantes `is-short/is-medium/is-full`), `.nova-skeleton-box`, `.nova-skeleton-avatar`, `.nova-skeleton-stat`, animacion shimmer via `nova-skeleton-wave`; componentes `.nova-connecting`, `.nova-connecting-dots` con animacion `nova-dot-bounce` y variante `is-light` para fondos oscuros; componente `.nova-card-loading` con barra de progreso superior animada.
- `emach/views/partials/navbar.php` - agregado `<div class="app-page-loader" id="app-page-loader">` con JS unificado que usa `window.appUi.setLoading()`; EMACH ahora comparte el mismo patron de page loader que Redmine Mantencion.
- `telegram/views/partials/navbar.php` - agregado `<div class="app-page-loader" id="app-page-loader">` con el mismo JS de page loader; Telegram ahora tiene page loader en toda la navegacion.

### Sesion 11 - Auditoria BD contra diseno objetivo
- `ANALISIS_WEB.md` - actualizados conteos reales de BD, mapeo entre diseno objetivo y tablas actuales, riesgos de tablas activas fuera del modelo final y plan de normalizacion.
- `AGENTS.md` - agregado estandar de modelo de datos objetivo y regla para no eliminar tablas activas sin migracion, backup y adaptacion de repositorios.

### Sesion 12 - Normalizacion inicial de BD
- `storage/app/backups/nova_pre_db_normalization_20260613_134316.sql` - respaldo SQL completo antes de cambios de estructura/datos.
- `database/migrations/2026_06_12_100001_add_composite_indexes_for_performance.php` - corregidos indices pendientes para usar columnas reales de `redmine_tic_reportes`: `modulo_id`, `estado`, `fecha` y `asignado_a`.
- `database/migrations/2026_06_13_134500_normalize_redmine_mantencion_data.php` - NUEVO, crea `categorias`, `unidades`, `redmine_mantencion_reportes` y `horas_extras`; migra datos desde `catalogos_modulo` y `redmine_mantencion_storage`.
- `AGENTS.md` - actualizado estado de transicion BD con tablas normalizadas nuevas.
- `ANALISIS_WEB.md` - actualizados conteos, tablas activas, mejoras aplicadas, backlog y verificacion.

### Sesion 13 - Reporte manual TIC y dashboard compacto
- `redmine_tic/nova/resources/views/native-sections/webhook.blade.php` - formulario manual ampliado con fecha inicio, fecha fin, fecha reporte, hora, chat ID Telegram y mensaje corto; selector "Asignar a" ahora usa usuarios activos del proyecto y envia `redmine_id` numerico, dejando el usuario logueado como valor por defecto.
- `redmine_tic/nova/app/Http/Controllers/RedmineDashboardController.php` - validacion de los nuevos campos manuales (`fecha_inicio`, `fecha_fin`, `fecha`, `hora`, `chat_id_telegram`, `mensaje`) y fallback de asignado usando `redmine_id` antes que ID interno NOVA.
- `redmine_tic/nova/resources/views/native-sections/dashboard.blade.php` - tabla de solicitudes activas compactada en columnas agrupadas para mostrar ticket, solicitud, fechas, clasificacion, solicitante/unidad, asignado, estado y acciones sin depender de zoom del navegador; modal conserva `mensaje` real.
- `public/assets/nova-ui.css` - estilos `.rm-dashboard-table` para tabla TIC densa, truncado controlado, lineas secundarias y botones compactos.

### Sesion 14 - Asignables TIC por acceso de modulo
- `redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php` - `projectUsersFromNova()` ahora lista usuarios desde `permisos_usuario_modulo` con permiso activo para el modulo actual; `redmine_tic_perfiles_usuario` solo enriquece esos usuarios y ya no agrega asignables sin acceso.
- `AGENTS.md` - documentada regla de asignacion: los selectores TIC deben listar solo usuarios con acceso efectivo al modulo.

### Sesion 15 - Estilo tabla dashboard TIC
- `redmine_tic/nova/resources/views/native-sections/dashboard.blade.php` - tabla de solicitudes activas ajustada al estilo solicitado: encabezados compactos en mayuscula, columnas lineales `Redmine ID`, `Asunto`, `Solicitante`, `Fecha creacion`, `Tipo solicitud`, `Unidad`, `Unidad solicitante`, `Asignado core`, `Estado local` y `Acciones`.
- `public/assets/nova-ui.css` - `.rm-dashboard-table` actualizado para replicar grilla clara tipo reporte: filas alternadas, header azul claro, asunto destacado, estado como icono circular y acciones compactas.

### Sesion 16 - Backfill asignado TIC manual
- `database/migrations/2026_06_13_141500_backfill_redmine_tic_manual_assignee.php` - NUEVO, completa `asignado_a` en reportes TIC manuales antiguos que estaban en `NULL`, usando un usuario con acceso efectivo al modulo y `redmine_id` numerico; prioriza `redmine_id=42` si existe y tiene permiso.

### Sesion 17 - Normalizacion campos TIC y retiro `datos_extra`
- `storage/app/backups/nova_pre_drop_redmine_tic_datos_extra_20260613_180452.sql` - respaldo SQL completo antes de eliminar la columna `datos_extra`.
- `database/migrations/2026_06_13_180500_normalize_redmine_tic_report_dates.php` - NUEVO, agrega columnas reales `fecha_inicio`, `fecha_fin`, `chat_id_telegram` y `mensaje` a `redmine_tic_reportes`, migra valores desde `datos_extra` y elimina la columna JSON.
- `database/migrations/2026_06_11_000002_create_redmine_tic_database_tables.php` - actualizada para instalaciones limpias: crea `fecha_inicio`, `fecha_fin`, `chat_id_telegram` y `mensaje`; ya no crea `datos_extra`.
- `redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php` - lectura/escritura de reportes TIC actualizada para usar columnas reales y no `datos_extra`.
- `AGENTS.md` - documentado que `redmine_tic_reportes` no debe volver a usar `datos_extra` como bolsa JSON.

### Sesion 18 - Archivado como estado de reporte
- `storage/app/backups/nova_pre_archive_estado_normalization_20260613_181456.sql` - respaldo SQL completo antes de eliminar columnas de archivado.
- `database/migrations/2026_06_13_181500_archive_reports_as_estado.php` - NUEVO, migra reportes TIC con `archivado_at` a `estado = archivado`, elimina `archivado_at`/`archivado_por` en TIC y Mantencion si existen, y crea indice `idx_reportes_modulo_estado_fecha`.
- `database/migrations/2026_06_11_000002_create_redmine_tic_database_tables.php` - instalaciones limpias ya no crean columnas `archivado_at` ni `archivado_por`.
- `database/migrations/2026_06_12_100001_add_composite_indexes_for_performance.php` - indice compuesto corregido de `modulo/archivado/fecha` a `modulo/estado/fecha`.
- `database/migrations/2026_06_13_180500_normalize_redmine_tic_report_dates.php` - rollback ajustado para no depender de `archivado_at`.
- `redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php` - consultas activas filtran `estado <> archivado`, historico filtra `estado = archivado`, y archivar reportes actualiza `estado` sin campos auxiliares.
- `AGENTS.md` - documentado que `redmine_tic_reportes` y `redmine_mantencion_reportes` representan archivado solo con `estado = archivado`.

### Sesion 19 - Telegram en usuarios y renombre campo TIC
- `storage/app/backups/nova_pre_telegram_chat_id_normalization_20260613_183338.sql` - respaldo SQL completo antes de mover datos de Telegram y renombrar columna TIC.
- `database/migrations/2026_06_13_183500_move_telegram_chat_to_users_and_rename_tic_number.php` - NUEVO, agrega `usuarios_nova.telegram_id_chat`, migra cualquier `integraciones_usuario.tipo=telegram`, elimina esas filas legacy y renombra `redmine_tic_reportes.numero` a `chat_id_telegram`.
- `database/migrations/2026_06_09_000001_create_usuarios_nova_table.php` - instalaciones limpias crean `telegram_id_chat` en `usuarios_nova`.
- `database/migrations/2026_06_11_000002_create_redmine_tic_database_tables.php` - instalaciones limpias crean `chat_id_telegram` en `redmine_tic_reportes` en vez de `numero`.
- `database/migrations/2026_06_13_180500_normalize_redmine_tic_report_dates.php` - migracion historica ajustada para usar `chat_id_telegram`.
- `app/Models/NovaUser.php`, `app/Support/Auth/NovaUserRepository.php`, `app/Support/Integrations/UserIntegrationRepository.php` - lectura/escritura de Chat ID Telegram movida a `usuarios_nova.telegram_id_chat`; las integraciones Telegram legacy se eliminan al guardar.
- `redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php`, `RedmineDashboardController.php`, vistas `dashboard.blade.php` y `webhook.blade.php` - reportes TIC usan `chat_id_telegram`; se mantiene compatibilidad de entrada con `numero` solo como alias antiguo.
- `redmine-mantencion/controllers/auth.php` - usuarios Mantencion leen `telegram_id_chat` desde `usuarios_nova`.
- `AGENTS.md` - documentado que `telegram_id_chat` es dato central del usuario y que TIC usa `chat_id_telegram`.

### Sesion 20 - Simplificacion perfiles usuario TIC
- `storage/app/backups/nova_pre_drop_redmine_tic_roles_20260613_184720.sql` - respaldo SQL completo antes de eliminar `redmine_roles`.
- `database/migrations/2026_06_13_185000_drop_redmine_tic_roles_from_profiles.php` - NUEVO, elimina la columna `redmine_roles` de `redmine_tic_perfiles_usuario`.
- `database/migrations/2026_06_12_000001_move_redmine_tic_users_to_profiles.php` y `database/migrations/2026_06_11_000002_create_redmine_tic_database_tables.php` - instalaciones limpias ya no crean `redmine_roles`.
- `redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php` - sincronizacion/listado de usuarios TIC ya no lee ni escribe `redmine_roles`.
- `AGENTS.md` - documentado que `redmine_tic_perfiles_usuario` conserva solo `rol`, `estado_usuario`, `permisos` y `redmine_membership_id`; no debe guardar `redmine_roles`.

### Sesion 21 - Estado usuario TIC preservado en importaciones
- `redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php` - sincronizacion Redmine e importacion de usuarios desde respaldo ahora crean usuarios nuevos con `estado_usuario = baneado` por defecto y conservan `estado_usuario` cuando el perfil ya existe; siguen actualizando nombre/apellido, acceso, token, permisos, rol y `redmine_membership_id` cuando corresponde.
- `redmine_tic/nova/resources/views/native-sections/users.blade.php` - columna renombrada a `Estado TIC` y badge visual con variantes `activo`/`baneado` para que el estado sea visible en el listado.
- `AGENTS.md` - documentado que `redmine_membership_id` es solo referencia tecnica de membresia Redmine y no debe modificar permisos/estado NOVA.

### Sesion 22 - TIC usa solo id de reporte
- `storage/app/backups/nova_pre_drop_redmine_tic_local_id_20260613_201630.sql` - respaldo SQL completo antes de eliminar `local_id`.
- `database/migrations/2026_06_13_191500_use_report_id_in_redmine_tic_reports.php` - NUEVO, migra `redmine_tic_horas_extra_grupos.report_local_ids` a `report_ids`, mapea referencias antiguas a `redmine_tic_reportes.id`, elimina `report_local_ids`, elimina indice `uq_reporte_modulo_local` y borra `redmine_tic_reportes.local_id`.
- `database/migrations/2026_06_11_000002_create_redmine_tic_database_tables.php` - instalaciones limpias ya no crean `local_id`; horas extra TIC crea `report_ids`.
- `redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php` - acciones, creacion manual/Telegram, historico, archivado y horas extra usan el `id` numerico del reporte; nuevos reportes se insertan directo para obtener el ID autoincremental real.
- `AGENTS.md` - documentado que el identificador operativo de reportes TIC es solo `redmine_tic_reportes.id` y que no debe reintroducirse `local_id`.

### Sesion 23 - Vista usuarios TIC y simplificacion columna Estado local
- `redmine_tic/nova/resources/views/native-sections/users.blade.php` - columna RUT eliminada de la tabla (th + td); colspan actualizado de 6 a 5; columna renombrada a `Estado TIC`; badges de estado y rol ahora visibles con la clase `.nova-badge` y variantes `.is-success`/`.is-danger`/`.is-warning`; boton de eliminar reemplazado por modal de confirmacion `#delete-user-modal`; boton toggle banear/activar en columna de acciones con color `btn-warning` (activo→banear) o `btn-success` (baneado→activar); preservacion de posicion de scroll via `sessionStorage` al enviar formularios; boton flotante `#users-scroll-top` que aparece al salir del `aria-label="Resumen usuarios"` via `IntersectionObserver`.
- `redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php` - `deleteUser()` reescrito para eliminar realmente el perfil TIC y el acceso al modulo (borraba `estado_usuario` antes); nuevo metodo `toggleUserStatus()` que alterna `estado_usuario` entre `activo` y `baneado` en `redmine_tic_perfiles_usuario` y `usuarios_nova`.
- `redmine_tic/nova/app/Http/Controllers/RedmineDashboardController.php` - `userAction()` actualizado para manejar acciones `delete` y `toggle_status` usando los nuevos metodos del repositorio.
- `public/assets/nova-ui.css` - overrides de `.nova-badge` agregados al final del archivo con texto oscuro y variantes semanticas (`is-success`, `is-danger`, `is-warning`); dentro de heroes oscuros se restaura el blanco via selectores padre (`.rm-hero .nova-badge`, etc.); fix del bug donde `.nova-badge` en el bloque "NOVA Visual System" heredaba `color: #fff !important` globalmente.
- `redmine_tic/nova/resources/views/native-sections/dashboard.blade.php` - columna `Estado local` simplificada: se elimina el indicador condicional `.rm-dashboard-status.is-hours` (hora extra); queda solo el span `.rm-dashboard-status` principal con el icono de estado del reporte.

## Mejoras aplicadas

1. Seguridad: throttling en login y extension de sesion.
2. Seguridad: limites de longitud en credenciales para reducir abuso de payloads grandes.
3. Seguridad: cabeceras HTTP defensivas globales (`SecurityHeaders` middleware).
4. Seguridad: validacion de archivo de importacion de respaldo JSON (extension, tamano, formato).
5. Rendimiento: carga en lote de `integraciones_usuario` al listar usuarios NOVA.
6. Seguridad (P0): logout migrado a POST con CSRF en 6 vistas y JS de session-control.
7. Seguridad (P0): token API bloqueado en login interactivo; solo activo con `$allowApiToken=true`.
8. Errores criticos (P1): 25 Feature tests nuevos (AuthTest x14 + ModuleAccessTest x11), total 32 pasando.
9. Errores criticos (P1): comando de reparacion de nombres restringido al modo `--write-json`.
10. Rendimiento (P2): cache de estado de modulos 5 minutos con invalidacion en escritura.
11. Rendimiento (P2): indices compuestos en `redmine_tic_reportes`, `integraciones_usuario` y `usuarios_nova`.
12. Arquitectura (P3): ~17 metodos privados muertos eliminados de `NovaUserRepository` y `NovaAccessRepository`.
13. UI/UX (P4): bloque `<style>` de 480 lineas migrado de `home.blade.php` a `nova-ui.css`.
14. Seguridad (S3): logout GET→POST con CSRF en `native.blade.php` (TIC) y `navbar.php` (Mantencion).
15. UI/UX (S3): page loader activado en Mantencion (usa `.app-page-loader` de theme.css) y en TIC (`.nova-page-loader` de nova-ui.css).
16. UI/UX (S3): componentes visuales globales en `nova-ui.css`: page loader, integration overlay, empty state, integration status, toasts completos, spinner inline, `.rm-empty-state`.
17. UI/UX (S3): empty states mejorados en 8 vistas (NextcloudHistorial, Usuarios, Dashboard, Historico en Mantencion; dashboard, history, hours, users en TIC).
18. Rendimiento (S4): causa raiz TIC mas lento que Mantencion — `activeReports()` se llamaba 3-4 veces por request sin cache (727+ filas cada vez), `archiveExpiredProcessedReports()` ejecutaba un UPDATE en cada GET, y `configuration()` consultaba BD multiples veces por request. Solucion: memoizacion de instancia + debounce de 5 min via Cache Laravel.
19. UI/UX (S4): unificacion visual global via `nova-ui.css` — mismo gradiente navy→azul→teal para navbar TIC y Mantencion, hero card unificado con mejor gradiente profundo, hero icon estandarizado, estados de reporte (`.nova-estado-*`), tipografia/tablas/dropdowns/scrollbar refinados, mejoras mobile.
20. UI/UX (S5): inspeccion NOVA/EMACH y correccion de contraste/visibilidad en heroes y cards; se evita que `.card` global opaque fondos oscuros en `emach-hero`, `telegram-hero` y `card-hero`.
21. Seguridad (S5): logout de EMACH migrado a POST con CSRF, incluyendo el boton del modal de sesion.
22. Infraestructura (S6): migracion completa Laravel 9.52.21 → 12.62.0 en 3 etapas progresivas; PHP requerido ^8.2; PHPUnit 9→11; Sanctum 3→4; Collision 6→8; spatie/ignition removido; 32 tests pasando sin cambios en logica de negocio.

23. UI/UX (S7): sistema visual global unificado en `nova-ui.css` para NOVA, EMACH, Telegram, Redmine TIC, Redmine Mantencion, CORE/Nextcloud dentro de Administracion/Integraciones, Usuarios, Configuracion y Dashboard.
24. UI/UX (S7): componentes globales estandarizados: navbar, hero, tablas, formularios, cards, modales, botones, badges, alertas, toasts, estados de carga e indicadores para integraciones externas.
25. UI/UX (S7): eliminados bloques `<style>` inline duplicados en Login, Modulos, Usuarios, Telegram NOVA y control de sesion; se conservan bloques especificos complejos en TIC/Mantencion/Admin cuando contienen layout o comportamiento local no trivial.
26. Seguridad (S7): logout legacy de Telegram y modal de sesion de Mantencion migrados a POST con CSRF.
27. UI/UX (S8): navbar y hero globales igualados al estilo visual real de Redmine TIC mostrado en pantalla, para que NOVA, EMACH, Telegram, Mantencion, Usuarios, Configuracion y Dashboard compartan la misma cabecera y hero.
28. UI/UX (S10): capa responsive global para todas las vistas con `nova-ui.css`: navbars/tabs adaptativas, heroes compactos, grids a una columna, tablas con scroll horizontal, modales ajustados al viewport y botones/formularios sin desbordes.
28. UI/UX (S9): skeleton loading global disponible en todos los modulos via `.nova-skeleton*`; componente de animacion de conexion `.nova-connecting` + `.nova-connecting-dots`; card loading bar `.nova-card-loading` — todos en `nova-ui.css`.
29. UI/UX (S9): page loader (`app-page-loader`) agregado a EMACH y Telegram usando el patron unificado `window.appUi.setLoading()`; los cuatro modulos principales (TIC, Mantencion, EMACH, Telegram) tienen page loader activo en toda navegacion.

30. BD (S11): auditoria de tablas reales contra el diseno objetivo del usuario; confirmado que `users` y `redmine_tic_usuarios` no existen, y que las tablas fuera del modelo final son soporte activo o deuda tecnica documentada.
31. Arquitectura BD (S11): definido camino seguro de normalizacion: mantener `usuarios_nova` como identidad unica, separar reportes TIC/Mantencion, migrar Mantencion desde `redmine_mantencion_storage` a tablas nativas y convertir catalogos/horas extra a estructuras explicitas antes de borrar tablas puente.
32. BD (S12): creadas tablas normalizadas `redmine_mantencion_reportes`, `categorias`, `unidades` y `horas_extras`; datos migrados: 275 reportes Mantencion, 500 categorias, 616 unidades y 25 registros de horas extra.
33. Rendimiento/BD (S12/S18): corregida migracion de indices compuestos TIC para columnas reales; indices aplicados en `redmine_tic_reportes` para modulo/estado/fecha y modulo/asignado/estado.
34. TIC (S13/S19): reporte manual ahora captura fechas, hora, chat ID Telegram y mensaje corto; asignacion por defecto usa el usuario logueado cuando tiene `redmine_id`, y el select lista usuarios activos del proyecto con ID Redmine valido.
35. UI/UX (S13): dashboard TIC compactado para ver mas datos sin alejar la pantalla, agrupando campos relacionados y manteniendo edicion completa en el modal.
36. TIC (S14): usuarios asignables filtrados por acceso efectivo al modulo (`permisos_usuario_modulo.permitido=1`); los perfiles TIC ya no agregan usuarios sin permiso al selector.
37. UI/UX (S15): dashboard TIC alineado al estilo de tabla solicitado; se reemplazo `Establecimiento`/`Departamento` por `Unidad`/`Unidad solicitante`.
38. BD/TIC (S16): reparados reportes manuales historicos con `asignado_a IS NULL`; verificacion deja `manuales_sin_asignado=0`.
39. BD/TIC (S17/S19): `redmine_tic_reportes` normalizada: `fecha_inicio`, `fecha_fin`, `chat_id_telegram` y `mensaje` son columnas reales; `datos_extra` y la columna antigua `numero` fueron eliminados.
40. BD/TIC/Mantencion (S18): archivado normalizado como valor de `estado`; `redmine_tic_reportes` ya no tiene `archivado_at`/`archivado_por`, y `redmine_mantencion_reportes` conserva el mismo contrato sin columnas auxiliares.
41. BD/Usuarios (S19): Chat ID de Telegram movido a `usuarios_nova.telegram_id_chat`; `integraciones_usuario` ya no guarda filas `tipo=telegram`.
42. BD/TIC (S20): `redmine_tic_perfiles_usuario` simplificada; se elimina `redmine_roles` porque no controla permisos ni flujos activos.
43. TIC (S21): importaciones/sincronizaciones de usuarios preservan `estado_usuario` si el perfil existe; usuarios nuevos importados quedan `baneado` por defecto.
44. BD/TIC (S22): eliminado `local_id` de `redmine_tic_reportes`; el modulo usa solo `id` autoincremental y `redmine_tic_horas_extra_grupos` referencia reportes mediante `report_ids`.
45. UI/UX TIC (S23): vista usuarios TIC simplificada — columna RUT eliminada, badges de estado y rol visibles, modal de confirmacion para eliminar, boton toggle banear/activar con color segun estado actual.
46. UI/UX TIC (S23): fix CSS `.nova-badge` — overrides al final de `nova-ui.css` con texto oscuro y variantes semanticas; dentro de heroes oscuros se restaura blanco via selector padre.
47. UI/UX TIC (S23): scroll preservado en vista usuarios via `sessionStorage`; boton flotante volver arriba con `IntersectionObserver` sobre el resumen de usuarios.
48. UI/UX TIC (S23): columna `Estado local` del dashboard simplificada — solo el indicador `.rm-dashboard-status` principal; eliminado el indicador `.is-hours` de hora extra de esa columna.
49. Arquitectura (Step5A): 11 clases movidas a namespaces canonicos `App\Repositories\*` / `App\Services\*`; `TelegramService` creado como unico punto de entrada Telegram; `StringNormalizer` centraliza 4 implementaciones duplicadas de `normalize()`; `LegacyLoggerService` encapsula `require_once` de logger legacy.
50. Arquitectura (Step6): desacoplamiento Core→TIC via `ProjectUserProviderInterface`; `ProjectAccessGuard` ya no importa ninguna clase `RedmineTic\`; `NovaUser::perfilTic()` (HasOne sin uso) eliminada; binding condicional en `AppServiceProvider`; 6576 clases en autoload.
51. Seguridad (Production Hardening): `.env.example` actualizado con defaults seguros para produccion; `config/app.php` timezone configurable via `APP_TIMEZONE`; `GET /logout` eliminado, solo POST; `LegacyProjectController` resuelve fallback `logout.php` con form POST auto-submitting; `X-Frame-Options: SAMEORIGIN` agregado a `SecurityHeaders`; comandos de despliegue, backup, restauracion y usuario BD documentados en `AGENTS.md`.
49. BD/TIC (S25 Phase 3a): 3 tablas relacionales de permisos creadas y pobladas (`redmine_tic_permisos_catalogo`, `redmine_tic_permisos_rol`, `redmine_tic_permisos_usuario`); dual-write activo en `saveProjectUsers()` y `saveRolesToDatabase()`; lectura primaria relacional con fallback al JSON original; columna `permisos` JSON y fila `roles` en `configuraciones_modulo` preservadas sin cambios destructivos.
50. BD/TIC (S26 Validación Phase 3a): detectado y corregido bug de backfill (41/43 perfiles omitidos por `permisos="[]"`); migración backfill aplicada; 1591 filas correctas; comando `nova:validate-phase3a` (17/17 checks) y 16 tests PHPUnit (48/48 suite) pasados; Phase 3c aprobada para planificar.
51. BD/Normalización (S27 Phase 3c): DROP COLUMN `permisos` de `redmine_tic_perfiles_usuario` y DELETE clave=`roles` de `configuraciones_modulo`; Phase 3 completa — 100% de permisos en tablas relacionales sin fallback JSON.
52. BD/Normalización (S27 Limpieza): datos operacionales históricos eliminados de 6 tablas; config Mantención migrada a `configuraciones_modulo` (39 claves, modulo_id=2); `redmine_mantencion_storage` reducido a 2 entradas activas; Phase 2 (migraciones 110000–110002) ejecutadas; suite 47/47 + 1 skipped contextual.
53. BD/Schema (S28): DROP TABLE `horas_extras` — tabla puente de S12 confirmada sin lectores/escritores activos, vaciada en S27, columna `reporte_local_id` huérfana; tablas TIC (`redmine_tic_horas_extra_grupos` + pivot) sin cambios.
54. BD/Schema (S29): DROP 7 columnas legacy de `redmine_mantencion_reportes` — `local_id`, `source_path` (artefactos migración S13), `proyecto`, `project_id`, `tipo_id`, `priority_id` (derivables de `configuraciones_modulo` modulo_id=2), `unidad_nombre` (viola 3FN, derivable via FK); schema queda en 24 columnas; `redmine_mantencion_storage.payload_json` activo y conservado. Ver `LIMPIEZA_REDMINE_MANTENCION_DB.md`.

### Sesion 24 - Normalización JSON BD + Configuración Mantención en BD

- `NORMALIZACION_DB.md` — NUEVO, análisis completo de columnas JSON: 8 detectadas, 3 justificadas como JSON válido, 5 candidatas a normalización en 2 fases.
- `database/migrations/2026_06_14_100000_create_horas_extra_grupo_reportes_pivot.php` — NUEVO, Phase 1a: tabla pivot `redmine_tic_horas_extra_grupo_reportes` (121 filas desde 37 grupos); `report_ids` preservado.
- `database/migrations/2026_06_14_100001_promote_predeterminado_in_catalogs.php` — NUEVO, Phase 1b: columna `predeterminado` en `categorias` y `unidades` promovida desde `datos_extra`; columna `datos_extra` preservada.
- `database/migrations/2026_06_14_100002_create_modulo_opciones.php` — NUEVO, Phase 1c: tabla `modulo_opciones` con 12 filas (3 trackers, 5 prioridades, 4 estados de TIC); `configuraciones_modulo` preservada.
- `app/Support/RedmineMantencion/MantencionConfigRepository.php` — NUEVO, repositorio Laravel para leer/escribir configuración de Mantención en `configuraciones_modulo` (modulo_id=2, tipado string/int/bool/json).
- `redmine-mantencion/controllers/storage.php` — agregado `config_mantencion_repository()` helper disponible para todos los controladores legacy.
- `redmine-mantencion/controllers/configuracion.php` — `load_config` lee de `configuraciones_modulo` primero (migración automática en primer acceso); `save_config` escribe a ambas tablas (dual-write).
- `redmine-mantencion/controllers/dashboard.php` — `load_platform_config` / `save_platform_config` usan el mismo patrón dual-write.

Conteos Phase 1 verificados: `redmine_tic_horas_extra_grupo_reportes=121`, `modulo_opciones=12`, `categorias.predeterminado` poblado, 32 tests pasando.

### Sesion 25 — Phase 3a Normalización permisos no destructiva

- `database/migrations/2026_06_14_120000_phase3a_create_permisos_tables.php` — NUEVO, crea `redmine_tic_permisos_catalogo` (37 claves), `redmine_tic_permisos_rol` (~148 filas desde configuraciones_modulo + defaultRoles) y `redmine_tic_permisos_usuario` (~1.591 filas desde perfiles existentes). `down()` elimina en orden FK-safe. Codificación de valores: claves scope → string literal; resto → `si`/`no`.
- `redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php` — constante `PERMISSION_SCOPE_KEYS`; `defaultRoles()` expandido de 9 a 37 claves; 8 métodos privados nuevos (encode/decode, batch-load, upsert relacional para permisos y roles); `rolesFromDatabase()` con dual-read (relacional primero, fallback JSON); `saveRolesToDatabase()` con dual-write; `projectUsersFromNova()` con batch-load de permisos relacionales (1 query para todos los perfiles); `saveProjectUsers()` con dual-write a tabla relacional.

Reglas Phase 3a: JSON `permisos` y `configuraciones_modulo` clave=`roles` preservados. Lectura primaria desde tablas relacionales con fallback automático al JSON si tablas ausentes o vacías. Tests: 27 pasados (52 assertions). Ver `PERMISOS_NORMALIZACION.md` sección 12 para estado completo.

### Sesion 26 — Validación Phase 3a y corrección de backfill

- `database/migrations/2026_06_14_120000_phase3a_create_permisos_tables.php` — CORREGIDO, `populateUserPermissions()` ahora construye set completo de 37 claves por rol (merge defaults + existing JSON); el error saltaba 41/43 perfiles con `permisos="[]"`.
- `database/migrations/2026_06_14_120001_phase3a_backfill_user_permissions.php` — NUEVO, backfill para instancia ya migrada; inserta las 37 claves para todos los perfiles usando su `rol` como fuente de defaults; idempotente (upsert).
- `app/Console/Commands/ValidatePhase3aPermisos.php` — NUEVO, comando `nova:validate-phase3a` con 7 secciones y 17 verificaciones: existencia de tablas, conteos catálogo, permisos_usuario (por perfil y total), permisos_rol (por rol), consistencia JSON↔relacional, lectura via repositorio con reflection, consistencia dual-write de roles.
- `tests/Feature/Phase3aPermissionsTest.php` — NUEVO, 16 tests PHPUnit contra BD real; cubre existencia de tablas, conteos catálogo, tipos correctos, cobertura 43 perfiles × 37 claves, 5 roles × 37 claves, consistencia JSON, lectura via reflection, dual-write via reflection.
- `VALIDACION_PHASE3A_PERMISOS.md` — NUEVO, informe completo de validación: error detectado, corrección aplicada, resultados de 17 checks + 16 tests, veredicto Phase 3c.

Resultado S26: `redmine_tic_permisos_usuario` = 1591 filas (43×37 exactas). `nova:validate-phase3a` 17/17. `php artisan test` 48/48. Phase 3c aprobada para planificar.

### Sesion 27 — Limpieza y normalización final de BD

- `database/migrations/2026_06_15_000000_phase3c_drop_permisos_json.php` — NUEVO, Phase 3c: DROP COLUMN `permisos` de `redmine_tic_perfiles_usuario` (reemplazado por `redmine_tic_permisos_usuario`); DELETE clave=`roles` de `configuraciones_modulo` (reemplazado por `redmine_tic_permisos_rol`). Guarda no-op si la columna/fila ya no existe.
- `database/migrations/2026_06_15_000001_migrate_mantencion_config_to_db.php` — NUEVO, copia configuración de Mantención desde `redmine_mantencion_storage` path=`configuracion.json` a `configuraciones_modulo` (modulo_id=2, 39 filas); excluye claves de caché transitoria (`nextcloud_cached_groups`, `nextcloud_cached_groups_at`, `core_last_sync`, `core_last_error`).
- `database/migrations/2026_06_15_000002_cleanup_operational_data.php` — NUEVO, limpieza operacional: vacía `redmine_tic_reportes` (729), `redmine_mantencion_reportes` (275), `redmine_tic_horas_extra_grupos` (37), `redmine_tic_horas_extra_grupo_reportes` (121), `horas_extras` (25), `redmine_tic_activity_logs` (5); limpia `redmine_mantencion_storage` dejando solo `configuracion.json` y `roles.json`.
- `tests/Feature/Phase3aPermissionsTest.php` — ACTUALIZADO, `test_json_and_relational_values_match_for_sample_profiles` detecta que la columna `permisos` fue eliminada (Phase 3c) y se marca como `markTestSkipped` con nota contextual.
- `ESTADO_DB_NORMALIZACION.md` — NUEVO, informe completo de normalización: 13 tablas conservadas, 6 tablas vaciadas, 6 columnas eliminadas, 4 filas JSON eliminadas, datos de configuración protegidos, migraciones ejecutadas y riesgos pendientes.

Resultado S27: Phase 2 (110000–110002) + Phase 3c + config Mantencion a BD + limpieza operacional completadas. `configuraciones_modulo` = 59 filas (modulo_id=1: 20, modulo_id=2: 39). `redmine_mantencion_storage` = 2 filas. `php artisan test` 47/47 + 1 skipped contextual. Ver `ESTADO_DB_NORMALIZACION.md`.

### Sesion 28 — DROP tabla huérfana `horas_extras`

- `database/migrations/2026_06_15_100000_drop_horas_extras_orphaned_table.php` — NUEVO, DROP TABLE `horas_extras`; confirmado: 0 lectores/escritores activos en runtime (referencias son array keys y función de filesystem, no DB queries); `reporte_local_id` apuntaba a `local_id` eliminado en S22; tabla vacía desde S27. `down()` recrea la estructura exacta original.
- `HORAS_EXTRA_MODELO.md` — ACTUALIZADO, estado final: DROP ejecutado, tablas TIC (`redmine_tic_horas_extra_grupos` + pivot) intactas.

Resultado S28: `horas_extras` eliminada (14ms). Total tablas BD: 21. `php artisan test` 47/47 + 1 skipped. Modelo TIC grupos+pivot sin cambios.

### Sesion 29 — Limpieza `redmine_mantencion_reportes`: DROP 7 columnas legacy

- `database/migrations/2026_06_15_200000_cleanup_mantencion_reportes_columns.php` — NUEVO, DROP 7 columnas de `redmine_mantencion_reportes`: `local_id` (artefacto migración), `source_path` (artefacto migración), `proyecto` (derivable de config), `project_id` (derivable de config), `tipo_id` (derivable de config), `priority_id` (derivable de config), `unidad_nombre` (derivable via FK `unidad_id → unidades.nombre`, viola 3FN). Confirmado: ningún código activo lee `redmine_mantencion_reportes`; la vista histórico usa `storage_json_by_prefix()`, no la tabla. Tabla vacía desde S27. `down()` restaura las 7 columnas e índices originales.
- `LIMPIEZA_REDMINE_MANTENCION_DB.md` — NUEVO, análisis completo: columnas actuales, columnas usadas, candidatas a eliminar, comparación con `redmine_tic_reportes`, análisis de `payload_json`, schema final.

Resultado S29: 7 columnas eliminadas de `redmine_mantencion_reportes` (114ms). Schema queda con 24 columnas. `payload_json` de `redmine_mantencion_storage` sigue activo (bridge configuracion.json + roles.json). `php artisan test` 47/47 + 1 skipped. Sin regresiones.

### Sesion 30 — Eliminación JSON de negocio/runtime (DB-only total)

Objetivo: NOVA sin JSON de negocio ni runtime, usando solo Laravel MVC + MariaDB + Bootstrap.

**Archivos JSON eliminados (stale, sin lector activo):**
- `storage/app/nova/access.json` — stale, acceso ya en `permisos_usuario_modulo`.
- `redmine_tic/data/estadisticas_api_cache.json` — cache migrado a `configuraciones_modulo`.
- `redmine_tic/data/estadisticas_manual.json` — estadísticas huérfanas sin lector runtime.
- `emach/data/usuarios.json` — sombra stale de `usuarios_nova`.

**Migraciones S30 (4 migraciones aplicadas, 164–231ms):**
- `2026_06_15_300000_create_nova_system_tables.php` — crea `nova_audit_logs` (event/message/user_id/user_name/ip/contexto JSON/registrado_at) y `nova_settings` (clave UNIQUE/valor/tipo/actualizado_at); importa y elimina audit.json y settings.json si existían.
- `2026_06_15_300001_add_module_state_columns.php` — agrega `habilitado` (bool, default true) y `en_mantencion` (bool, default false) a `modulos_nova`; importa y elimina state.json.
- `2026_06_15_300002_create_mantencion_permisos_rol.php` — crea `mantencion_permisos_rol` (rol/permiso/valor, unique compuesto); seeds desde `redmine_mantencion_storage` payload_json de roles.json (o filesystem fallback, o 4 roles hardcoded); elimina la fila roles.json de `redmine_mantencion_storage`.
- `2026_06_15_300003_drop_mantencion_storage_json_bridges.php` — elimina filas configuracion.json y roles.json de `redmine_mantencion_storage`; tabla queda vacía.

**Tablas nuevas S30:** `nova_audit_logs`, `nova_settings`, `mantencion_permisos_rol`. Total tablas BD: 24.

**Código PHP actualizado:**
- `app/Support/Nova/NovaAuditRepository.php` — reescrito: usa `nova_audit_logs`; max 500 entradas; `record()` / `recent()` sin I/O de archivo.
- `app/Support/Nova/NovaSettingsRepository.php` — reescrito: usa `nova_settings`; defaults hardcoded (session_timeout/notification_enabled/health_warning_threshold); cast tipado (bool/int/string).
- `app/Support/Nova/NovaNotificationService.php` — actualizado: inyecta `NovaSettingsRepository`; reemplaza `file_get_contents(settings.json)` con `$this->settings->all()['notification_enabled']`.
- `app/Support/Modules/ModuleRegistry.php` — actualizado: `state()` lee `modulos_nova.habilitado/en_mantencion` (Schema::hasColumn guard para backwards compat); `saveState()` actualiza esas columnas; `maintenanceState()` lee `configuraciones_modulo` directamente en vez de `redmine_mantencion_storage`.
- `app/Support/Integrations/TelegramCommandSettingsRepository.php` — reescrito: usa `configuraciones_modulo` para `clave_modulo='telegram'`; seed desde filesystem en primer acceso; sin `storage_path('app/telegram/command_settings.json')` como destino de escritura.
- `telegram/bin/listen.php` — actualizado: `telegram_user_by_chat_id()` usa `usuarios_nova.telegram_id_chat` vía Laravel DB; eliminadas funciones obsoletas de lectura de JSON de usuarios.
- `redmine-mantencion/controllers/auth.php` — actualizado: `auth_config_timeout()` lee `session_timeout` de `MantencionConfigRepository`; `auth_load_roles()` lee desde `mantencion_permisos_rol` (primario) con fallback a filesystem roles.json; `auth_roles_file()` eliminada.
- `redmine-mantencion/controllers/nextcloud.php` — actualizado: `nextcloud_config_load()` / `nextcloud_config_save()` usan solo `MantencionConfigRepository`; eliminada variable global `$GLOBALS['NEXTCLOUD_CONFIG_FILE']`.
- `redmine-mantencion/controllers/onlyoffice.php` — actualizado: `onlyoffice_config()` usa `MantencionConfigRepository`.
- `redmine-mantencion/controllers/configuracion.php` — actualizado: `save_config()` ya no llama `storage_write_json()`; `configuraciones_modulo` es la única fuente de verdad.
- `redmine-mantencion/controllers/dashboard.php` — actualizado S33: `dashboard_catalog_names()` y `load_name_map()` usan `MantencionCatalogRepository`; no hay fallback runtime a `categorias.json`/`unidades.json`.

**JSON operativo pendiente (documentado, no implementado en S30):**
- `nextcloud_created_history.json` → requiere tabla `redmine_mantencion_nextcloud_historial` (complejo, defer).
- `procedimientos/index.json` → sistema de gestión de procedimientos (complejo, defer).
- `telegram/data/config.json` → migrar a `configuraciones_modulo` (Docker-sensitive, defer).
- `redmine_tic/data/*.json` — archivos de importación histórica; stale si el comando de importación ya no es necesario.
- `redmine-mantencion/data/backups/` — datos de backup archival; conservar.
- `redmine-mantencion/data/usuarios.json` — interceptado a DB por `storage_read_json()`; archivo es carta muerta.

**Resultado S30:** 4 migraciones aplicadas, 11 archivos PHP actualizados, 4 JSON stale eliminados. `nova_audit_logs`, `nova_settings`, `mantencion_permisos_rol` creadas. `redmine_mantencion_storage` vacía. `php artisan test` 47/47 + 1 skipped. Sin regresiones.

### Sesion 31 — Auditoría y Normalización Completa de Base de Datos

Objetivo: inventario completo de las 24 tablas, detección de objetos muertos verificada en código, corrección de bugs críticos de escritura/lectura, drops seguros y schema fixes.

**Migración S31 aplicada (`2026_06_16_000000_s31_drop_dead_columns_and_tables` — 387ms):**
- DROP COLUMN `usuarios_nova.email` — 0/58 filas pobladas, nunca en queries de runtime.
- DROP COLUMN `integraciones_usuario.metadata` — 0/69 filas pobladas, nunca escrita ni leída.
- DROP COLUMN `integraciones_usuario.chat_id` — 0/69 filas pobladas; Telegram migrado a `usuarios_nova.telegram_id_chat` en S19.
- DROP COLUMN `modulos_nova.activo` — escrita en INSERT pero nunca leída; `habilitado` es el path real.
- DROP TABLE `_nova_column_backups` — artifact de migración S25 (1456 filas de backup de columnas), sin lector en runtime.
- FIX `configuraciones_modulo.actualizado_at` → agrega `ON UPDATE CURRENT_TIMESTAMP` (faltaba en DDL original).
- FIX `nova_audit_logs.contexto` → cambia `longtext` → `json` para validación a nivel BD.

**Bugs críticos corregidos:**
- `RedmineDataRepository::saveProjectUsers()` — eliminada escritura a `permisos` (columna eliminada en Phase 3c; cualquier sync de usuarios fallaba con `Unknown column 'permisos'`).
- `RedmineDataRepository::registerOrUpdateModule()` — `'activo' => 1` cambiado a `'habilitado' => 1`.
- `NovaAccessRepository::databaseModuleId()` — `'activo' => !empty(...)` cambiado a `'habilitado' => !empty(...)`.
- `NovaHealthRepository::checks()` — `fileCheck(settings.json)` (archivo eliminado S30) reemplazado por `settingsCheck()` que consulta tabla `nova_settings`.
- `NovaHealthRepository::nextcloudCheck()` — `RedmineMantencionStorageRepository::readJson('configuracion.json')` (bridge eliminado S30) reemplazado por `MantencionConfigRepository::loadAll()`.

**Objetos confirmados activos (no eliminados):**
- `usuarios_nova.usuario_core` — 0/58 pobladas PERO referenciada en `UserIntegrationRepository` y `NovaAccessRepository` para identidad CORE; no eliminar.
- `redmine_mantencion_storage` — 1 fila activa `path=security.log`; escrita por `storage_append_line()` en logger de Mantención.
- `catalogos_modulo` — activa con FK desde `redmine_tic_reportes`; migración a `categorias`/`unidades` diferida a S32-B.

**Documento generado:** `AUDITORIA_DB_COMPLETA.md` con inventario de 7 fases, hallazgos por severidad (C/A/M/B), roadmap S32/S33 y criterios de completitud.

Resultado S31: 1 tabla eliminada, 4 columnas eliminadas, 2 schema fixes, 5 bugs críticos corregidos. Total tablas BD: 23. `php artisan test` 47/47 + 1 skipped. Sin regresiones.

### Sesion 32 — Hardening de Esquema y Eliminación de Dependencias JSON Residuales

Objetivo: parchar los 3 bloqueantes críticos identificados en auditoría S32 y aplicar P1 de hardening.

**Migración S32-FINAL aplicada (`2026_06_17_000000_s32_schema_hardening` — 57ms):**
- C3: `redmine_tic_reportes.estado` — backfill NULL→'pendiente'; MODIFY NOT NULL DEFAULT 'pendiente'. Confirmado: `databaseReportPayload()` siempre setea `estado` explícitamente.
- P1: `redmine_tic_reportes.hora_extra` — backfill NULL→0; MODIFY NOT NULL DEFAULT 0. Confirmado: `databaseReportPayload()` siempre setea `hora_extra` como 0 o 1.
- P1: CREATE INDEX `idx_audit_user_date` ON `nova_audit_logs(user_id, registrado_at)` — índice compuesto para queries de auditoría por usuario.
- P1: DROP INDEX `idx_integraciones_tipo` de `integraciones_usuario` — duplicado por `uq_integracion_usuario_tipo` (compuesto usuario_id+tipo, UNIQUE).
- P1: ON UPDATE CURRENT_TIMESTAMP en `redmine_tic_reportes.actualizado_at`, `permisos_usuario_modulo.actualizado_at`, `modulos_nova.actualizado_at`, `integraciones_usuario.actualizado_at`.
- P1: Seed de `nova_settings` con 3 defaults base: `session_timeout=3600`, `notification_enabled=0`, `health_warning_threshold=1`.

**Bugs críticos corregidos:**
- C1a: `emach/bin/monitor.php::emach_monitor_nova_users()` — leía `storage/app/nova/users.json` a pesar de tener Laravel bootstrapped. Reemplazado con JOIN `usuarios_nova` + `integraciones_usuario(tipo=emach)` via `DB::table()`.
- C1b: `emach/index.php` — eliminadas `emach_nova_users_path()`, `emach_read_nova_users()`, `emach_write_nova_users()`, `emach_find_current_user_index()`, `emach_encrypt_secret()`, `emach_decrypt_secret()`; fallbacks en `emach_current_user_credentials()` y `emach_save_current_user_credentials()` reemplazados por retorno vacío/false.
- C2: `app/Support/Nova/NovaBackupRepository.php` — `targets()` apuntaba a `settings.json` (eliminado S30). Reescrito: `type='db_table'`, método privado `backupDbTable()` exporta `nova_settings` a JSON en directorio de backups datado.
- Archivo eliminado: `storage/app/nova/users.json`.

Resultado S32-FINAL: 3 bugs críticos corregidos, 6 schema fixes, 1 índice creado, 1 índice duplicado eliminado, `nova_settings` seedeada. `php artisan test` 47/47 + 1 skipped. Sin regresiones.

### Sesion Step5A — Namespace Normalizacion

- `app/Support/StringNormalizer.php` — NUEVO, `static normalize(string): string`; centraliza `strtolower(preg_replace(...))`.
- `app/Services/Nova/LegacyLoggerService.php` — NUEVO, wraps `require_once logger.php` + `log_security_event()`.
- `app/Services/Telegram/TelegramService.php` — NUEVO, unico punto de entrada Telegram.
- 11 clases movidas a `app/Repositories/` y `app/Services/` (ver tabla de Step 5A arriba).
- 11 archivos origen eliminados despues de la migracion.
- 9 archivos de controladores/rutas/middleware actualizados con nuevos imports.
- `telegram/bin/listen.php` — FQN strings actualizados a nuevos paths canonicos.
- `tests/Unit/NovaAuditRepositoryTest.php` — import actualizado.
- `app/Http/Controllers/NovaAuthController.php` — inyecta `LegacyLoggerService`; usa `NovaAuditRepository` para `extendSession()`.
- `app/Http/Controllers/TelegramController.php` — reescrito; inyecta `TelegramService`; todos los `TelegramLibrary::load()` y `telegram_*()` reemplazados.
- `app/Http/Controllers/NovaAdministrationController.php` — inyecta `TelegramService`; 3 `TelegramLibrary::load()` reemplazados.

Resultado Step5A: 6574 clases, **47 passed + 1 skipped**, 66 rutas, 32 batches. Sin regresiones.

### Sesion Step6 — Core/TIC Decoupling

- `app/Contracts/ProjectUserProviderInterface.php` — NUEVO, interfaz Core `projectUsers(string $projectKey): array`.
- `redmine_tic/nova/app/Services/Redmine/RedmineProjectUserProvider.php` — NUEVO, implementacion TIC condicionada a `projectKey='redmine_tic'`.
- `app/Providers/AppServiceProvider.php` — `register()` vincula interfaz a implementacion TIC con `class_exists` guard.
- `app/Services/Nova/ProjectAccessGuard.php` — `use RedmineTic\...` removido; `findProjectUser()` usa `app()->bound()` pattern.
- `app/Models/NovaUser.php` — `perfilTic()` HasOne eliminada; import `Illuminate\...\HasOne` eliminado.
- `composer dump-autoload` — 6576 clases (2 nuevas vs Step5A).

Resultado Step6: **47 passed + 1 skipped** (119 assertions), 66 rutas, 32 batches. Cero imports `RedmineTic\` en archivos `app/` de runtime.

## Backlog priorizado

### P0 Seguridad

- Auditar todas las rutas POST legacy excluidas de CSRF Laravel y cerrar excepciones con una capa central compatible con `legacy_csrf_token`. *(pendiente)*
- [COMPLETADO] Migrar logout a POST y reemplazar enlaces `<a href=logout>` por formularios/botones con CSRF, incluyendo NOVA, TIC, Mantencion y EMACH.
- [COMPLETADO Production Hardening] `GET /logout` eliminado — ruta es ahora solo `POST /logout`. `LegacyProjectController` maneja el fallback `logout.php` con form auto-submitting POST+CSRF.
- [COMPLETADO] Eliminar autenticacion por token API como password interactiva; mantener tokens solo para integraciones.
- [COMPLETADO Production Hardening] `X-Frame-Options: SAMEORIGIN` agregado a `SecurityHeaders` middleware.
- Revisar permisos de escritura en `storage`, `redmine-mantencion/data`, `telegram/data` y logs. *(pendiente)*
- Revisar `npm audit` del frontend: `npm install` reporto 3 vulnerabilidades (1 baja, 2 altas). No se aplico `npm audit fix --force` porque puede introducir cambios incompatibles. *(pendiente)*
- [COMPLETADO Production Hardening] `.env.example` actualizado con valores correctos para produccion: `APP_ENV=production`, `APP_DEBUG=false`, `APP_NAME=NOVA`, `LOG_LEVEL=error`, `DB_DATABASE=nova`, timezone. Agregar manualmente los valores reales antes de desplegar.
- [COMPLETADO Production Hardening] `config/app.php` timezone → `env('APP_TIMEZONE', 'America/Santiago')`.
- Configurar backup externo via cron (`mysqldump`). Comando documentado en `AGENTS.md`. *(pendiente — accion DBA/admin)*
- Implementar Content-Security-Policy. Requiere auditoria de scripts/estilos inline en modulos legacy. *(pendiente — ver CSP Recommendation Report en seccion Production Hardening)*
- Configurar usuario de BD no-root (`nova_app`). Sentencias SQL documentadas en `AGENTS.md`. *(pendiente — accion DBA)*

### P1 Errores criticos

- [COMPLETADO] Agregar tests de Feature para login, sesion expirada, bloqueo de usuario, permisos por modulo y acceso a modulos.
- [COMPLETADO] Revisar comandos de reparacion que aun escriben `usuarios.json` historico y restringirlos claramente a modo migracion.
- Validar que todos los flujos de alta/edicion de usuario mantengan apellido obligatorio. *(pendiente)*
- [COMPLETADO] Completar campos obligatorios de negocio en reporte manual TIC: fechas, hora, chat ID Telegram, mensaje y asignacion por usuario del proyecto.
- [COMPLETADO] Restringir asignacion TIC a usuarios con acceso al modulo actual.
- [COMPLETADO] Reparar reportes TIC manuales antiguos con `asignado_a` nulo.
- [COMPLETADO] Eliminar `datos_extra` de `redmine_tic_reportes` y migrar campos relevantes a columnas reales.
- [COMPLETADO] Eliminar columnas auxiliares de archivado; el historico de TIC/Mantencion usa `estado = archivado`.
- [COMPLETADO] Mover Chat ID Telegram a `usuarios_nova.telegram_id_chat` y renombrar `redmine_tic_reportes.numero` a `chat_id_telegram`.
- [COMPLETADO] Eliminar `redmine_roles` de `redmine_tic_perfiles_usuario`; la tabla se mantiene para rol/estado/permisos propios de TIC.
- [COMPLETADO] Preservar `estado_usuario` al sincronizar/importar usuarios TIC existentes y dejar nuevos importados como `baneado`.
- [COMPLETADO] Eliminar `local_id` de `redmine_tic_reportes` y migrar acciones/horas extra para usar solo `id`.

### P2 Rendimiento

- [PARCIAL] Normalizar entidades frecuentes de Mantencion fuera de `redmine_mantencion_storage`: tablas creadas y columnas legacy limpiadas (S29); falta adaptar lecturas/escrituras del modulo para usar `redmine_mantencion_reportes`, `categorias` y `unidades` como fuente viva (la tabla ya tiene schema limpio de 24 columnas).
- [COMPLETADO S27] Normalizar columnas JSON de BD: Phase 1, Phase 2, Phase 3a y Phase 3c completadas. Todas las columnas JSON reemplazadas eliminadas. Datos operacionales históricos limpiados. Ver `ESTADO_DB_NORMALIZACION.md`.
- [COMPLETADO] Crear tablas nativas iniciales para Mantencion (`redmine_mantencion_reportes`, `categorias`, `unidades`, `horas_extras`) y migrar datos existentes desde el storage puente.
- [COMPLETADO] Agregar indices compuestos por consultas reales en reportes: modulo/estado/fecha, modulo/asignado/estado.
- [COMPLETADO] Cachear configuracion de modulo con invalidacion en escritura.
- [COMPLETADO] Memoizar activeReports/archivedReports/configuration en RedmineDataRepository; debounce de archiveExpiredProcessedReports. TIC tenia 3-4 full scans de redmine_tic_reportes por request en dashboard.

### P3 Arquitectura

- [PARCIAL Step5A] Extraer servicios desde controladores hacia `app/Services/*` y `app/Repositories/*`; 11 clases normalizadas a namespaces canonicos. Pendiente: clases legacy en `App\Support\*` con FQN strings en runtime legacy.
- [PARCIAL Step6] Reducir responsabilidad de `RedmineDataRepository`: desacoplado de `ProjectAccessGuard` via `ProjectUserProviderInterface`. Cleanup de metodos individuales (nativeSectionData, estadisticas, reportes webhook) diferido — alto riesgo sin tests de integracion. *(pendiente)*
- Mover configuraciones globales restantes desde JSON local a tablas dedicadas. *(pendiente)*
- Revisar nombres fisicos de tablas contra el contrato objetivo: `modulos_nova` equivale a `modulos`, `permisos_usuario_modulo` equivale a `modulos_usuarios`, `integraciones_usuario` equivale a `usuario_integracion`. Mantener nombres actuales mientras el codigo los use, o renombrar solo con migracion compatible. *(pendiente)*
- Migrar `RedmineDataRepository` y Mantencion legacy a repositorios que lean/escriban `categorias`, `unidades`, `redmine_mantencion_reportes` y `horas_extras`; despues retirar `catalogos_modulo`, `redmine_tic_horas_extra_grupos` y `redmine_mantencion_storage` si ya no tienen consumidores. *(pendiente)*
- [COMPLETADO S25 Phase 2] Eliminar columnas JSON originales: `report_ids` en `redmine_tic_horas_extra_grupos` (migrado a pivot, código actualizado), `datos_extra` en `categorias`/`unidades`/`redmine_mantencion_reportes`/`horas_extras`, filas tipo=json de trackers/prioridades/estados en `configuraciones_modulo`. Ver `NORMALIZACION_DB.md` sección 5.
- [COMPLETADO S27 Phase 3c] Normalizar `redmine_tic_perfiles_usuario.permisos` y `configuraciones_modulo` clave=`roles` a tablas relacionales: Phase 3a (3 tablas, dual-write) + Phase 3c (DROP COLUMN permisos, DELETE clave=roles) completadas. Diseño y estado en `PERMISOS_NORMALIZACION.md` y `ESTADO_DB_NORMALIZACION.md`.
- [COMPLETADO] Eliminar codigo muerto residual en `NovaUserRepository` y `NovaAccessRepository`.

### P4 UI/UX

- [COMPLETADO] Mover estilos inline grandes a `assets/nova-ui.css`.
- [COMPLETADO] Mejorar estados vacios y mensajes de error por accion — empty states en 8 vistas.
- [COMPLETADO] Convertir logout a botones/formularios con CSRF consistente — ahora cubre TIC y Mantencion tambien.
- [COMPLETADO] Agregar barras de carga para navegacion en ambos modulos.
- [COMPLETADO] Corregir contraste y visibilidad de heroes/cards en NOVA y EMACH, incluyendo `card-body p-4 d-flex align-items-center justify-content-between gap-3 flex-wrap`.
- [COMPLETADO] Compactar dashboard TIC para visualizar datos principales sin depender del zoom del navegador.
- [COMPLETADO] Aplicar estilo de grilla solicitado al dashboard TIC y renombrar columnas a `Unidad` y `Unidad solicitante`.
- Migrar bloque `<style>` inline de `native.blade.php` (697 lineas) a `nova-ui.css`. *(pendiente — bajo impacto dado que nova-ui.css ya sobreescribe las clases)*
- Resolver duplicacion de `:root` en `theme.css` (refactor de consolidacion). *(pendiente — no rompe funcionalidad)*

- [COMPLETADO] Crear sistema visual global basado en Redmine TIC para navbar, hero, tablas, formularios, cards, modales, botones, badges, alertas, toasts y estados de carga.
- [COMPLETADO] Agregar skeleton loading, connecting animation y card loading bar a `nova-ui.css`.
- [COMPLETADO] Agregar page loader a EMACH y Telegram para igualar a TIC y Mantencion.
- Migrar bloques `<style>` especificos grandes restantes (`native.blade.php`, secciones TIC avanzadas, Mantencion Procedimientos/Configuracion/Dashboard y Administracion) a archivos CSS modulares. *(pendiente controlado: la capa global ya normaliza apariencia, pero esos bloques contienen layouts complejos)*

## Plan de accion

1. Semana 1: cerrar CSRF legacy, migrar logout a POST y agregar tests de seguridad.
2. Semana 2: cubrir tests de usuarios/accesos y comandos de importacion.
3. Semana 3: normalizar configuraciones restantes y separar repositorios grandes.
4. Semana 4: limpieza UI, consolidacion CSS y pruebas visuales.

## Roadmap tecnologico

- Corto plazo: estabilizar seguridad de modulos legacy y ampliar cobertura PHPUnit.
- Mediano plazo: migrar Redmine Mantencion a controladores Laravel nativos por flujo.
- Mediano plazo: reemplazar JSON locales de configuracion por tablas versionadas.
- [COMPLETADO] Laravel 12, PHP 8.2+: proyecto corriendo en Laravel 12.62.0, PHP 8.2.12, PHPUnit 11.5. Jobs/queues para integraciones externas y auditoria en BD siguen pendientes.
- Largo plazo: pipeline CI con `composer validate`, `php artisan test`, `npm run build` y analisis estatico PHP.

## Verificacion realizada

- `php -l` en archivos PHP modificados.
- `C:/xampp/php/php.exe -l emach/views/partials/navbar.php`.
- `C:/xampp/php/php.exe -l telegram/views/partials/navbar.php`.
- `C:/xampp/php/php.exe -l telegram/views/partials/session-control.php`.
- `C:/xampp/php/php.exe -l redmine-mantencion/views/partials/navbar.php`.
- `rg` confirmo que no quedan `href`/redirect JS hacia logout GET en `telegram`, `emach`, `redmine-mantencion`, `resources/views/nova` ni `redmine_tic/nova/resources/views`.
- `php artisan migrate:status`.
- Consultas MariaDB de tablas, conteos e indices.
- Consultas MariaDB S11: `information_schema.TABLES`, `information_schema.COLUMNS`, conteos por tipo en `catalogos_modulo`, `configuraciones_modulo`, `integraciones_usuario`, estados/roles de `usuarios_nova`, estados de `redmine_tic_reportes` y paths de `redmine_mantencion_storage`.
- Respaldo pre-normalizacion creado: `storage/app/backups/nova_pre_db_normalization_20260613_134316.sql`.
- `C:/xampp/php/php.exe -l database/migrations/2026_06_12_100001_add_composite_indexes_for_performance.php` - OK.
- `C:/xampp/php/php.exe -l database/migrations/2026_06_13_134500_normalize_redmine_mantencion_data.php` - OK.
- `C:/xampp/php/php.exe artisan migrate --force` - migraciones S12 aplicadas.
- Conteos S12 verificados: `categorias=500`, `unidades=616`, `redmine_mantencion_reportes=275`, `horas_extras=25`, `redmine_tic_reportes=727`, `usuarios_nova=58`.
- `C:/xampp/php/php.exe artisan migrate:status` - todas las migraciones en estado `Ran`.
- `C:/xampp/php/php.exe -l redmine_tic/nova/app/Http/Controllers/RedmineDashboardController.php` - OK.
- `C:/xampp/php/php.exe -l redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php` - OK.
- Verificacion S15 con `rg`: dashboard TIC contiene encabezados `Redmine ID`, `Unidad solicitante`, clases `.rm-dashboard-table` y `.rm-dashboard-status`.
- `C:/xampp/php/php.exe -l database/migrations/2026_06_13_141500_backfill_redmine_tic_manual_assignee.php` - OK.
- `C:/xampp/php/php.exe artisan migrate --force` - backfill S16 aplicado.
- Consulta S16: reportes manuales TIC recientes tienen `asignado_a=42`; `manuales_sin_asignado=0`.
- Respaldo pre-eliminacion `datos_extra`: `storage/app/backups/nova_pre_drop_redmine_tic_datos_extra_20260613_180452.sql`.
- `C:/xampp/php/php.exe -l database/migrations/2026_06_13_180500_normalize_redmine_tic_report_dates.php` - OK.
- `C:/xampp/php/php.exe artisan migrate --force` - normalizacion S17 aplicada.
- Consultas S17: columna `datos_extra` ya no existe en `redmine_tic_reportes`; `fecha_inicio` con 728 registros, `fecha_fin` con 726 registros.
- `rg "datos_extra"` en runtime TIC solo encuentra referencias dentro de la migracion reversible S17; no quedan lecturas/escrituras runtime.
- Respaldo pre-normalizacion archivado: `storage/app/backups/nova_pre_archive_estado_normalization_20260613_181456.sql`.
- `C:/xampp/php/php.exe -l database/migrations/2026_06_13_181500_archive_reports_as_estado.php` - OK.
- `C:/xampp/php/php.exe artisan migrate --force` - normalizacion S18 aplicada.
- Consultas S18: `redmine_tic_reportes` y `redmine_mantencion_reportes` ya no tienen columnas `archivado%`; TIC queda con `estado=archivado` para 725 registros y `estado=pendiente` para 3 registros; indice activo `idx_reportes_modulo_estado_fecha`.
- Respaldo pre-normalizacion Telegram/TIC: `storage/app/backups/nova_pre_telegram_chat_id_normalization_20260613_183338.sql`.
- `C:/xampp/php/php.exe -l database/migrations/2026_06_13_183500_move_telegram_chat_to_users_and_rename_tic_number.php` - OK.
- `C:/xampp/php/php.exe artisan migrate --force` - normalizacion S19 aplicada.
- Consultas S19: `usuarios_nova.telegram_id_chat` existe; `redmine_tic_reportes.numero` ya no existe; `redmine_tic_reportes.chat_id_telegram` existe con 726 registros poblados; `integraciones_usuario.tipo=telegram` queda en 0 filas.
- Respaldo pre-eliminacion `redmine_roles`: `storage/app/backups/nova_pre_drop_redmine_tic_roles_20260613_184720.sql`.
- `C:/xampp/php/php.exe -l database/migrations/2026_06_13_185000_drop_redmine_tic_roles_from_profiles.php` - OK.
- `C:/xampp/php/php.exe artisan migrate --force` - normalizacion S20 aplicada.
- Consultas S20: `redmine_tic_perfiles_usuario.redmine_roles` ya no existe; la tabla conserva 43 perfiles con `rol`, `estado_usuario`, `permisos` y `redmine_membership_id`.
- `C:/xampp/php/php.exe -l redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php` - OK tras regla S21.
- `C:/xampp/php/php.exe artisan test`: 32 pruebas pasadas tras regla S21 (32 passed, 62 assertions).
- Respaldo pre-eliminacion `local_id`: `storage/app/backups/nova_pre_drop_redmine_tic_local_id_20260613_201630.sql`.
- `C:/xampp/php/php.exe -l database/migrations/2026_06_13_191500_use_report_id_in_redmine_tic_reports.php` - OK.
- `C:/xampp/php/php.exe artisan migrate --force` - normalizacion S22 aplicada.
- Consultas S22: `redmine_tic_reportes.local_id` ya no existe; `redmine_tic_horas_extra_grupos.report_local_ids` ya no existe; `report_ids` existe con 36 grupos y 119 referencias; `redmine_tic_reportes=728`; referencias invalidas en `report_ids=0`.
- `C:/xampp/php/php.exe artisan test`: 32 pruebas pasadas tras S22 (32 passed, 62 assertions).
- `C:/xampp/php/php.exe artisan test`: 32 pruebas pasadas en Laravel 12.62.0 (32 passed, 62 assertions).
- `npm install` para restaurar `vite` local y `npm run build`: build Vite exitoso.
- `composer audit`: 0 vulnerabilidades en Laravel 12.
- `php -l emach/views/partials/navbar.php` — OK (Sesion 9).
- `php -l telegram/views/partials/navbar.php` — OK (Sesion 9).
- Verificacion S23: `rg "rm-dashboard-status is-hours"` en `dashboard.blade.php` — sin resultados; el indicador de hora extra fue eliminado de la columna Estado local.
- Verificacion S23: `rg "nova-badge"` en `nova-ui.css` — overrides de texto oscuro y variantes semanticas presentes al final del archivo.
- `C:/xampp/php/php.exe -l database/migrations/2026_06_14_120000_phase3a_create_permisos_tables.php` - OK.
- `C:/xampp/php/php.exe -l redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php` - OK tras cambios Phase 3a.
- `C:/xampp/php/php.exe artisan test --filter="AuthTest|ModuleAccessTest"` - 27 passed (52 assertions), 1.99s. Sesion 25.
- Sesion 26 — Phase 3a validación: `populateUserPermissions()` corregida (saltaba 41/43 perfiles con `permisos="[]"`).
- `C:/xampp/php/php.exe artisan migrate --path=database/migrations/2026_06_14_120000_phase3a_create_permisos_tables.php` - Ran 950ms.
- `C:/xampp/php/php.exe artisan migrate --path=database/migrations/2026_06_14_120001_phase3a_backfill_user_permissions.php` - Ran 3s. 1591 filas insertadas.
- `C:/xampp/php/php.exe artisan nova:validate-phase3a` - APROBADO 17/17 verificaciones.
- `C:/xampp/php/php.exe artisan test` - 48 passed (120 assertions), 2.36s. Incluye 16 tests Phase3aPermissionsTest.
- Sesion 27 — Phase 2 + Phase 3c + limpieza operacional:
- `C:/xampp/php/php.exe artisan migrate --path=database/migrations/2026_06_14_110000_phase2_drop_datos_extra_columns.php` — OK 3s. datos_extra eliminado de categorias, unidades, redmine_mantencion_reportes, horas_extras.
- `C:/xampp/php/php.exe artisan migrate --path=database/migrations/2026_06_14_110001_phase2a_drop_report_ids.php` — OK 125ms. report_ids eliminado de redmine_tic_horas_extra_grupos.
- `C:/xampp/php/php.exe artisan migrate --path=database/migrations/2026_06_14_110002_phase2d_cleanup_json_config_rows.php` — OK 13ms. Filas trackers/prioridades/estados eliminadas de configuraciones_modulo.
- `C:/xampp/php/php.exe artisan migrate --path=database/migrations/2026_06_15_000000_phase3c_drop_permisos_json.php` — OK 18ms. columna permisos eliminada, fila roles eliminada.
- `C:/xampp/php/php.exe artisan migrate --path=database/migrations/2026_06_15_000001_migrate_mantencion_config_to_db.php` — OK 99ms. 39 filas insertadas en configuraciones_modulo para modulo_id=2.
- `C:/xampp/php/php.exe artisan migrate --path=database/migrations/2026_06_15_000002_cleanup_operational_data.php` — OK 326ms. 6 tablas vaciadas, 20 entradas storage eliminadas.
- Consultas S27: configuraciones_modulo=59 (modulo_id=1:20, modulo_id=2:39), redmine_mantencion_storage=2, todas las tablas operacionales=0.
- `C:/xampp/php/php.exe artisan test` — S27: 47 passed + 1 skipped (test JSON↔relacional contextual tras Phase 3c).
- Sesion 28 — DROP horas_extras:
- Grep confirmado: ningún `DB::table('horas_extras')` en código activo; referencias son array keys y función de filesystem.
- `C:/xampp/php/php.exe artisan migrate --path=database/migrations/2026_06_15_100000_drop_horas_extras_orphaned_table.php` — OK 14ms. Tabla eliminada.
- Consultas S28: horas_extras=NO EXISTE, redmine_tic_horas_extra_grupos=EXISTS, redmine_tic_horas_extra_grupo_reportes=EXISTS. Total tablas BD: 21.
- `C:/xampp/php/php.exe artisan test` — S28: 47 passed + 1 skipped.
- Sesion 29 — Limpieza `redmine_mantencion_reportes`:
- Grep confirmado: ningún código en `redmine-mantencion/` ni `app/` lee `redmine_mantencion_reportes` en runtime; historico.php usa `storage_json_by_prefix()`.
- `C:/xampp/php/php.exe artisan migrate --path=database/migrations/2026_06_15_200000_cleanup_mantencion_reportes_columns.php` — OK 114ms. 7 columnas eliminadas.
- Schema S33 `redmine_mantencion_reportes`: conserva el payload real Redmine/CORE y usa `unidad_texto` para la Unidad manual. `unidad_id` fue eliminado en `2026_06_18_020000_s33_drop_mantencion_reportes_unidad_id`.
- `redmine_mantencion_storage.payload_json`: activo, bridge para configuracion.json y roles.json. NO eliminar.
- `C:/xampp/php/php.exe artisan test` — S29: 47 passed + 1 skipped. Sin regresiones.
