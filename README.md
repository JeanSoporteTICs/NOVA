# NOVA

NOVA es la plataforma interna que centraliza autenticación, usuarios, permisos e
integraciones para los módulos Redmine TIC, Redmine Mantención, EMACH, Telegram,
Procedimientos y Monitor de Servidores.

El proyecto funciona sobre Laravel 12 y PHP 8.2. Conserva algunos flujos PHP
legacy detrás del enrutador de Laravel, pero la identidad, la configuración y los
datos operativos tienen a MySQL/MariaDB como fuente de verdad. Los archivos JSON
y respaldos locales usados durante la migración ya no forman parte del runtime.

## Módulos

- **NOVA:** acceso, administración, permisos globales e integraciones personales.
- **Redmine TIC:** reportes de soporte, histórico, horas extra, usuarios,
  catálogos, estadísticas, webhook y bitácora.
- **Redmine Mantención:** reportes manuales y CORE, histórico, horas extra,
  usuarios, catálogos, Nextcloud y bitácora.
- **EMACH:** consulta de marcaciones, horarios y monitoreo.
- **Telegram:** bot, listener, cola y configuración de comandos.
- **Procedimientos:** gestión documental integrada con Nextcloud.
- **Monitor de Servidores:** comprobaciones Ping/ICMP, TCP, HTTP y HTTPS, prueba de
  destinos antes de guardarlos, ventanas de mantenimiento, historial de incidentes
  y alertas Telegram a administradores y suscriptores.

## Requisitos

- PHP 8.2 con extensiones requeridas por Laravel y MySQL.
- Composer 2.
- MySQL o MariaDB.
- Node.js y npm para compilar los recursos Vite.
- Servidor web con el `DocumentRoot` apuntando exclusivamente a `public/`.
- Docker Compose para el listener de Telegram y el monitor de servidores.

### Redes Docker del servidor NOVA

La instalación definitiva usa redes separadas y un único punto de entrada:

- `apache-web` es el único contenedor NOVA que publica un puerto en el host
  (`80/tcp`).
- `nova_backend` es una red Docker interna (`internal: true`) compartida por
  Apache, MariaDB, OnlyOffice, phpMyAdmin, Telegram y Monitor.
- `nova_egress` permite salida sin publicar puertos a OnlyOffice, Telegram y
  Monitor.
- MariaDB, phpMyAdmin y OnlyOffice no publican puertos al host.
- OnlyOffice se consume a través de Apache en `/onlyoffice/`; su URL NOVA es
  `http://<servidor>/onlyoffice`.

Las copias auditables de los Compose del host viven en `ops/docker-host/`. Las
ubicaciones operativas actuales son `/opt/docker/apache-web` y
`/home/odin/docker/compose/*`.

Para comprobar que solo Apache está publicado:

```bash
docker ps --format 'table {{.Names}}\t{{.Ports}}\t{{.Networks}}'
docker network inspect nova_backend --format 'internal={{.Internal}}'
```

## Instalación local

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

Configure en `.env` la URL de la aplicación, la conexión de base de datos, las
sesiones y las integraciones necesarias. No almacene contraseñas, tokens,
cookies, respaldos ni volcados SQL dentro del repositorio.

En XAMPP para Windows, use el binario PHP de la instalación:

```text
C:/xampp/php/php.exe artisan migrate
C:/xampp/php/php.exe artisan test
```

En este entorno Linux/XAMPP el equivalente habitual es:

```bash
/opt/lampp/bin/php artisan migrate
/opt/lampp/bin/php artisan test
```

Después de modificar configuración, rutas o variables de entorno:

```bash
php artisan optimize:clear
```

## Desarrollo

```bash
npm run dev
```

Las entradas frontend son `resources/css/app.css` y `resources/js/app.js`. El
sistema visual compartido vive en `public/assets/nova-ui.css`; los módulos deben
reutilizar sus componentes antes de añadir estilos locales.

Comandos útiles:

