# AGENTS.md

Guia rapida para agentes IA que trabajen en NOVA. Lee esto antes de explorar el repo completo.

## Stack y Versiones

- Backend principal: PHP 8.x sobre Laravel 9 (`laravel/framework ^9.19`). En este entorno se usa XAMPP/LAMPP; preferir `/opt/lampp/bin/php` para `artisan`.
- Composer: `guzzlehttp/guzzle`, `laravel/sanctum`, `laravel/tinker`; dev con `phpunit ^9.5`, `laravel/pint`, `mockery`, `faker`.
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
- Tests: `/opt/lampp/bin/php artisan test`
- Formato PHP: `/opt/lampp/bin/php vendor/bin/pint`
- Migraciones: `/opt/lampp/bin/php artisan migrate`
- Limpiar caches: `/opt/lampp/bin/php artisan optimize:clear`
- Importar Redmine TIC JSON a BD: `/opt/lampp/bin/php artisan redmine:tic-import-json`
- Importar Redmine Mantencion JSON/texto a BD: `/opt/lampp/bin/php artisan redmine:mantencion-import-json`
- Reparar nombres de usuarios Mantencion/NOVA tras migracion: `/opt/lampp/bin/php artisan redmine:mantencion-repair-user-names`
- Archivar reportes TIC procesados: `/opt/lampp/bin/php artisan redmine:archive-processed`
- Servicio Telegram Docker: `docker compose -f docker-compose.telegram.yml ps|logs|restart`

No hay script `npm test` ni `npm lint` definido en `package.json`.

## Puntos de Entrada

- Laravel HTTP: `public/index.php`, `routes/web.php`.
- Login/sesion NOVA: `app/Http/Controllers/NovaAuthController.php`, middleware `app/Http/Middleware/EnsureNovaAuthenticated.php`.
- Home y administracion NOVA: `resources/views/nova/home.blade.php`, `app/Http/Controllers/NovaAdministrationController.php`.
- Registro y permisos de modulos: `config/modules.php`, `app/Support/Modules/ModuleRegistry.php`, `app/Support/Modules/ProjectAccessGuard.php`, `app/Support/Nova/NovaAccessRepository.php`.
- Bridge a modulos legacy: `app/Http/Controllers/LegacyProjectController.php`.
- Redmine TIC MVC/DB: `redmine_tic/nova/app/Http/Controllers/RedmineDashboardController.php`, `redmine_tic/nova/resources/views/native.blade.php`, `redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php`.
- Redmine TIC legacy: `redmine_tic/index.php`, `redmine_tic/controllers/*.php`, `redmine_tic/views/**/*.php` quedan como codigo historico; las rutas `/redmine_tic/*` redirigen al MVC nativo salvo assets/health/app.
- Redmine Mantencion: `redmine-mantencion/index.php`, `redmine-mantencion/controllers/*.php`, `redmine-mantencion/views/**/*.php`.
- Storage DB Mantencion: `redmine-mantencion/controllers/storage.php`, `app/Support/RedmineMantencion/RedmineMantencionStorageRepository.php`, tabla `redmine_mantencion_storage`.
- Procedimientos Mantencion / Nextcloud / OnlyOffice: `redmine-mantencion/controllers/procedimientos.php`, `procedimientos_file.php`, `nextcloud.php`, `onlyoffice.php`, `views/Procedimientos/procedimientos.php`.
- Telegram: `app/Http/Controllers/TelegramController.php`, `telegram/lib/telegram.php`, `telegram/bin/service.php`, `telegram/bin/listen.php`.
- EMACH: `emach/index.php`, `emach/lib/client.php`, `emach/bin/monitor.php`.
- Comandos artisan custom: `routes/console.php`.

## Convenciones de Codigo

