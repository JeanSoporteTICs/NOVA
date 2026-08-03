# Redes Docker del host NOVA

- `nova_backend`: red Docker `internal` para tráfico privado entre NOVA,
  MariaDB, OnlyOffice y workers. No entrega salida directa al host o Internet.
- `nova_egress`: red sin puertos publicados para servicios que requieren salida
  (OnlyOffice, Telegram y Monitor).
- `nova_web`: red frontal de Apache. `apache-web` es el único servicio que
  publica un puerto en el host.

OnlyOffice se publica exclusivamente detrás de Apache en `/onlyoffice/`.
MariaDB y phpMyAdmin no publican puertos al host.

Los archivos de este directorio son las copias auditables de la configuración
operativa instalada bajo `/opt/docker` y `/home/odin/docker/compose`.
