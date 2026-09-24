# Pruebas de persistencia en MariaDB aislada

`PersistenceCompatibilityTest` verifica resultados de guardado, preservación de
credenciales, deduplicación, concurrencia y rollback real de usuarios y permisos.
`RedmineSendReservationTest` verifica exclusión entre conexiones, resultados
inciertos, reenvío secuencial, conciliación y migración reversible en una base
descartable. `MantencionPersistenceTest` cubre configuración, opciones, actualizaciones por
reporte y respuestas de envío a Redmine simuladas, incluso la aceptación remota
seguida de un fallo local. Las pruebas usan datos sintéticos y triggers que
provocan errores; no llaman a servicios externos ni cargan el `.env` operativo.
Los casos de persistencia no cargan el kernel de NOVA. Los casos con funciones auxiliares legacy se ejecutan en procesos
separados para no modificar las funciones disponibles en otras pruebas.

`HoursExtraPersistenceTest` cubre compatibilidad de fechas/horarios, rollback de
jornadas y vínculos, archivado Mantención y borrado acotado al origen e ID real.
También compara el borrado masivo agrupado con el recorrido anterior para 40
reportes que comparten jornada, exige el mismo estado final y conserva vínculos
no seleccionados de TIC y Mantención. La métrica de consultas queda en
`/tmp/nova-p06-bulk-delete-metrics.json`.
El grupo `hours_compatibility` conserva cuatro casos de comportamiento previo;
se puede ejecutar con `--group hours_compatibility`. El rollback de archivado TIC
se verifica además en `tests/Unit/RedmineTicHoursExtraTest.php`.

`HoursExtraReadQueryTest` compara los lectores de horas extra TIC/Mantención con
copias de los anteriores. Verifica igualdad completa de grupos y reportes,
42 combinaciones de usuario/filtro TIC (incluyendo totales, años y sugerencias),
35 combinaciones de usuario/período Mantención, conciliación de IDs públicos
repetidos y campos vacíos, varios vínculos por reporte, grupos vacíos, fechas cero,
IDs BIGINT y orden de horarios. No modifica las reglas de conciliación ni llama
a EMACH. Dos cargas sintéticas comprueban menor memoria: reportes TIC no vinculados
y reportes Mantención repetidos en muchas jornadas. Los tiempos orientativos y
picos adicionales quedan en `/tmp/nova-p06-tic-hours-metrics.json` y
`/tmp/nova-p06-mantencion-hours-metrics.json`; no son garantías de producción.
La lectura por etapas se compara también con 400 reportes vinculados fuera del
usuario o período seleccionado; sus métricas están en
`/tmp/nova-p06-hours-screen-metrics.json`. Se verifica que las pantallas vacías no
lean detalles y que una actualización concurrente entre proyección y detalle no
mezcle versiones dentro de la transacción REPEATABLE READ de la instancia aislada.
Una lectura posterior debe mostrar el cambio; no hay caché global de jornadas.

`MantencionStatisticsReadQueryTest` reconstruye el esquema de referencia y
compara la respuesta completa de estadísticas contra las tres lecturas anteriores,
exceptuando la etiqueta de hora de actualización. Cubre 23 combinaciones de filtros,
casos CORE/manual, Unicode y reparación de texto, estados, IDs públicos repetidos,
BIGINT, fechas ausentes/cero, origen horas de otro módulo, orden y repetición de
vínculos. Comprueba el recorrido GET, que un resultado vacío no cargue detalles,
que la consulta sin filtros conserve su lectura completa y la consistencia ante
actualizaciones concurrentes en REPEATABLE READ. Se ejecuta en procesos separados
y carga las funciones reales requeridas sin arrancar el runtime legacy.
El ensayo con 400 reportes grandes fuera del rango guarda memoria/tiempo en
`/tmp/nova-p06-mantencion-statistics-metrics.json`. No llama a servicios externos.

`DashboardReadQueryTest` compara los dashboards con su composición previa:
30 combinaciones TIC de usuario/estado, detalles completos, contadores y errores;
alcances Mantención por ID, nombre, CORE y RUT, orden, IDs públicos repetidos y
BIGINT. Comprueba que los POST mantengan la lectura completa, que la retención
intente archivar los mismos reportes y que sus fallos conserven la misma respuesta.
Incluye repositorio no disponible, caché TIC ya cargada y fallback por fechas
empatadas. Una segunda conexión modifica asignado/estado/detalle entre consultas
para comprobar la consistencia en REPEATABLE READ de la instancia aislada.
Los ensayos con 400 reportes extensos fuera del alcance visible guardan memoria
y tiempos orientativos en `/tmp/nova-p06-dashboard-metrics.json`. No realizan
llamadas a Redmine ni modifican la configuración o datos operativos.

