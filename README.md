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
- OnlyOffice usa `ALLOW_PRIVATE_IP_ADDRESS=true` para poder descargar desde las
  URL temporales privadas de NOVA; `ALLOW_META_IP_ADDRESS` se mantiene en
  `false`. Al modificar estos valores se debe recrear el contenedor.

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
npm install
npm run build
```

Configure en `.env` la URL de la aplicación, la conexión de base de datos, las
sesiones y las integraciones necesarias. No almacene contraseñas, tokens,
cookies, respaldos ni volcados SQL dentro del repositorio.

Para preparar la base, siga [Recuperación y migraciones](#recuperación-y-migraciones-p07).
La cadena histórica desde cero no reproduce exactamente el esquema operativo.
La línea base versionada es una excepción al veto de volcados: contiene solamente
estructura e historial de migraciones, sin filas de la aplicación ni secretos.
Al recuperar una instalación, conserve su `APP_KEY`; genere una nueva solo para
una instalación independiente sin credenciales cifradas que recuperar.

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

Para preparar, revisar, confirmar y publicar todos los cambios no ignorados en
`main` y `desarrollo` con una sola operación:

```bash
./scripts/git-publicar.sh "descripcion breve del cambio"
```

También puede ejecutarse sin argumentos; en ese caso solicitará el mensaje del
commit directamente en la terminal:

```bash
./scripts/git-publicar.sh
```

El script cambia automáticamente a `main` cuando corresponde, actualiza las
ramas solo mediante avance rápido, ejecuta `git add -A`, valida el diff y muestra
los archivos antes de pedir confirmación. Después publica `main`, intenta
fusionarla en `desarrollo` y vuelve a `main`. Si la fusión presenta conflictos,
la cancela automáticamente para no dejar el repositorio en un estado incompleto.

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
RedmineMantencion/      Módulo Redmine Mantención y bridge legacy
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
- `destinatarios_informes_modulo` define, por módulo y usuario, quién recibe su
  informe individual de tickets abiertos y quién actúa como jefatura para
  recibir el resumen consolidado. El envío programado usa de lunes a domingo
  de la semana anterior; la comprobación manual usa los siete días exactos
  anteriores a su ejecución.
- Las reimportaciones desde CORE identifican la solicitud por `id_core`: si el
  reporte sigue pendiente, actualizan sus datos modificados; los reportes
  procesados, con error o archivados no se sobrescriben. El detalle de la
  solicitud (incluidas sus filas, RUN, motivo, establecimientos y permisos)
  se conserva en `redmine_mantencion_reportes.core_detalle`; requiere ejecutar
  las migraciones antes de importar nuevamente.
- Los repositorios encapsulan el acceso a datos; los controladores coordinan los
  casos de uso.
- La edición administrativa NOVA escribe los campos modificados de la cuenta
  seleccionada y detecta conflictos ocurridos durante el guardado. La contraseña
  solo se incluye cuando se solicita cambiarla; email, Chat ID y secretos
  personales conservan sus propios escritores.
- TIC guarda las claves de permisos y su eliminación de claves obsoletas en una
  transacción. El perfil y sus permisos se confirman juntos por usuario; un fallo
  de escritura devuelve error al flujo administrativo.
- La deduplicación implícita de usuarios NOVA vuelve a leer identidades e
  integraciones bajo bloqueo antes de guardar las fichas que cambian. Conserva
  las reglas existentes de combinación y no elimina las identidades originales.
- Mantención edita, importa cambios CORE, reinicia errores y archiva mediante
  escrituras por reporte, comparando los campos cambiados con la lectura inicial.
  Cada respuesta de envío a Redmine se guarda individualmente. Si Redmine acepta
  un ticket pero falla su guardado local, el lote se detiene y muestra el ID
  recibido para revisarlo antes de reintentar.
- La configuración de Mantención guarda parámetros y opciones en una transacción;
  las operaciones sobre opciones incluyen su valor predeterminado. Un fallo
  revierte la operación e informa el error en el formulario.
- No se deben reintroducir archivos JSON como almacenamiento de runtime.

Las credenciales externas son personales. Nunca deben mostrarse en vistas
administrativas, registrarse en bitácoras ni almacenarse en historiales.

## Envíos e históricos Redmine

TIC y Mantención reservan cada envío en `redmine_send_attempts` antes del POST.
La reserva excluye intentos simultáneos sobre el mismo módulo y reporte; la
transacción termina antes de consultar Redmine. La tabla conserva el UUID del
intento, resultado HTTP y ticket confirmado, sin API keys ni payloads. No cambia
los estados de negocio visibles ni prohíbe un nuevo envío secuencial después de
una confirmación guardada. Una respuesta incierta, un proceso interrumpido o una
confirmación pendiente de guardado mantienen la reserva hasta conciliación.
No hay liberación por vencimiento ni repetición automática del POST.

La migración `2026_09_14_000000_create_redmine_send_attempts` quedó aplicada en
la BD operativa `nova` el **16-09-2026**, después de verificar un respaldo cifrado
y su restauración aislada. Se comprobó que no cambiaron los datos ni estructuras
preexistentes y que la conciliación de consulta funciona. En esa comprobación no
había reservas ni migraciones pendientes. Los emisores TIC/Mantención pasaron
pruebas con transporte simulado; la verificación de la instalación web/servicios
activos queda pendiente de identificar su servidor, porque en este equipo no
estaban ejecutándose Apache ni los workers NOVA. No se enviaron tickets reales.

El respaldo y los recibos privados de esta activación están fuera del proyecto:
`/home/jean/.local/share/nova-backups/p04-20260916-r2/`. La clave de recuperación
se conserva separada en `/home/jean/.local/share/nova-backup-keys/`, con permisos
restringidos. No incluir esos archivos en Git ni publicar su contenido. El SQL
sin cifrar y la base restaurada temporal se eliminaron tras la comprobación.
Este respaldo contiene la BD previa a P04; no incluye archivos de usuario ni
`APP_KEY`, y no sustituye la recuperación integral pendiente de P07.

Para otras instalaciones, preparar respaldo SQL, comprobar
la migración en un entorno equivalente y coordinar la actualización de todos los
emisores web/CLI. La migración es aditiva; no modifica reportes existentes. Si
falta la tabla o no puede reservarse el reporte, el envío falla antes del POST.

```bash
php artisan migrate --path=database/migrations/2026_09_14_000000_create_redmine_send_attempts.php --force
php artisan redmine:reconcile-send
php artisan redmine:reconcile-send UUID_DEL_INTENTO
```

El último comando solo muestra la reserva. Para resolverla, un operador debe
verificar el ticket en Redmine (proyecto, contenido y correspondencia con el
reporte) y comprobar que el proceso emisor terminó o fue detenido. Registrar una
referencia de esa revisión, sin secretos:

```bash
php artisan redmine:reconcile-send UUID_DEL_INTENTO --ticket=12345 --note="Revisión operativa del reporte" --writer-stopped --apply
# Solo si se comprobó que Redmine NO creó el ticket:
php artisan redmine:reconcile-send UUID_DEL_INTENTO --not-created --note="Ausencia de ticket verificada" --writer-stopped --apply
```

La conciliación con ticket actualiza el vínculo local y libera la reserva en una
transacción; conserva el estado archivado cuando corresponde. No puede descartar
ni sustituir un ticket confirmado en la respuesta guardada. El comando no envía
solicitudes a Redmine; la comprobación remota es manual. La vuelta de código debe
conservar esta tabla y conciliar intentos antes de reanudar escritores antiguos;
el `down()` rechaza retirar una tabla con registros.

Los históricos comprueban la API con la credencial personal (hasta 5 segundos).
Cada ejecución tiene hasta 29 segundos para consultas externas, incluida esa
comprobación, y el navegador cancela a los 30 segundos. Cada consulta individual
conserva su límite de 5 segundos; las páginas de la sincronización completa TIC
usan hasta 20 segundos, siempre limitadas por el presupuesto restante. El plazo
no sustituye los límites del servidor web o de la conexión a la base de datos.

Ante caída de transporte, 401/403, 429 o 5xx, se detienen las consultas pendientes;
un 404 individual permite seguir con los demás tickets. Se conservan el ID y
último estado válido. El botón **Continuar sincronización** consulta los IDs
pendientes de la pantalla, sin bucles automáticos. El botón completo TIC conserva
el desplazamiento por usuario/proyecto en la sesión y continúa desde esa página;
una respuesta parcial no anuncia sincronización completa. En Mantención se
reaplica el filtro al completar la consulta sin iniciar otra ronda automática.

## Integridad de horas extra

Las operaciones de jornadas y vínculos se guardan en una transacción. El
archivado y la desactivación de horas extra incluyen sus cambios asociados; si
falla una escritura, se revierte la operación. Al eliminar reportes, se retiran
únicamente los vínculos de su origen y de los IDs realmente seleccionados en la
base, conservando otros reportes y los vínculos del otro módulo.

La creación de una jornada para un usuario identificado coordina operaciones
concurrentes sobre ese usuario. La vinculación y la eliminación de un grupo vacío
comparten un bloqueo: una vinculación tardía informa que la jornada ya no existe.
No se eliminan otros grupos vacíos ni se sanea el histórico automáticamente.

Se mantienen las reglas actuales: TIC retira sus vínculos antes de reconstruirlos;
Mantención conserva vínculos anteriores. La edición por fecha sigue afectando a
las jornadas de ese origen y fecha, y los horarios entrantes no vacíos conservan
su precedencia sobre el horario guardado. También se mantienen el cálculo de
minutos, las jornadas sin usuario y los vínculos históricos múltiples. Cambiar
esa precedencia o conciliar datos existentes requiere una decisión separada.
Esta mejora no requiere una migración adicional.

## Consultas de usuarios e histórico

Las consultas de notificación TIC comparan `asignado_a` con parámetros string,
acordes a su tipo VARCHAR. Se mantiene la validación previa del identificador;
no se convierten filas almacenadas ni se equiparan variantes numéricas de texto.

En MariaDB, `NovaUserRepository::find()` busca la identidad y comprueba duplicados
en una consulta SQL sin transferir el directorio a PHP. Carga la ficha y las
integraciones únicamente del usuario seleccionado. Conserva la normalización
ASCII por bytes, los identificadores aceptados y el orden anterior; ante
duplicados utiliza la resolución y reparación existentes. SQL sigue examinando
identidades: no es una búsqueda íntegramente indexada ni una política nueva.
La comprobación de duplicados cuenta huellas binarias de tamaño fijo, evitando
agrupar el resultado de texto largo del normalizador. Las huellas solo deciden
si se necesita la conciliación anterior: nunca seleccionan una cuenta. La
selección conserva la comparación normalizada exacta tras un prefiltro LIKE.

Los listados TIC y la resolución de nombres usan `users(false)` para excluir
contraseñas y credenciales. Los consumidores legacy de `users()` conservan su
contrato; sus credenciales Redmine se precargan por lote, con la misma prioridad
de credencial canónica/heredada. El resultado no se cachea entre peticiones.
La proyección comprueba si existe `usuarios_nova.email`: S31 eliminó esa columna
y consultarla incondicionalmente dejaba vacía la lista de usuarios/nombres.

El histórico Mantención aplica filtros, alcance, deduplicación, conteo y paginación
en SQL y carga los detalles completos de la página. Solo proyecta las columnas
necesarias para los filtros activos. Conserva las coincidencias por nombre, la
prioridad de las fuentes y los empates del orden anterior. Los selectores se
construyen con pares distintos agregados en SQL, conservando sus etiquetas y
orden PHP. Las fechas cero y los textos fuera del alfabeto normalizado se
interpretan con los helpers anteriores; no se aproximan mediante una collation.
Una búsqueda por descripción evalúa ese campo en SQL y lee únicamente los valores
excepcionales que necesitan la transliteración PHP. El modal conserva su detalle.

La bitácora TIC aplica alcance, conteo y paginación en SQL y lee los contextos de
la página, sin cargar `linea`. Conserva filtros y opciones de eventos. JSON con
IDs no textuales, claves repetidas/escapadas o profundidad excepcional mantiene
la interpretación PHP anterior antes de autorizar su lectura.

El histórico de reportes TIC usa `RedmineHistoryRepository` para filtros, conteos
y paginación SQL. Resuelve el alcance con los mismos nombres/permisos del lector
anterior, hidrata los reportes de la página y conserva totales de horas extra,
catálogos, opciones y detalle del modal. Las búsquedas no ASCII mantienen la
transliteración PHP de la pantalla; las fechas vacías/incompletas conservan sus
reglas. Si coinciden fecha normalizada, creación y actualización entre filas
autorizadas, utiliza el lector previo: su orden implícito no permite sustituirlo
por otro desempate sin cambiar resultados. El aviso de mantención ahora consulta
solo configuración, evitando cargar nuevamente todo el histórico desde el encabezado.

Estadísticas TIC proyecta los campos usados por los gráficos, conservando el
normalizador de fechas, los catálogos y la agregación anterior. No transporta
descripciones ni mensajes cuando el orden es inequívoco. Si existen empates de
creación/actualización que puedan cambiar el orden de las etiquetas, o el
repositorio ya cargó reportes, conserva la lectura anterior.

Actividad Mantención cuenta y pagina en SQL y carga los detalles de la página.
Para usuarios restringidos resuelve primero los actores desde `id/contexto`,
con el mismo decodificador PHP; solo los eventos que infieren el actor desde el
texto necesitan leer `detalle` antes de paginar. Conserva coincidencias por ID
y nombre, redacción de secretos, opciones y orden. Los contextos y las listas
de IDs autorizados aún crecen con los candidatos; no se agregó una columna de actor.

Estas rutas conservan su lector previo para drivers distintos de MySQL/MariaDB.
Las pruebas de equivalencia y carga usan MariaDB 12.3.2 aislada. El beneficio
medido es principalmente menor transferencia y memoria; las expresiones de
normalización todavía recorren datos y los tiempos dependen de la distribución
y la red. Los valores excepcionales y las opciones de selectores pueden crecer
con el conjunto consultado. No se cambiaron índices, esquema ni datos operativos.
Los ensayos alternados de identidad muestran menor latencia frente a la consulta
SQL previa con 50, 500 y 2.000 usuarios. Con 50 usuarios la proyección PHP antigua
sigue siendo ligeramente más rápida; no se certifica el tiempo del login remoto.
P06 mantiene pendientes la comprobación en el despliegue, los caminos excepcionales
y los costes restantes de dashboards (escrituras de lotes, resultados amplios en
Mantención y otros listados aún amplios).
La revisión por pantalla y las mediciones están en el plan de mejoras de `Auditoria/`.

Las acciones individuales de dashboard también evitan leer todos los reportes.
TIC autoriza un ID mediante la misma consulta acotada usada por las acciones
masivas. Mantención carga el reporte elegido y conserva los procesados completos
cuando el POST ejecuta retención; el endpoint independiente de hora extra solo
carga el elegido. El envío masivo Mantención carga completos los seleccionados y
los procesados necesarios para esa misma retención. CSRF, permisos, orden,
retención, respuestas y escrituras
mantienen el flujo anterior. Ante una consulta incompleta o fallida se usa el
lector completo conservador.

Los dashboards amplios evitan trabajo duplicado: Mantención cuenta estados en
una pasada y consulta logs Redmine solo para reportes visibles con error; TIC
calcula una vez el resumen del alcance. La lista completa de reportes visibles
sigue disponible con todos sus detalles para los modales actuales.

El archivado masivo Mantención actualiza solo estado y fecha de cambio por
reporte; conserva la comprobación de concurrencia y la transacción individual
con horas extra. El restablecimiento masivo reutiliza categorías y la inspección
del esquema durante el lote, sin alterar las escrituras y errores parciales.

El borrado masivo de TIC y Mantención desvincula en lote los reportes de horas
extra. Bloquea primero los reportes y después las jornadas en orden, vuelve a leer
los vínculos tras adquirir los bloqueos, elimina solo el origen/IDs seleccionados
y limpia únicamente jornadas que quedan vacías. El borrado del reporte y sus
vínculos conserva una sola transacción y rollback conjunto.

El listado de usuarios TIC consulta perfiles y permisos solamente de los miembros
con acceso al proyecto. Conserva sus campos, roles, estados, orden y credenciales
cuando se solicitan; la lectura completa de permisos sigue disponible para otros
consumidores. Los IDs fuera del rango entero PHP conservan el recorrido previo.
Los selectores Mantención de dashboard, Pendiente Manual, estadísticas y
configuración usan una proyección sin contraseñas ni secretos de integraciones,
manteniendo los usuarios externos CORE/Nextcloud y los permisos. Ante nombres
empatados según la intercalación SQL, usan el lector anterior y vacían los secretos
de la respuesta para conservar el orden. El login mantiene la lectura completa.
No se cachean usuarios ni permisos entre llamadas. La revisión de P06 registra también un fallo previo al
eliminar el último rol TIC persistido, reproducido en el entorno de pruebas.

Los listados administrativos NOVA/Mantención usan indicadores de credenciales
configuradas y entregan sus campos secretos vacíos a las vistas. NOVA aplica la
misma proyección a la matriz administrativa de accesos; conserva sus dos lecturas
independientes. Si hay identidades duplicadas o nombres empatados, vuelve al lector
completo, incluidas sus reparaciones y bloqueos. El recorrido ordinario consulta
solo indicadores de presencia para EMACH/Nextcloud, dentro de una transacción de
lectura, sin transferir sus secretos. Mantención usa la proyección solo en GET/HEAD:
CORE/Nextcloud se leen como indicadores, pero Redmine aún se descifra para conservar
la diferencia entre tokens cifrados vacíos y credenciales heredadas. Los POST
conservan las credenciales completas. Si hay configuración global Nextcloud por
migrar, se recuperan nuevamente las filas completas antes de ejecutar el flujo
de migración y guardado anterior. Los indicadores conservan la semántica previa;
no constituyen una validación remota de las credenciales.

Horas extra TIC consulta los reportes archivados vinculados a sus grupos en
lugar de cargar todo el histórico del módulo. Conserva el orden de los vínculos,
la conversión de IDs y la conciliación por fecha de inicio. Mantención distribuye
los reportes entre grupos en una sola pasada y transforma cada reporte una vez,
conservando el orden SQL de fecha/ID y la precedencia de horarios de las jornadas.
No se modifican guardado, fechas, permisos, totales, años disponibles ni filtros.
Las pantallas de horas extra leen primero IDs, asignados y fechas para conciliar
y calcular los años disponibles. Después de filtrar por usuario y período,
cargan los detalles necesarios dentro de la misma transacción. Mantención conserva
todas las contribuciones de registros con el mismo ID público, incluso si alguno
individualmente tiene otro asignado; el permiso se aplica al resultado conciliado,
como antes. Los horarios proceden de la conciliación completa. Los metadatos y
vínculos aún crecen con el conjunto; las lecturas completas para otros consumidores
mantienen su contrato. La prueba concurrente usa REPEATABLE READ en MariaDB aislada.

Estadísticas Mantención filtra primero una proyección de campos de fecha,
categoría, unidad y asignado, con los normalizadores existentes, y después carga
los detalles de los IDs seleccionados. Conserva por separado reportes archivados,
mensajes activos y vínculos de horas extra, incluidas sus repeticiones y orden.
La respuesta mantiene todos los campos de los reportes para los listados; no se
modifican el filtro del gestor ni el control de acceso del controlador. Sin filtros
se usa la lectura completa anterior para evitar consultas de metadatos adicionales.
Los filtros y la agregación siguen en PHP; los candidatos y los resultados visibles
pueden crecer con el conjunto. El beneficio principal es evitar transferir textos
de reportes excluidos. No se agregaron índices ni se modificaron datos.

Los dashboards seleccionan primero los reportes mediante una proyección de ID,
estado y asignado, aplicando los mismos filtros de permisos existentes. TIC carga
después el detalle del estado seleccionado; mantiene el lector completo cuando
hay fechas de creación empatadas o reportes ya cargados en la caché de instancia.
Mantención optimiza GET/HEAD y conserva todos los estados visibles y los
procesados vencidos necesarios para la retención automática, incluso de otros
usuarios. Proyecta también las fechas y reutiliza un único umbral de retención
durante la selección y el archivado del request. Los reportes ajenos recientes
no necesitan cargar sus detalles. Los lectores sin umbral mantienen la selección
anterior de todos los procesados.
Los POST conservan completos los reportes que pueden modificar. Las consultas de selección y detalle
comparten una transacción; la retención mantiene su ejecución y transacciones
de escritura anteriores. Se conservan orden, contadores, IDs públicos repetidos,
detalles y acciones masivas. No se añade paginación ni se cambia la lógica de
permisos. `DashboardReadQueryTest` compara ambos recorridos y mide el ahorro de
memoria con reportes extensos fuera del alcance visible. Los resultados amplios,
los empates TIC y la retención todavía pueden requerir lecturas completas.
Si falla la lectura parcial de detalles TIC, el dashboard vuelve al lector
completo para no mostrar una tabla vacía con contadores no vacíos.

La implementación local de P06 pasó 179 pruebas de integración y las pruebas
Chromium de modales y archivado. Los históricos TIC/Mantención vuelven a sus
lectores compatibles si falla la página SQL optimizada. La aceptación de
rendimiento HTTP autenticada se hará tras desplegar; no se cambió la selección
masiva mediante paginación sin comprobar su semántica entre páginas.

La retención TIC conserva su consulta y orden, pero recorre las filas con cursor
y aplica primero un filtro SQL conservador que excluye filas claramente recientes.
Prepara los detalles y nombres solamente de los reportes vencidos. Reutiliza
la interpretación PHP de fechas y el fallback de `procesado_at` a `actualizado_at`;
mantiene el debounce de cinco minutos y las transacciones de archivado/horas extra.
El margen SQL cubre los desfases de zona horaria y la comparación PHP decide el
vencimiento exacto. El driver puede almacenar los candidatos en memoria; los
reportes claramente recientes ya no se transfieren en esa consulta.
Los registros del dashboard conservan sus ventanas de 200 entradas TIC y 20
Mantención. TIC formatea solo los ocho errores que ya mostraba por reporte,
sin cambiar la selección de entradas ni filtrar antes del límite SQL.

El dashboard TIC carga `mensaje` y `descripcion` de un reporte solo al abrir el
modal de edición. La consulta de la tabla omite esas columnas y la respuesta HTML
ya no las repite por fila. El endpoint puntual conserva los permisos de edición y
el alcance exacto por ID; si falla, el formulario no permite guardar. La ruta de
lectura usa `Cache-Control: private, no-store`. Los demás consumidores conservan
el lector completo.

El dashboard Mantención también deja de incluir la descripción y las filas de
vista previa en cada botón. La lectura puntual usa el ID interno de BD, porque
varios reportes pueden compartir `fuente_id`, y aplica el mismo alcance del
usuario antes de devolver el detalle. La lectura GET separa los candidatos
vencidos, que conserva completos para la retención, de los reportes visibles que
no requieren archivado y omiten `descripcion` desde SQL. Si la proyección no
reconstruye todas las filas, conserva el lector completo anterior.

Las acciones masivas TIC autorizan la selección consultando solo ID/asignado de
los reportes solicitados, y conservan el filtro de permisos y nombres existente.
La comprobación final PHP mantiene IDs exactos, orden y duplicados de la petición;
una comparación numérica SQL no autoriza variantes como `005`. Si la instancia
ya tiene reportes completos en caché o la proyección falla, se usa el lector
anterior. La proyección parcial no ocupa esa caché; el método general conserva
su comportamiento por defecto. Archivado, eliminación, envío y restablecimiento
mantienen sus lectores/escritores y transacciones anteriores después de autorizar.
Mantención utiliza una tabla de IDs seleccionados para evitar recorrer toda la
selección por cada reporte al archivar, eliminar y restablecer errores. Conserva
la comparación estricta de IDs, reportes repetidos, orden y éxitos parciales.
Este índice PHP añade memoria proporcional a los IDs; no cambia el guardado por
reporte.

En Mantención, los POST `archive_selected`, `delete_selected` y `reset_errors`
leen completos los reportes seleccionados y todos los procesados, preservando
la retención anterior a la acción y su cálculo de fecha. Los demás reportes
aportan solo datos de identidad, estado y asignación para permisos, contadores
y selección. Los detalles se unen por ID real de BD, sin colapsar IDs públicos
repetidos. La proyección es interna a esas tres acciones y nunca reemplaza sus
payloads de guardado; si falla la lectura de detalles, vuelve al lector completo.
Los POST de envío, edición, hora extra e importación y los lectores generales
conservan sus datos completos. Seleccionar todos los reportes o tener muchos
procesados sigue requiriendo leer sus detalles; los listados visibles y las
escrituras de lotes grandes aún deben medirse en el despliegue.

El archivado masivo de los dashboards TIC y Mantención muestra el indicador
compartido «Archivando reportes» con la cantidad seleccionada y bloquea envíos
repetidos mientras espera. Mantención cierra el aviso al recibir éxito o error;
TIC lo mantiene durante su POST hasta la navegación. Los controles se restauran
al volver desde la caché de navegación del navegador. La prueba aislada
`tests/Browser/redmine_archive_feedback.py` usa Playwright, el JavaScript real y
respuestas simuladas para verificar espera, errores, selección, POST y doble envío.

## Recuperación y migraciones (P07)

La referencia revisada está en `database/baselines/2026-09-15/`: `schema.sql`
contiene estructura sin datos, contadores AUTO_INCREMENT ni DEFINER;
`manifest.json` contiene metadatos, DDL, checksum SQL y el ledger capturado
(nombres y lotes de migración). No contiene usuarios, configuraciones, reportes
ni credenciales. No es un respaldo operativo ni una instalación lista para usar.
Los triggers se crean con los permisos del usuario restaurador; esa cuenta debe
conservar los permisos necesarios para ejecutarlos.

La captura de metadatos del origen fue de solo lectura. La reconstrucción y el
respaldo/restauración SQL con datos sintéticos se probaron en **MariaDB 12.3.2**.
La collation de los triggers requiere un servidor compatible; MariaDB 10.4 no
reproduce esta referencia y el bootstrap rechaza esa incompatibilidad antes de
crear tablas. No se sustituyen collations ni tipos automáticamente.

### Diferencias con una instalación por migraciones

`migration-comparison.json` registra la comparación de estructura contra toda la
cadena ejecutada en una base vacía, también en MariaDB 12.3.2. Las diferencias
incluyen nombres de restricciones/índices y, además, diferencias funcionales:

- `usuarios_nova.redmine_id` y `redmine_tic_reportes.asignado_a` son `VARCHAR(80)`
  en la referencia y enteros sin signo en la cadena desde cero.
- La cadena no crea la FK de `asignado_a` hacia `usuarios_nova.redmine_id` ni los
  siete triggers de actualización existentes. Dos columnas de auditoría usan
  `ON UPDATE` en la cadena en lugar de la definición de la referencia.
- El índice de nombres de usuarios tiene distinta composición; la cadena añade
  otros índices que no están en la referencia. No se cambian índices operativos.
- La cadena incluye `redmine_send_attempts` (P04), cuya migración no estaba
  aplicada en el origen de esta captura. Esa diferencia es esperada y sigue
  pendiente de activación operativa.

El ledger por sí solo no acredita igualdad de esquema. No convertir IDs ni
eliminar relaciones/triggers actuales para igualarlos a las migraciones antiguas.
La línea base vive fuera de `database/schema`, por lo que Laravel no la importa
automáticamente al ejecutar `migrate`.

### Recuperar una copia actual con datos

1. Obtener un respaldo consistente de esquema, datos, triggers y **tabla
   `migrations`**, junto con el código correspondiente. Conservar por separado
   `APP_KEY` y los archivos persistentes necesarios; mantenerlos protegidos fuera
   del repositorio. No generar otra clave para descifrar secretos existentes.
2. Restaurar primero en una base aislada y vacía de versión compatible, sin
   listeners, cron ni envíos externos activos. Importar el respaldo completo;
   no ejecutar bootstrap, migraciones históricas ni `migrate:fresh` sobre él.
3. Comparar el esquema y ledger con una captura del mismo respaldo. Verificar
   conteos y contenido de reportes, IDs, relaciones de horas extra, FK y triggers,
   además de acceso y descifrado en el entorno aislado. El ensayo P07 verifica
   datos sintéticos; no sustituye el ensayo del respaldo operativo y sus claves.
4. Revisar `migrate:status` y ejecutar `nova:database-upgrade-check`. Evaluar cada
   migración pendiente antes de aplicarla. Mantener la copia original para volver
   de un despliegue; un rollback de código no recupera datos borrados.

Los comandos de captura/verificación son de solo lectura en la base. Use una
conexión Laravel configurada explícitamente para el destino revisado; `recovery`
es un ejemplo de nombre, no una conexión incluida ni una selección automática.
Los directorios de captura deben quedar fuera de `public/` y del repositorio.

```bash
php artisan nova:database-baseline capture /ruta/privada/captura --database=recovery
php artisan nova:database-baseline verify /ruta/privada/captura --database=recovery
php artisan nova:database-upgrade-check --database=recovery
php artisan migrate:status --database=recovery
```

La verificación compara estructura y ledger; no valida el contenido de datos.
El checksum detecta discrepancias entre SQL y DDL del manifiesto, no firma la
procedencia del archivo: importar únicamente una referencia revisada. Una
captura posterior a nuevas migraciones debe acompañar su propio respaldo.

### Reconstruir solamente la estructura actual

En una **base nueva, completamente vacía**, con charset `utf8mb4` y collation
`utf8mb4_unicode_ci`, use la referencia revisada y una conexión dedicada:

```bash
php artisan nova:database-baseline bootstrap database/baselines/2026-09-15 --database=recovery
php artisan nova:database-baseline verify database/baselines/2026-09-15 --database=recovery
```

Bootstrap exige `--database` y rechaza cualquier tabla existente. Crea la
estructura, verifica su equivalencia y solo entonces copia el ledger capturado,
conservando nombres, orden y lotes. No ejecuta `up()` antiguos ni inventa entradas
para omitir migraciones. No cargar después un respaldo con DDL sobre esa base;
para recuperación con datos, use el procedimiento de respaldo completo.

El DDL de MariaDB no es transaccional: si falla durante la creación, puede quedar
una estructura parcial. El comando conserva ese destino para revisión y no
instala el ledger si la estructura no coincide. No reintentar contra una base
parcial ni eliminar objetos automáticamente. Las vistas, rutinas, eventos y FK
externas requieren ampliar/revisar el procedimiento antes de capturarlos.

### Actualizar estados antiguos y volver de un despliegue

Antes de `migrate`, NOVA comprueba si la limpieza histórica
`2026_06_15_000002_cleanup_operational_data` sigue pendiente y si borraría filas.
En ese caso bloquea la cadena antes de iniciar migraciones, incluso con `--force`.
También comprueba el evento de ejecución de esa limpieza para llamadas directas
al migrador dentro de Laravel. La migración histórica `up()` permanece intacta.

Una copia antigua con datos y ledger perdido requiere recuperar su historial
válido o diseñar una conversión específica con respaldo y pruebas. **No copiar
el ledger de la línea base a un esquema antiguo ni marcar migraciones a mano.**
El bloqueo no certifica otras migraciones destructivas, no protege SQL ejecutado
por fuera de Laravel ni hace seguro `migrate:fresh`, `refresh` o un rollback
histórico. Coordinar una ventana sin escritores al actualizar.

El `down()` de `2026_06_12_100001_add_composite_indexes_for_performance` ya no
invoca `dropIndexIfExists`, inexistente en Blueprint. Conserva los índices porque
el `up()` original no registró cuáles creó y cuáles ya existían. Un rollback
lógico puede retirar esa entrada del ledger, pero los índices permanecen; el
`up()` existente tolera su presencia. Una retirada física requiere una migración
posterior revisada con evidencia de propiedad y de uso. No se ensayó ningún
rollback ni se aplicó P04 sobre la base operativa.

## Evaluación de cambios condicionados (P08)

`nova:database-review` reúne evidencia agregada para revisar integridad e índices.
No ejecuta reparaciones, migraciones, purgas ni llamadas externas; tampoco carga
los lectores de aplicación que pueden tener efectos de escritura diferidos.

```bash
php artisan nova:database-review --database=mysql
php artisan nova:database-review --database=mysql --timeout=5 --json
```

`--database` selecciona una conexión Laravel existente; verifique su destino.
La salida JSON es opcional y no se guarda automáticamente. Incluye conteos,
metadatos de índices y nombres de comprobaciones; no contiene identidades,
asuntos, descripciones, claves externas reales ni secretos.

La revisión usa una transacción **READ ONLY / REPEATABLE READ** en MariaDB y la
revierte al terminar. Rechaza conexiones con transacciones ya abiertas, limita
cada consulta a cinco segundos por defecto (`--timeout` entre 1 y 30), comprueba
un presupuesto de 60 segundos entre comprobaciones y restaura la configuración
de sesión aun cuando una consulta falle. La conexión inicial depende del timeout
del driver; ese presupuesto no es un límite HTTP. Evite ejecutar diagnósticos de
carga durante una ventana de alta actividad.

Las 27 comprobaciones cubren multiplicidad/fechas de horas extra, vínculos sin
reporte, usuarios nulos, claves de origen/CORE, identidades externas de categorías
y unidades, coherencia de módulo/tipo de catálogos, booleanos, estimaciones,
horarios y cantidad de eventos de auditoría global. Una fecha distinta describe
el estado actual del reporte y la jornada, no determina cuál debe conservarse.
Las jornadas que cruzan medianoche se informan sin calificarlas de inválidas.

`observed` significa que existen filas/casos con la condición descrita;
`not_observed` significa que no se encontraron en ese snapshot; `not_evaluated`
indica columnas/tablas ausentes y usa `count: null`, nunca un cero engañoso.
El código de salida es 0 si se completaron todas las comprobaciones, incluso
cuando hay observaciones; es 1 ante fallo o esquema incompleto. **No es un check
de autorización para migrar ni una certificación de integridad completa.**
No incluye un inventario exhaustivo de formatos que PHP ya normalizó al importar.

El inventario de índices solo propone prefijos BTREE coincidentes en columnas,
longitudes y dirección. Excluye PK/UNIQUE como candidatos a retirar. La cobertura
por otro índice no demuestra desuso ni valida dependencias FK: conservarlos hasta
revisar todos los consumidores y ensayar su retirada/recreación individual.

El candidato del histórico `(modulo_id,estado,fecha_reporte,id)` se ensayó sobre
las consultas reales de candidatos/página de P06, con y sin índice, en una copia
sintética de MariaDB 12.3.2. Se conservaron resultados y se probó retirar el índice
creado. Ayudó parcialmente en una distribución selectiva, pero mantuvo el filesort
de la unión y no cambió el plan cuando todos los reportes estaban archivados;
aumentó espacio y costo de actualización. La consulta simple con LIMIT mejoró
más, pero no representa el flujo completo actual. **No se añade una migración
de índice con esa evidencia.** Las métricas reproducibles y sus límites están en
`tests/Integration/README.md` y el avance del plan en `Auditoria/04_Plan_Mejoras_Compatibles.md`.

P08 mantiene condicionadas las restricciones UNIQUE/FK/CHECK, otras conciliaciones
fuera del lote TIC descrito abajo, la autoridad del horario manual, la política de usuarios nulos y la
retención. Antes de aplicar uno de esos cambios se debe definir su contrato,
seleccionar los registros afectados y probar respaldo/conversión/vuelta. Los
conteos actuales no sustituyen esas decisiones; tampoco justifican borrar el
vínculo más antiguo ni cambiar claves manuales por inferencia.

### Fecha de jornadas TIC: decisión y corrección del 16/09/2026

La jornada TIC debe coincidir con `fecha_inicio` del reporte. Se conserva la
fecha del reporte; en los 28 casos revisados ya coincidían `fecha`, inicio y fin.
Se retiraron únicamente los 28 vínculos a otra fecha, manteniendo el vínculo
correcto de cada reporte. No se modificaron reportes, horarios, grupos ni vínculos
Mantención. Las jornadas que quedaron vacías se conservaron para no borrar
horarios históricos. La comprobación posterior obtuvo cero vínculos TIC con
fecha distinta y cero reportes en varias jornadas.

`RedmineHoursExtraRepository::attachReporte()` valida la fecha antes de vincular.
Conserva el fallback previo a `fecha` cuando `fecha_inicio` es NULL; si ambas
faltan, rechaza el vínculo. La corrección del lote exige que ambas coincidan.
El importador de paquetes legacy compara la jornada del respaldo con la fecha
persistida del ticket, también si el reporte ya existía. Ante una discrepancia
revierte el paquete completo con un error explícito; no cambia fechas ni descarta
silenciosamente los datos del respaldo. La sincronización habitual ya prioriza
`fecha_inicio` y mantiene su comportamiento.

`HoursDateReconciler` permite preparar una selección explícita de IDs. Solo aplica
si existe exactamente un vínculo a la fecha correcta, los propietarios coinciden
y los datos siguen iguales a la revisión. Guarda respaldo antes del DELETE y
verifica reportes/grupos después, todo dentro de una transacción. Rechaza cambios
concurrentes, ausencia de respaldo y selecciones ambiguas. No se añadieron
restricciones SQL ni un proceso automático de saneamiento.

El respaldo y recibo del lote están protegidos en
`storage/app/private/database-repairs/tic-hours-20260916/`, fuera de `public/` y
excluidos de Git. Incluyen las filas del pivot, metadatos de jornadas y hashes de
reportes, sin descripciones ni credenciales. No son un respaldo completo de BD.
El método `restore()` se probó en la instancia descartable: restaura esos vínculos
solo si los reportes, jornadas y vínculos conservados siguen como se esperaba;
si cambian, exige revisar el caso antes de restaurar. No restaurar este respaldo
automáticamente, pues reintroduciría las asociaciones corregidas.

## Seguridad

- P01 cuenta con [preparación y pruebas de cuenta limitada](ops/database-security/README.md).
  `php artisan nova:database-security-check` informa permisos directos y cifrado de
  la sesión sin mostrar credenciales. La activación operativa de la cuenta sigue
  pendiente; TLS se aplazó por decisión del responsable el 16-09-2026. El código
  mantiene la conexión actual. Un resultado DML válido no certifica TLS ni cierra P01.
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

Las [pruebas de persistencia aisladas](tests/Integration/README.md) reproducen
conflictos de edición y fallos de transacción sobre MariaDB temporal, con dos
conexiones independientes y sin arrancar el kernel ni leer `.env`. Se ejecutan
explícitamente; no forman parte de las suites Unit/Feature por defecto.

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
