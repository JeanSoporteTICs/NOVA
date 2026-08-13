# Redes Docker del host NOVA

- `nova_backend`: red Docker `internal` para tráfico privado entre NOVA,
  MariaDB, OnlyOffice y workers. No entrega salida directa al host o Internet.
- `nova_egress`: red sin puertos publicados para servicios que requieren salida
  (OnlyOffice, Telegram y Monitor).
- `nova_web`: red frontal de Apache. `apache-web` es el único servicio que
  publica un puerto en el host.

OnlyOffice se publica exclusivamente detrás de Apache en `/onlyoffice/`.
MariaDB y phpMyAdmin no publican puertos al host.
El proxy de Apache conserva el `Upgrade` WebSocket de `/onlyoffice/`; el editor
usa este canal para cargar y editar documentos, y no funciona correctamente si
la solicitud se reenvía como HTTP normal. También reconstruye
`X-Forwarded-Host` con el host original y el prefijo `/onlyoffice`, para impedir
que Document Server genere recursos bajo `http://(null)/onlyoffice/`.

El contenedor de OnlyOffice permite direcciones IP privadas porque Document
Server debe descargar los archivos desde las URL temporales de NOVA, que se
sirven dentro de `10.x`/`nova_backend`. Los endpoints de metadatos permanecen
bloqueados. Después de cambiar esta configuración hay que recrear el servicio
`onlyoffice` para que su script de inicio actualice `local.json`.

El archivo externo `/home/odin/docker/compose/onlyoffice/.env` debe definir un
`SECURE_LINK_SECRET` estable y no versionado. El arranque del contenedor usa ese
valor para sincronizar la firma de `storage.fs.secretString` con la validación
`secure_link` de Nginx; si difieren, las descargas de `Editor.bin` responden
`403 Forbidden`.

Apache acepta `/nova` y `/NOVA`; la variante minúscula redirige a `/NOVA`
para mantener una sola URL canónica y una única sesión de aplicación.

Los archivos de este directorio son las copias auditables de la configuración
operativa instalada bajo `/opt/docker` y `/home/odin/docker/compose`.
