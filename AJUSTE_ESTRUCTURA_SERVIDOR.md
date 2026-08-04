# Ajuste de estructura del servidor NOVA

Este procedimiento adapta el despliegue productivo sin mover el checkout de
su ruta actual:

```text
/var/www/NOVA
```

La base productiva sigue siendo `nova`. La base `nova_desarrollo` queda
reservada para staging y pruebas manuales. Nunca ejecutar pruebas PHPUnit ni
`migrate:fresh` contra `nova`.

## Objetivo

- Mantener el código completo en `/var/www/NOVA`.
- Exponer por Apache solamente `/var/www/NOVA/public`.
- Ejecutar Telegram, Monitor y Artisan desde `/var/www/NOVA`.
- Usar `APP_ENV=production` y `APP_DEBUG=false` en producción.
- Mantener MariaDB en la red Docker privada y usar el nombre del servicio como
  `DB_HOST` cuando PHP se ejecute dentro de Docker.

## 1. Comprobaciones previas

Ejecutar en el servidor, sin modificar nada:

```bash
cd /var/www/NOVA
pwd
git status --short
git rev-parse --short HEAD
docker ps --format 'table {{.Names}}\t{{.Status}}\t{{.Ports}}\t{{.Networks}}'
docker network inspect nova_backend --format 'internal={{.Internal}}'
docker compose ls
df -h
free -h
```

Verificar el entorno sin imprimir secretos:

```bash
grep -E '^(APP_ENV|APP_DEBUG|APP_URL|DB_HOST|DB_PORT|DB_DATABASE|CACHE_STORE|SESSION_DRIVER|QUEUE_CONNECTION)=' /var/www/NOVA/.env
```

Producción debe indicar al menos:

```dotenv
APP_ENV=production
APP_DEBUG=false
DB_DATABASE=nova
```

`DB_HOST` debe ser el nombre DNS del servicio MariaDB dentro de
`nova_backend`; no usar `127.0.0.1` desde un contenedor distinto.

## 2. Respaldo obligatorio

Antes de migrar o reiniciar servicios:

1. Crear un dump consistente de `nova` con `mariadb-dump
   --single-transaction --routines --triggers --events`.
2. Respaldar `/var/www/NOVA/.env` fuera del DocumentRoot.
3. Respaldar los volúmenes de documentos y archivos operacionales.
4. Registrar el commit desplegado y comprobar que el dump puede leerse.

Usar los scripts bajo `scripts/prod/` cuando estén configuradas sus
autorizaciones y archivos de credenciales. No escribir contraseñas en la línea
de comandos ni dentro del repositorio.

## 3. Web root de Apache

La configuración objetivo debe resolver el sitio a:

```apache
DocumentRoot /var/www/NOVA/public

<Directory /var/www/NOVA/public>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

Si Apache corre en Docker, montar únicamente el directorio público en el web
root del contenedor:

```yaml
volumes:
  - /var/www/NOVA/public:/var/www/html:ro
```

PHP también necesita leer el código ubicado fuera de `public`. Según la
arquitectura real del host, se debe usar una de estas opciones:

- Apache con PHP integrado: montar `/var/www/NOVA` en una ruta no pública y
  definir el VirtualHost directamente sobre su subdirectorio `public`.
- Apache como proxy hacia PHP-FPM: montar el proyecto completo en PHP-FPM y
  solamente `public` en Apache.

No aplicar ciegamente el mount `public:/var/www/html` si la imagen actual usa
`mod_php` y no tiene otro mount para el resto del proyecto: `index.php` no
podría cargar `vendor/autoload.php`. Primero inspeccionar:

```bash
docker exec apache-web apachectl -S
docker exec apache-web apachectl -M | grep -E 'php|proxy_fcgi'
docker inspect apache-web --format '{{json .Mounts}}'
```

Conservar las reglas que bloquean dotfiles, dumps, logs, `vendor`, `storage` y
`.git` como defensa adicional.

## 4. Workers

Las definiciones auditables están en:

- `ops/docker-host/nova-telegram/compose.yaml`
- `ops/docker-host/nova-monitor/compose.yaml`

Ambas deben usar `/var/www/NOVA`. Telegram y Monitor deben ejecutarse con
`APP_ENV=production`. Debe existir un solo consumidor Telegram y un solo daemon
del monitor:

```bash
docker compose -f ops/docker-host/nova-telegram/compose.yaml up -d --build
docker compose -f ops/docker-host/nova-monitor/compose.yaml up -d --build
docker compose -f ops/docker-host/nova-telegram/compose.yaml ps
docker compose -f ops/docker-host/nova-monitor/compose.yaml ps
```

## 5. Preparación de Laravel

Desde `/var/www/NOVA`:

```bash
composer install --no-dev --prefer-dist --no-interaction --classmap-authoritative
npm ci
npm run build
php artisan optimize:clear
php artisan migrate:status
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Solo `storage/` y `bootstrap/cache/` necesitan escritura por el usuario que
ejecuta PHP. No dar permisos globales `777`.

## 6. Scheduler

Configurar una sola entrada en el host:

```cron
* * * * * cd /var/www/NOVA && php artisan schedule:run >> /dev/null 2>&1
```

No duplicarla dentro de otro contenedor o cron del sistema.

## 7. Verificación

Después del despliegue:

```bash
cd /var/www/NOVA
php artisan about
php artisan migrate:status
php artisan route:list
docker ps
curl -fsS http://127.0.0.1/ >/dev/null
```

Validar manualmente login/logout, permisos, Redmine TIC, Redmine Mantención,
CORE, Nextcloud, OnlyOffice, Telegram, EMACH, Horas Extra y Monitor.

Confirmar además que estas rutas no sean descargables por HTTP:

```text
/.env
/.git/config
/composer.json
/storage/logs/laravel.log
```

Todas deben responder `403` o `404`.

## 8. Rollback

Si falla la aplicación:

1. Volver al artefacto o commit previamente registrado.
2. Restaurar el `.env` respaldado.
3. Restaurar la base solo mediante el procedimiento aprobado en
   `scripts/prod/restore.sh`.
4. Reconstruir caches Laravel y reiniciar los contenedores afectados.
5. Repetir los smoke tests antes de reabrir el servicio.

No usar `git reset --hard`, `migrate:fresh` ni importar un dump sobre `nova`
sin respaldo validado y autorización explícita.
