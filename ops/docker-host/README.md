# Redes Docker del host NOVA

- `nova_backend`: red Docker `internal` para tráfico privado entre NOVA,
  MariaDB, OnlyOffice y workers. No entrega salida directa al host o Internet.
- `nova_egress`: red sin puertos publicados para servicios que requieren salida
  (OnlyOffice, Telegram y Monitor).
- `nova_web`: red frontal de Apache. `apache-web` es el único servicio que
  publica un puerto en el host.

OnlyOffice se publica exclusivamente detrás de Apache en `/onlyoffice/`.
MariaDB y phpMyAdmin no publican puertos al host.

El contenedor de OnlyOffice permite direcciones IP privadas porque Document
Server debe descargar los archivos desde las URL temporales de NOVA, que se
sirven dentro de `10.x`/`nova_backend`. Los endpoints de metadatos permanecen
bloqueados. Después de cambiar esta configuración hay que recrear el servicio
`onlyoffice` para que su script de inicio actualice `local.json`.

Apache acepta `/nova` y `/NOVA`; la variante minúscula redirige a `/NOVA`
para mantener una sola URL canónica y una única sesión de aplicación.

Los archivos de este directorio son las copias auditables de la configuración
operativa instalada bajo `/opt/docker` y `/home/odin/docker/compose`.
