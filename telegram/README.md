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

Si el token y proxy ya estan guardados desde NOVA, el contenedor los lee desde `storage/app/telegram/config.json`.
Tambien puedes pasarlos como variables de entorno:

```bash
TELEGRAM_BOT_TOKEN=xxx TELEGRAM_PROXY_URL=10.6.206.80:8080 docker compose -f docker-compose.telegram.yml up -d --build
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
