# AGENTS.md

Guia rapida para agentes IA que trabajen en NOVA. Lee esto antes de explorar el repo completo.

## Stack y Versiones

- Backend principal: PHP 8.2 sobre **Laravel 12** (`laravel/framework ^12.0`, actualmente 12.62.0). En este entorno se usa XAMPP en Windows; usar `C:/xampp/php/php.exe` para `artisan` y `composer.phar`.
- Composer: `guzzlehttp/guzzle`, `laravel/sanctum ^4.0`, `laravel/tinker ^2.10`; dev con `phpunit ^11.5`, `laravel/pint ^1.13`, `mockery ^1.6.12`, `faker`, `nunomaduro/collision ^8.6`. `spatie/laravel-ignition` fue removido (incompatible con L12).
- Frontend Laravel: Vite 4 con `laravel-vite-plugin`, `axios`, `lodash`. Entradas: `resources/css/app.css` y `resources/js/app.js`.
- Redmine TIC tambien incluye un servicio Python opcional con `fastapi`, `uvicorn` y `httpx` en `redmine_tic/requirements.txt`.
- Base de datos: MySQL/MariaDB via Laravel (`config/database.php` + `.env`). La instalacion local actual apunta a la BD NOVA del entorno LAMPP/remoto configurado en `.env`.

## Estructura Principal

```text
app/                         Laravel: controladores, middleware, modelos y servicios Support.
bootstrap/                   Bootstrap y cache de Laravel; debe ser escribible por Apache/PHP.
config/                      Configuracion Laravel y registro de modulos NOVA (`modules.php`).
database/                    Migraciones, factories y seeders.
docker/                      Dockerfiles auxiliares, hoy usado por el servicio Telegram.
docs/                        Documentacion del proyecto.
emach/                       Modulo legacy EMACH: PHP procedural, datos JSON y cliente externo.
lang/                        Traducciones Laravel.
public/                      Front controller Laravel (`index.php`) y assets publicados/build.
redmine-mantencion/          Modulo Redmine Mantencion: legacy PHP servido por rutas Laravel.
redmine_tic/                 Modulo Redmine TIC: mezcla legacy PHP, vista nativa Laravel y webhook Python.
resources/                   Vistas Blade base y assets fuente para Vite.
routes/                      Rutas web/API/consola Laravel.
storage/                     Estado runtime Laravel y NOVA: logs, cache, backups, JSON locales.
telegram/                    Modulo Telegram: UI, listener, cola y libreria de bot.
tests/                       Tests PHPUnit/Laravel.
vendor/, node_modules/       Dependencias generadas; no editar manualmente.
```

## Comandos Clave

- Instalar backend: `composer install`
- Instalar frontend: `npm install`
- Dev assets: `npm run dev`
- Build assets: `npm run build`
- Tests: `C:/xampp/php/php.exe artisan test`
- Formato PHP: `C:/xampp/php/php.exe vendor/bin/pint`
- Migraciones: `C:/xampp/php/php.exe artisan migrate`
- Limpiar caches: `C:/xampp/php/php.exe artisan optimize:clear`
- Consolidar usuarios legacy/TIC/Mantencion en identidad central NOVA: `C:/xampp/php/php.exe artisan nova:consolidate-users`
- Reparar nombres de usuarios Mantencion/NOVA en BD: `C:/xampp/php/php.exe artisan redmine:mantencion-repair-user-names`
- Archivar reportes TIC procesados: `C:/xampp/php/php.exe artisan redmine:archive-processed`
- Servicio Telegram Docker: `docker compose -f docker-compose.telegram.yml ps|logs|restart`

No hay script `npm test` ni `npm lint` definido en `package.json`.

## Puntos de Entrada

- Laravel HTTP: `public/index.php`, `routes/web.php`.
- Login/sesion NOVA: `app/Http/Controllers/NovaAuthController.php`, middleware `app/Http/Middleware/EnsureNovaAuthenticated.php`.
- Cabeceras de seguridad HTTP: `app/Http/Middleware/SecurityHeaders.php`, registrado globalmente en `app/Http/Kernel.php`.
- Home y administracion NOVA: `resources/views/nova/home.blade.php`, `app/Http/Controllers/NovaAdministrationController.php`.
- Registro y permisos de modulos: `config/modules.php`, `app/Repositories/Modules/ModuleRegistry.php`, `app/Services/Nova/ProjectAccessGuard.php`, `app/Repositories/Nova/NovaAccessRepository.php`.
- Identidad central NOVA: modelo `app/Models/NovaUser.php`, repositorio `app/Repositories/Nova/NovaUserRepository.php`, tablas `usuarios_nova`, `integraciones_usuario`, `modulos_nova` y `permisos_usuario_modulo`.
- Bridge a modulos legacy: `app/Http/Controllers/LegacyProjectController.php`.
- Redmine TIC MVC/DB: `redmine_tic/nova/app/Http/Controllers/RedmineDashboardController.php`, `redmine_tic/nova/resources/views/native.blade.php`, `redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php`.
- Redmine TIC legacy: `redmine_tic/index.php`, `redmine_tic/controllers/*.php`, `redmine_tic/views/**/*.php` quedan como codigo historico; las rutas `/redmine_tic/*` redirigen al MVC nativo salvo assets/health/app.
- Redmine Mantencion: `redmine-mantencion/index.php`, `redmine-mantencion/controllers/*.php`, `redmine-mantencion/views/**/*.php`.
- Storage DB Mantencion: `redmine-mantencion/controllers/storage.php`, `app/Support/RedmineMantencion/RedmineMantencionStorageRepository.php`, tabla `redmine_mantencion_storage`.
- Procedimientos Mantencion / Nextcloud / OnlyOffice: `redmine-mantencion/controllers/procedimientos.php`, `procedimientos_file.php`, `nextcloud.php`, `onlyoffice.php`, `views/Procedimientos/procedimientos.php`.
- Navegador personal Nextcloud (Procedimientos): `redmine-mantencion/controllers/nc_browser.php` (dispatcher AJAX), `views/Procedimientos/nc_browser_ajax.php` (entry point), `views/Procedimientos/_nc_browser.php` (UI parcial). Requiere que el usuario tenga credenciales Nextcloud propias en `integraciones_usuario` (tipo=nextcloud); muestra una puerta de credenciales si no las tiene. El parcial `_nc_browser.php` se incluye desde `procedimientos.php` solo cuando `!$isPublicShare && !$showEditor && !$showDetail`.
- Telegram: `app/Http/Controllers/TelegramController.php`, `app/Services/Telegram/TelegramService.php`, `telegram/lib/telegram.php`, `telegram/bin/service.php`, `telegram/bin/listen.php`.
- EMACH: `emach/index.php`, `emach/lib/client.php`, `emach/bin/monitor.php`.
- Comandos artisan custom: `routes/console.php`.