```bash
php artisan route:list
php artisan migrate:status
php artisan nova:consolidate-users
php artisan redmine:mantencion-repair-user-names
php artisan redmine:archive-processed
php artisan nova:health-alerts
php artisan nova:monitor-servers
```

Telegram puede administrarse con:

```bash
docker compose -f docker-compose.telegram.yml ps
docker compose -f docker-compose.telegram.yml logs
docker compose -f docker-compose.telegram.yml restart
```

El token global de Telegram tiene una sola fuente de verdad:
`TELEGRAM_BOT_TOKEN` en `.env`. Nunca se guarda en
`storage/app/telegram/config.json`. Al actualizar una instalacion antigua,
ejecute una vez `php artisan telegram:migrate-token-env`; luego recree los
contenedores para que relean el entorno:

```bash
docker compose -f docker-compose.telegram.yml up -d --force-recreate
docker compose -f docker-compose.monitor.yml up -d --force-recreate
```

### Monitor de Servidores con Docker

El monitor se ejecuta como un contenedor separado y comparte el código del
proyecto mediante el volumen `./:/app`. Antes de iniciarlo deben estar aplicadas
las migraciones, disponible la base de datos desde Docker y configurado el bot de
Telegram de NOVA.

Variables relevantes en `.env`:

- `MONITOR_DB_HOST`: host de MySQL visto desde el contenedor. En Docker Desktop
  normalmente es `host.docker.internal` si MySQL corre en el mismo servidor.
- `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD`: conexión de NOVA.
- `TELEGRAM_BOT_TOKEN` y `TELEGRAM_PROXY_URL`: envío de alertas.
- `APP_TIMEZONE`: usar `America/Santiago` para las fechas operacionales.

No use `localhost` como `MONITOR_DB_HOST` cuando MySQL se encuentre fuera del
contenedor: dentro de Docker, `localhost` identifica al propio contenedor.

#### Instalación inicial

Desde la raíz del proyecto en Windows/XAMPP:

```powershell
C:\xampp\php\php.exe artisan migrate --force
C:\xampp\php\php.exe artisan optimize:clear
docker compose -f docker-compose.monitor.yml up -d --build
docker compose -f docker-compose.monitor.yml ps
docker compose -f docker-compose.monitor.yml logs --tail=100 nova-monitor
```

En Linux sustituya `C:\xampp\php\php.exe` por el binario PHP del servidor, por
ejemplo `/opt/lampp/bin/php`.

La primera construcción descarga `php:8.2-cli-alpine` desde Docker Hub. Si aparece
`context deadline exceeded`, compruebe la salida HTTPS del servidor, DNS y proxy:

```powershell
Test-NetConnection registry-1.docker.io -Port 443
docker pull php:8.2-cli-alpine
```

Después de recuperar la conectividad, repita `docker compose ... up -d --build`.

#### Actualización del código

El volumen entrega al contenedor los archivos actualizados sin reconstruir la
imagen, pero el daemon PHP mantiene las clases cargadas en memoria. Después de
publicar cambios ejecute:

```powershell
C:\xampp\php\php.exe artisan migrate --force
C:\xampp\php\php.exe artisan optimize:clear
docker compose -f docker-compose.monitor.yml restart nova-monitor
```

No es necesario usar `--build` mientras no cambien `docker/monitor/Dockerfile`,
las extensiones PHP o las dependencias del contenedor. Si alguno de esos elementos
cambia, use nuevamente:

```bash
docker compose -f docker-compose.monitor.yml up -d --build
```

#### Verificación operacional

```powershell
docker compose -f docker-compose.monitor.yml ps
docker compose -f docker-compose.monitor.yml logs --tail=100 nova-monitor
docker compose -f docker-compose.monitor.yml exec nova-monitor php artisan nova:monitor-servers --healthcheck
```

La verificación es correcta cuando:

- `nova-monitor` figura `Up` y `healthy`.
- `--healthcheck` finaliza con código `0`.
- La portada del módulo muestra `Servicio Docker: Monitoreando` y un ciclo reciente.
- `Comprobar` actualiza estado y latencia sin errores.
- Una caída y recuperación controladas generan una sola alerta de cada tipo en el
  canal de prueba autorizado.

