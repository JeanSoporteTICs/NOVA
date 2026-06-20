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

## Identidad central de usuarios

NOVA debe usar `usuarios_nova` como tabla unica de identidad para las personas. Los modulos no deben crear una segunda fuente principal de usuarios cuando ya existe una relacion por `redmine_id`, RUT, `usuario` o `usuario_core`.

Campos centrales esperados:

- `id`/`uuid`: identificador interno NOVA.
- `usuario`: usuario de acceso.
- `rut`: identidad nacional cuando exista.
- `nombre` y `apellido`: nombre personal separado; los listados deben mostrar ambos.
- `password`, `rol` y `estado`: acceso global NOVA.
- `redmine_id`: ID Redmine asociado cuando exista.
- `usuario_core`: usuario externo CORE.
- Auditoria: `ultimo_login_at`, `creado_at`, `actualizado_at`.

Las integraciones por usuario, como API Redmine, Nextcloud, EMACH o Telegram, deben quedar asociadas al usuario central. Los secretos y credenciales se guardan mediante `integraciones_usuario` o repositorios/helpers existentes, no incrustados en vistas ni logs. Los permisos de acceso por modulo se resuelven con `modulos_nova` y `permisos_usuario_modulo`.

Las vistas y repositorios de runtime no deben reconstruir usuarios ni accesos desde archivos `.json`. `storage/app/nova/users.json`, `storage/app/nova/access.json` y los `data/usuarios.json` de los modulos quedan como artefactos historicos o de importacion manual; la consulta viva debe salir de `usuarios_nova`, `integraciones_usuario`, `modulos_nova`, `permisos_usuario_modulo` y las tablas propias de cada modulo.

La sincronizacion de usuarios sigue estas reglas:

1. Al importar desde Redmine Mantencion o Redmine TIC, crear o actualizar `usuarios_nova` con nombre y apellido separados. Primero usar `firstname`/`lastname` de Redmine; si no existen, consultar el formulario `/users/{id}/edit`; partir `name` solo como fallback.
2. Si NOVA edita datos centrales de un usuario, esos cambios deben reflejarse en el proyecto correspondiente cuando el usuario este registrado o tenga acceso a ese modulo.
3. Si NOVA concede acceso a Redmine Mantencion o Redmine TIC para un usuario creado centralmente, ese usuario debe aparecer en la vista/listado de usuarios del modulo.
4. Redmine Mantencion y Redmine TIC conservan permisos, roles y estados propios por modulo; esos valores no reemplazan el rol global de NOVA.

Estado actual:

- Redmine TIC guarda reportes en `redmine_tic_reportes` y perfil de usuario por modulo en `redmine_tic_perfiles_usuario`; no usa `redmine_tic_usuarios`.
- Redmine Mantencion proyecta las lecturas legacy de `usuarios.json` desde `usuarios_nova`/permisos y guarda cambios de usuarios contra la identidad central.
- Los comandos de importacion JSON siguen existiendo solo como puente historico controlado; no deben ejecutarse automaticamente al pintar vistas.

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