La misma prueba cubre ahora la preselección de retención: límite inclusivo y
un segundo posterior, fallback de fechas nulas (con columna sintética nullable),
fechas cero TIC, zonas UTC/Santiago y los extremos UTC+14/UTC-12, estados y orden. Compara todos los intentos
de archivado Mantención con el lector completo, incluidos reportes ajenos y
fallos, y verifica el GET real con umbral compartido y modo mantenimiento.
TIC conserva los 200 registros recientes y los primeros ocho errores por reporte,
incluidos JSON inválidos y diferencias entre la columna de evento y `linea`.
Un ensayo adicional con 400 reportes recientes extensos compara memoria y tiempo
en `/tmp/nova-p06-retention-metrics.json`. TIC aplica un filtro SQL conservador
para descartar reportes claramente recientes antes del cursor: conserva un margen
para zonas horarias y deja la decisión exacta a PHP. El ahorro incluye ahora la
transferencia SQL de esas filas, además de objetos y preparación de reportes.
El archivado real/debounce TIC se cubre además con `RedmineReportAutoArchiveTest`;
los tests de horas extra conservan las comprobaciones de rollback.

`DashboardReadQueryTest` compara ahora los logs de error visibles de Mantención
con el mapa completo anterior: último evento por ID, variantes de mayúsculas,
fallback ante fallo de consulta y cero consultas para una selección vacía. La
carga sintética de logs ocultos escribe `/tmp/nova-p06-dashboard-log-metrics.json`.

`MassActionSelectionTest` compara la autorización proyectada TIC con el lector
completo para varios alcances, nombres, IDs alternativos/BIGINT, duplicados y
orden. Verifica consulta acotada sin textos, caché completa ya cargada, ausencia
de caché parcial, fallback ante fallo de proyección y una modificación concurrente.
También demuestra que la autorización individual por ID entrega el mismo resultado
que el lector completo para esos casos.
Compara los efectos reales en BD de archivar, eliminar y restablecer errores con
ambas selecciones. Mantención contrasta el archivado con el bucle original ante
IDs de tipos distintos, ceros iniciales, duplicados y fallos parciales; sus POST
de eliminación/restablecimiento se prueban con el servicio/repositorio real,
CSRF simulado, permisos denegados y reportes ajenos/no seleccionados.
Las mediciones de autorización TIC con 400 reportes extensos no seleccionados y
de selección Mantención de 6.000 IDs entre 12.000 reportes están en
`/tmp/nova-p06-mass-selection-metrics.json`. El ensayo Mantención usa archivado
simulado: mide selección PHP y no la latencia de las escrituras del lote.

`MantencionPersistenceTest` compara el archivado específico con el escritor
genérico en cuatro estados y ante una actualización concurrente. También compara
30 restablecimientos individuales con `syncMessages()` y verifica el estado final
y las consultas. Métricas: `/tmp/nova-p06-mantencion-archive-metrics.json` y
`/tmp/nova-p06-mantencion-reset-metrics.json`.

`MantencionBulkReadQueryTest` compara la proyección interna de siete POST con el
lector completo: orden, contadores, permisos, IDs públicos repetidos, origen,
IDs vacíos/BIGINT, todos seleccionados, fallos de lectura y cambios concurrentes.
Ejecuta ambos recorridos con envío (detenido antes de red por configuración
sintética), archivado, borrado, restablecimiento, edición y cambio de hora extra;
cubre además la lectura acotada del endpoint nativo de hora extra. Los POST que
ejecutan retención mantienen completos todos los procesados; el endpoint
independiente carga solamente el reporte solicitado.
Compara respuestas, estados, jornadas y vínculos, con permisos permitidos/denegados
y retención activa/desactivada. Incluye rollback y éxitos parciales mediante un
trigger de error. Las secuencias autoincrementales de jornadas avanzan incluso
tras rollback: el comparador identifica jornadas por usuario/fecha y traduce sus
vínculos a esa identidad. No normaliza fechas, horarios ni reportes para comparar.
Un ensayo con 400 reportes pendientes extensos no seleccionados guarda memoria y
tiempo de lectura en `/tmp/nova-p06-bulk-post-metrics.json`; no mide la latencia
de archivado de un lote completo ni llama a plataformas externas.