Las ventanas de mantenimiento continúan comprobando el destino, pero suspenden
las alertas hasta finalizar. Al terminar, el contador de fallos comienza nuevamente
desde cero.

El comando `/tic problema, unidad, solicitante` crea un reporte pendiente de
forma directa. El modo diario permite enviar después mensajes sin comando:

```text
/tic activar
problema, unidad, solicitante
/tic estado
/tic salir
```

El modo se guarda por Chat ID en la caché de Laravel, permanece tras reinicios
del listener y expira automáticamente a las 23:59 de `America/Santiago`. Los
mensajes sin comando solo se procesan cuando contienen exactamente los tres
campos no vacíos separados por comas.

## Arquitectura

```text
app/                    Núcleo Laravel, contratos, middleware y comandos
Nova/                   Módulo central NOVA
RedmineTic/             Módulo Redmine TIC nativo
RedmineMantencion/      Módulo Redmine Mantención nativo
Emach/                  Módulo EMACH
Procedimientos/         Módulo documental
telegram/               Listener y librería del bot
app/Modulos/MonitorServidores/  Inventario, comprobaciones y alertas
database/migrations/    Evolución versionada del esquema
resources/              Vistas y recursos fuente
public/                 Única raíz pública del servidor web
tests/                  Pruebas PHPUnit
ops/ y scripts/         Construcción, verificación y operación
```

Los módulos se registran en `config/modules.php`. El núcleo no debe depender
directamente de implementaciones de Redmine TIC: las integraciones entre capas
se resuelven mediante contratos en `app/Contracts` y enlaces del contenedor.

### Redmine Mantención nativo

Todas las rutas operativas bajo `/redmine-mantencion` usan controladores
Laravel, sesión NOVA, CSRF, permisos relacionales y vistas Blade. Esto incluye
Dashboard, pendientes manuales, importación CORE, envío y estados Redmine,
Histórico, Horas extra, Usuarios, Configuración, Estadísticas, Actividad y
aprovisionamiento/historial Nextcloud. Sus assets se sirven mediante un
controlador nativo propio.

Mantención ya no pasa por `LegacyProjectController`, no abre `NOVALEGACY` y se
retiraron físicamente sus controladores, bootstrap, login y vistas PHP
procedurales. Las rutas históricas solo redirigen a su equivalente nativo. El
cambio reutiliza las tablas y repositorios existentes: no crea almacenamiento
paralelo ni reintroduce archivos JSON.

La pantalla de cuentas conectadas también usa el layout/permisos nativos de
Mantención. Procedimientos consume Nextcloud mediante
`NextcloudBrowserService`, `NextcloudWebdavClient` y `NextcloudOcsClient`; ya no
incluye el antiguo dispatcher `nc_browser.php`. Tanto Redmine como CORE y
Nextcloud obtienen las credenciales personales desde `integraciones_usuario`.

En el envío mixto a Redmine, los reportes cuyo estado CORE sigue **En Revisión**
permanecen pendientes. El resultado que informa ese bloqueo se presenta después
de completar la barra de progreso y cerrar el overlay de integración.

### Datos e identidad

- `usuarios_nova` es la única identidad central.
- `usuario` y `rut` son únicos. El RUT se persiste en formato canónico sin
  puntos (`12345678-9`) para que su índice único no dependa del formato de
  entrada.
- Al importar miembros desde Redmine TIC o Mantención, un cambio de
  `redmine_id` se reconcilia únicamente mediante una coincidencia única entre
  el `login` remoto y el usuario de acceso/RUT central; nunca por nombre.
- `integraciones_usuario` guarda cuentas y secretos externos por usuario.
- Redmine Mantención y Redmine TIC comparten una única API key personal en la
  integración `tipo=redmine`; los tipos históricos `redmine_mantencion` y
  `redmine_tic` solo se aceptan como compatibilidad durante la consolidación.
