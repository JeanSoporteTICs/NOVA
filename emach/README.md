# EMACH

Modulo integrado a NOVA.

URL local:

```text
http://localhost/NOVA/public/index.php/emach
```

Archivos principales:

- `index.php`: pantalla inicial.
- `assets/theme.css`: estilos del modulo.
- `data/usuarios.json`: usuarios con acceso al proyecto.
- `bin/monitor.php`: monitor CLI para reenviar marcaciones nuevas a Make o al canal central de NOVA.

## Monitor EMACH -> NOVA / Make

El monitor consulta EMACH, crea una linea base de marcaciones ya vistas y luego envia solo las marcaciones nuevas. Puede enviar al servicio central de notificaciones NOVA, a Make o a ambos.

Puedes guardar credenciales y webhook desde:

```text
http://localhost/NOVA/public/index.php/emach/views/Mantenedor/mantenedor.php
```

Se guardan en `storage/app/emach/monitor_config.json`, archivo ignorado por git. Las variables de entorno siguen teniendo prioridad si las defines.

Credenciales por variables de entorno, opcional si ya usas el mantenedor:

```bash
export EMACH_USER='19006667-3'
export EMACH_PASSWORD='tu_contrasena'
export MAKE_WEBHOOK_URL='https://hook.us1.make.com/xxxxxxxx'
```

El canal Telegram se configura fuera de EMACH, en el modulo central NOVA:

```text
http://localhost/NOVA/public/index.php/telegram
```

Prueba sin enviar a Make:

```bash
php emach/bin/monitor.php --dry-run
```

Enviar un payload ficticio para probar el canal configurado:

```bash
php emach/bin/monitor.php --test-webhook
```

Escuchar comandos desde el servicio Telegram central:

```bash
php telegram/bin/listen.php
```

Comandos disponibles en Telegram:

```text
/estado
/test
/ayuda
```

Ejecucion unica, enviando solo nuevas marcaciones:

```bash
php emach/bin/monitor.php
```

Ejecucion constante cada 60 segundos:

```bash
php emach/bin/monitor.php --loop --interval=60
```

Ejecucion con horario inteligente:

```bash
php emach/bin/monitor.php --loop --schedule=07:00-09:30=15,16:30-19:30=15 --slow-interval=300
```

En ese ejemplo consulta cada 15 segundos entre 07:00-09:30 y 16:30-19:30. Fuera de esos horarios consulta cada 300 segundos.

Opciones utiles:

```bash
php emach/bin/monitor.php --year=2026 --month=6
php emach/bin/monitor.php --send-existing
php emach/bin/monitor.php --test-webhook
php emach/bin/monitor.php --state=/ruta/monitor_state.json
php emach/bin/monitor.php --schedule=07:00-09:30=15,16:30-19:30=15 --slow-interval=300
```

Variables opcionales:

```bash
export EMACH_SCHEDULE='07:00-09:30=15,16:30-19:30=15'
export EMACH_SLOW_INTERVAL='300'
```

Formato de `EMACH_SCHEDULE`: `HH:MM-HH:MM=segundos`, separado por comas para multiples ventanas. El minimo permitido es 15 segundos.

Nota: en la primera ejecucion normal no envia el historico; solo guarda la linea base para evitar mensajes masivos. Si necesitas enviar todo lo existente, usa `--send-existing`.

Payload enviado a Make:

```json
{
  "event": "emach.marcacion_detectada",
  "detected_at": "2026-06-04T10:00:00-04:00",
  "source": "NOVA EMACH",
  "mark": {
    "run": "19006667-3",
    "nombre": "CORTES LORCA JEAN CARLOS",
    "fecha": "01/06/2026",
    "marcas": "07:53:51",
    "tipo": "ENTRADA",
    "reloj": "OIRS 1"
  },
  "telegram_text": "Nueva marcacion EMACH: ..."
}
```