`ProjectUserProjectionTest` compara el listado TIC con una copia del recorrido
anterior de `projectUsers`, en ambos modos de credenciales. Verifica consultas
acotadas a los miembros, cambios de acceso/permisos entre llamadas, perfiles
ausentes e IDs fuera del rango entero PHP. En Mantención compara todos los
campos salvo los cuatro secretos deliberadamente vacíos; conserva roles globales
y del módulo, permisos, estados y nombres externos. Comprueba que la lectura
ordinaria del selector no consulte secretos ni los descifre, el fallback de
orden por nombres empatados, columnas opcionales, módulo ausente y las opciones
reales de dashboard/Pendiente Manual. Los lectores de login mantienen sus secretos.
El ensayo con 300 usuarios adicionales, credenciales extensas y permisos de
usuarios ajenos a TIC escribe `/tmp/nova-p06-project-users-metrics.json`.
El caso de nombres empatados verifica SQL/bindings idénticos al lector completo,
descifrado por fallback y campos exactos por ID; evita exigir un desempate entre
ejecuciones que la consulta original no define.

`AdministrativeUserReadTest` compara las proyecciones administrativas NOVA y
Mantención contra los lectores completos, preservando campos visibles, orden,
indicadores y matriz de acceso. Incluye espacios de `trim()` PHP, tipos de
integración con espacios, tokens cifrados vacíos, texto heredado `0`, nombres
empatados y cambios concurrentes de credenciales. Verifica reparación real de
duplicados NOVA y rollback ante un trigger de error. La migración Mantención
ejecuta el servicio real con configuración sintética y un guardador que registra
los datos recibidos: compara las filas completas y cambios de configuración del
flujo anterior, con credenciales existentes/ausentes, usuario ausente y sesión
vacía. Comprueba que GET no persista la proyección y POST conserve datos completos
y CSRF. El ensayo de 250 usuarios adicionales con secretos extensos guarda sus
métricas en `/tmp/nova-p06-admin-users-metrics.json`. No se prueban credenciales
externas ni se envían mensajes; las dos plantillas se verifican además compilando
Blade y comprobando sintaxis PHP.
En nombres empatados según la collation, el caso Mantención verifica el SQL y
bindings exactos del lector completo y todos los campos por ID. No exige que dos
ejecuciones mantengan un orden entre empates que la consulta original no define.

Dos casos de horas extra ejecutan `fixtures/hours_worker.php` mediante procesos
PHP separados: creación concurrente de la misma jornada y vinculación durante
la eliminación de un grupo vacío. Requieren `proc_open` habilitado y lectura de
`information_schema.PROCESSLIST` en la instancia temporal. Comprueban la consulta
bloqueada y el resultado después del commit; el worker valida también que su
destino sea una base de prueba dentro de la instancia descartable.

P06 añade `UserReadQueryTest` (identidades normalizadas, duplicados, aislamiento y
crecimiento de consultas), `ReportQueryBindingTest` (parámetros textuales y EXPLAIN
con el índice existente) y `HistoryReadQueryTest` (orden, filtros, totales,
selectores y detalle frente a los lectores anteriores). Este último ejecuta 57
combinaciones de filtros/páginas y una carga de 1.585 reportes sintéticos, con
descripciones grandes. Se ejecuta en procesos separados y carga únicamente las
dos funciones puras reales de normalización, sin arrancar el módulo legacy.
La extensión SQL compara también Unicode, mojibake, fechas cero, duplicados,
opciones numéricas como `101`/`0101` y la proyección de la entrega previa.
`ActivityReadQueryTest` compara 98 combinaciones de alcance/filtros/páginas con el
lector anterior, incluyendo JSON inválido, claves repetidas/escapadas, profundidad
excesiva, IDs no textuales y BIGINT fuera del rango entero PHP. Comprueba además
que los contextos ordinarios se carguen solo para la página y no se lea `linea`.

`TicHistoryReadQueryTest` reconstruye el esquema de referencia y compara 132
combinaciones de usuario/filtro/página contra `history()` y una copia del bloque
de filtrado original de la vista. Incluye catálogo de otro módulo, etiquetas
numéricas/acentuadas, fechas cero, renombrado, permisos por nombre, origen de horas
extra, vínculos múltiples y enteros fuera del rango PHP. Comprueba el fallback
para fechas empatadas y compara el HTML Blade completo de ambas rutas con una
sesión sintética. Verifica también que el encabezado no recargue todos los reportes
y que la lista de usuarios funcione sin la columna `email` eliminada por S31.

