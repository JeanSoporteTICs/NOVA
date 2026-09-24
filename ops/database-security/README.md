# P01: cuenta de aplicación y comprobación de permisos

Preparación del 16-09-2026. Por decisión del responsable se continúa **sin
certificados/TLS**. Esta excepción deja pendiente DB-003: la sesión actual no
está cifrada. No se activa `require_secure_transport`, no se instalan certificados
y no se cambia la conexión operativa con este paquete.

## Consumidores y alcance

| Consumidor | Configuración efectiva | Operaciones previstas |
| --- | --- | --- |
| Apache/PHP: NOVA, TIC, Mantención y EMACH web | Laravel `config/database.php`; entorno y caché de configuración | SELECT, INSERT, UPDATE, DELETE sobre NOVA; transacciones |
| Telegram `telegram/bin/listen.php` | Bootstrap Laravel; Compose puede sobreescribir `.env` | Identidad, configuración, reportes y estado operativo |
| Monitor `artisan nova:monitor-servers` | Laravel; entorno de `docker-compose.monitor.yml` | Configuración, comprobaciones y eventos |
| EMACH `Emach/bin/monitor.php` | Bootstrap Laravel y entorno del proceso | Configuración e identidad; revisar tareas instaladas al activar |
| Scheduler y comandos operativos | Laravel; entorno del usuario del servicio | Archivado, notificaciones y persistencia; excluir migraciones de esta cuenta |
| Migraciones, recuperación y administración | Credenciales separadas y temporales para la operación aprobada | DDL específico, fuera de la cuenta de aplicación |
| `scripts/prod/backup.sh` y `restore.sh` | Cliente MariaDB/MySQL y archivo privado `MYSQL_CNF` | Cuenta de respaldo/restauración separada; conservar sus controles operativos |

El inventario identifica código disponible. No demuestra qué cron, servicios o
copias de Compose están instalados en el servidor. Revisar también los ejemplos
en `ops/docker-host/` al efectuar el cambio. La cuenta limitada se prueba con el
esquema recuperado, incluidos sus triggers; no debe recrear ni modificar triggers.

## Aprovisionamiento y cambio coordinado

1. Confirmar los orígenes reales de Apache, contenedores y tareas tal como los ve
   MariaDB. Crear una cuenta nueva por origen autorizado; no utilizar `%` por
   comodidad ni modificar la cuenta administrativa existente.
2. En una sesión administrativa privada, crear `nova_app` con un secreto nuevo y
   conceder únicamente `SELECT, INSERT, UPDATE, DELETE ON nova.*`. No conceder
   `GRANT OPTION`, roles administrativos ni privilegios sobre `*.*`. Si se usa
   otro nombre de esquema con `_` o `%`, escaparlos en el alcance del GRANT.
   Revisar roles heredados/PUBLIC por separado. No pasar secretos por argumentos
   de shell, historial SQL, Git o mensajes; usar el mecanismo privado del servidor.
3. Mantener el modo de transporte actual durante este cambio. La nueva cuenta
   no debe exigir SSL mientras los consumidores sigan sin certificados.
   No retirar requisitos TLS de otras cuentas ni cambiar políticas globales.
4. Preparar credenciales privadas por consumidor con `DB_USERNAME=nova_app` y
   `DB_PASSWORD` nuevo. Revisar `DATABASE_URL`, variables del servicio y caché:
   pueden sobreescribir `.env`. El Compose del repositorio ahora usa `nova_app`
   como valor por defecto, pero conserva un `DB_USERNAME` explícito existente.
5. Validar primero en el entorno de ensayo con los repositorios y tareas reales.
   Aplicar el cambio de entorno y regenerar caché en una ventana coordinada;
   recrear los contenedores necesarios para tomar las nuevas variables y
   reiniciar workers persistentes. No basta editar `.env` o hacer un restart
   del contenedor si su configuración de creación contiene el usuario anterior.
6. Ejecutar la comprobación siguiente en **cada proceso/entorno**, probar login,
   contraseña, permisos, dashboards, reportes, horas e integraciones. Verificar
   heartbeat de workers; evitar envíos reales de prueba a destinatarios externos.
   Si falla, mantener la ventana y corregir la cuenta/origen o un permiso de datos
   demostrado necesario; no resolverlo concediendo privilegios globales.
7. Retirar la credencial anterior de los consumidores solo después de comprobar
   el cambio completo. Coordinar su rotación y la del resto de secretos expuestos
   como operación separada, con custodia y recuperación. No ejecutar
   `key:generate`: cambiar `APP_KEY` requiere recifrado/recuperación previamente
   probados para conservar acceso a las integraciones.

No se han creado cuentas ni cambiado secretos en el servidor operativo.

## Comprobación sin mostrar secretos

```bash
php artisan nova:database-security-check
php artisan nova:database-security-check --database=mysql
```

El comando usa SELECT/SHOW, no muestra concesiones originales, hashes, usuario,
contraseña ni nombres de registros. Devuelve JSON con:

- `direct_grants_dml_only`: concesiones directas exactamente DML en el esquema,
  sin delegación. Concesiones no reconocidas o roles explícitos no pasan.
- `session_encrypted` y `tls_version`: cifrado observado en **esa sesión**.
- `certificate_ca_configured`: presencia de CA en configuración; no prueba su
  validez ni la verificación del nombre del servidor.

Salida 0 significa únicamente que las concesiones directas cumplen la política
DML. Salida 1 significa permisos incompatibles o revisión incompleta. Un 0 con
`session_encrypted=false` **no cierra P01**, y tampoco certifica roles implícitos
ni procesos distintos del que ejecuta el comando.

## Pruebas reproducibles

Usar únicamente MariaDB descartable con socket, `skip_networking=1` y datadir bajo
`/tmp`, siguiendo `tests/Integration/README.md`. Requiere MariaDB compatible con
el baseline 12.3.2; el test crea y elimina su esquema y cuenta aleatorios.
No arranca Laravel completo ni lee `.env`.

```bash
NOVA_PERSISTENCE_TEST_SOCKET=/ruta/temporal/mysql.sock php vendor/bin/phpunit \
  --no-configuration --bootstrap vendor/autoload.php tests/Integration/RuntimeDatabaseSecurityTest.php
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
  tests/Unit/RuntimeSecurityReviewTest.php tests/Production
```

Se comprueban permisos reales de MariaDB, transacciones, persistencia de reportes
y horas, login/contraseña/edición de identidad e integración cifrada, rechazo de
DDL/delegación/lectura de usuarios del servidor y detección de cuenta administrativa.
La prueba de artefacto construye desde un repositorio sintético limpio bajo `/tmp`;
no es una liberación de NOVA ni sustituye las pruebas operativas de cada worker.

## Estado pendiente

- Activar y verificar la cuenta limitada en todos los consumidores reales.
- TLS aplazado por decisión del responsable; no se considera implementado.
- Rotaciones, custodia de dumps y limpieza coordinada de historial; APP_KEY requiere
  tratamiento específico para no perder credenciales cifradas.
- Construir el artefacto oficial desde un commit limpio. Los dumps históricos del
  repositorio se conservan hasta completar su custodia; el builder los excluye.
