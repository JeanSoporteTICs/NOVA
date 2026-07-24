# NOVA

NOVA es la plataforma interna que centraliza autenticación, usuarios, permisos e
integraciones para los módulos Redmine TIC, Redmine Mantención, EMACH, Telegram y
Procedimientos.

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

## Requisitos

- PHP 8.2 con extensiones requeridas por Laravel y MySQL.
- Composer 2.
- MySQL o MariaDB.
- Node.js y npm para compilar los recursos Vite.
- Servidor web con el `DocumentRoot` apuntando exclusivamente a `public/`.
- Opcional: Docker Compose para el listener de Telegram.

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
```

Telegram puede administrarse con:

```bash
docker compose -f docker-compose.telegram.yml ps
docker compose -f docker-compose.telegram.yml logs
docker compose -f docker-compose.telegram.yml restart
```

## Arquitectura

```text
app/                    Núcleo Laravel, contratos, middleware y comandos
Nova/                   Módulo central NOVA
RedmineTic/             Módulo Redmine TIC nativo
RedmineMantencion/      Módulo Redmine Mantención y bridge legacy
Emach/                  Módulo EMACH
Procedimientos/         Módulo documental
telegram/               Listener y librería del bot
database/migrations/    Evolución versionada del esquema
resources/              Vistas y recursos fuente
public/                 Única raíz pública del servidor web
tests/                  Pruebas PHPUnit
ops/ y scripts/         Construcción, verificación y operación
```

Los módulos se registran en `config/modules.php`. El núcleo no debe depender
directamente de implementaciones de Redmine TIC: las integraciones entre capas
se resuelven mediante contratos en `app/Contracts` y enlaces del contenedor.

### Datos e identidad

- `usuarios_nova` es la única identidad central.
- `integraciones_usuario` guarda cuentas y secretos externos por usuario.
- `modulos_nova` y `permisos_usuario_modulo` controlan el acceso global.
- Cada módulo conserva sus permisos y tablas operativas específicas.
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