Las mediciones escriben `nova-p06-history-metrics.json` y
`nova-p06-users-metrics.json`, `nova-p06-identity-metrics.json` y
`nova-p06-activity-metrics.json` y `nova-p06-tic-history-metrics.json` en el directorio temporal del sistema. Incluyen
conteos, pico adicional de memoria, duración orientativa y planes EXPLAIN; no
contienen datos operativos. Los tiempos no son umbrales de aceptación. La prueba
de histórico verifica reducción de memoria y equivalencia de resultados; la de
usuarios exige una única consulta de credenciales con 100 usuarios. El escenario
de credenciales cifradas está además en `tests/Unit/SharedRedmineCredentialTest.php`.
La búsqueda de identidad compara 2.000 usuarios con la proyección PHP anterior
y medianas de doce ejecuciones alternadas con 50, 500 y 2.000 identidades formateadas,
comparando la proyección PHP, la consulta SQL anterior y la consulta actual;
la bitácora compara 366 entradas sintéticas. El histórico TIC mide 1.120 reportes
con detalle grande y carga completa acotada a 25 filas, sin empates ambiguos en
esa muestra. Los tiempos locales no garantizan
una mejora de latencia en producción ni certifican los listados no cubiertos.

La continuación P06 añade identidades vacías, claves largas, cambios de duplicados
entre llamadas y un oráculo de la consulta SQL previa. El histórico TIC incluye
pruebas de la proyección de estadísticas frente al lector completo, catálogos
heredados, fechas cero, filtros, orden de etiquetas y fallback ante empates.
Escribe `nova-p06-tic-statistics-metrics.json` en el temporal del sistema.

`MantencionActivityReadQueryTest` compara 520 combinaciones de actor, filtros y
página, incluidos IDs textuales/números/arrays, claves JSON duplicadas/escapadas,
JSON inválido/profundo, nombres inferidos desde detalle, etiquetas numéricas y
redacción de secretos. La carga de 1.180 eventos mide por separado administrador
y usuario restringido en `nova-p06-mantencion-activity-metrics.json`. Los contextos
se siguen decodificando en PHP para conservar la autorización exacta; las consultas
de detalles completos quedan acotadas a la página y a actores inferidos del texto.

Cada caso crea una base con nombre aleatorio terminado en `_testing` y la elimina
al terminar. Se requiere una **instancia descartable** de MariaDB con:

- `datadir` dentro del directorio temporal del sistema;
- `--skip-networking`, con acceso únicamente mediante socket local;
- cuenta local `root` sin contraseña, usada exclusivamente en esa instancia
  temporal para crear y eliminar bases de prueba.

La clase base verifica directorio y ausencia de red antes de crear una base.
Nunca apuntar esta variable al socket del servidor habitual.

Ejemplo de preparación en LAMPP/Linux, desde la raíz del repositorio:

```bash
test_root=$(mktemp -d /tmp/nova-p00-XXXXXXXX)
/opt/lampp/bin/mysql_install_db --no-defaults --basedir=/opt/lampp \
  --datadir="$test_root/data" --auth-root-authentication-method=normal --skip-test-db
/opt/lampp/sbin/mysqld --no-defaults --basedir=/opt/lampp \
  --datadir="$test_root/data" --socket="$test_root/mysql.sock" \
  --pid-file="$test_root/mysql.pid" --log-error="$test_root/mysql.log" \
  --skip-networking --innodb-buffer-pool-size=64M &
```

Esperar a que el servidor esté listo y comprobarlo con `mysqladmin ping` antes
de lanzar las pruebas:

```bash
/opt/lampp/bin/mysqladmin --no-defaults --socket="$test_root/mysql.sock" -u root ping
NOVA_PERSISTENCE_TEST_SOCKET="$test_root/mysql.sock" \
  /opt/lampp/bin/php vendor/bin/phpunit tests/Integration
/opt/lampp/bin/mysqladmin --no-defaults --socket="$test_root/mysql.sock" -u root shutdown
```

Si falta la variable, los casos se omiten. El esquema mínimo de cada caso se crea
con el Schema Builder; estas pruebas no prueban por sí solas la equivalencia
entre todas las migraciones y el esquema operativo. Ejecutar también las suites
de regresión afectadas contra una instalación aislada preparada con las
migraciones, verificando destino, configuración y almacenamiento **antes** del
arranque de Laravel.

