# Análisis técnico y estructural de NOVA

**Fecha del análisis:** 3 de agosto de 2026

**Repositorio:** `/var/www/NOVA`

**Rama revisada:** `main`

**Commit de referencia:** `70dad4f` (`Corrige acciones y formularios de Mantencion`)
**Alcance:** estructura del código, responsabilidades, datos, rutas, dependencias, pruebas, Docker, operación y riesgos visibles en el repositorio.

## 1. Resumen ejecutivo

NOVA es una plataforma interna modular construida sobre Laravel 12 y PHP 8.2. Su función central es unificar autenticación, identidad, permisos, configuración e integraciones para varios dominios operacionales: Redmine TIC, Redmine Mantención, EMACH, Telegram, Procedimientos, Horas Extra y Monitor de Servidores.

La arquitectura actual es híbrida:

- Laravel actúa como front controller, capa de seguridad, enrutador, contenedor de dependencias y núcleo de identidad.
- Los módulos nuevos o modernizados usan controladores, servicios, repositorios y vistas Blade.
- Redmine Mantención y EMACH todavía conservan PHP procedural, servido de forma controlada a través de Laravel.
- MySQL/MariaDB es la fuente de verdad para identidad, permisos, configuración y datos operacionales.
- Docker se usa tanto para la plataforma web y servicios de infraestructura como para procesos persistentes de Telegram y monitoreo.

El diseño general es coherente y muestra una modernización progresiva bien encaminada: hay separación por módulos, repositorios para persistencia, servicios para reglas de negocio, middleware de seguridad, migraciones versionadas y una suite de pruebas considerable. La principal deuda no está en la dirección arquitectónica, sino en la limpieza y seguridad operacional del repositorio: existen dumps SQL, logs, documentos operacionales y respaldos JSON versionados que contradicen la política de artefactos de producción. También existe documentación histórica que ya no coincide con las ubicaciones y nombres actuales.

## 2. Estado técnico observado

| Elemento | Estado observado |
|---|---|
| PHP CLI | 8.2.32 |
| Framework | Laravel 12.62.0 |
| Rutas Laravel registradas | 93 |
| Migraciones | 71 |
| Archivos de prueba `*Test.php` | 50 |
| Gestor backend | Composer 2 / `composer.lock` |
| Frontend | Vite 4, Axios, Lodash y CSS/JS propio |
| Persistencia | MySQL/MariaDB |
| Rama | `main` |
| Working tree al analizar | limpio |
| Docker CLI | 29.7.1 |
| Estado de contenedores | no verificable: sin permiso sobre `/var/run/docker.sock` |

No se ejecutaron acciones destructivas, migraciones ni escrituras en base de datos. Tampoco se inspeccionaron valores secretos de `.env`.

## 3. Vista general de la arquitectura

```text
Usuario / navegador
        |
        v
Apache (único puerto publicado)
        |
        +---- /onlyoffice/ ---> OnlyOffice
        |
        v
Laravel / public/index.php
        |
        +---- NOVA central: identidad, accesos, administración
        +---- Redmine TIC nativo
        +---- Redmine Mantención híbrido
        +---- EMACH legacy integrado
        +---- Telegram UI/configuración
        +---- Procedimientos / Nextcloud / OnlyOffice
        +---- Horas Extra consolidadas
        +---- Monitor de Servidores
        |
        v
MariaDB + servicios externos
        |
        +---- Redmine
        +---- CORE
        +---- Nextcloud/WebDAV
        +---- Telegram Bot API
        +---- EMACH
```

### Flujo de una solicitud

1. Apache recibe el tráfico y entrega NOVA a Laravel.
2. `public/index.php` inicia la aplicación.
3. El grupo `web` crea sesión, aplica CSRF, resuelve bindings y registra actividad.
4. `SecurityHeaders` agrega cabeceras globales de seguridad.
5. Salvo login y callbacks públicos específicos, las rutas pasan por `nova.auth`.
6. `ProjectAccessGuard` y `NovaAccessRepository` resuelven el acceso a módulos.
7. Las rutas nativas llaman controladores Laravel; las legacy pasan por `LegacyProjectController`, que limita rutas y archivos permitidos.
8. Los repositorios acceden a MariaDB y los servicios coordinan reglas de dominio e integraciones externas.

