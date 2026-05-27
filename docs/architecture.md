# Arquitectura NOVA

NOVA es la aplicacion Laravel principal. Los sistemas existentes se integran como modulos independientes para evitar mezclar codigo legacy con la plataforma base.

## Modulos actuales

- `redmine`: reportes hacia el proyecto Redmine principal.
- `redmine-mantencion`: reportes, mantencion, procedimientos e integraciones operativas.

## Registro de modulos

Los modulos disponibles se declaran en `config/modules.php`.

Cada modulo define:

- `name`: nombre visible.
- `description`: descripcion corta para la portada.
- `type`: tipo de modulo. Puede ser `native` o `legacy`.
- `path`: carpeta fisica del modulo.
- `entry`: archivo de entrada.
- `allowed_static_roots`: carpetas publicas permitidas.
- `allowed_php_roots`: areas PHP legacy que Laravel puede despachar.

## Estrategia de migracion

La migracion se hace por modulo, sin mezclar carpetas ni datos entre proyectos:

1. Mantener cada modulo dentro de su carpeta en `NOVA`.
2. Extraer lectura y escritura a servicios Laravel por modulo.
3. Reemplazar controladores PHP legacy por controladores Laravel cuando cada flujo este cubierto.
4. Bloquear el passthrough PHP para los modulos que ya queden como `native`.

Esta estructura permite agregar nuevos modulos sin duplicar la aplicacion base ni acoplar los proyectos Redmine entre si.

## Estado migracion Redmine

Redmine tiene una capa Laravel nativa bajo:

- `app/Support/Redmine`
- `app/Http/Controllers/Redmine`
- `resources/views/redmine`

Rutas nativas:

- `/redmine/nativo`
- `/redmine/nativo/dashboard`
- `/redmine/nativo/webhook`
- `/redmine/nativo/horas-extra`
- `/redmine/nativo/historico`
- `/redmine/nativo/usuarios`
- `/redmine/nativo/configuracion`
- `/redmine/nativo/sync-categorias`
- `/redmine/nativo/unidades-cf`
- `/redmine/nativo/estadisticas`
- `/redmine/nativo/estadisticas-api`
- `/redmine/nativo/actividad`

`redmine` esta registrado como modulo `native`. La ruta `/redmine` abre Laravel y el passthrough legacy generico ya no acepta `redmine`; solo queda disponible para modulos legacy como `redmine-mantencion`.

La capa nativa lee y escribe exclusivamente en `NOVA/Redmine/data` mediante `RedmineDataRepository`. Actualmente cubre:

- Dashboard, edicion, eliminacion, archivado, reintento de errores y envio a Redmine.
- Usuarios, categorias y unidades.
- Configuracion, mantencion y salud del modulo.
- Historico, horas extra, actividad y simulacion webhook.
- Estadisticas basicas desde reportes activos e historicos.