Los plazos y la continuación paginada se prueban sin red ni esperas reales en
`tests/Unit/RedmineHistoryDeadlineTest.php`. La prueba de navegador usa HTML local,
respuestas simuladas y el reloj virtual de Playwright:

```bash
python tests/Browser/redmine_history_sync.py
```

Requiere Playwright para Python y Chromium instalado. Comprueba continuación de
pendientes, fallos sin reintento automático, cancelación a 30 segundos y el
formulario de sincronización completa TIC. No equivale a una prueba visual de
todas las pantallas con sesiones reales.

## Recuperación P07

`DatabaseRecoveryTest` verifica la referencia versionada, rechazo de bases
ocupadas/collations incompatibles, conservación de la sesión SQL, bloqueo de
limpieza e índices retenidos durante `up/down/up`. El ensayo SQL completo exporta
una base sintética con `mysqldump --single-transaction --triggers`, vacía solamente
esa base aleatoria y restaura el volcado con el cliente MariaDB. Compara reportes,
IDs, vínculos, estructura y ledger; comprueba también un trigger y una FK real.

`DatabaseUpgradeCommandTest` arranca un kernel separado mediante
`fixtures/database_upgrade.php`, con `.env` y caches fuera del proyecto. Prueba
`migrate --force --database=recovery` antes de cualquier DDL y una llamada directa
al migrador antes de la limpieza. La conexión predeterminada apunta a una base
inexistente para demostrar que se respeta la conexión elegida. Laravel omite
eventos de consola bajo `APP_ENV=testing`; el fixture habilita explícitamente el
dispatcher usado por el CLI habitual para probar el bloqueo de entrada.

La equivalencia completa de la referencia requiere **MariaDB 12.3.2**. En 10.4
los dos ensayos de reconstrucción/restauración se omiten con explicación; las
otras siete protecciones sí se prueban. Para 12.3 puede usarse un contenedor
descartable `mariadb:12.3.2`, sin red ni puertos, montando un directorio temporal
en la misma ruta absoluta dentro y fuera del contenedor. Arrancarlo con
`--datadir=<temporal>/data --socket=<temporal>/mysql.sock --skip-networking`; el
directorio debe ser accesible para el usuario del contenedor. No utilizar el
volumen ni el socket de una instalación existente.

```bash
NOVA_PERSISTENCE_TEST_SOCKET="<temporal>/mysql.sock" \
  /opt/lampp/bin/php vendor/bin/phpunit tests/Integration --no-progress
```

El ensayo SQL usa por defecto `/opt/lampp/bin/mysqldump` y
`/opt/lampp/bin/mysql`. Puede configurar `NOVA_TEST_DUMP_BINARY` y
`NOVA_TEST_MYSQL_BINARY` con rutas a binarios compatibles. Si no existen, ese
ensayo se omite explícitamente. No se guardan ni versionan volcados con datos.
Detener el servidor/contenedor descartable al terminar. El procedimiento de
recuperación y las diferencias de migraciones se documentan en el README raíz.

## Evaluación P08

`ConditionalChangeReviewTest` verifica agregados y preservación exacta de datos y
esquema, separación por módulo, jornadas múltiples/NULL/medianoche, catálogos con
tipo incorrecto, claves repetidas y ausencia de datos personales en la salida.
También comprueba que MariaDB rechace un INSERT durante la revisión, que un
`SELECT SLEEP(3)` sea interrumpido con límite de un segundo, que se restaure un
timeout previo fraccionario, y que no se invada una transacción del llamador.
La salida del comando distingue esquema incompleto de resultados iguales a cero.

`ConditionalIndexEvaluationTest` reconstruye la referencia P07 en MariaDB 12.3.2
con 10.000 reportes sintéticos, dos módulos, fechas NULL/empatadas y vínculos de
horas extra. Evalúa las consultas reales construidas por `MantencionHistoryRepository`
y contrasta la propuesta simple con LIMIT. Se comparan hashes/filas antes y
después, EXPLAIN, lecturas Handler, espacio estimado y mediana de tres ejecuciones.
Se mide además una actualización de fecha de 1.000 filas, revertida en cada ensayo.
El índice candidato se crea y elimina solo en esa base aleatoria; al retirarlo
se comprueba que el esquema completo vuelva a coincidir con la referencia.

```bash
NOVA_PERSISTENCE_TEST_SOCKET="<temporal>/mysql.sock" \
  /opt/lampp/bin/php vendor/bin/phpunit \
  tests/Integration/ConditionalChangeReviewTest.php \
  tests/Integration/ConditionalIndexEvaluationTest.php --no-progress
```