## Convenciones de Codigo

- Laravel sigue PSR-4: `App\\` en `app/` y `RedmineTic\\` en `redmine_tic/nova/app/`.
- Estructura de namespaces canonica (Step 5A): `app/Repositories/*` para acceso a datos puro, `app/Services/*` para logica de dominio e integraciones, `app/Contracts/*` para interfaces Core (implementadas por TIC), `app/Support/*` para utilidades compartidas. Clases que tienen FQN strings en `redmine-mantencion/controllers/storage.php` y `emach/index.php` deben QUEDAR en `App\Support\*` permanentemente; moverlas rompe el runtime legacy.
- Desacoplamiento Core→TIC via interfaces (Step 6): los archivos bajo `app/` NO deben importar clases bajo `RedmineTic\` directamente. El patron aprobado es `app/Contracts/SomeInterface.php` implementado en `redmine_tic/nova/app/Services/*/ConcreteProvider.php`; `AppServiceProvider::register()` registra la implementacion condicionalmente con `class_exists`; el Core usa `app()->bound(Interface::class)` antes de resolver. Ver `ProjectUserProviderInterface` + `RedmineProjectUserProvider` como ejemplo canónico.
- Mantener la logica compartida en `app/Support/*` (utilidades) y `app/Repositories/*` (acceso BD); los controladores Laravel deben coordinar, no acumular reglas de negocio extensas.
- Los modulos legacy usan PHP procedural con controladores en `controllers/`, vistas en `views/` y parciales en `views/partials/`. Redmine TIC y Redmine Mantencion son 100% DB — **no existen archivos JSON de datos en ninguno de los dos modulos**. Los directorios `redmine_tic/data/` y `redmine-mantencion/data/*.json` fueron eliminados en la eradicacion JSON (2026-06-21).
- En legacy, usar helpers existentes: `storage_read_text()` / `storage_write_text()` / `storage_append_line()` para datos de Mantencion (todos redirigen a `redmine_mantencion_storage` DB), `auth_can()` para permisos, `legacy_csrf_token()` / validacion CSRF para POST, y bloqueos de mantencion cuando correspondan. **NO usar** `storage_backup_file()`, `storage_run_auto_backup()`, `storage_copy_recursive()`, `storage_prune_backups()` — eliminados.
- No duplicar nombres derivados si existe relacion por ID. La tendencia actual es normalizar datos y usar repositorios/relaciones para resolver nombres.
- La identidad de usuarios debe resolverse desde una sola ficha central en `usuarios_nova` cuando exista. Esa tabla concentra datos unicos del usuario: `uuid`/ID, `usuario`, `rut`, `redmine_id`, `nombre`, `apellido`, `email`, `rol`, `estado`, `password`, `usuario_core`, `telegram_id_chat` y auditoria de login/creacion/actualizacion. Los registros legacy de TIC/Mantencion pueden proyectar usuarios centrales, pero no deben volver a convertirse en fuente principal si ya existe relacion por `redmine_id`, RUT o usuario.
- Al importar usuarios desde Redmine Mantencion o Redmine TIC, crear o actualizar `usuarios_nova` con nombre y apellido separados. El apellido es obligatorio como dato de identidad; preferir `firstname`/`lastname` de Redmine y solo partir `name` como fallback.
- Los cambios direccionales hechos en NOVA sobre datos centrales de usuario deben propagarse al proyecto donde el usuario este registrado o tenga acceso. Si desde NOVA se otorga acceso a Redmine Mantencion o Redmine TIC, el usuario debe aparecer en la vista/listado de usuarios de ese modulo.
- Redmine Mantencion y Redmine TIC conservan sus propios permisos, roles y estados operativos por modulo; no mezclar esos permisos especificos con el rol global de NOVA salvo mediante `permisos_usuario_modulo`/repositorios de acceso.
- En listados y selectores de usuarios mostrar nombre y apellido juntos cuando ambos existan, y no depender solo de IDs Redmine o usuarios tecnicos.
- Las integraciones y credenciales de usuario deben pasar por repositorios/helpers existentes y persistir en `integraciones_usuario`; no loguear secretos ni escribir tokens en vistas. Redmine Mantencion usa helpers como `auth_central_redmine_api_token()` y TIC usa `RedmineDataRepository` para leer/grabar tokens de usuario.
- Las credenciales personales de plataformas integradas (EMACH, Redmine, CORE, Nextcloud, Telegram Chat ID y equivalentes) son propiedad del usuario: cada usuario debe ingresarlas desde el modulo/flujo correspondiente. Las vistas administrativas pueden mostrar estado configurado/pendiente, pero no deben editar ni reemplazar secretos de otro usuario.
- La gestion personal centralizada vive en `UserIntegrationController` + `resources/views/nova/integrations/user-config.blade.php`. Rutas actuales: `/emach/configuracion`, `/redmine-mantencion/app/mis-integraciones` y `/redmine_tic/app/mis-integraciones`. Reusar `integraciones_usuario` para nuevos secretos por usuario; no crear archivos JSON ni tablas nuevas sin justificarlo.
- Excepcion importante: el Chat ID de Telegram es dato unico del usuario y debe guardarse en `usuarios_nova.telegram_id_chat`, no como fila `tipo=telegram` en `integraciones_usuario`. `integraciones_usuario` queda para credenciales/cuentas externas con secreto o usuario externo.
- Modelo de datos objetivo: `usuarios_nova` es la unica tabla de identidad; `integraciones_usuario` representa "usuario_integracion"; `modulos_nova` representa "modulos"; `permisos_usuario_modulo` representa "modulos_usuarios"; Redmine TIC y Redmine Mantencion deben tener reportes separados porque sus campos y flujos son distintos; categorias, unidades y horas extra deben terminar en tablas explicitas y compartibles por modulo.
- Estado S33 (2026-06-18): Mantencion usa `app/Support/Mantencion/MantencionCatalogRepository.php` como fuente runtime para `categorias` y `unidades`. No leer ni escribir `redmine-mantencion/data/categorias.json` ni `redmine-mantencion/data/unidades.json` para dropdowns, filtros, sincronizacion o creacion de reportes; esos archivos quedan solo legacy/deprecated. La migracion `2026_06_18_000000_s33_drop_confirmed_legacy_columns` elimina defensivamente `redmine_tic_horas_extra_grupos.report_ids`, `categorias.origen`, `categorias.datos_extra`, `unidades.origen`, `unidades.datos_extra` y `catalogos_modulo.datos_extra` si existen. `asignado_nombre` se mantiene como cache denormalizada de visualizacion/historico. No fusionar logs: `nova_audit_logs` es global/auth/seguridad y `redmine_tic_activity_logs` es operacional TIC. Estado final BD: READY WITH MINOR FIXES; tests S33: 47 passed + 1 skipped.
- Ajuste S33 Mantencion reportes: `redmine_mantencion_reportes` debe conservar los campos reales del ticket Redmine: `proyecto`/`project_id`, `tipo`/`tipo_id`, `asunto`, `descripcion`, `estado` local, `estado_redmine`/`estado_id`, `prioridad`/`priority_id`, asignado, categoria, solicitante, anexo, `unidad_texto` (texto manual o CORE), fechas, tiempo estimado, correo, hora extra y `numero_ticket_redmine`. La migracion `2026_06_18_020000_s33_drop_mantencion_reportes_unidad_id` elimina `unidad_id` porque Mantencion no usa unidad catalogada en reportes; el texto manual vive en `unidad_texto`. `MantencionReportRepository` proyecta los mensajes manuales y los importados desde CORE hacia esa tabla cuando se guardan.
- Estado actual de transicion BD (S31 — Auditoría y Normalización Completa): `redmine_tic_reportes` modela reportes TIC con `id` autoincremental; tablas de datos operacionales vaciadas (reportes, horas extra, logs); `redmine_mantencion_storage` tiene 1 fila activa (`path=security.log`, escrita por el logger de Mantención; no eliminar); `configuraciones_modulo` tiene 59 filas (modulo_id=1: 20 config TIC, modulo_id=2: 39 config Mantencion); `catalogos_modulo` sigue activo para TIC aunque sus categorias/unidades fueron copiadas a tablas explicitas (ver sistema catálogo dual en `AUDITORIA_DB_COMPLETA.md`); `redmine_tic_perfiles_usuario` conserva `rol`, `estado_usuario` y `redmine_membership_id` — la columna `permisos` fue eliminada en Phase 3c; `redmine_tic_horas_extra_grupos` ya no tiene `report_ids` (Phase 2a); referencias grupo↔reporte en pivot `redmine_tic_horas_extra_grupo_reportes`; `modulo_opciones` (12 filas) es la fuente de trackers/prioridades/estados TIC. Tabla `horas_extras` eliminada en S28. `redmine_mantencion_reportes` limpiada en S29: 31→24 columnas. **Tablas nuevas S30:** `nova_audit_logs` (event log DB), `nova_settings` (configuración global clave/valor), `mantencion_permisos_rol` (permisos de Mantención por rol, reemplaza roles.json); `modulos_nova` tiene columnas `habilitado` y `en_mantencion`. **S31 — Columnas eliminadas (objetos muertos):** `usuarios_nova.email` (0/58 pobladas), `integraciones_usuario.metadata` (0/69), `integraciones_usuario.chat_id` (migrado a `usuarios_nova.telegram_id_chat` en S19), `modulos_nova.activo` (nunca leída, `habilitado` es el path real). **S31 — Tabla eliminada:** `_nova_column_backups` (artifact de migración S25, 1456 filas de backup). **S31 — Schema fixes:** `configuraciones_modulo.actualizado_at` tiene ON UPDATE CURRENT_TIMESTAMP; `nova_audit_logs.contexto` es tipo JSON. Total tablas BD: 23. Ver `AUDITORIA_DB_COMPLETA.md` para inventario completo y roadmap de S32/S33.
- Normalizacion JSON BD (S27 - completa): Phase 1, Phase 2, Phase 3a y Phase 3c completadas. Columnas eliminadas: `report_ids` de `redmine_tic_horas_extra_grupos`, `datos_extra` de `categorias`/`unidades`/`redmine_mantencion_reportes`/`horas_extras`, `permisos` de `redmine_tic_perfiles_usuario`. Filas JSON eliminadas de `configuraciones_modulo`: trackers, prioridades, estados, roles. Tablas relacionales de permisos: `redmine_tic_permisos_catalogo` (37 claves), `redmine_tic_permisos_rol` (5 roles × 37 claves), `redmine_tic_permisos_usuario` (43 perfiles × 37 claves = 1591 filas). No quedan columnas JSON redundantes. Ver `NORMALIZACION_DB.md`, `PERMISOS_NORMALIZACION.md`, `VALIDACION_PHASE3A_PERMISOS.md` y `ESTADO_DB_NORMALIZACION.md`.
- Configuracion Mantencion en BD (S24→S30): `MantencionConfigRepository` lee/escribe `configuraciones_modulo` con `modulo_id=2`. Desde S30 todos los lectores directos (`auth.php`, `onlyoffice.php`, `nextcloud.php`, `configuracion.php`) usan `MantencionConfigRepository`; el dual-write a `redmine_mantencion_storage` fue eliminado. `redmine_mantencion_storage` queda vacía.
- `modulo_opciones` es la tabla normalizada para listas de opciones por modulo (trackers, prioridades, estados). `RedmineDataRepository::configuration()` lee de `modulo_opciones`; `saveConfiguration()` escribe a `modulo_opciones`. Las filas JSON en `configuraciones_modulo` para esas claves fueron eliminadas en Phase 2d. Al agregar nuevas opciones de modulo estructuradas, usar `modulo_opciones`.
- `redmine_tic_horas_extra_grupo_reportes` es el pivot M2M que reemplaza `report_ids`. `RedmineDataRepository` tiene cuatro metodos actualizados: `syncHoursExtraForReport`, `removeHoursExtraRecord`, `hoursExtraFromDatabase`, `saveHoursGroupsToDatabase`. Si el pivot no existe (tabla ausente), esas operaciones retornan vacío/no-op de forma segura.
- `redmine_tic_activity_logs.contexto` es JSON por diseño (esquema libre por evento). No normalizar.
- `redmine_tic_perfiles_usuario.permisos` fue eliminada en Phase 3c (S27). La fuente autoritativa es `redmine_tic_permisos_usuario` (43 perfiles × 37 claves). `RedmineDataRepository` lee desde `redmine_tic_permisos_usuario` sin fallback JSON. No agregar de vuelta la columna `permisos`; encapsular toda lectura/escritura de permisos TIC en `RedmineDataRepository`.
- En `redmine_tic_perfiles_usuario`, `redmine_membership_id` es solo la referencia tecnica a la membresia del usuario en el proyecto Redmine remoto. No define permisos NOVA ni estado de usuario. Al sincronizar/importar usuarios TIC desde Redmine o respaldos, los usuarios nuevos deben quedar `estado_usuario = baneado` por defecto; si el perfil ya existe, no modificar `estado_usuario`.
- No eliminar ni renombrar tablas activas de BD sin: backup SQL previo, migracion Laravel reversible, migracion de datos, adaptacion de repositorios/controladores, pruebas de los modulos afectados y actualizacion de `ANALISIS_WEB.md`. Especial cuidado con `redmine_mantencion_storage`, porque borrarla hoy deja Mantencion sin datos operativos.
- Los roles administradores definidos en `config/nova.php` (`module_admin_roles`) tienen acceso amplio a modulos; `ProjectAccessGuard` y `NovaAccessRepository` deben conservar ese comportamiento.
- Mantener throttling en `POST /login` y `POST /session/extend`; si se agregan nuevos endpoints de autenticacion o verificacion de credenciales, deben tener limite de intentos.
- Las importaciones manuales de respaldos JSON deben validar archivo presente, extension `.json` y tamano maximo antes de decodificar.
- Mantener estilos visuales del modulo: Bootstrap/Bootstrap Icons (`bi ...`), `assets/theme.css` y parciales `bootstrap-head.php`, `navbar.php`.
- El logout en vistas Laravel debe ser siempre `form POST` con `@csrf`, no `<a href>` hacia `/logout`. La ruta `GET /logout` fue eliminada (solo existe `POST /logout`). Vistas migradas: `home.blade.php`, `modules/index.blade.php`, `admin/index.blade.php`, `users/index.blade.php`, `telegram/index.blade.php` y JS en `session-control.blade.php`. En modulos legacy que redirigian a `logout.php`, `LegacyProjectController::passthrough()` ahora devuelve un form auto-submitting con CSRF en vez de un redirect GET.
- `NovaUserRepository::attempt(string $username, string $password, bool $allowApiToken = false)` acepta token API como password solo cuando `$allowApiToken=true`. En el login interactivo del controlador no pasar ese flag; usarlo solo desde integraciones API.
- `ModuleRegistry::state()` esta cacheada 5 minutos (`Cache::remember('nova.modules.state', 300, ...)`); `saveState()` invalida el cache con `Cache::forget`. No leer ni escribir el JSON de estado directamente sin pasar por estos metodos.
- `NovaUserRepository` no tiene metodos privados de sincronizacion JSON-era (`ensureSeeded`, `syncProjectUsers`, `projectUsers`, etc.); no reintroducirlos. La identidad es DB-only.
- **Step 3 — NovaUserService (S35)**: La logica de dominio de usuarios esta en `app/Services/NovaUserService.php` (namespace `App\Services`). `NovaUserRepository` delega a ese servicio para: normalizacion de identidad, RUT (validacion + username), roles, estados, deduplicacion, reglas de merge, verificacion de credenciales, hashing de passwords y proyeccion de sesion (`toSessionUser`). `NovaUserRepository` conserva solo acceso a BD puro. Los controladores siguen usando `NovaUserRepository` directamente; el servicio es una dependencia interna inyectada por el contenedor. No reimplementar en el repositorio la logica que ya vive en el servicio.
- **Step 4 — Dead code removal (S36)**: Eliminados en Step 4: `app/Models/User.php` (modelo muerto — tabla `users` inexistente, nunca instanciado), `database/factories/UserFactory.php` (solo creaba `User`, seeder comentado), `app/Http/Controllers/NovaUserController.php` (ninguna ruta lo usaba), `resources/views/nova/users/index.blade.php` (solo renderizada por el controlador muerto). Removido import muerto `use App\Http\Controllers\NovaUserController` de `routes/web.php`. Actualizado `config/auth.php`: provider `users` ahora apunta a `App\Models\NovaUser::class`. Cargador de libreria Telegram extraido de dos controladores a `app/Support/Telegram/TelegramLibrary::load()` — `NovaAdministrationController` y `TelegramController` ahora delegan. No se eliminaron: `redmine-mantencion/login.php`, `redmine-mantencion/logout.php` (activos como redirect/fallback), `redmine-mantencion/app/` (usado por login/logout), `NovaAccessRepository::projectUserExists()` (stub activo llamado por `defaultAccess`), archivos EMACH (CLI activo).
- **Step 5A — Namespace normalization**: 11 clases movidas a namespaces canonicos (ver Sesion Step5A en ANALISIS_WEB.md). Clases que deben permanecer en `App\Support\*`: `RedmineMantencionStorageRepository`, `MantencionConfigRepository`, `MantencionCatalogRepository`, `MantencionReportRepository`, `NovaSettingsRepository`, `NovaNotificationService` (vieja), `UserIntegrationRepository`, `NovaHealthRepository` (vieja) — referenciadas por FQN string en legacy runtime. Nuevas ubicaciones canonicas: `App\Services\Auth\LegacyUserProvider`, `App\Services\Nova\{ProjectAccessGuard,NovaNotificationService}`, `App\Repositories\{Modules\ModuleRegistry,Nova\{NovaAuditRepository,NovaBackupRepository,NovaUserRepository,NovaAccessRepository,NovaHealthRepository},Integrations\{TelegramCommandSettingsRepository,TelegramCommandCatalog}}`, `App\Services\Telegram\TelegramService`, `App\Support\StringNormalizer`.
- **Nextcloud Browser personal (2026-06-21)**: `procedimientos.php` incluye `_nc_browser.php` en la vista de lista (fuera de editor/detalle/share publico). El parcial llama al endpoint AJAX `nc_browser_ajax.php` (que despacha a `nc_browser_handle()`). Acciones GET: `list` (PROPFIND WebDAV), `shares_with_me` (OCS), `download` (streaming). Acciones POST CSRF-protegidas: `mkdir`, `rename` (MOVE), `delete`, `upload` (PUT), `share_link` (link publico OCS), `share_user` (compartir con usuario OCS shareType=0), `share_delete`. Toda ruta de archivo pasa por `nextcloud_path_safe()`. Las credenciales se leen desde `integraciones_usuario` via `nextcloud_credentials_for_user($userId)` — cada usuario usa sus propias credenciales Nextcloud. Cuando el usuario no tiene credenciales guardadas, el parcial muestra un mensaje gate con enlace a `/redmine-mantencion/app/mis-integraciones`. No se crea ninguna copia local salvo el archivo temporal de PHP durante el upload, que se descarta tras el PUT. No se crean tablas nuevas — usa `integraciones_usuario` para credenciales y hace llamadas directas a la API Nextcloud.
- **JSON Eradication (2026-06-21)**: Runtime JSON eliminado completamente. Base de datos es la unica fuente de verdad. Eliminados: `redmine_tic/data/` (18 archivos), `redmine-mantencion/data/*.json` (135 archivos), `data/backups/auto/*`. Conservados solo: `data/procedimientos/documentos/`, `data/procedimientos/imagenes/`, `data/logs/`. Comandos eliminados: `redmine:tic-import-json`, `redmine:mantencion-import-json`, flag `--write-json`. Metodos eliminados: `importJsonDataToDatabase()`, `archivedReportsFromFiles()`, `dataPath()`, `readList()`, `readJsonMap()`, `readJsonTree()`, `assertInsideDataRoot()` de `RedmineDataRepository`; `loadFromFileSystem()`, `filesystemPath()` de `TelegramCommandSettingsRepository`. Config EMACH migrada a `nova_settings.emach_monitor_config` (JSON en BD). `emach/bin/monitor.php` lee config desde `nova_settings` via DB. Funciones de backup eliminadas de `storage.php`: `storage_backup_file()`, `storage_run_auto_backup()`, `storage_prune_backups()`, `storage_copy_recursive()`. Fallback `REDMINE_MANTENCION_JSON_FALLBACK` eliminado de `storage_read_text()` y `storage_write_text()`. Tests: 47 passed + 1 skipped (118 assertions). Rutas: 66. Migraciones: 32 ran, 0 pending.
- **Step 6 — Core/TIC decoupling**: `app/Contracts/ProjectUserProviderInterface.php` define el contrato para proveedores de usuarios de proyecto. `RedmineTic\Services\Redmine\RedmineProjectUserProvider` implementa la interfaz usando `RedmineDataRepository` internamente. `AppServiceProvider::register()` enlaza la interfaz condicionalmente. `ProjectAccessGuard::findProjectUser()` usa `app()->bound()` en vez de `class_exists(RedmineDataRepository::class)` — no importa ninguna clase TIC directamente. `NovaUser::perfilTic()` (HasOne hacia TIC, nunca usada) eliminada. Deuda restante aceptada: `ValidatePhase3aPermisos` usa RedmineDataRepository via reflection (comando one-time), `routes/console.php` tiene comandos de integracion TIC (capa de integracion aceptable), `routes/web.php` usa `RedmineDashboardController` (requerido).
- Tests Feature: `tests/Feature/AuthTest.php` (16 tests), `tests/Feature/ModuleAccessTest.php` (11 tests), `tests/Feature/Phase3aPermissionsTest.php` (16 tests, 1 skipped contextual tras Phase 3c). Ejecutar con `C:/xampp/php/php.exe artisan test`. Suite Step6-FINAL: 47 passed + 1 skipped (119 assertions) — sin regresiones tras namespace normalization (Step 5A) y Core/TIC decoupling (Step 6).
- Comando de validacion Phase 3a (referencia histórica): `C:/xampp/php/php.exe artisan nova:validate-phase3a` — 7 grupos, 17 verificaciones. Ya no aplica el check JSON↔relacional porque la columna `permisos` fue eliminada en Phase 3c; los demás checks siguen siendo válidos.
- Estado BD normalizado documentado en `ESTADO_DB_NORMALIZACION.md` (S28): 13 tablas config conservadas, 5 tablas operacionales vaciadas (estructura), 1 tabla eliminada (`horas_extras`), 6 columnas JSON eliminadas, 59 filas en configuraciones_modulo. Ver también `HORAS_EXTRA_MODELO.md` para análisis completo del modelo horas extra TIC (2 tablas activas: grupos + pivot). Ver `LIMPIEZA_REDMINE_MANTENCION_DB.md` para análisis S29. Estado S30: 3 tablas nuevas (`nova_audit_logs`, `nova_settings`, `mantencion_permisos_rol`), `modulos_nova` con columnas `habilitado`/`en_mantencion`. Ver `PLAN_ELIMINACION_JSON_FINAL.md` para análisis completo S30. Estado S31: 4 columnas eliminadas (`usuarios_nova.email`, `integraciones_usuario.metadata`, `integraciones_usuario.chat_id`, `modulos_nova.activo`), tabla `_nova_column_backups` eliminada, 2 schema fixes, 5 bugs corregidos. Estado S32-FINAL: `redmine_tic_reportes.estado` y `hora_extra` endurecidos NOT NULL; índice compuesto `nova_audit_logs(user_id, registrado_at)` creado; `idx_integraciones_tipo` eliminado (duplicado); ON UPDATE CURRENT_TIMESTAMP en 4 tablas; `nova_settings` seedeada con 3 defaults; `storage/app/nova/users.json` eliminado; EMACH usa BD; `NovaBackupRepository` exporta desde BD. Ver `AUDITORIA_DB_COMPLETA.md` para inventario completo S31+S32-FINAL y roadmap S33. Total tablas BD: 23.
- `public/assets/nova-ui.css` es el sistema visual global de NOVA. Contiene "Nova Home", "NOVA Unified Design System", "NOVA Visual System" y la capa S34 "NOVA Unified TIC Reference System"; no agregar bloques `<style>` inline en vistas Blade/PHP para navbar, hero, tablas, formularios, cards, modales, botones, badges, alertas, toasts o estados de carga. Extender `nova-ui.css` primero y luego ajustar vistas solo para clases/estructura.
- `nova-ui.css` define componentes globales reutilizables: `.nova-page-loader`/`.app-page-loader` (barra de carga superior), `.nova-integration-overlay` + `.nova-integration-card` + `.nova-integration-bar` (overlay de integracion), `.nova-empty-state` y `.rm-empty-state`, `.nova-integration-status` con variantes `is-loading/success/error/warning`, `.nova-toast` con variantes `is-success/info/danger`, `.nova-spinner`, `.nova-summary`, `.rm-hero`, `.telegram-hero`, `.emach-hero`, `.card.card-hero`, `.nova-card`, `.telegram-card`, `.emach-card`, `.rm-work-panel`, `.modules-table`, `.nova-session-badge`, `.nova-session-modal`, `.login-*`, `.user-*` y nav seccional compartido (`.rm-section-nav`, `.admin-section-nav`, `.telegram-nav-link`, `.emach-nav-link`, `.sb-nav-link`). **Sistema S34 basado en Redmine TIC**: `.nova-system-shell`, `.nova-system-hero`, `.nova-system-head`, `.nova-system-icon`, `.nova-system-meter`, `.nova-system-grid`, `.nova-system-card`, `.nova-system-toolbar`, `.nova-filter-panel`, `.nova-status-badge` y `.nova-log-panel`; tambien expone alias globales para `.rm-module-head`, `.rm-module-head-icon`, `.rm-module-meter`, `.rm-module-grid`, `.rm-info-card` y `.rm-form-shell`. **Skeleton loading**: `.nova-skeleton` + `.nova-skeleton-line.is-short/medium/full`, `.nova-skeleton-box`, `.nova-skeleton-avatar`, `.nova-skeleton-stat` (animacion shimmer `nova-skeleton-wave`). **Connecting state**: `.nova-connecting` + `.nova-connecting-dots` (3 `<span>` hijos) con variante `.is-light` para fondos oscuros. **Card loading**: `.nova-card-loading` (barra de progreso superior animada en `::before`).
- El page loader `.app-page-loader` (clase `is-visible`) esta activo en los cuatro modulos principales: Redmine TIC, Redmine Mantencion, EMACH y Telegram. Todos usan el mismo patron JS con `window.appUi.setLoading(true/false)` via IIFE en el `navbar.php` o `native.blade.php` correspondiente. El CSS de `.app-page-loader` vive en `nova-ui.css` (cargado despues de theme.css) y sobreescribe cualquier definicion previa en theme.css.
- El logout en modulos legacy (PHP) debe hacerse como `<form method="POST">` con `<input type="hidden" name="_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">`. La funcion `csrf_token()` de Laravel esta disponible en el contexto legacy porque el request pasa por Laravel. Implementado en `navbar.php` de Mantencion, EMACH y Telegram; los modales de sesion tambien deben cerrar sesion por POST, no por redirect GET.
- Los empty states de tablas en TIC usan la clase `.nova-empty` en la celda `<td>` (padding, centrado, color muted). En Mantencion el mismo patron aplica. Para paginas con empty state de pantalla completa usar `.nova-empty-state` con icono, titulo y parrafo de descripcion.
- `RedmineDataRepository` memoiza `activeReports()`, `archivedReports()` y `configuration()` en propiedades de instancia (`$activeReportsCache`, `$archivedReportsCache`, `$configurationCache`). Al escribir datos que los invaliden, usar `saveActiveReports()` o `saveConfiguration()` que ya limpian el cache. No llamar a `activeReportsFromDatabase()` directamente desde fuera. `archiveExpiredProcessedReports()` tiene debounce de 5 minutos via `Cache::put('nova.redmine.archive_check.<projectKey>', ...)`.
- El diseño visual de ambos modulos Redmine esta unificado en `nova-ui.css` (sección "NOVA Unified Design System"): misma paleta y gradiente para `.rm-navbar` y `nav.sb-navbar`, mismo gradiente profundo para `.card.card-hero` y `.rm-hero`, estados de reporte via clases `.nova-estado-pendiente/procesado/error/enviando`, label typography con `font-weight: 700` y `font-size: 0.88rem`, scrollbar refinado via `::-webkit-scrollbar`.
- EMACH y algunas vistas NOVA cargan `public/assets/nova-ui.css` despues del theme propio del modulo. Si se agregan heroes/cards oscuros (`.emach-hero`, `.telegram-hero`, `.card.card-hero`) usar reglas especificas en `nova-ui.css` para preservar fondo, contraste de `text-white-50`, botones `btn-outline-light` y layout responsive del `card-body`; no resolverlo con estilos inline en cada vista.

- El diseno visual global usa Redmine TIC como referencia: navbar navy `#102033` con borde inferior redondeado, brand mark translucido, tabs blancas con activo `#2563eb`, hero azul profundo `#1f4f7e` a `#244a75` con circulo decorativo, cards blancas con borde suave, tablas con encabezado azul claro, formularios de 42px+, botones con icono Bootstrap Icons, badges redondeados y estados semanticos unicos (`success`, `warning`, `danger`, `info`, `loading`). Este estandar cubre NOVA, EMACH, Telegram, Redmine TIC, Redmine Mantencion, CORE/Nextcloud dentro de Administracion/Integraciones, Usuarios, Configuracion y Dashboard.
- La responsividad global vive al final de `nova-ui.css` en "NOVA Global Responsive Layer". Antes de agregar media queries locales, revisar si se puede extender esa capa para contenedores, navbars, tabs horizontales, heroes, grids, tablas, modales, formularios y botones. Las vistas deben evitar overflow horizontal y mantener tablas dentro de `.table-responsive`/`.rm-table-wrap`.
- EMACH, Telegram y Mantencion cargan `public/assets/nova-ui.css` despues del theme propio del modulo. Si un theme local define un color/layout antiguo, resolver la inconsistencia con una regla compartida y especifica en `nova-ui.css`; no duplicar estilos inline por vista. Los bloques `<style>` complejos restantes de TIC/Mantencion/Admin son deuda controlada y solo deben migrarse cuando se pueda validar la pantalla completa.

## Configuracion y Servicios Criticos

- Variables Laravel: `APP_*`, `DB_*`, `SESSION_*`, `CACHE_*`, `MAIL_*` viven en `.env`. No exponer ni commitear secretos reales.
- Rutas de modulos configurables: `NOVA_REDMINE_TIC_PATH`, `NOVA_REDMINE_MANTENCION_PATH`, `NOVA_EMACH_PATH`, `NOVA_TELEGRAM_PATH`, `NOVA_ADMIN_STORAGE_PATH`.
- Telegram: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_PROXY_URL`; Docker usa `docker-compose.telegram.yml` y ejecuta `telegram/bin/service.php`.
- Integraciones externas actuales: Redmine API, CORE, Nextcloud/WebDAV, OnlyOffice, Telegram Bot API y EMACH. En sandbox puede fallar red por permisos.
- Apache/XAMPP sirve normalmente desde `/NOVA/public`. Laravel necesita permisos de escritura en `storage/` y `bootstrap/cache/`.
- Administracion y accesos NOVA se exponen por `/administracion`; usuarios, integraciones y permisos de modulo se almacenan en BD. `storage/app/nova/users.json` fue eliminado definitivamente en S32-FINAL; no recrearlo. `storage/app/nova/access.json` fue eliminado en S30; no recrearlo.
- `NovaBackupRepository` exporta la tabla `nova_settings` a un archivo JSON en `storage/app/nova/backups/YYYY-MM-DD/`. Usa el tipo `db_table` en `targets()`; no reintroducir targets basados en paths de archivo.
- `redmine_tic_reportes.estado` es `VARCHAR(20) NOT NULL DEFAULT 'pendiente'`. Siempre debe estar presente en INSERTs; `databaseReportPayload()` lo garantiza con fallback `?: 'pendiente'`.
- `redmine_tic_reportes.hora_extra` es `TINYINT(1) NOT NULL DEFAULT 0`. Siempre 0 o 1 en INSERTs; `databaseReportPayload()` lo garantiza con `isHoursExtraReport() ? 1 : 0`.
- `nova_settings` tiene 3 filas base seedeadas: `session_timeout`, `notification_enabled`, `health_warning_threshold`. `NovaSettingsRepository::all()` fusiona defaults de código + BD; no depender solo de BD si hay riesgo de vaciado.
- Respaldo importable de BD: generar dumps con estructura y datos, por ejemplo `mysqldump --single-transaction --routines --triggers --events --add-drop-table --databases nova --result-file=storage/app/backups/nova_full_YYYYMMDD_HHMMSS.sql`. No publicar dumps con datos reales.

## Despliegue en Produccion

### Variables .env obligatorias para produccion

```
APP_ENV=production
APP_DEBUG=false
APP_NAME=NOVA
APP_URL=https://tu-dominio-aqui
APP_TIMEZONE=America/Santiago
LOG_LEVEL=error
SESSION_SECURE_COOKIE=true   # solo si usas HTTPS
```

### Comandos de despliegue (ejecutar en orden)

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
C:/xampp/php/php.exe artisan migrate --force
C:/xampp/php/php.exe artisan config:cache
C:/xampp/php/php.exe artisan route:cache
C:/xampp/php/php.exe artisan view:cache
```

### Backup de base de datos (externo — agregar a cron)

```bash
# Dump completo con estructura y datos
mysqldump --single-transaction --routines --triggers \
  --add-drop-table --databases nova \
  --result-file=storage/app/backups/nova_full_$(date +%Y%m%d_%H%M%S).sql
```

### Restauracion

```bash
# 1. Verificar que el dump es integro
mysql -u nova_app -p nova < storage/app/backups/nova_full_YYYYMMDD_HHMMSS.sql
# 2. Verificar migraciones
C:/xampp/php/php.exe artisan migrate:status
# 3. Ejecutar migraciones pendientes si las hay
C:/xampp/php/php.exe artisan migrate --force
```

### Usuario de base de datos recomendado

No usar `root` en produccion. Crear un usuario dedicado:

```sql
CREATE USER 'nova_app'@'127.0.0.1' IDENTIFIED BY 'contraseña_segura';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES
  ON nova.*
  TO 'nova_app'@'127.0.0.1';
-- Solo durante migraciones o instalacion inicial:
-- GRANT CREATE, ALTER, DROP, INDEX, REFERENCES ON nova.* TO 'nova_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

**Privilegios minimos en produccion estable (sin migraciones):** `SELECT, INSERT, UPDATE, DELETE`.
**Durante migraciones:** agregar temporalmente `CREATE, ALTER, DROP, INDEX, REFERENCES` y revocar al terminar.

### Cabeceras de seguridad actuales

`SecurityHeaders` middleware establece en cada respuesta:
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: SAMEORIGIN`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- `X-Permitted-Cross-Domain-Policies: none`

**No implementado aun (deuda documentada):**
- `Content-Security-Policy` — requiere auditoria de todos los scripts/estilos inline en modulos legacy antes de poder definir directivas. Punto de partida recomendado cuando se implemente: `default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;`
- `Strict-Transport-Security` — agregar solo cuando el servidor tenga HTTPS configurado: `max-age=31536000; includeSubDomains`

## Precauciones

- No modificar manualmente `vendor/`, `node_modules/`, `public/build/` ni caches generados.
- Tratar `storage/`, `bootstrap/cache/`, `redmine_tic/data/`, `redmine-mantencion/data/`, `emach/data/` y `telegram/data/` como estado runtime/local. Pueden contener datos creados por el usuario.
- `redmine-mantencion/data/procedimientos/documentos` e `imagenes` son uploads; no borrar ni regenerar sin instruccion explicita.
- `database_nova_reconstruida.sql` es un dump de reconstruccion; tocarlo solo si la tarea es de migracion/backup.
- El bridge `LegacyProjectController` limita roots PHP/assets segun `config/modules.php`; al agregar rutas o archivos ejecutables, revisar esas listas.
- Redmine TIC debe persistir en BD en runtime. No escribir nuevos datos en `redmine_tic/data/*.json`; usar `RedmineDataRepository` y las tablas `redmine_tic_reportes`, `catalogos_modulo`, `configuraciones_modulo`, `redmine_tic_perfiles_usuario`, `redmine_tic_horas_extra_grupos` y `redmine_tic_activity_logs`. El comando `redmine:tic-import-json` solo es puente historico de migracion.
- Redmine TIC debe proyectar usuarios desde `usuarios_nova`, guardar secretos en `integraciones_usuario` y conservar roles/estado propios en `redmine_tic_perfiles_usuario`.
- En Redmine TIC, `redmine_tic_reportes` no debe usar `datos_extra` como bolsa JSON. Los campos del reporte manual se persisten en columnas reales: `fecha_inicio`, `fecha_fin`, `fecha`, `hora`, `chat_id_telegram`, `mensaje`, `descripcion`, `categoria_catalogo_id`, `unidad_catalogo_id`, `unidad_solicitante_catalogo_id`, `solicitante`, `hora_extra`, `tiempo_estimado` y `asignado_a`. El campo `asignado_a` debe usar el `redmine_id` numerico del usuario del proyecto, no el ID interno NOVA ni UUID; por defecto debe seleccionar al usuario logueado si tiene `redmine_id`.
- En Redmine TIC, `redmine_tic_reportes.local_id` fue eliminado. El unico ID interno del reporte es `redmine_tic_reportes.id`; vistas, acciones, historico y horas extra deben enviar/guardar ese ID numerico. No reintroducir UUID locales para reportes TIC. `redmine_tic_horas_extra_grupos` no debe volver a usar `report_ids`; las referencias grupo-reporte viven en el pivot `redmine_tic_horas_extra_grupo_reportes`.
- En `redmine_tic_reportes` y `redmine_mantencion_reportes`, un reporte archivado se representa solo como `estado = 'archivado'`. No reintroducir columnas `archivado_at`, `archivado_por` ni indices basados en ellas; las consultas de activos deben filtrar `estado <> 'archivado'` y el historico debe filtrar `estado = 'archivado'`.
- Los selectores/listados de asignacion de Redmine TIC deben listar solo usuarios con acceso efectivo al modulo actual (`permisos_usuario_modulo.permitido = 1` para `modulos_nova.clave_modulo`). `redmine_tic_perfiles_usuario` solo enriquece rol/estado/permisos de usuarios ya autorizados; no debe ampliar por si sola el listado de asignables.
- El dashboard de Redmine TIC debe mantener una tabla densa estilo grilla (`.rm-dashboard-table`) con encabezados compactos en mayuscula. Columnas esperadas: `Redmine ID`, `Asunto`, `Solicitante`, `Fecha creacion`, `Tipo solicitud`, `Unidad`, `Unidad solicitante`, `Asignado core`, `Estado local`, `Acciones`. No usar `Establecimiento`/`Departamento` en TIC; esos datos deben mostrarse como unidad/unidad solicitante.
- Redmine Mantencion debe persistir en BD en runtime. No escribir nuevos datos en `redmine-mantencion/data/*.json`; durante la transicion usa `storage.php`/`RedmineMantencionStorageRepository` y la tabla `redmine_mantencion_storage`, pero el destino normalizado es `redmine_mantencion_reportes`, `categorias`, `unidades` y `horas_extras`. El comando `redmine:mantencion-import-json` solo es puente historico de migracion.
- Redmine Mantencion no debe usar `usuarios.json` como fuente viva de usuarios. Las vistas deben leer usuarios desde `usuarios_nova` y `permisos_usuario_modulo`, tomar tokens API centrales desde `integraciones_usuario` y guardar ediciones contra esa BD central.
- Los modulos legacy tienen excepciones CSRF por compatibilidad; antes de agregar nuevos POST legacy, usar `legacy_csrf_token()`/`csrf_validate()` o migrar el endpoint a Laravel con CSRF nativo.
- `redmine-mantencion/controllers/storage.php` normaliza rutas Windows y Linux; mantener validaciones de path dentro del data root para evitar escapes fuera del modulo.
- Si aparece error de permisos en vistas/cache (`storage/framework/views` o `bootstrap/cache`), limpiar cache primero y revisar owner/permisos; no aplicar `chown/chmod` amplio sin aprobacion.
- Antes de cambios grandes, ejecutar al menos `C:/xampp/php/php.exe artisan test` y limpiar caches si se alteran rutas/configuracion.
- Mantener actualizado `ANALISIS_WEB.md` cuando se corrijan hallazgos de seguridad, arquitectura, BD, rendimiento o UI/UX.