- Laravel sigue PSR-4: `App\\` en `app/` y `RedmineTic\\` en `redmine_tic/nova/app/`.
- Mantener la logica compartida en `app/Support/*`; los controladores Laravel deben coordinar, no acumular reglas de negocio extensas.
- Los modulos legacy usan PHP procedural con controladores en `controllers/`, vistas en `views/` y parciales en `views/partials/`. Redmine TIC y Redmine Mantencion ya no deben escribir runtime en `data/*.json`; esos archivos son historicos/importables.
- En legacy, usar helpers existentes: `storage_read_json()` / `storage_write_json()` / `storage_json_by_prefix()` para datos de Mantencion, `auth_can()` para permisos, `legacy_csrf_token()` / validacion CSRF para POST, y bloqueos de mantencion cuando correspondan.
- No duplicar nombres derivados si existe relacion por ID. La tendencia actual es normalizar datos y usar repositorios/relaciones para resolver nombres.
- Las integraciones y credenciales de usuario deben pasar por repositorios/helpers existentes; no loguear secretos ni escribir tokens en vistas.
- Mantener estilos visuales del modulo: Bootstrap/Bootstrap Icons (`bi ...`), `assets/theme.css` y parciales `bootstrap-head.php`, `navbar.php`.

## Configuracion y Servicios Criticos

- Variables Laravel: `APP_*`, `DB_*`, `SESSION_*`, `CACHE_*`, `MAIL_*` viven en `.env`. No exponer ni commitear secretos reales.
- Rutas de modulos configurables: `NOVA_REDMINE_TIC_PATH`, `NOVA_REDMINE_MANTENCION_PATH`, `NOVA_EMACH_PATH`, `NOVA_TELEGRAM_PATH`, `NOVA_ADMIN_STORAGE_PATH`.
- Telegram: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_PROXY_URL`; Docker usa `docker-compose.telegram.yml` y ejecuta `telegram/bin/service.php`.
- Integraciones externas actuales: Redmine API, CORE, Nextcloud/WebDAV, OnlyOffice, Telegram Bot API y EMACH. En sandbox puede fallar red por permisos.
- Apache/XAMPP sirve normalmente desde `/NOVA/public`. Laravel necesita permisos de escritura en `storage/` y `bootstrap/cache/`.
- Administracion y accesos NOVA se almacenan bajo `storage/app/nova` y se exponen por `/administracion`.

## Precauciones

- No modificar manualmente `vendor/`, `node_modules/`, `public/build/` ni caches generados.
- Tratar `storage/`, `bootstrap/cache/`, `redmine_tic/data/`, `redmine-mantencion/data/`, `emach/data/` y `telegram/data/` como estado runtime/local. Pueden contener datos creados por el usuario.
- `redmine-mantencion/data/procedimientos/documentos` e `imagenes` son uploads; no borrar ni regenerar sin instruccion explicita.
- `database_nova_reconstruida.sql` es un dump de reconstruccion; tocarlo solo si la tarea es de migracion/backup.
- El bridge `LegacyProjectController` limita roots PHP/assets segun `config/modules.php`; al agregar rutas o archivos ejecutables, revisar esas listas.
- Redmine TIC debe persistir en BD en runtime. No escribir nuevos datos en `redmine_tic/data/*.json`; usar `RedmineDataRepository` y las tablas `reportes_redmine`, `catalogos_modulo`, `configuraciones_modulo`, `redmine_tic_usuarios`, `redmine_tic_horas_extra_grupos` y `redmine_tic_activity_logs`. El comando `redmine:tic-import-json` solo es puente historico de migracion.
- Redmine Mantencion debe persistir en BD en runtime. No escribir nuevos datos en `redmine-mantencion/data/*.json`; usar `storage.php`/`RedmineMantencionStorageRepository` y la tabla `redmine_mantencion_storage`. El comando `redmine:mantencion-import-json` solo es puente historico de migracion.
- Si aparece error de permisos en vistas/cache (`storage/framework/views` o `bootstrap/cache`), limpiar cache primero y revisar owner/permisos; no aplicar `chown/chmod` amplio sin aprobacion.
- Antes de cambios grandes, ejecutar al menos `/opt/lampp/bin/php artisan test` y limpiar caches si se alteran rutas/configuracion.