El segundo test escribe únicamente métricas sintéticas en
`/tmp/nova-p08-index-evaluation.json` (o el temporal del sistema). Su ANALYZE TABLE
se ejecuta exclusivamente en la base descartable. No impone umbrales de latencia
ni afirma que el optimizador siempre elegirá el mismo índice. La distribución,
el caché local caliente y el servidor de prueba limitan las conclusiones.
La selección de claves del ensayo incluye todos los candidatos: no sustituye
la caracterización de permisos/filtros de `HistoryReadQueryTest`, que permanece
vigente y se ejecuta en la suite de persistencia.

## Conciliación de fechas TIC

`HoursDateReconciliationTest` usa la referencia MariaDB 12.3.2 y verifica retiro
exclusivo del vínculo cuya fecha difiere, conservación de reportes/grupos/otros
orígenes, respaldo privado obligatorio, restauración exacta, huellas de revisión
y rollback ante fallo al retirar el segundo vínculo. El respaldo sintético se
elimina al finalizar cada caso. `LegacyTicBackupImportServiceTest` conserva la
idempotencia con fechas coincidentes y verifica que una jornada de otra fecha
aborte el paquete completo sin dejar reportes ni vínculos parciales.

## Cuenta limitada P01

`RuntimeDatabaseSecurityTest` restaura el baseline en la instancia descartable
y crea una cuenta aleatoria con SELECT/INSERT/UPDATE/DELETE en el esquema del
test. Comprueba login, contraseña, edición de identidad, credenciales cifradas,
reportes, horas y rollback; MariaDB debe rechazar DDL, delegación y acceso a sus
usuarios administrativos. La cuenta y el esquema se eliminan al finalizar.
La prueba usa socket sin TLS y no lee `.env`; no demuestra activación operativa.
Ver `ops/database-security/README.md` para alcance, comandos y pendientes.

El dashboard nativo TIC usa una proyección sin `mensaje`/`descripcion` para la
tabla; `DashboardReadQueryTest` compara todas las demás claves, orden, resumen,
logs y acceso exacto al detalle por ID. La carga sintética de 200 reportes
visibles deja la medición en `/tmp/nova-p06-tic-dashboard-detail-metrics.json`.
El script de apertura del modal se comprobó con respuestas sintéticas de éxito
y error; Playwright no estaba instalado en este entorno para validación visual.

Mantención añade una prueba con dos filas BIGINT que comparten `fuente_id` y
pertenecen a usuarios distintos. Comprueba que la vista identifique cada fila
por su ID de BD y que la lectura puntual devuelva solo la descripción/vista
previa permitida. La suite de integración completa pasa: 174 pruebas y 4.287
verificaciones. El script del modal se simuló con respuestas de éxito/error; la
verificación visual posterior en Chromium se documenta al final de este archivo.

La lectura GET ligera de Mantención compara el resultado visible con el lector
completo, exceptuando `descripcion` que ahora llega al abrir el modal. Comprueba
los mismos intentos de retención y mide 300 reportes extensos visibles en
`/tmp/nova-p06-mantencion-dashboard-detail-metrics.json`; inspecciona el SQL
para confirmar que la selección ligera no trae `descripcion`. Suite de
integración: 175 pruebas / 4.308 verificaciones.


El fallback del dashboard TIC se prueba fallando solamente la consulta de
detalles después de seleccionar filas visibles: debe devolver el lector
completo y mantener contadores, orden y permisos. Suite de integración:
176 pruebas / 4.324 verificaciones.

`MantencionStatisticsReadQueryTest` fuerza un error solo en la segunda consulta
de la lectura filtrada y comprueba que el fallback devuelve el resultado
completo anterior. Suite de integración: 177 pruebas / 4.329 verificaciones.
Los modales TIC/Mantención se validan con Chromium ejecutando
`tests/Browser/redmine_dashboard_detail.py` sobre el JavaScript de las vistas,
con datos y respuestas sintéticas, sin servidor ni credenciales reales.

`HistoryReadQueryTest` y `TicHistoryReadQueryTest` fuerzan errores en las
proyecciones SQL y comprueban los lectores de respaldo. Suite P06 local:
179 pruebas / 4.340 verificaciones. La BD configurada en este entorno tenía
4 reportes TIC activos y 0 de Mantención al consultar solo conteos el
24-09-2026; la medición HTTP autenticada debe hacerse tras desplegar.