- `modulos_nova` y `permisos_usuario_modulo` controlan el acceso global;
  `rol_modulo` conserva el rol interno de Mantención sin modificar
  `usuarios_nova.rol`.
- Los roles globales son `usuario`, `admin` y `root`. `admin` administra NOVA
  pero respeta los permisos internos de cada módulo; solo `root` obtiene
  acceso total automático.
- Cada módulo conserva sus permisos, roles y tablas operativas específicas.
- Las reimportaciones desde CORE identifican la solicitud por `id_core`: si el
  reporte sigue pendiente, actualizan sus datos modificados; los reportes
  procesados, con error o archivados no se sobrescriben.
- Los repositorios encapsulan el acceso a datos; los controladores coordinan los
  casos de uso.
- No se deben reintroducir archivos JSON como almacenamiento de runtime.

Las credenciales externas son personales. Nunca deben mostrarse en vistas
administrativas, registrarse en bitácoras ni almacenarse en historiales.

## Seguridad

- El cierre de sesión se realiza únicamente mediante `POST /logout` con CSRF.
- Los endpoints de autenticación y extensión de sesión conservan throttling.
- Toda acción legacy que modifique datos debe validar CSRF y permisos.
- Los archivos de usuario y los secretos permanecen fuera de `public/`.
- Los logs y respaldos de producción deben almacenarse fuera del repositorio,
  cifrados y con una política de retención.

## Pruebas y validación

```bash
php artisan test
php vendor/bin/pint --test
npm run build
```

No existe un script `npm test` ni `npm lint`. Antes de desplegar también se debe
verificar:

```bash
php artisan migrate:status
php artisan route:list
```

Las pruebas que requieren servicios externos o una base de datos de staging
deben ejecutarse únicamente con credenciales y autorización del entorno
correspondiente.

## Producción

El artefacto de producción se construye desde un commit o tag limpio mediante
los scripts de `ops/production`. El despliegue debe publicar solo `public/`,
instalar dependencias desde los lockfiles, ejecutar las migraciones aprobadas y
mantener un mecanismo de rollback.

### Lista previa a liberar el Monitor de Servidores

- Confirmar `APP_ENV=production`, `APP_DEBUG=false`, zona horaria y URL pública.
- Respaldar la base de datos y registrar la versión/tag que se desplegará.
- Ejecutar `artisan migrate --force` y confirmar cero migraciones pendientes.
- Verificar que Apache publique exclusivamente el directorio `public/`.
- Confirmar que el contenedor alcance MySQL y Telegram sin exponer secretos en logs.
- Revisar permisos: usuarios con acceso pueden ver el resumen y solo administradores
  pueden gestionar servidores y destinatarios.
- Probar Ping/ICMP, TCP y al menos una URL HTTPS con certificado válido.
- Probar una caída y recuperación sobre un destino controlado; confirmar Telegram.
- Programar una ventana breve y confirmar que no genera alertas durante ella.
- Verificar `docker compose ... ps`, healthcheck, logs y heartbeat en la interfaz.
- Mantener disponible la versión anterior y seguir el runbook ante cualquier NO-GO.

No realice la prueba de caída contra un servidor productivo real. Use un destino
controlado y destinatarios de Telegram autorizados para la liberación.

Documentación operativa vigente:

- [Construcción y verificación del artefacto](ops/production/README.md)
- [Runbook de despliegue](docs/PRODUCTION_DEPLOYMENT_RUNBOOK.md)
- [Pruebas de humo](docs/PROD05_SMOKE_TESTS.md)
- [Sistema visual](docs/nova-design-system.md)

## Reglas de mantenimiento

- No editar `vendor/` ni `node_modules/`.
- No versionar `.env`, logs, dumps, respaldos o archivos temporales.
- Toda modificación de esquema requiere una migración reversible.
- Mantener separados los permisos globales NOVA y los permisos operativos de
  cada módulo.
- Actualizar este README cuando cambien requisitos, módulos, comandos o el
  proceso de despliegue.
