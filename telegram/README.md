# Telegram Service NOVA

Servicio unico para recibir comandos de Telegram y enviar mensajes pendientes desde NOVA.

## Que hace

- Recibe comandos por `getUpdates`, por ejemplo `/emach`.
- Envia respuestas inmediatas del bot.
- Procesa mensajes en cola desde `storage/app/telegram/outbox`.
- Mueve mensajes enviados a `storage/app/telegram/sent`.
- Mueve mensajes fallidos a `storage/app/telegram/failed` despues de 3 intentos.

## Uso local

```bash
php telegram/bin/service.php
```

Diagnostico:

```bash
php telegram/bin/listen.php --diagnose
php telegram/bin/listen.php --send-queued
php telegram/bin/listen.php --delete-webhook
php telegram/bin/queue.php --chat=7449883192 --text="Prueba desde cola"
```

## Docker

El compose solo levanta el servicio Telegram, no reemplaza tu Apache/XAMPP.
No ejecutes al mismo tiempo el servicio desde el panel web y desde Docker, porque Telegram permite un solo consumidor `getUpdates` por bot.

```bash
docker compose -f docker-compose.telegram.yml up -d --build
docker compose -f docker-compose.telegram.yml logs -f nova-telegram
docker compose -f docker-compose.telegram.yml stop nova-telegram
```

El token global se guarda exclusivamente como `TELEGRAM_BOT_TOKEN` en el
archivo `.env` de NOVA. `storage/app/telegram/config.json` no contiene el
token. El proxy puede definirse con `TELEGRAM_PROXY_URL`.

```bash
docker compose -f docker-compose.telegram.yml up -d --build --force-recreate
```

Al cambiar el token, recrea los contenedores de Telegram y del monitor para
que Docker vuelva a cargar las variables de `.env`:

```bash
docker compose -f docker-compose.telegram.yml up -d --force-recreate
docker compose -f docker-compose.monitor.yml up -d --force-recreate
```

Para actualizar una instalacion antigua que aun tenga `bot_token` en
`storage/app/telegram/config.json`, ejecuta una sola vez:

```bash
php artisan telegram:migrate-token-env
```

## Encolar mensajes desde codigo PHP

```php
require_once base_path('telegram/lib/telegram.php');

telegram_queue_configured_message('Texto del mensaje', [
    'chat_id' => '7449883192',
    'source' => 'emach',
]);
```

El servicio los enviara automaticamente en el siguiente ciclo.