## 4. Distribución del repositorio

### Núcleo Laravel: `app/`

Contiene la infraestructura transversal y no el dominio completo de NOVA:

- `app/Http/`: kernel HTTP, middleware base, autenticación NOVA, CSRF y cabeceras de seguridad.
- `app/Console/`: comandos de validación, alertas, monitoreo y auditoría.
- `app/Contracts/`: contratos que desacoplan el núcleo de implementaciones modulares.
- `app/Providers/`: registro de servicios y ubicaciones adicionales de vistas.
- `app/Modulos/Shared/`: bitácoras comunes.
- `app/Modulos/Telegram/`: controlador, cliente API, repositorios y servicios del módulo Telegram.
- `app/Modulos/MonitorServidores/`: controlador, repositorio, servicio de dominio y sondas de red.
- `app/Support/`: utilidades transversales.

El `AppServiceProvider` enlaza `ProjectUserProviderInterface` con la implementación de Redmine TIC solo si esta existe. Es una buena frontera: el núcleo conoce un contrato, no el repositorio concreto del módulo.

### NOVA central: `Nova/`

Es el módulo de gobierno de la plataforma y usa el namespace `App\Modulos\Nova\`:

- `Controllers/`: login, administración, módulos, accesos, integraciones y horas extra.
- `Models/`: identidad central, módulos, permisos, categorías, unidades, configuración y auditoría.
- `Repositories/`: acceso a usuarios, permisos, salud, respaldos, settings e integraciones.
- `Services/`: identidad, validación, notificaciones, salud, acceso a proyectos y consolidación de horas extra.
- `Support/`: manejo seguro de secretos y compatibilidad de sesiones PHP legacy.
- `views/`: home, login, administración, integraciones, módulos, Telegram y horas extra.

La identidad central vive en `usuarios_nova`. Los permisos globales se relacionan mediante `modulos_nova` y `permisos_usuario_modulo`; las credenciales personales externas viven en `integraciones_usuario`.

### Redmine TIC: `RedmineTic/`

Es un módulo Laravel nativo con namespace propio `RedmineTic\`:

- Controlador principal: `RedmineDashboardController`.
- Repositorios separados para reportes, usuarios, permisos, catálogos, configuración, horas extra, actividad y estadísticas.
- Servicios para envío y actualización de issues, sincronización de membresías e importación legacy controlada.
- Modelos Eloquent para reportes, perfiles, permisos y actividad.
- Vistas Blade por sección: dashboard, usuarios, categorías, unidades, histórico, horas extra, estadísticas, webhook, actividad y configuración.

Las rutas `/redmine_tic/app/*` apuntan a este módulo. Las rutas antiguas redirigen al dashboard nativo, reduciendo la superficie legacy.

### Redmine Mantención: `RedmineMantencion/`

Es el módulo de mayor tamaño y principal zona híbrida:

- Código moderno: controlador, modelos, repositorios, clientes externos y servicios.
- Código legacy: `controllers/*.php`, `views/**/*.php` y un micro-MVC bajo `app/`.
- Integraciones: Redmine, CORE, Nextcloud/WebDAV y procedimientos.
- Assets propios: hojas CSS por pantalla, JavaScript, imágenes y plantillas XLSX.
- Datos locales aún presentes: documentos, imágenes y logs bajo `data/`.

Laravel expone el módulo bajo `/redmine-mantencion/app/*`. `LegacyProjectController` despacha solo las pantallas expresamente mapeadas. Procedimientos ya se redirige al módulo nativo dedicado.

### EMACH: `Emach/`

Módulo pequeño de integración con sistema externo:

- Cliente/scraper externo y repositorio de credenciales.
- Servicios para consulta y cálculo/sugerencia de horas extra.
- Entrada legacy `index.php`, horario y monitor CLI.
- Vistas parciales y tema propio.

El acceso web sigue siendo legacy, pero está encapsulado detrás de Laravel.

### Procedimientos: `Procedimientos/`

Módulo nativo y acotado:

- Controlador de documentos y navegador.
- Servicio de salud de OnlyOffice.
- Generación/validación JWT para OnlyOffice.
- Vistas Blade fuente en `resources/views/procedimientos/`.

Se integra directamente con Nextcloud y OnlyOffice; incluye endpoints públicos específicos para documento y callback, mientras la gestión permanece autenticada.

### Telegram

Está dividido en dos capas:

- `app/Modulos/Telegram/`: lógica Laravel, configuración, cliente HTTP y comandos.
- `telegram/`: proceso CLI persistente, listener, cola, librería y parciales legacy.

`telegram/bin/service.php` es el proceso principal del contenedor. Debe existir un solo consumidor `getUpdates` por bot.

### Monitor de Servidores

Vive en `app/Modulos/MonitorServidores/` y se ejecuta de dos formas:

- UI y endpoints dentro de Laravel.
- Daemon Docker con `php artisan nova:monitor-servers --daemon --sleep=10`.

Soporta comprobaciones ICMP/Ping, TCP, HTTP/HTTPS, historial, ventanas de mantenimiento y alertas Telegram.

### Otras carpetas relevantes

| Ruta | Responsabilidad |
|---|---|
| `config/` | Laravel, seguridad, módulos y reglas globales NOVA |
| `database/migrations/` | esquema reproducible y evolución de datos |
| `public/` | front controller y assets públicos compartidos |
| `resources/` | entradas Vite y vistas Blade fuera de módulos |
| `routes/` | web, API, consola y broadcasting |
| `tests/` | pruebas unitarias, feature y políticas de producción |
| `ops/docker-host/` | copia auditable de la instalación Docker del host |
| `ops/production/` | builder y verificador de release por allowlist |
| `scripts/prod/` | preflight, deploy, backup, restore, rollback y smoke tests |
| `docs/` | diseño, runbooks, smoke tests y plantillas legacy |
| `storage/` | logs, sesiones, cache y estado runtime Laravel |

## 5. Registro y acceso a módulos

`config/modules.php` registra nueve entradas funcionales:

| Clave | Tipo | Entrada principal |
|---|---|---|
| `redmine_tic` | nativo | `redmine.native.dashboard` |
| `redmine-mantencion` | nativo/híbrido | `redmine.mantencion.dashboard` |
| `emach` | legacy integrado | `Emach/index.php` |
| `telegram` | nativo + daemon | `telegram.index` |
| `procedimientos` | nativo | `procedimientos.index` |
| `horas-extra` | nativo | `horas-extra.index` |
| `monitoreo-servidores` | nativo + daemon | `monitor.dashboard` |
| `integraciones` | nativo, oculto del home | `integrations.nova` |
| `administracion` | nativo | `administracion.index` |

Los roles `admin` y `root` pueden administrar NOVA, pero solo `root` omite permisos específicos de módulos. Esta distinción evita que un administrador global se transforme automáticamente en superusuario operacional de cada módulo.

## 6. Persistencia y modelo de datos

Las 71 migraciones registran una evolución desde tablas Laravel iniciales y almacenamiento JSON hacia un esquema relacional por dominios.

### Núcleo de identidad

- `usuarios_nova`: ficha única de usuario.
- `modulos_nova`: catálogo y estado de módulos.
- `permisos_usuario_modulo`: acceso y rol por módulo.
- `integraciones_usuario`: cuentas y secretos externos cifrados.
- `nova_settings`: configuración global clave/valor.
- `nova_audit_logs`: eventos globales, autenticación y seguridad.

### Dominios operacionales

- Redmine TIC mantiene reportes, perfiles, permisos, catálogos, opciones, horas extra y actividad en tablas propias.
- Mantención mantiene reportes y permisos propios y comparte catálogos/configuración cuando corresponde.
- Horas extra fue consolidado en tablas compartidas con adaptadores TIC/Mantención.
- Procedimientos, monitoreo y Nextcloud tienen migraciones dedicadas.
- Los campos JSON que permanecen, como contexto libre de eventos, son intencionales; no deben confundirse con el antiguo almacenamiento de archivos JSON.

La regla arquitectónica correcta es tratar las migraciones como definición autoritativa del esquema, no los dumps SQL ni documentos paralelos.

## 7. Seguridad y control de acceso

Controles positivos observados:

- Login y extensión de sesión con throttling.
- Logout exclusivamente por POST y CSRF.
- Middleware global de cabeceras de seguridad.
- CSRF en el grupo web y adaptación explícita en flujos legacy.
- Rutas autenticadas agrupadas bajo `nova.auth`.
- Acceso modular separado de los permisos internos de cada Redmine.
- Secretos personales centralizados y cifrados mediante repositorios.
- Contrato para evitar dependencia directa Core → Redmine TIC.
- Producción construida por allowlist, con manifest y verificador de archivos prohibidos.
- Apache bloquea dotfiles, dumps, logs, claves, `vendor`, `storage`, `.git` y otros paths sensibles.

Puntos que requieren atención:

1. El repositorio contiene artefactos que no deberían formar parte del código: varios dumps SQL, logs, archivos de entorno auxiliares y un respaldo JSON histórico.
2. `redmine_tic/README.md` está obsoleto y contiene una credencial/API key de ejemplo incrustada. Debe asumirse comprometida y rotarse si alguna vez fue válida; no se reproduce su valor en este informe.
3. `RedmineMantencion/data/` contiene documentos, imágenes y logs versionados. Debe clasificarse qué es fixture autorizado y qué es información operacional o personal.
4. El Apache Docker auditable monta `/var/www` completo y usa `/var/www/html` como `DocumentRoot`, aunque la política de release exige publicar únicamente `public/`. Las reglas de denegación reducen el riesgo, pero la configuración objetivo debería alinear físicamente el DocumentRoot con el artefacto `/public`.
5. El runbook de despliegue mantiene decisión `NO-GO`; por tanto, no debe interpretarse la existencia de scripts como aprobación de producción.

## 8. Docker e infraestructura

### Topología prevista

```text
                         nova_web
Internet/Host :80 ---> apache-web
                         |
                         | nova_backend (privada/interna)
       +-----------------+------------------+
       |                 |                  |
    mariadb          phpmyadmin         OnlyOffice
       |                                    |
       +------------ workers ---------------+
                    |          |
              nova-telegram  nova-monitor
                    |          |
                    + nova_egress ----------> Internet/APIs
```

### Redes

- `nova_web`: frente de Apache.
- `nova_backend`: red compartida privada; debe existir previamente y se documenta como `internal: true`.
- `nova_egress`: salida para OnlyOffice, Telegram y Monitor, sin puertos publicados.

Los Compose de los módulos declaran `nova_backend` y `nova_egress` como redes externas. Esto significa que `docker compose up` no las crea y fallará si la preparación del host no se ejecutó.

### Servicios

#### `apache-web`

- Imagen propia basada en `php:8.2-apache`.
- Instala Composer, `mbstring`, `intl`, `bcmath`, `pdo_mysql`, `zip` y OPcache.
- Habilita rewrite, headers, expires y módulos proxy.
- Publica el único puerto del conjunto, normalmente `80:80`.
- Monta `/var/www` y configuración Apache desde `/opt/docker/apache-web`.
- Hace reverse proxy de `/onlyoffice/` al contenedor `OnlyOffice`.
- Tiene healthcheck HTTP cada 30 segundos.

#### `mariadb`

- Imagen `mariadb`, tag configurable y actualmente predeterminado a `12.3`.
- Credenciales vía `env_file` externo al repositorio.
- Datos en volumen externo persistente `mariadb_data`.
- No publica puerto al host.
- Incluye `phpmyadmin` solo en la red backend y dependiente de la salud de MariaDB.

#### `OnlyOffice`

- Imagen `onlyoffice/documentserver:latest`.
- Persistencia dividida en volúmenes externos para datos, PostgreSQL, RabbitMQ, Redis, logs, documentos y fuentes.
- Configuración sensible mediante archivo externo.
- Sin puerto host; acceso solo por proxy Apache.
- Conectado a backend y egress.

Usar `latest` facilita actualizaciones, pero reduce reproducibilidad. Conviene fijar una versión probada y actualizarla deliberadamente.

#### `nova-telegram`

- Imagen propia basada en `php:8.2-cli-alpine`.
- Extensiones: curl, mbstring, pcntl y pdo_mysql.
- Monta el checkout en `/app`.
- Ejecuta `telegram/bin/service.php` con reinicio `unless-stopped`.
- Consume base de datos y variables Telegram.
- Requiere backend para MariaDB y egress para la API de Telegram.

#### `nova-monitor`

- Imagen CLI Alpine equivalente, agregando `iputils` para Ping/ICMP.
- Ejecuta el comando Artisan en modo daemon cada 10 segundos.
- Healthcheck propio mediante `--healthcheck`.
- Comparte código y `.env` con el host en la definición operacional.
- Requiere acceso a MariaDB y a destinos externos.

### Dos niveles de Compose

El repositorio conserva dos conjuntos:

- `docker-compose.telegram.yml` y `docker-compose.monitor.yml`: uso desde el checkout, práctico para desarrollo o instalación directa.
- `ops/docker-host/*/compose.yaml`: copia auditable de la instalación real prevista en el servidor, con rutas absolutas y secretos externos.

Esta duplicación es razonable si se mantiene explícitamente como “desarrollo” versus “host”, pero debe existir una validación periódica para evitar divergencia entre ambas definiciones.

### Estado runtime

El cliente Docker está instalado, pero el usuario de esta sesión no tiene permiso para consultar el daemon. Por ello este análisis confirma la configuración declarada, no que los contenedores estén actualmente levantados o saludables. La validación operacional debe ejecutar con un usuario autorizado:

```bash
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}\t{{.Networks}}'
docker network inspect nova_backend --format 'internal={{.Internal}}'
docker compose -f docker-compose.telegram.yml ps
docker compose -f docker-compose.monitor.yml ps
```

## 9. Frontend y sistema visual

El frontend combina:

- Vite para `resources/css/app.css` y `resources/js/app.js`.
- `public/assets/nova-ui.css` como sistema visual global.
- CSS/JS especializados para Redmine TIC, administración y monitor.
- Temas locales en Mantención, EMACH y Telegram.
- Bootstrap y Bootstrap Icons en vistas legacy.

La dirección correcta es extender primero `nova-ui.css` y conservar los CSS locales solo cuando representen comportamiento específico del módulo. Mantención todavía tiene once hojas CSS y concentra la mayor deuda de consolidación visual.

No existen scripts `npm test` ni `npm lint`; solo `npm run dev` y `npm run build`.

## 10. Pruebas y calidad

La suite contiene 50 archivos de prueba distribuidos en:

- `tests/Unit/`: identidad, secretos, permisos, Redmine, Telegram, CORE, EMACH, horas extra, catálogos y monitor.
- `tests/Feature/`: autenticación, acceso modular, permisos y endpoints del monitor.
- `tests/Production/`: compatibilidad de migraciones y política de artefactos de producción.

Fortalezas:

- Cobertura orientada a reglas críticas, no solo ejemplos de framework.
- Pruebas específicas para aislamiento de permisos y secretos.
- Validación de políticas de producción incluida en el propio repositorio.
- `composer.lock` y `package-lock.json` permiten builds reproducibles.

Limitaciones visibles:

- No hay pipeline CI visible en el inventario revisado.
- No hay lint/test frontend configurado.
- Las pruebas con DB requieren un entorno aislado y no se ejecutaron como parte de este inventario documental.
- La presencia de archivos operacionales versionados indica que la política de artefactos no está aplicada a todo el working tree, aunque sí al builder de release.

## 11. Operación y despliegue

Existen dos líneas operacionales:

1. `ops/production/`: construye un artefacto por allowlist, instala dependencias desde lockfiles, compila assets, genera hashes y verifica archivos prohibidos.
2. `scripts/prod/`: preflight, respaldo, despliegue, smoke test, verificación, rollback y restore.

El enfoque de release inmutable y DocumentRoot limitado a `public/` es apropiado. El runbook exige backup validado, migraciones controladas, rollback ensayado y observación posterior. Sin embargo, declara explícitamente `NO-GO` hasta resolver los prerrequisitos de auditoría; esto debe conservarse como bloqueo formal y no como comentario informativo.

El scheduler Laravel ejecuta:

- `redmine:archive-processed` cada hora.
- `nova:health-alerts` cada cinco minutos, sin solapamiento.

Debe garantizarse un único `schedule:run` en producción para evitar ejecuciones duplicadas.

## 12. Hallazgos priorizados

### Prioridad crítica

1. **Retirar y custodiar artefactos sensibles versionados.** Se observaron dumps SQL, logs, archivos auxiliares de entorno y respaldo JSON histórico dentro de Git. Antes de eliminarlos debe existir copia forense cuando corresponda, clasificación de datos, rotación de secretos potencialmente expuestos y limpieza del historial Git mediante un procedimiento aprobado.
2. **Rotar la credencial incrustada en documentación legacy.** `redmine_tic/README.md` contiene un API key literal. Aunque parezca histórico, debe considerarse expuesto.
3. **Alinear el web root real con `public/`.** La configuración productiva objetivo exige que ninguna otra carpeta sea accesible físicamente desde Apache. Las reglas `<FilesMatch>` son defensa adicional, no sustituto de un DocumentRoot mínimo.

### Prioridad alta

4. **Clasificar `RedmineMantencion/data/`.** Documentos, imágenes y logs no deberían mezclarse con código salvo fixtures sintéticos expresamente aprobados.
5. **Validar el runtime Docker con acceso autorizado.** Confirmar salud, redes, puertos, reinicios, mounts y que solo Apache publique al host.
6. **Fijar versiones de imágenes.** Sustituir `onlyoffice/documentserver:latest` por una versión ensayada; revisar también la disponibilidad real del tag MariaDB configurado antes del próximo build.
7. **Resolver contradicciones documentales.** `AGENTS.md` conserva rutas antiguas (`app/...`, `redmine_tic/...`) mientras Composer y el runtime usan `Nova/`, `RedmineTic/`, `RedmineMantencion/` y `Emach/`.

### Prioridad media

8. **Definir CI obligatorio.** Ejecutar Composer validation/audit, Pint, PHPUnit, migración limpia/upgrade, `npm ci`, build, política de artefacto y scanner corporativo de secretos.
9. **Agregar control frontend.** Incorporar lint y, donde aporte valor, pruebas JS.
10. **Reducir divergencia Compose.** Comparar automáticamente Compose local y Compose de host en cada cambio operacional.
11. **Continuar modernización de Mantención.** Es el módulo con más PHP procedural, estilos locales y datos dentro de su árbol.

## 13. Fortalezas del proyecto

- Separación modular clara y autoload PSR-4 explícito.
- Identidad central única y permisos de módulo normalizados.
- Persistencia relacional como fuente de verdad.
- Frontera Core/TIC mediante contrato e inyección de dependencias.
- Buen uso de repositorios y servicios en módulos modernizados.
- Seguridad web transversal y protección de rutas legacy.
- Migraciones extensas que documentan la evolución real del esquema.
- Suite de pruebas enfocada en permisos, secretos e integraciones críticas.
- Sistema visual compartido y capas responsivas centralizadas.
- Diseño Docker con un único punto de entrada y separación backend/egress.
- Herramientas de release, verificación, backup, restore y rollback presentes.

## 14. Conclusión

NOVA ya no es simplemente una colección de aplicaciones PHP: es una plataforma Laravel modular que centraliza gobierno, identidad y operación, mientras encapsula componentes legacy que todavía son necesarios. La estructura actual permite continuar migrando por módulo sin una reescritura total.

La prioridad inmediata no debería ser reorganizar nuevamente namespaces o tablas, sino cerrar la brecha entre arquitectura y operación: sanear artefactos versionados, rotar credenciales expuestas, hacer que Apache publique únicamente `public/`, comprobar el runtime Docker y convertir las validaciones existentes en un CI obligatorio. Después de eso, la reducción progresiva del PHP procedural de Mantención y la consolidación frontend son las líneas naturales de evolución.

Este documento describe el estado observado del repositorio en el commit indicado. No reemplaza una auditoría de base de datos en ejecución, una revisión de secretos/historial Git ni una inspección del daemon Docker con permisos operacionales.
