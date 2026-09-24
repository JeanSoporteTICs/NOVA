# Auditoría Backend

Revisión: 10–11 de septiembre de 2026. Proyecto: NOVA. Modalidad: revisión de código y documentación, sin correcciones.

## 1. Resumen ejecutivo

Se identificaron **33 hallazgos: 0 CRÍTICOS, 17 ALTOS, 15 MEDIOS y 1 BAJO**. De ellos, 32 tienen una condición defectuosa confirmada en código y uno, BACK-009, se clasifica **REQUIERE VERIFICACIÓN** porque su explotación depende de una vinculación de Telegram a grupos que no se inspeccionó en producción. Confirmar código vulnerable no equivale a confirmar una explotación ni una incidencia real.

Los principales riesgos son la autorización de operaciones distinta de la autorización de sus pantallas, el escalamiento de permisos en TIC, la conservación de privilegios revocados en sesiones y la modificación de datos fuera del alcance del usuario. También destacan una copia versionada de la configuración local con secretos, dos vías de ejecución de contenido activo en el origen web de NOVA y problemas de concurrencia que pueden duplicar tickets o restaurar datos anteriores, incluidas contraseñas.

La aplicación dispone de controles útiles: identidad central, comprobación del estado global, permisos por módulo, protección CSRF en numerosas rutas, límites de intentos de autenticación, consultas parametrizadas, normalización de rutas Nextcloud y comprobación de disponibilidad de Redmine. Los hallazgos describen inconsistencias concretas entre esos controles y sus rutas alternativas; no implican que toda la aplicación esté desprotegida.

**Alcance y método.** Se inventariaron estructura, dependencias declaradas/bloqueadas, rutas, middleware, controladores, servicios, repositorios, modelos, helpers, integraciones, configuración y pruebas existentes. La lectura siguió los flujos entrada HTTP/bot → identidad y permisos → servicio/repositorio → BD o integración → respuesta. El inventario de backend en los directorios funcionales, excluyendo vistas/assets/datos, contiene 212 archivos PHP y 46.591 líneas; este conteo expresa tamaño, no cobertura exhaustiva línea por línea. Se revisaron especialmente las operaciones sensibles y sus llamadas, incluyendo implementaciones nativas y puentes legacy.

Se ejecutaron comprobaciones PHP aisladas en memoria sobre métodos reales, con dependencias simuladas cuando fue necesario. Confirmaron: modificación de conexión con `cfg_conexion=false` utilizando el panel resumen; conservación y prioridad del permiso `all`; sesión administrativa que conserva su rol tras una degradación; reconstrucción activa de un perfil de proyecto bloqueado; acceso por coincidencia de nombre pese a un ID asignado diferente; aceptación de un JWT firmado expirado por el helper; aceptación de una contraseña de un carácter por el helper de hash; normalización silenciosa de fecha/hora inválida; e inconsistencia del modelo de autenticación configurado. Estas pruebas no invocaron integraciones ni escribieron datos.

**Límites.** No se ejecutaron HTTP contra el despliegue, consultas/escrituras sobre la BD real, envíos a Redmine/Telegram, pruebas de carga, migraciones, Artisan, build ni la suite funcional completa: esas operaciones pueden generar estado y exceden esta etapa de solo análisis. No se verificaron configuración efectiva de Apache/proxy, exposición pública del repositorio, permisos reales de cuentas externas ni validez remota de secretos. Tampoco se realizó consulta de avisos de vulnerabilidades/SCA; las versiones identificadas no se presentan como seguras ni vulnerables por sí solas. Los escenarios de explotación se describen con sus precondiciones.

Único archivo creado en esta etapa: `Auditoria/02_Auditoria_Backend.md`. No se aplicó ninguna solución sugerida.

## 2. Arquitectura identificada

| Capa | Implementación observada | Responsabilidad y observaciones |
| --- | --- | --- |
| Plataforma | PHP `^8.2`; Laravel `v12.62.0` en `composer.lock` | Aplicación Laravel modular con autenticación propia sobre sesión. |
| Dependencias relevantes | Guzzle `7.15.1`, Sanctum `4.3.2`, Symfony HttpFoundation `7.4.13` | Versiones del lock, no resultado de una auditoría de vulnerabilidades de terceros. |
| Entrada | `public/index.php`, `routes/web.php`, `routes/api.php`, `app/Http/Kernel.php` | Web concentra HTML y acciones JSON; API mantiene una ruta protegida con Sanctum. |
| NOVA | `Nova/Controllers`, `Nova/Services`, `Nova/Repositories`, `Nova/Models` | Identidad, administración, accesos, credenciales, auditoría y horas extra consolidadas. |
| Redmine TIC | `RedmineTic/Controllers/RedmineDashboardController.php`, servicios y repositorios bajo `RedmineTic/` | Controlador multipropósito; fachada de datos más repositorios especializados. |
| Mantención | `RedmineMantencion/Controllers`, `Services`, `Repositories`, `controllers` | MVC Laravel que todavía llama a helpers procedurales, superglobales y funciones de respuesta legacy. |
| Procedimientos | `Procedimientos/Controllers`, `Procedimientos/Services` y cliente WebDAV de Mantención | Editor OnlyOffice; navegador Nextcloud compartido con Mantención. |
| EMACH / Telegram | `Emach/`, `app/Modulos/Telegram/`, `telegram/lib/`, `telegram/bin/` | Integración web/CLI, listener, comandos y credenciales personales. |
| Monitor | `app/Modulos/MonitorServidores/` | CRUD y sondas autorizadas; controlador, servicio, repositorio y worker/comando. |
| Persistencia | Query Builder/Eloquent, repositorios y migraciones | Identidad central en `usuarios_nova`; secretos en `integraciones_usuario`; reportes y permisos específicos por módulo. |
| Procesos diferidos | `app/Console/Kernel.php`, comandos y listeners | Tareas programadas y workers específicos; no se encontraron Jobs de aplicación en los módulos inspeccionados. Varias operaciones web costosas siguen siendo síncronas. |

Los namespaces actuales de Composer apuntan a `App\Modulos\Nova\`, `App\Modulos\RedmineMantencion\`, `App\Modulos\Emach\`, `App\Modulos\Procedimientos\` y `RedmineTic\`, además de `App\`. Algunas rutas y descripciones de AGENTS.md son históricas; las conclusiones usan los archivos actuales. No se considera activo el antiguo servicio Python/FastAPI únicamente por estar mencionado en esa guía.

Flujo de referencia: el navegador envía un formulario o AJAX; Laravel inicia sesión y aplica los middleware; el controlador recupera `nova_user` y, cuando corresponde, la proyección de usuario de proyecto; comprueba permisos; coordina repositorios/servicios; estos leen o escriben BD y llaman a Redmine, CORE, Nextcloud, OnlyOffice, EMACH o Telegram; el controlador responde HTML, JSON o redirección. Las salidas `exit` de algunos helpers interrumpen este último tramo y el retorno por middleware (BACK-018). El bot entra por CLI y requiere aplicar explícitamente las mismas políticas que HTTP (BACK-008).

## 3. Superficie de ataque identificada

Se contaron **102 declaraciones literales de rutas en `routes/web.php`**. No equivalen a 102 endpoints resueltos: existen `match`, parámetros, closures y rutas configurables por módulo. La siguiente matriz agrupa la superficie y las diferencias relevantes.

| Entrada | Autenticación / controles presentes | Operaciones sensibles y resultado de revisión |
| --- | --- | --- |
| `GET/POST /login`, `POST /logout` | Sesión propia; CSRF web; login `throttle:5,1` | Validación de credenciales y rotación/invalidez de sesión en los flujos normales. |
| `POST /session/extend` | Contraseña y `throttle:5,1`; sin `nova.auth` ni CSRF | También puede crear sesión anónima autenticada: BACK-031. |
| `GET/POST /mi-cuenta/password` | `nova.auth`; POST con CSRF y `throttle:5,1` | Cambio propio con contraseña actual; no revoca otras sesiones: BACK-016. |
| `/administracion/*`, `/admin/modules`, aliases de usuarios | `nova.auth` y comprobaciones administrativas en controladores | Roles tomados de sesión; escrituras de usuarios y configuración: BACK-003, BACK-014, BACK-025. |
| `GET/POST /horas-extra` | `nova.auth`, acceso central y al módulo de origen, CSRF | Escritura sin permiso granular equivalente al módulo: BACK-006. |
| `/redmine_tic/app/*` | `nova.auth`, preparación de usuario de proyecto, permisos por acción/sección, CSRF web | CRUD, configuración, histórico, horas, reportes, webhook y envíos. Inconsistencias BACK-001/002/004/005/013/028/032/033. |
| `/redmine-mantencion/app/*` | `nova.auth`, autorización legacy/nativa y validación CSRF propia en numerosos handlers | Dashboard, pendiente manual, histórico, usuarios, configuración y Nextcloud. Exclusión CSRF por prefijo alcanza rutas nativas: BACK-007. |
| `/mis-integraciones`, rutas TIC, Mantención y EMACH de configuración personal | Identidad de sesión y acceso al módulo | No se acepta libremente otro propietario para editar secretos. Excepciones CSRF y flash: BACK-007, BACK-017. |
| `/procedimientos`, `/procedimientos/browser`, `/procedimientos/editor` | `nova.auth`, acceso al módulo; navegador con validación de rutas y CSRF de escrituras | Listar, descargar, cargar, mover, compartir y editar archivos. BACK-010, BACK-018, BACK-024, BACK-030. |
| `GET /procedimientos/document/{token}` | Capacidad aleatoria temporal en caché; deliberadamente sin sesión | Entrega documento privado al editor. Token utilizable mientras vive: BACK-020. |
| `POST /procedimientos/callback/{token}` | Capacidad temporal y verificación de firma JWT; sin CSRF por integración servidor a servidor | Guarda documento; vinculación insuficiente del mensaje firmado: BACK-015. |
| `/telegram`, `POST /telegram/test`, administración Telegram | `nova.auth`; administración con guardas adicionales | Índice/test carecen de comprobación del módulo: BACK-008. |
| Bot Telegram / comandos CLI | Vinculación de chat a usuario activo | No hereda middleware HTTP; faltan políticas de módulos y, condicionalmente, identidad del remitente en grupos: BACK-008/009. |
| `/emach`, `/emach/horario.php` y configuración | Sesión, puente y credenciales propias; mezcla de CSRF Laravel/legacy | Consultas externas; alcance de exclusión nativa en BACK-007. |
| `/monitoreo-servidores/*` | `authorizeAccess`; mutaciones y sondas con `authorizeManager`; destinos de prueba con `throttle:10,1` | `whereNumber` para IDs. Sondas intranet son funcionalidad prevista del administrador, no SSRF demostrada por sí mismas. Lotes: BACK-023. |
| Assets, health, redirects y puentes | Assets públicos con resolución restringida; health dentro de `nova.auth`; puentes con comprobaciones | Health devuelve también `base_path` al usuario autenticado; conviene minimizarlo. No se demostró lectura arbitraria por traversal. |
| `GET /api/user` | `auth:sanctum` | Configuración residual incompatible con el modelo/autenticación NOVA: BACK-029. |

Referencias de rutas: `routes/web.php:51`, `routes/web.php:68`, `routes/web.php:84`, `routes/web.php:98`, `routes/web.php:117`, `routes/web.php:140`, `routes/web.php:169`, `routes/web.php:188`, `routes/web.php:221` y `routes/api.php:17`.

## 4. Hallazgos críticos

**No se confirmó ningún hallazgo CRÍTICO** con la evidencia y precondiciones observadas. No se demostró ejecución remota de código, toma de control anónima generalizada ni exposición pública efectiva de la configuración.

BACK-011 merece atención inmediata aunque se mantenga ALTO: la copia versionada coincide con la configuración local. Su accesibilidad desde Internet o una credencial de alcance excepcional elevarían el riesgo, pero son **REQUIERE VERIFICACIÓN**; no se asumen.

Las cadenas BACK-001 → BACK-002 y BACK-003 → operaciones administrativas agravan el alcance de usuarios ya autenticados. BACK-014 puede afectar contraseñas por concurrencia y debe tratarse como integridad de seguridad, no solo como defecto funcional.

## 5. Autenticación

La identidad central se proyecta a `nova_user`. El login interactivo comprueba contraseña, tiene límite de intentos y no habilita indiscriminadamente el token API como contraseña. El cambio propio exige contraseña actual, confirmación y política de longitud; el logout central es POST. Se observó uso de hash de contraseña y cifrado para secretos de integración mediante los helpers correspondientes.

Los principales problemas son la vigencia de la autorización dentro de la sesión (BACK-003), la falta de revocación de otras sesiones después de cambiar contraseña (BACK-016), la política distinta en creación/restablecimiento administrativo (BACK-025) y el login alternativo sin CSRF (BACK-031). La comprobación de estado global bloqueado sí existe; no debe confundirse el fallo de perfil de módulo BACK-004 con una omisión del bloqueo global.

Cookies `Secure`, SameSite, transporte HTTPS, expiración efectiva en proxy/servidor y almacenamiento de sesiones requieren verificación del despliegue. `config/session.php` ofrece esas opciones; no se infiere que una configuración de ejemplo sea la efectiva. La sesión no se cifra por defecto en la configuración revisada, relevante para el flash de secretos (BACK-017). No se identificó en las rutas revisadas un flujo convencional de recuperación pública por correo que pudiera auditarse como tal.

## 6. Autorización y permisos

La revisión distinguió cuatro decisiones: cuenta central activa, acceso al módulo, permiso de operación y alcance sobre el registro. `nova.auth` por sí solo no acredita las otras tres. Se verificaron rutas manuales y acciones POST alternativas, sin depender de la presencia de botones.

| Fallo de frontera | Hallazgos | Consecuencia |
| --- | --- | --- |
| Permiso de pantalla usado para otra operación | BACK-001, BACK-006 | Configurar conexión/roles u horas extra sin el permiso correspondiente. |
| Administración modular que otorga privilegios superiores | BACK-002, BACK-033 | Permiso total TIC o cambio del estado global de una cuenta desde TIC. |
| Rol/estado no actualizado correctamente | BACK-003, BACK-004 | Privilegio revocado persistente o sustitución de perfil bloqueado. |
| Alcance de registro omitido o ambiguo | BACK-005, BACK-032 | Eliminación de histórico ajeno o autorización basada en nombres coincidentes. |
| Entrada alternativa sin política equivalente | BACK-008, BACK-009 | Uso del bot o de Telegram sin acceso modular o con identidad de grupo ambigua. |
| Estado habilitado aplicado solo a una vía | BACK-026 | Módulo nativo deshabilitado sigue alcanzable por URL. |

Controles que sí se preservan: filtrado por usuario de los IDs del dashboard TIC; filtrado de IDs accesibles al actualizar estados del histórico TIC; verificación de alcance al eliminar histórico Mantención; autorización administrativa del monitor; credenciales personales resueltas desde sesión. Estos controles reducen la superficie, pero el filtrado TIC comparte el defecto de identidad BACK-032. La propagación por fecha de horas extra está expresamente documentada en el repositorio; su política de edición colectiva requiere aclaración, sin invalidar la omisión confirmada del permiso de edición en BACK-006.

## 7. Vulnerabilidades de seguridad

| Categoría solicitada | Resultado sustentado |
| --- | --- |
| SQL Injection | No confirmada en los flujos revisados. Predomina Query Builder/Eloquent con valores enlazados. Expresiones raw examinadas son fijas; las tablas dinámicas del log provienen de un conjunto cerrado. |
| XSS reflejado/almacenado | BACK-030: serialización en scripts e inserción HTML. BACK-010: contenido activo remoto servido inline desde NOVA. |
| CSRF | BACK-007 y BACK-031. La exclusión del callback servidor a servidor no es por sí misma un fallo; su autenticación se evalúa en BACK-015. |
| IDOR / Broken Access Control | BACK-001 a BACK-006, BACK-008, BACK-026, BACK-032 y BACK-033. BACK-009 es condicional. |
| Mass Assignment | No se confirmó asignación masiva genérica `request → Model::create`. Sí hay campos inesperados aceptados entre paneles en BACK-001. |
| Path Traversal / LFI | No confirmado en el puente actual: resolución canónica y límites de directorio. Nextcloud emplea `pathSafe`/helpers y codificación de segmentos. |
| Command Injection | No confirmada: se observaron argumentos de procesos separados y conversiones numéricas en las llamadas relevantes. |
| Upload / documentos | BACK-010 y BACK-024. Guardar archivos generales en Nextcloud no prueba ejecución PHP en NOVA. |
| SSRF | El cambio no autorizado de destino en BACK-001 habilita llamadas salientes indebidas. No se afirma SSRF anónima del monitor ni del callback: existen controles administrativos y restricción de host/puerto, respectivamente. Verificar redirecciones/destinos finales forma parte del endurecimiento. |
| Open Redirect | No se confirmó una redirección arbitraria explotable en los flujos inspeccionados. |
| Secretos / configuración | BACK-011, BACK-012, BACK-017 y BACK-020. No se reproducen valores sensibles. |
| Deserialización insegura | No se identificó entrada no confiable alcanzable hacia deserialización de objetos PHP en los flujos revisados. |
| Dependencias vulnerables | REQUIERE VERIFICACIÓN con SCA/advisories actuales. No se atribuyen CVE sin comprobar versión, componente y alcance. |

Los helpers de extracción de archivos y las importaciones CLI no se declaran vulnerabilidades HTTP por existir. Se requiere una cadena de entrada alcanzable; no se encontró un endpoint público activo que sustentara esa conclusión.

## 8. APIs y endpoints

La mayor parte de la API real de la interfaz vive en rutas `web`, con sesión y respuestas JSON. Los permisos están distribuidos entre middleware, controladores y funciones legacy. `GET/POST` compartidos en Mantención hacen especialmente importante vincular cada acción a permiso, CSRF y método explícitos (BACK-001, BACK-007).

La consistencia de respuestas necesita revisión: algunas operaciones retornan éxito por reconocer la acción, no por persistir el cambio (BACK-028), o normalizan entradas inválidas (BACK-027). Conviene distinguir 401/403, 404 para recursos fuera de alcance, 409 para conflicto concurrente, 422 para validación y errores de integración 502/504 cuando corresponda. No se recomienda transformar indiscriminadamente todas las redirecciones HTML en JSON.

Login, cambio propio de contraseña, extensión de sesión y varias pruebas de integración tienen throttling. No se considera automáticamente vulnerable cada POST sin límite; el riesgo concreto está en los lotes síncronos de BACK-023. El endpoint llamado `webhook` de TIC permanece dentro de sesión/permisos y no se asumió público por su nombre.

## 9. Acceso a Base de Datos

Se utilizan repositorios sobre tablas centrales y modulares con consultas enlazadas. La separación por repositorios y las escrituras dirigidas de reportes TIC son avances útiles. Las migraciones son la referencia de esquema; no se certifica su aplicación en el servidor ni se publican conteos de datos reales.

Los problemas prioritarios son las escrituras de snapshots completos que pueden revertir cambios concurrentes (BACK-014), la falta de reserva atómica para envíos externos (BACK-013), la eliminación sin alcance del histórico (BACK-005) y la modificación de estado global desde permisos modulares (BACK-033). Una transacción alrededor de una escritura no resuelve por sí sola una lectura obsoleta realizada antes ni hace atómica una llamada a Redmine.

BACK-021 describe lecturas globales y N+1 comprobables por llamadas; BACK-022 describe filtrado/paginación después de materializar colecciones. BACK-028 incluye fallos silenciados con persistencia parcial. No se propone añadir índices concretos sin contrastar los existentes y el plan de ejecución en una copia representativa.

## 10. Rendimiento

| Hallazgo | Costo visible en código | Verificación posterior recomendada |
| --- | --- | --- |
| BACK-021 | Cargar todos los usuarios/integraciones para resolver una identidad; resolver credenciales por usuario en bucle | Número de consultas por request y usuarios 10/100/1.000 con datos sintéticos. |
| BACK-022 | Materialización de reportes/logs antes de filtrar y cortar páginas | Memoria y tiempo por página a medida que crece el histórico. |
| BACK-023 | Suma de esperas externas por elemento en lotes síncronos | Duración total, cancelación, límite de concurrencia y ocupación de workers. |
| BACK-024 | Cuerpo completo de archivos remotos en memoria y copias adicionales | Archivos grandes, respuesta sin longitud y límites de transferencia. |
| BACK-019 | Recorte de auditoría en cada inserción | Contención y conservación efectiva bajo tráfico normal. |

Ya existen timeouts y comprobaciones de disponibilidad de Redmine. No es correcto describir el estado actual como ausencia total de timeout. Persisten el tiempo total de lote y la posibilidad de duplicación ante concurrencia o resultado incierto. Las observaciones de complejidad no son mediciones de latencia del despliegue.

## 11. Logging y manejo de errores

La auditoría global y los logs operacionales cumplen funciones diferentes. El límite fijo de 500 eventos globales combinado con tráfico rutinario reduce el valor de investigación (BACK-019); registrar URLs con capacidades temporales expone acceso a documentos (BACK-020). Las salidas directas de PHP evitan el registro posterior del middleware (BACK-018).

Se observaron capturas que silencian fallos de permisos/persistencia y respuestas de éxito que no expresan el resultado real (BACK-028). Los secretos completos enviados a flash pueden terminar en el almacenamiento de sesión (BACK-017); esto es distinto de afirmar que se imprimen en la vista.

No se verificó una respuesta HTTP real con stack trace ni se concluye exposición de errores por inferir `APP_DEBUG` a partir de archivos locales. Se recomienda registrar códigos de error y correlación, preservar el detalle en canales restringidos y evitar secretos, URLs de capacidad y cuerpos completos de integraciones.

## 12. Calidad y mantenibilidad

Persisten concentraciones de responsabilidad: `RedmineDataRepository.php` tiene 3.000 líneas, `MantencionCoreImportService.php` 1.761 y `RedmineDashboardController.php` 1.207. El tamaño no se cuenta como vulnerabilidad por preferencia de estilo. Su efecto verificable aparece en acciones que comparten una guarda demasiado amplia (BACK-001), contratos de resultado inconsistentes (BACK-028), reglas repetidas o divergentes (BACK-006, BACK-025) y helpers que terminan el proceso HTTP (BACK-018).

La mezcla Laravel/procedural hace que los permisos y la persistencia de sesión dependan del camino de entrada. La futura extracción debe separar casos de uso y políticas con pruebas de comportamiento; no requiere reescribir todos los módulos. El proveedor Sanctum/modelo residual de BACK-029 sí tiene una incompatibilidad concreta, independiente de preferencias arquitectónicas.

Hay pruebas existentes de autenticación, acceso, permisos y comportamiento modular; esta etapa no las ejecutó ni declara cobertura porcentual. Se recomienda añadir pruebas negativas específicas por hallazgo, especialmente combinaciones de rol/permiso/propietario y carreras de escritura. No se propone eliminar dependencias, archivos históricos o interfaces por aparente falta de uso sin comprobar sus consumidores.

## 13. Detalle completo de hallazgos

### BACK-001 — El panel elegido por el cliente determina el permiso, pero no limita la operación

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN CÓDIGO; bypass de conexión TIC reproducido con dependencias en memoria.
- **Archivo:** `RedmineTic/Controllers/RedmineDashboardController.php:379`, `RedmineTic/Controllers/RedmineDashboardController.php:408`, `RedmineTic/Controllers/RedmineDashboardController.php:478`; `RedmineMantencion/Controllers/ConfiguracionController.php:24`; `RedmineMantencion/Services/MantencionConfiguracionService.php:97`; `RedmineMantencion/controllers/maintenance.php:514`.
- **Método/función/ruta:** `configurationAction`, `ConfiguracionController::index`, handler de configuración y `handle_maintenance_request`; POST de configuración de ambos Redmine.
- **Problema:** Se autoriza el panel indicado en query y luego se procesan acciones/campos que pertenecen a otros permisos. En Mantención, un panel vacío evita además la comprobación granular.
- **Evidencia:** TIC consulta `panel` con default `resumen`, autoriza `CONFIG_PANEL_PERMISSIONS[$panel]` y después ejecuta `save_user_permissions`, `save_role_permissions` o lee `platform_url` sin vincularlos al panel. Mantención permite entrada por `configuracion` o `categorias` y el servicio procesa configuración enviada. La prueba aislada del controlador TIC guardó una URL sintética con `cfg_conexion=false` y `cfg_resumen=true`.
- **Riesgo real:** Modificación de conexiones, permisos o mantenimiento fuera de la autorización prevista. Alterar el destino Redmine puede enviar posteriormente un token personal a un servidor controlado por el atacante.
- **Escenario:** Usuario autenticado con acceso a configuración/resumen envía un POST al panel permitido incluyendo campos de conexión o una acción de permisos. Para la fuga posterior debe producirse una llamada real al destino alterado; no se realizó.
- **Solución recomendada:** Autorizar una acción identificada por el servidor; restringir los campos aceptados por esa acción y comprobar permisos de destinatarios/roles/mantenimiento por separado. Rechazar combinaciones inesperadas. Una lista visual de paneles no sustituye esa política.
- **Código sugerido:** Patrón ilustrativo, con nombres de acciones a adaptar; no aplicado:

```php
$permissions = [
    'save_connection' => 'cfg_conexion',
    'save_role_permissions' => 'cfg_roles',
    'save_user_permissions' => 'cfg_usuarios',
];
$action = (string) $request->input('config_action');
abort_unless(isset($permissions[$action]), 422);
$this->authorizePermission($request, $redmine, $permissions[$action]);
// Cada handler valida y acepta exclusivamente sus campos.
```

### BACK-002 — Un administrador no root puede conceder el permiso total TIC

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN CÓDIGO; prioridad de `all` reproducida en memoria.
- **Archivo:** `RedmineTic/Controllers/RedmineDashboardController.php:408`, `RedmineTic/Controllers/RedmineDashboardController.php:983`, `RedmineTic/Controllers/RedmineDashboardController.php:1170`; `RedmineTic/Repositories/RedmineUserRepository.php:359`.
- **Método/función/ruta:** `configurationAction`, `preserveRestrictedScopes`, `can`, `saveUserPermissions`.
- **Problema:** `user_role=root` introduce `permisos.all=true`; la restricción para un actor no root conserva scopes, pero no elimina `all` ni prohíbe ese rol reservado.
- **Evidencia:** La rama asigna `all` antes de persistir; `can()` devuelve verdadero por `all` antes de un permiso explícito falso. La prueba aislada conservó `all` para actor no root y autorizó `reportes_eliminar=false`.
- **Riesgo real:** Escalamiento vertical a permiso total del módulo TIC. No se afirma modificación automática del rol global NOVA.
- **Escenario:** Actor con acceso a guardar permisos, directamente o mediante BACK-001, envía el rol reservado para un usuario del proyecto.
- **Solución recomendada:** Verificar el rol central vigente del actor; impedir que una administración modular conceda `all` o roles reservados. Aplicar un límite explícito a los permisos delegables y validarlo también en el caso de uso, no solo en el formulario.
- **Código sugerido:** Antes de persistir, retirar `all` del payload editable y rechazar la solicitud de rol reservado cuando el actor central no esté autorizado; cubrir tanto permisos individuales como plantillas de rol.

### BACK-003 — La sesión conserva privilegios globales revocados

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN CÓDIGO; comprobación de middleware reproducida en memoria.
- **Archivo:** `app/Http/Middleware/EnsureNovaAuthenticated.php:14`; `Nova/Controllers/NovaAdministrationController.php:357`; `Nova/Repositories/NovaAccessRepository.php:153`; `Nova/Services/NovaUserService.php:203`.
- **Método/función/ruta:** Middleware de autenticación, guardas administrativas y `canAccess`.
- **Problema:** El middleware verifica existencia/estado de la cuenta, pero no sustituye el rol de `nova_user` por el vigente. Controladores y accesos amplios consultan ese rol de sesión.
- **Evidencia:** Una cuenta sintética activa degradada de admin a usuario superó la comprobación y dejó `role=admin` en sesión. `canAccess` contempla acceso amplio administrativo antes de restricciones de usuario.
- **Riesgo real:** La revocación administrativa no surte efecto inmediato en sesiones existentes; el usuario puede seguir ejecutando operaciones privilegiadas mientras su sesión siga vigente.
- **Escenario:** Se reduce el rol de un administrador activo. Su navegador ya autenticado sigue realizando solicitudes sin reautenticarse.
- **Solución recomendada:** Rehidratar identidad/rol desde una consulta puntual o comparar una versión de autorización de usuario en cada request. Invalidar sesiones al cambiar privilegios, con reglas explícitas para la sesión actual.
- **Código sugerido:** Proyectar nuevamente el usuario canónico con el servicio existente tras resolver su ID; no copiar indiscriminadamente secretos o toda la ficha a sesión.

### BACK-004 — Un perfil TIC bloqueado se sustituye por un perfil activo de respaldo

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN CÓDIGO; guard real probado con proveedores en memoria.
- **Archivo:** `Nova/Services/ProjectAccessGuard.php:28`, `Nova/Services/ProjectAccessGuard.php:65`, `Nova/Services/ProjectAccessGuard.php:169`; `RedmineTic/Controllers/RedmineDashboardController.php:76`, `RedmineTic/Controllers/RedmineDashboardController.php:968`.
- **Método/función/ruta:** `findProjectUser`, `projectUserFromRows`, `sessionProjectUser`, preparación TIC y permisos efectivos.
- **Problema:** El perfil bloqueado se descarta como `null`; ese resultado es indistinguible de perfil inexistente y activa el fallback de sesión, con estado activo y permisos de rol.
- **Evidencia:** Con cuenta central activa, acceso central explícito y perfil de proyecto `baneado`, la prueba devolvió usuario de proyecto no nulo y `activo`.
- **Riesgo real:** El estado operativo del módulo deja de actuar como denegación.
- **Escenario:** Cuenta central existente y activa conserva acceso al módulo, pero su perfil TIC está bloqueado, por ejemplo después de una importación que crea perfiles bloqueados. No se afirma que el bloqueo global se eluda: ese bloqueo sí se verifica; el toggle actual de TIC también modifica el estado global (BACK-033).
- **Solución recomendada:** Distinguir estados ausente, permitido y bloqueado. Denegar un perfil bloqueado antes de considerar cualquier fallback; reservar este último para ausencia real y autorizada.
- **Código sugerido:** Devolver un resultado explícito de resolución con estado, en vez de usar `null` para ausencia y denegación.

### BACK-005 — La eliminación de histórico TIC omite el alcance del usuario

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN CÓDIGO; no se eliminaron registros.
- **Archivo:** `RedmineTic/Controllers/RedmineDashboardController.php:584`, `RedmineTic/Controllers/RedmineDashboardController.php:636`; `RedmineTic/Repositories/RedmineDataRepository.php:1076`, `RedmineTic/Repositories/RedmineDataRepository.php:1367`; `RedmineTic/Repositories/RedmineReportRepository.php:565`.
- **Método/función/ruta:** `historyAction`, `history`, `deleteArchivedReport`; POST `/redmine_tic/app/historico`.
- **Problema:** Listar histórico aplica scope, pero eliminar recibe el ID y consulta módulo/estado archivado sin propietario o conjunto accesible.
- **Evidencia:** El controlador exige `historico`/`historico_acciones`, luego delega directamente el ID. El repositorio elimina por ID, módulo y estado. La actualización de estados del histórico sí utiliza IDs accesibles, por lo que no se generaliza el defecto a todas sus acciones.
- **Riesgo real:** IDOR destructivo sobre histórico de otros usuarios del mismo módulo.
- **Escenario:** Usuario con acciones de histórico y scope asignado cambia el ID del POST por el de un reporte archivado ajeno.
- **Solución recomendada:** Resolver y eliminar mediante una consulta ya limitada al alcance del actor, dentro de una operación consistente. No autorizar comparando nombres. Responder 403/404 sin mutación cuando el ID esté fuera de alcance.
- **Código sugerido:** Extraer una consulta compartida de reportes autorizados y aplicar `whereKey($id)` sobre ella antes de borrar; evitar cargar todo el histórico solo para validar un ID.

### BACK-006 — Horas extra consolidadas omiten el permiso de edición del módulo

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN CÓDIGO para omisión de permiso; alcance colectivo por fecha REQUIERE VERIFICACIÓN funcional.
- **Archivo:** `Nova/Controllers/HoursExtraController.php:29`, `Nova/Controllers/HoursExtraController.php:53`; `RedmineTic/Controllers/RedmineDashboardController.php:665`; `RedmineMantencion/Controllers/HorasExtraController.php:53`; `Nova/Repositories/HorasExtraRepository.php:204`; `RedmineTic/Repositories/RedmineHoursExtraRepository.php:23`.
- **Método/función/ruta:** `HoursExtraController::update`, `updateGroupTime`, `updateGroupsByOrigenAndFecha`; POST `/horas-extra`.
- **Problema:** La ruta central comprueba acceso a módulos, pero no `horas_extra_editar` ni las mismas restricciones de mantenimiento de los flujos nativos. El listado filtra por asignado; la escritura delega solo origen/fecha/horas.
- **Evidencia:** TIC y Mantención exigen permiso de edición en sus rutas; la central no. El repositorio documenta y ejecuta actualización de todos los grupos del origen/fecha, sin usuario en el filtro.
- **Riesgo real:** Un usuario con acceso de consulta puede modificar horas pese a no poder hacerlo por la ruta nativa. Según la política deseada, la modificación también puede afectar a otros usuarios del día.
- **Escenario:** Usuario con ambos accesos centrales, pero permiso de edición del origen denegado, envía el formulario central. No se clasifica automáticamente la propagación colectiva como IDOR: está documentada y debe confirmarse quién puede realizarla.
- **Solución recomendada:** Centralizar autorización del caso de uso por origen/acción y mantenimiento. Definir explícitamente edición propia frente a edición colectiva; si es propia, incluir usuario/grupo autorizado en la escritura.
- **Código sugerido:** El servicio de actualización debe recibir actor y recurso/grupo, y autorizar allí; la firma actual basada solo en fecha no expresa esa decisión.

### BACK-007 — Exclusiones CSRF legacy alcanzan escrituras nativas de credenciales

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN CÓDIGO.
- **Archivo:** `app/Http/Middleware/VerifyCsrfToken.php:20`; `config/modules.php`; `routes/web.php:125`, `routes/web.php:174`; `Nova/Controllers/UserIntegrationController.php:177`, `Nova/Controllers/UserIntegrationController.php:346`.
- **Método/función/ruta:** Exclusión por prefijo y `UserIntegrationController::update`; configuración personal EMACH y Mantención.
- **Problema:** La exclusión CSRF se aplica a todo el prefijo de módulos legacy o marcados para validación propia. Las rutas nativas de integraciones personales bajo esos prefijos no realizan esa validación propia.
- **Evidencia:** El middleware construye las excepciones a partir de `type`/`legacy_csrf_validation`; el controlador autoriza módulo/usuario y guarda o elimina credenciales, sin invocar validación CSRF alternativa. Definir helpers en `requireauth` no equivale a ejecutarlos.
- **Riesgo real:** POST forjado que altera credenciales de integración de la víctima cuando el navegador adjunta la sesión. SameSite limita escenarios entre sitios, pero no sustituye protección CSRF, especialmente entre orígenes del mismo sitio.
- **Escenario:** Un origen atacante que consigue enviar cookies de sesión provoca una actualización/eliminación con parámetros válidos. No se requiere poder leer la respuesta. Las rutas central y TIC no comparten esta exclusión concreta.
- **Solución recomendada:** Restringir excepciones a endpoints legacy exactos que sí validan token, o integrar toda la escritura con CSRF Laravel. Añadir pruebas de ausencia/token incorrecto con respuesta 419 y sin cambios.
- **Código sugerido:** Mantener las rutas nativas personales fuera de la lista de excepciones; no añadir una excepción general nueva para arreglar compatibilidad.

### BACK-008 — Telegram y sus comandos no aplican de forma uniforme el acceso modular

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN CÓDIGO; no se enviaron mensajes ni reportes.
- **Archivo:** `app/Modulos/Telegram/Controllers/TelegramController.php:24`, `app/Modulos/Telegram/Controllers/TelegramController.php:95`; `telegram/bin/listen.php:100`, `telegram/bin/listen.php:341`, `telegram/bin/listen.php:397`, `telegram/bin/listen.php:446`; `RedmineTic/Repositories/RedmineDataRepository.php:2104`.
- **Método/función/ruta:** `index`, `test`, resolución de chat, creación de reporte TIC y comando EMACH; `/telegram`, `/telegram/test`, listener.
- **Problema:** Índice/test web no comprueban acceso a Telegram. El listener valida cuenta activa por chat, pero no los permisos centrales y operacionales de la integración solicitada. Crear reporte desde Telegram comprueba mantenimiento, no autorización de creación ni estado del perfil.
- **Evidencia:** La cadena termina en persistencia de reporte; si no resuelve asignado puede crear el pendiente sin asignación en lugar de denegar al actor. `/emach` usa credenciales de la cuenta, sin comprobar acceso central al módulo.
- **Riesgo real:** Revocar acceso en NOVA no impide usar ciertas funciones por bot; credenciales guardadas pasan a actuar como sustituto de autorización.
- **Escenario:** Usuario central activo y chat vinculado cuyo acceso TIC fue retirado sigue enviando reportes mediante bot. Requiere que el listener esté operativo; no se verificó su estado remoto.
- **Solución recomendada:** Aplicar el mismo caso de uso autorizado a HTTP y CLI, con actor, módulo, estado del perfil y permiso de acción. Proteger explícitamente índice/test web; devolver denegación antes de cualquier llamada externa o escritura.
- **Código sugerido:** Usar un servicio de autorización compartido accesible desde el listener; no simular una sesión administrativa para reutilizar controladores.

### BACK-009 — Un chat grupal vinculado puede representar a todos sus remitentes como una cuenta

- **Severidad:** ALTO.
- **Estado:** **REQUIERE VERIFICACIÓN** de configuración y política de grupos. El uso de `chat.id` sin identidad individual está confirmado en código.
- **Archivo:** `telegram/bin/listen.php:82`, `telegram/bin/listen.php:100`, `telegram/bin/listen.php:397`; `Nova/Repositories/UserIntegrationRepository.php:183`, `Nova/Repositories/UserIntegrationRepository.php:593`.
- **Método/función/ruta:** Extracción del mensaje y resolución de usuario por chat; guardado del Chat ID personal.
- **Problema:** Los comandos personales se asocian mediante `message.chat.id`; no se exige chat privado ni se valida `message.from.id` contra una vinculación personal verificada. El guardado permite un identificador no vacío sin demostrar posesión/tipo.
- **Evidencia:** El dispatcher resuelve cuenta por chat y ejecuta los comandos como ella. No se observó un handshake que separe al destinatario grupal de la identidad del actor.
- **Riesgo real:** Si una cuenta personal está vinculada a un grupo, otros miembros podrían invocar comandos con sus integraciones y recibir información en el grupo.
- **Escenario:** Un usuario guarda el ID de un grupo y otro miembro envía un comando reconocido. No se inspeccionaron chats configurados ni se afirma que este escenario exista actualmente. En un chat privado la equivalencia de chat y remitente reduce este riesgo.
- **Solución recomendada:** Para comandos personales, exigir chat privado y vinculación verificada; si se permiten grupos, autenticar al remitente por separado y definir qué resultados pueden publicarse allí.
- **Código sugerido:** Comprobar tipo de chat antes de resolver credenciales; no basta con validar que el ID sea numérico. Verificar también el esquema real antes de concluir si hay unicidad de bindings.

### BACK-010 — Archivos activos de Nextcloud se sirven inline desde el origen NOVA

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN CÓDIGO; sin carga de archivos en servicios reales.
- **Archivo:** `RedmineMantencion/controllers/nc_browser.php:278`, `RedmineMantencion/controllers/nc_browser.php:389`.
- **Método/función/ruta:** Acción `download` del navegador Nextcloud, accesible desde Procedimientos y el alias legacy.
- **Problema:** La descarga copia el MIME remoto, admite `svg` en la lista inline y entrega el cuerpo sin aislamiento ni saneamiento. `nosniff` no impide ejecutar scripts de un SVG servido correctamente como SVG al abrirlo como documento.
- **Evidencia:** Las líneas 291–299 leen `Content-Type`, seleccionan inline por extensión y emiten el contenido remoto. El navegador puede acceder a documentos compartidos por otros usuarios; no hace falta que NOVA almacene una copia local.
- **Riesgo real:** Contenido controlado por otra persona puede ejecutar código en el origen de NOVA al abrirse como documento, con los privilegios web de la víctima.
- **Escenario:** Se comparte un SVG activo por Nextcloud y la víctima abre su enlace de descarga NOVA. La ejecución depende de esa navegación, no de mostrarlo necesariamente como imagen `<img>`.
- **Solución recomendada:** Forzar attachment para contenido activo, o previsualizarlo mediante conversión/saneamiento e aislamiento de origen/sandbox. Validar MIME y extensión de la previsualización; conservar la función de almacenamiento general.
- **Código sugerido:** Retirar SVG de la lista inline es una contención parcial; para una política completa, no confiar solo en la extensión cuando el MIME proviene del servidor remoto.

### BACK-011 — Una copia versionada de configuración coincide con los secretos locales

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN REPOSITORIO; exposición pública y validez remota REQUIEREN VERIFICACIÓN.
- **Archivo:** `env.txt:3`, `env.txt:16`, `env.txt:59`; artefactos versionados `bd.txt`, `database_nova_reconstruida.sql`, `nova.sql`, `nova2.sql`, `nova-3.sql`, `nova(1).sql` y `.tools/complete_nova_schema.sql`.
- **Método/función/ruta:** Distribución del repositorio y configuración de despliegue; no requiere una ruta Laravel para exponerlo a lectores del repositorio.
- **Problema:** `env.txt` está seguido por Git y es idéntico byte a byte a `.env` local. Contiene valores no vacíos de `APP_KEY`, `DB_PASSWORD` y `TELEGRAM_BOT_TOKEN`. Hay además artefactos SQL versionados; se confirmó contenido CREATE/INSERT en `bd.txt` sin imprimir registros.
- **Evidencia:** Inspección de `git ls-files`, comparación local en memoria y comprobación de presencia de claves. No se incluyen valores ni extractos sensibles. `.htaccess` de `public` no protege automáticamente archivos hermanos en la raíz si Apache sirve también esa raíz.
- **Riesgo real:** Cualquier lector de esa copia del repositorio obtiene configuración sensible. Las copias SQL amplían los datos que deben clasificarse y controlar. No se afirma que todas contengan los mismos secretos ni que el repositorio sea público.
- **Escenario:** Acceso al repositorio, copia de soporte o raíz web mal publicada revela credenciales. El primer canal está demostrado como almacenamiento versionado; el último depende del despliegue.
- **Solución recomendada:** En una etapa autorizada, retirar secretos y dumps de la distribución, evaluar quién accedió, rotar las credenciales afectadas y coordinar saneamiento del historial/copias. Publicar solo `public/` y validar aliases. Planificar rotación de `APP_KEY` con los datos cifrados existentes para no perder acceso a ellos.
- **Código sugerido:** No corresponde un parche aislado: añadir patrones de ignore no elimina secretos del historial ni reemplaza rotación y control de copias.

### BACK-012 — El cliente CORE desactiva la verificación TLS

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN CÓDIGO; no se probó interceptación de red.
- **Archivo:** `RedmineMantencion/Services/MantencionCoreImportService.php:334`, `RedmineMantencion/Services/MantencionCoreImportService.php:1387`.
- **Método/función/ruta:** Cliente cURL y construcción del login CORE; importación/autenticación CORE desde Mantención.
- **Problema:** Se establecen `CURLOPT_SSL_VERIFYPEER=false` y `CURLOPT_SSL_VERIFYHOST=0` en el cliente que transporta autenticación y datos.
- **Evidencia:** Las opciones están en el transporte compartido; el flujo construye el campo `login_pass` con la credencial personal. Existen además redirecciones y cookies de integración.
- **Riesgo real:** Un atacante con capacidad de interceptar el trayecto puede suplantar un servidor HTTPS y obtener credenciales/sesión o alterar reportes importados.
- **Escenario:** Interceptación en red o resolución manipulada presenta un certificado inválido que el cliente acepta. No se presupone que la red actual esté comprometida.
- **Solución recomendada:** Validar certificado y hostname; instalar la CA institucional si corresponde. Restringir destino de formularios/redirecciones al origen confiable antes de reenviar secretos.
- **Código sugerido:**

```php
CURLOPT_SSL_VERIFYPEER => true,
CURLOPT_SSL_VERIFYHOST => 2,
// CURLOPT_CAINFO => $rutaDeCaInstitucional, si no está en el almacén del sistema.
```

### BACK-013 — El envío de reportes no reserva registros ni impide reenviar los ya procesados

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN CÓDIGO; no se crearon tickets de prueba en Redmine.
- **Archivo:** `RedmineTic/Repositories/RedmineDataRepository.php:1850`, `RedmineTic/Repositories/RedmineDataRepository.php:1906`, `RedmineTic/Repositories/RedmineDataRepository.php:1938`; `RedmineTic/Repositories/RedmineReportRepository.php:302`; `RedmineMantencion/Services/MantencionRedmineSyncService.php:355`, `RedmineMantencion/Services/MantencionRedmineSyncService.php:447`.
- **Método/función/ruta:** Envío de seleccionados de dashboards TIC/Mantención.
- **Problema:** Leer el reporte y crear el ticket remoto no se protege con una transición atómica de estado. El conjunto activo TIC excluye archivados, pero incluye procesados; las guardas de envío tampoco rechazan uniformemente tickets existentes.
- **Evidencia:** La llamada remota precede a la persistencia del resultado, sin claim exclusivo. Mantención lee mensajes y guarda al final del lote. Los controles de interfaz no impiden repetir el POST.
- **Riesgo real:** Duplicación de tickets, atribución inconsistente del número remoto y necesidad de conciliación manual.
- **Escenario:** Dos solicitudes envían el mismo reporte concurrentemente; ambas lo leen antes del cambio de estado. También un POST repetido con un ID procesado puede crear otro ticket. Un timeout después de la aceptación remota añade incertidumbre.
- **Solución recomendada:** Reservar atómicamente `pendiente → enviando`, rechazar ticket ya asociado y persistir cada resultado. Mantener un identificador durable de intento y conciliación para resultados inciertos. No mantener una transacción DB abierta durante toda la espera externa ni reintentar automáticamente un POST ambiguo.
- **Código sugerido:** Una actualización condicional debe devolver exactamente un registro reservado antes de llamar a Redmine; si devuelve cero, responder conflicto/estado actual. Adaptar estados y campos al esquema de cada módulo.

### BACK-014 — Escrituras de snapshots completos pueden restaurar contraseñas y datos antiguos

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN CÓDIGO por secuencia de lectura/escritura; carreras no ejecutadas sobre BD real.
- **Archivo:** `Nova/Repositories/NovaUserRepository.php:76`, `Nova/Repositories/NovaUserRepository.php:174`, `Nova/Repositories/NovaUserRepository.php:228`, `Nova/Repositories/NovaUserRepository.php:345`; `RedmineMantencion/Services/MantencionRedmineSyncService.php:355`, `RedmineMantencion/Services/MantencionRedmineSyncService.php:447`; `RedmineMantencion/Repositories/MantencionReportRepository.php:170`, `RedmineMantencion/Repositories/MantencionReportRepository.php:269`.
- **Método/función/ruta:** `save`, `writeUsersToDatabase`, cambio de contraseña y guardado de mensajes Mantención.
- **Problema:** Guardar un usuario reescribe la colección leída, incluidos campos de otras cuentas. Los reportes Mantención siguen un patrón similar de snapshot completo en operaciones largas.
- **Evidencia:** La lectura de todos los usuarios ocurre antes de modificar una fila y recorrerlos para actualizar password/rol/estado e integraciones. La transacción posterior de escritura no valida que el snapshot siga vigente. El cambio propio de contraseña usa otra actualización puntual.
- **Riesgo real:** Pérdida de cambios concurrentes, revocaciones revertidas o restauración de un hash de contraseña anterior. En reportes, pérdida de edición/estado/número de ticket.
- **Escenario:** A inicia edición de usuario X; B cambia su contraseña en otra cuenta; A guarda X y reescribe también el hash antiguo de B. No requiere que A seleccione editar a B.
- **Solución recomendada:** Persistir solo entidad y campos modificados, con control de versión para conflictos. Aplicar transacciones a la unidad de negocio y bloqueo breve donde sea necesario. Conservar la política de identidad central sin convertir listados en comandos de escritura global.
- **Código sugerido:** Sustituir el guardado de la colección por un `UPDATE` del usuario identificado; comparar versión/fecha de modificación antes de sobrescribir campos sensibles.

### BACK-015 — El callback OnlyOffice verifica una firma, pero utiliza datos no vinculados a ella

- **Severidad:** MEDIO.
- **Estado:** CONFIRMADO EN CÓDIGO; aceptación de expiración reproducida en helper aislado.
- **Archivo:** `Procedimientos/Controllers/ProcedimientosController.php:63`, `Procedimientos/Controllers/ProcedimientosController.php:107`, `Procedimientos/Controllers/ProcedimientosController.php:173`; `Procedimientos/Services/OnlyOfficeJwt.php:16`.
- **Método/función/ruta:** `callback`, `OnlyOfficeJwt::decode`; POST `/procedimientos/callback/{token}`.
- **Problema:** Basta que el JWT decodifique con firma válida; su payload se descarta. `status` y `url` se obtienen del request sin compararlos con lo firmado. El helper no comprueba `exp`/`nbf` ni propósito.
- **Evidencia:** `decode(...) === null` es la única decisión sobre el contenido firmado. La configuración firmada del editor se entrega al cliente. Un JWT sintético con `exp=1` fue aceptado por el helper.
- **Riesgo real:** Un poseedor del token de editor y la capacidad temporal puede usar un JWT válido de otro propósito para disparar un guardado con campos distintos. No se demuestra escritura en cualquier documento: necesita la capacidad de ese documento y la URL remota pasa por host/puerto permitido.
- **Escenario:** Cliente de una sesión de edición reutiliza su token firmado para un callback manipulado que descarga otro recurso permitido del servidor OnlyOffice.
- **Solución recomendada:** Validar el formato firmado esperado del callback, documento/key, propósito y vigencia; usar datos autenticados o compararlos exactamente con el request. Separar credenciales de entrada/salida cuando la configuración de OnlyOffice lo permita; comprobar también destinos de redirección al descargar.
- **Código sugerido:** Retener el payload de `decode`, validarlo contra el contrato del callback y rechazar inconsistencias antes de cualquier GET/PUT; no asumir que cualquier JWT firmado por el mismo secreto representa ese mensaje.

### BACK-016 — Cambiar contraseña no invalida las otras sesiones

- **Severidad:** MEDIO.
- **Estado:** CONFIRMADO EN CÓDIGO.
- **Archivo:** `Nova/Controllers/NovaAuthController.php:94`; `Nova/Repositories/NovaUserRepository.php:228`; `app/Http/Middleware/EnsureNovaAuthenticated.php:14`; `app/Http/Kernel.php`.
- **Método/función/ruta:** `updatePassword`, cambio administrativo y validación de sesión.
- **Problema:** El cambio actualiza el hash y regenera la sesión actual, sin invalidar las demás ni comparar una versión de autenticación en cada request.
- **Evidencia:** El middleware comprueba cuenta/estado; no comprueba hash/versionado. La autenticación personalizada no incorpora el middleware de autenticación de sesión estándar en el flujo usado.
- **Riesgo real:** Una sesión previamente sustraída sigue siendo válida tras cambiar la contraseña.
- **Escenario:** Usuario cambia su contraseña para recuperar control; otro navegador con sesión vigente continúa operando hasta que expire o la cuenta sea bloqueada.
- **Solución recomendada:** Versionar las sesiones por usuario y revocar otras al cambiar/restablecer contraseña, preservando explícitamente la sesión propia si esa es la política.
- **Código sugerido:** Integrar una versión de autenticación con `nova.auth`; no llamar sin adaptación a helpers de Auth estándar que no controlan `nova_user`.

### BACK-017 — El flash de formularios conserva secretos externos en sesión

- **Severidad:** MEDIO.
- **Estado:** CONFIRMADO EN CÓDIGO.
- **Archivo:** `Nova/Controllers/UserIntegrationController.php:217`; `app/Modulos/Telegram/Controllers/TelegramController.php:82`; `app/Exceptions/Handler.php:31`; `config/session.php:49`; `vendor/laravel/framework/src/Illuminate/Http/RedirectResponse.php:75`.
- **Método/función/ruta:** Ramas de error con `withInput` al guardar integraciones y configuración Telegram.
- **Problema:** Se flashea la entrada completa, incluidos campos como `secret` o `bot_token`, que no son cubiertos por los nombres convencionales de contraseña excluidos globalmente.
- **Evidencia:** `withInput()` conserva parámetros escalares; el filtrado de archivos no elimina secretos. El flash explícito tampoco queda corregido solo por ampliar `$dontFlash`. El almacenamiento de sesión no se cifra por defecto en la configuración examinada.
- **Riesgo real:** Copias de secretos fuera de su repositorio cifrado habitual, accesibles a lectores del almacenamiento de sesión y sus respaldos.
- **Escenario:** Falla el guardado de una integración; el request queda en `_old_input`. No se afirma que la vista lo imprima: la exposición observada es persistencia adicional en sesión.
- **Solución recomendada:** Whitelist de campos no sensibles para flash y exclusión global de nombres de secretos usados por la aplicación. Revisar todas las ramas manuales `withInput`.
- **Código sugerido:**

```php
return back()->withInput($request->only(['integration', 'user']))
    ->with('error', 'No se pudo guardar la configuración.');
// Adaptar los nombres reales; nunca incluir secret, bot_token o contraseñas.
```

### BACK-018 — Respuestas con exit interrumpen sesión y middleware Laravel

- **Severidad:** MEDIO.
- **Estado:** CONFIRMADO EN CÓDIGO.
- **Archivo:** `RedmineMantencion/controllers/nc_browser.php:125`; `RedmineMantencion/controllers/dashboard.php:13`; `Procedimientos/Controllers/ProcedimientosController.php:33`; `app/Http/Middleware/TrackProjectActivity.php:21`; `vendor/laravel/framework/src/Illuminate/Session/Middleware/StartSession.php:120`.
- **Método/función/ruta:** `nc_browser_json`, `dashboard_json_response` y puente del navegador.
- **Problema:** Helpers emiten cabeceras/cuerpo y ejecutan `exit`, evitando el retorno normal del controlador a middleware.
- **Evidencia:** `StartSession` guarda sesión después de `$next`; tracking y cabeceras de seguridad también realizan trabajo al retornar. `exit` impide alcanzar esas instrucciones.
- **Riesgo real:** Actividad/flash no persistidos, expiración inesperada mientras se usa el navegador de archivos, ausencia de registros/cabeceras añadidos al final. No implica ausencia de todos los logs: algunos helpers escriben los suyos antes de salir.
- **Escenario:** Varias acciones AJAX solo recorren estos helpers; la actividad de sesión modificada por request no llega a guardarse normalmente.
- **Solución recomendada:** Retornar `JsonResponse`, `Response` o `StreamedResponse` hasta el controlador; encapsular compatibilidad legacy sin terminar el proceso.
- **Código sugerido:** Cambiar contratos a retorno de respuesta y propagarlos por los llamadores; agregar `session_write_close()` de forma dispersa no sustituye el ciclo Laravel completo.

### BACK-019 — La auditoría global elimina eventos antiguos al superar 500 registros

- **Severidad:** MEDIO.
- **Estado:** CONFIRMADO EN CÓDIGO.
- **Archivo:** `Nova/Repositories/NovaAuditRepository.php:31`; `app/Http/Middleware/TrackProjectActivity.php:48`, `app/Http/Middleware/TrackProjectActivity.php:73`.
- **Método/función/ruta:** `record`, `shouldTrack`; peticiones normales y AJAX clasificadas como NOVA.
- **Problema:** Cada inserción elimina lo que queda después de los 500 más recientes. Eventos de autenticación/seguridad comparten retención con movimientos HTTP rutinarios.
- **Evidencia:** Uso de `skip(500)` y borrado de IDs antiguos. GET JSON/AJAX también se registra; rutas sin prefijo específico caen en auditoría NOVA.
- **Riesgo real:** Pérdida rápida de evidencia investigativa por uso normal o generación deliberada de tráfico permitido.
- **Escenario:** Consultas reiteradas a un estado JSON autorizado desplazan entradas de seguridad sin necesitar permiso de borrar logs.
- **Solución recomendada:** Retención por tiempo/tipo y canal protegido; separar eventos rutinarios de seguridad, purgar por tarea controlada y registrar la política. Ajustar volumen con medición, no conservar arbitrariamente solo N filas.
- **Código sugerido:** Retirar el recorte de cada inserción en una futura corrección y trasladarlo a una política de retención explícita y verificable.

### BACK-020 — El log HTTP almacena capacidades temporales de documentos

- **Severidad:** MEDIO.
- **Estado:** CONFIRMADO EN CÓDIGO.
- **Archivo:** `routes/web.php:59`; `Procedimientos/Controllers/ProcedimientosController.php:63`, `Procedimientos/Controllers/ProcedimientosController.php:96`; `app/Http/Middleware/TrackProjectActivity.php:57`, `app/Http/Middleware/TrackProjectActivity.php:76`; `Nova/Repositories/NovaAuditRepository.php:21`.
- **Método/función/ruta:** Registro del POST callback y GET de documento por token.
- **Problema:** El path literal del callback incluye el token que autoriza la lectura del documento. Ese path se almacena como mensaje de auditoría.
- **Evidencia:** La capacidad aleatoria queda ocho horas en caché; `document` la usa sin sesión del lector. El middleware registra `POST /procedimientos/callback/<token>` con el path completo.
- **Riesgo real:** Un lector de logs puede convertir una entrada reciente en acceso al documento mientras la capacidad siga vigente.
- **Escenario:** Se guarda un documento en OnlyOffice; alguien con acceso al log obtiene el token del callback y utiliza la ruta de lectura. La firma JWT protege el callback de escritura, pero no elimina este riesgo de lectura.
- **Solución recomendada:** Registrar nombre/plantilla de ruta y correlación no reversible, sin capacidades. Revisar además access logs del servidor/proxy y acortar/revocar capacidades según el ciclo de edición.
- **Código sugerido:** Usar la URI plantilla de la ruta (`procedimientos/callback/{token}`), no `$request->path()`, para endpoints con segmentos secretos.

### BACK-021 — Resolver identidades dispara lecturas globales y consultas por usuario

- **Severidad:** MEDIO.
- **Estado:** CONFIRMADO EN CÓDIGO; impacto de carga no medido en producción.
- **Archivo:** `Nova/Repositories/NovaUserRepository.php:55`, `Nova/Repositories/NovaUserRepository.php:287`, `Nova/Repositories/NovaUserRepository.php:417`; `Nova/Repositories/UserIntegrationRepository.php:91`; `RedmineTic/Repositories/RedmineUserRepository.php:49`, `RedmineTic/Repositories/RedmineUserRepository.php:656`.
- **Método/función/ruta:** `find`, carga de usuarios/integraciones, `userForSession`, `projectUsers`, resolución de secreto.
- **Problema:** Una búsqueda individual recorre usuarios cargados globalmente, incluyendo integraciones; otros helpers repiten la carga. TIC resuelve credenciales dentro del bucle de usuarios.
- **Evidencia:** `find` llama al listado completo; ese listado carga usuarios e integraciones. `userForSession` y su índice vuelven a consultar. La proyección TIC llama al resolver de credenciales por usuario.
- **Riesgo real:** Costo creciente por request, mayor memoria y carga DB, incluido el middleware de sesión frecuente.
- **Escenario:** El directorio crece y abrir un módulo o resolver una cuenta sigue leyendo el universo de usuarios/integraciones.
- **Solución recomendada:** Consultas puntuales por identidad canónica e índices existentes; proyección de listado sin secretos, resolución de credenciales solo cuando se usan y cargas por lote. Memoización limitada al request con invalidación al escribir.
- **Código sugerido:** Exponer un método de búsqueda DB directa y otro de listado; no construir un mapa de todos los usuarios para buscar un único UUID.

### BACK-022 — Listados materializan reportes y logs antes de paginar

- **Severidad:** MEDIO.
- **Estado:** CONFIRMADO EN CÓDIGO; no se realizaron benchmarks.
- **Archivo:** `RedmineTic/Repositories/RedmineDataRepository.php:160`, `RedmineTic/Repositories/RedmineDataRepository.php:244`, `RedmineTic/Repositories/RedmineDataRepository.php:1076`; `RedmineTic/Repositories/RedmineActivityRepository.php:45`; `RedmineMantencion/Controllers/HistoricoController.php`.
- **Método/función/ruta:** Listados de dashboard/histórico/actividad TIC e histórico Mantención.
- **Problema:** Se cargan colecciones completas, se filtran en PHP y luego se obtiene la página visible. La paginación de interfaz no limita necesariamente la consulta.
- **Evidencia:** `activeReports`/`archivedReports` alimentan filtros de arrays; actividad ejecuta `get()` antes de map/filter/slice. Mantención también prepara el conjunto antes de seleccionar la página.
- **Riesgo real:** Tiempo/memoria proporcional al histórico aunque el usuario pida pocas filas; agravamiento de BACK-021 en pantallas combinadas.
- **Escenario:** Miles de reportes archivados o eventos hacen costosa una consulta de una sola página.
- **Solución recomendada:** Aplicar módulo, alcance, fechas, filtros y paginación en SQL; calcular agregados mediante consultas específicas. Revisar índices con EXPLAIN en un entorno autorizado.
- **Código sugerido:** Construir un query autorizado reutilizable y terminar en `paginate`/cursor; no recuperar todos los registros para luego aplicar `array_slice`.

### BACK-023 — Los lotes síncronos no tienen un presupuesto total de espera

- **Severidad:** MEDIO.
- **Estado:** CONFIRMADO EN CÓDIGO; duración real depende de tamaño y servicios.
- **Archivo:** `RedmineTic/Repositories/RedmineDataRepository.php:1906`; `RedmineMantencion/Services/MantencionRedmineSyncService.php:355`; `RedmineMantencion/Services/MantencionCoreImportService.php:334`; `app/Modulos/MonitorServidores/Services/ServerMonitorService.php:50`.
- **Método/función/ruta:** Envío de reportes, importación CORE y `checkAllActive` desde web.
- **Problema:** Existen timeouts por llamada, pero la solicitud puede encadenar muchas llamadas lentas sin límite total o ejecución diferida.
- **Evidencia:** Los servicios recorren elementos secuencialmente. TIC limita conexión/solicitud de envío y prueba disponibilidad; Mantención también verifica disponibilidad. Eso no acota la suma cuando las respuestas tardan pero siguen siendo válidas.
- **Riesgo real:** Workers web ocupados, expiración del proxy/navegador y resultado parcial difícil de interpretar.
- **Escenario:** Un lote de N tickets tarda aproximadamente la suma de N respuestas remotas; un servicio que responde lento puede superar el tiempo web aunque nunca agote su timeout individual.
- **Solución recomendada:** Limitar lote, presupuesto total y concurrencia; mover lotes grandes a ejecución durable con progreso y resultados por elemento. Coordinar con BACK-013 para evitar reintentos duplicados.
- **Código sugerido:** Usar un deadline del caso de uso y dejar pendientes los elementos no iniciados, o encolar operaciones idempotentes. No aumentar indefinidamente el timeout HTTP.

**Comprobación adicional de históricos solicitada el 11 de septiembre.** La sincronización automática del estado asociado al Redmine ID en ambos históricos tampoco comprueba disponibilidad general antes de iniciar. `RedmineTic/Repositories/RedmineDataRepository.php:1166` y `RedmineMantencion/Controllers/HistoricoController.php:230` recorren IDs secuencialmente y continúan aunque una consulta resulte no disponible. Los clientes fijan conexión máxima de 4 segundos y solicitud total de 5 segundos por ticket (`RedmineTic/Services/RedmineIssueStatusService.php:84`, `RedmineTic/Services/RedmineIssueStatusService.php:166`, `RedmineMantencion/Services/RedmineIssueStatusService.php:177`, `RedmineMantencion/Services/RedmineIssueStatusService.php:260`). La interfaz envía grupos de cinco y sigue con los demás sin abortar por caída general; sus `fetch` no incluyen señal de cancelación/timeout (`RedmineTic/views/native-sections/history.blade.php:892`, `resources/views/redmine-mantencion/historico.blade.php:671`). Así, cinco consultas que agoten su timeout pueden acumular unos 25 segundos de espera remota por grupo, más trabajo local. No es una espera infinita por ticket, pero falta un límite global y un corte temprano de la sincronización. El botón de sincronización completa TIC usa otro camino: `synchronizeAllIssueStatuses` → `fetchRedmineIssues` → `getRedmineJson`, con 20 segundos por página y retorno al primer error, sin preflight ni presupuesto global (`RedmineTic/Repositories/RedmineDataRepository.php:1207`, `RedmineTic/Repositories/RedmineDataRepository.php:2663`, `RedmineTic/Repositories/RedmineDataRepository.php:2743`). Estas conclusiones son de código; no se contactó Redmine. Se incorporan a BACK-023, sin añadir otro hallazgo.

### BACK-024 — Transferencias remotas cargan archivos completos en memoria

- **Severidad:** MEDIO.
- **Estado:** CONFIRMADO EN CÓDIGO.
- **Archivo:** `RedmineMantencion/controllers/nc_browser.php:284`, `RedmineMantencion/controllers/nc_browser.php:414`; `RedmineMantencion/ExternalClients/NextcloudWebdavClient.php:70`; `Procedimientos/Controllers/ProcedimientosController.php:96`, `Procedimientos/Controllers/ProcedimientosController.php:123`.
- **Método/función/ruta:** Download/upload WebDAV y documento/callback OnlyOffice.
- **Problema:** Respuestas y cargas usan strings completos (`body`, `file_get_contents`, `Http::body`) y a veces copias adicionales, sin un límite de bytes de descarga impuesto por la aplicación.
- **Evidencia:** El navegador obtiene primero todo el cuerpo, calcula longitud y lo emite. El callback descarga completo antes del PUT; el cliente cURL utiliza retorno del cuerpo en memoria.
- **Riesgo real:** Agotamiento de memoria/worker ante un documento grande o respuesta remota inesperada.
- **Escenario:** Usuario autorizado abre un archivo Nextcloud mayor que la memoria disponible. Los límites PHP de upload entrante no limitan necesariamente ese download remoto.
- **Solución recomendada:** Streaming con límite de bytes, control de tamaño incluso sin `Content-Length`, cuotas y respuestas de error claras. Para uploads, validar archivo presente, tamaño y errores PHP antes de transferir.
- **Código sugerido:** Usar streams temporales acotados o respuestas transmitidas, sin convertir el archivo entero a string; mantener control de permisos y desconexión durante la transferencia.

### BACK-025 — Las contraseñas administrativas no cumplen la política del cambio propio

- **Severidad:** MEDIO.
- **Estado:** CONFIRMADO EN CÓDIGO; helper probado con contraseña sintética de un carácter.
- **Archivo:** `Nova/Controllers/NovaAdministrationController.php:320`; `Nova/Repositories/NovaUserRepository.php:146`, `Nova/Repositories/NovaUserRepository.php:206`; `Nova/Services/NovaUserService.php:189`.
- **Método/función/ruta:** Crear/guardar/restablecer usuario desde administración y helpers de contraseña.
- **Problema:** Los caminos administrativos comprueban presencia/confirmación, pero no la misma longitud mínima ni el límite de bytes para bcrypt que el cambio propio.
- **Evidencia:** La rama llama directamente a `hashPassword` después de confirmar igualdad. El helper admite un carácter. La revisión del controlador no identificó una validación previa equivalente.
- **Riesgo real:** Creación de cuentas con contraseñas triviales e inconsistencia ante entradas que superan el límite de bcrypt.
- **Escenario:** Administrador establece una contraseña de un carácter que el cambio propio rechazaría. No se afirma que un usuario sin permisos pueda restablecer contraseñas ajenas por esta vía.
- **Solución recomendada:** Una política de contraseña compartida por todos los casos de uso, con confirmación, mínimo y máximo de bytes compatibles con el algoritmo. Diferenciar validación de nuevas contraseñas de compatibilidad de login histórico.
- **Código sugerido:** Validar antes de llamar a `hashPassword` tanto en alta como en restablecimiento; probar límites con caracteres multibyte.

### BACK-026 — Deshabilitar un módulo no bloquea uniformemente sus rutas nativas

- **Severidad:** MEDIO.
- **Estado:** CONFIRMADO EN CÓDIGO; no se cambió estado de módulos reales.
- **Archivo:** `Nova/Repositories/ModuleRegistry.php:17`, `Nova/Repositories/ModuleRegistry.php:62`; `Nova/Services/ProjectAccessGuard.php:28`; `Nova/Repositories/NovaAccessRepository.php:153`; `Nova/Controllers/LegacyProjectController.php:313`.
- **Método/función/ruta:** Estado de módulos, `enabled`, `canAccess`, `abortIfDisabled` y rutas nativas.
- **Problema:** El estado habilitado filtra navegación y es comprobado por el puente, pero no forma parte uniforme de la autorización de controladores nativos.
- **Evidencia:** `enabled()` filtra módulos para la portada; `canAccess`/resolución de proyecto no consultan ese estado. La guarda explícita de deshabilitación está en el controlador legacy, que no ejecutan todas las rutas nativas.
- **Riesgo real:** Desactivar un módulo puede ocultarlo sin detener consultas/operaciones ya autorizadas por rol o enlace directo.
- **Escenario:** Usuario conserva una URL o pestaña nativa después de que administración deshabilita el módulo y continúa accediendo con sus permisos.
- **Solución recomendada:** Middleware/política común para disponibilidad del módulo en toda entrada nativa/CLI aplicable; definir excepciones administrativas explícitas y distinguir deshabilitado de mantenimiento.
- **Código sugerido:** Resolver la clave del módulo desde la ruta y consultar su estado antes del controlador, conservando los casos deliberadamente públicos como assets.

### BACK-027 — Fechas y horas inválidas se convierten silenciosamente en valores distintos

- **Severidad:** MEDIO.
- **Estado:** CONFIRMADO EN CÓDIGO; parsers reales probados en memoria.
- **Archivo:** `Nova/Controllers/HoursExtraController.php:64`; `RedmineTic/Repositories/RedmineHoursExtraRepository.php:23`, `RedmineTic/Repositories/RedmineHoursExtraRepository.php:166`, `RedmineTic/Repositories/RedmineHoursExtraRepository.php:179`; `RedmineTic/Controllers/RedmineDashboardController.php:665`.
- **Método/función/ruta:** Guardado de grupo, `parseDate`, `parseTime` y formularios de horas extra.
- **Problema:** La validación central solo exige fecha no vacía; los parsers aceptan overflow o acotan componentes en vez de rechazar entradas inválidas.
- **Evidencia:** `parseTime('99:99')` devolvió `23:59:00`; `parseDate('2026-02-31')` devolvió `2026-03-03` en comprobación aislada.
- **Riesgo real:** Guardado en un día/hora distintos de los introducidos, con efectos sobre registro y cálculo de horas extra.
- **Escenario:** Error de digitación o POST manipulado altera un grupo válido al normalizarse a otra fecha, en vez de devolver validación fallida.
- **Solución recomendada:** Parseo estricto con comparación de formato y errores, límites de hora/minuto, reglas explícitas para cruces de medianoche y rechazo 422 sin escritura.
- **Código sugerido:** Validar `Y-m-d` y `H:i` y comprobar round-trip exacto; no usar el resultado normalizado como evidencia de que la entrada era válida.

### BACK-028 — Algunas respuestas declaran éxito sin confirmar persistencia

- **Severidad:** MEDIO.
- **Estado:** CONFIRMADO EN CÓDIGO; no se provocaron fallos DB reales.
- **Archivo:** `RedmineTic/Controllers/RedmineDashboardController.php:242`, `RedmineTic/Controllers/RedmineDashboardController.php:665`; `RedmineTic/Repositories/RedmineUserRepository.php:359`; `RedmineTic/Repositories/RedminePermissionRepository.php:298`; `RedmineMantencion/Services/MantencionRedmineSyncService.php:447`.
- **Método/función/ruta:** Acciones AJAX dashboard, horas, permisos y guardado posterior a envío Mantención.
- **Problema:** Hay resultados booleanos ignorados, éxito basado en reconocer la acción y capturas de persistencia que continúan sin informar el fallo al llamador.
- **Evidencia:** `hoursAction` no utiliza el resultado del guardado antes de anunciar éxito; AJAX usa `$recognizedAction` para `ok` salvo una excepción. `saveUserPermissions` retorna true tras un método void; el repositorio de permisos captura errores por fila y continúa. Envío Mantención no comprueba el booleano final de `save_messages`.
- **Riesgo real:** Usuario/administrador cree que datos o permisos quedaron guardados; cambios parciales y tickets remotos sin correspondencia local fiable.
- **Escenario:** Error DB en una fila de permisos o al guardar un resultado remoto produce respuesta positiva o estado parcial, que puede inducir un reintento.
- **Solución recomendada:** Contratos de resultado explícitos, propagación de errores, transacciones para grupos de permisos y separación entre aceptación remota y persistencia local. Preservar información de conciliación y no reintentar automáticamente un ticket incierto.
- **Código sugerido:** Responder a partir del resultado del caso de uso, no del nombre de acción reconocido; distinguir error de validación, conflicto y fallo de persistencia.

### BACK-029 — El guard estándar conserva un modelo inexistente e incompatible

- **Severidad:** BAJO.
- **Estado:** CONFIRMADO EN CÓDIGO; comprobación de clases en memoria.
- **Archivo:** `config/auth.php:74`; `Nova/Models/NovaUser.php`; `routes/api.php:17`.
- **Método/función/ruta:** Provider `users`, guard estándar/Sanctum y GET `/api/user`.
- **Problema:** La configuración apunta a `App\Models\NovaUser`, que no existe tras la organización modular. El modelo real extiende Eloquent Model y no implementa el contrato Authenticatable; el login NOVA usa sesión propia.
- **Evidencia:** `class_exists` del FQN configurado devolvió falso; el modelo real no implementa Authenticatable. La ruta API residual usa `auth:sanctum`.
- **Riesgo real:** Integraciones que intenten utilizar el guard estándar fallan o no reconocen la sesión NOVA.
- **Escenario:** Se habilita un consumidor de `/api/user` esperando compatibilidad automática con el login web. No se afirma que el login actual `nova.auth` esté roto ni que la ruta filtre datos anónimamente.
- **Solución recomendada:** Retirar la ruta/configuración si no se utiliza, o implementar deliberadamente un puente de autenticación y modelo/contratos compatibles, con pruebas.
- **Código sugerido:** Cambiar solamente el namespace no es una solución completa: también deben resolverse contrato, guard, tokens y relación con la sesión personalizada.

### BACK-030 — Datos del backend atraviesan contextos JavaScript/HTML sin escape adecuado

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN CÓDIGO; coincide con cadenas documentadas en FRONT-001/FRONT-002 y se revisó su origen backend.
- **Archivo:** `RedmineMantencion/Controllers/HistoricoController.php:88`; `resources/views/redmine-mantencion/historico.blade.php:532`; `Procedimientos/Controllers/ProcedimientosController.php:85`; `resources/views/procedimientos/editor.blade.php:21`; `resources/views/redmine-mantencion/configuracion.blade.php:1309`, `resources/views/redmine-mantencion/configuracion.blade.php:1340`; `Nova/Repositories/NovaUserRepository.php:105`.
- **Método/función/ruta:** Filtro de estado del histórico, configuración del editor y selector de usuarios Mantención.
- **Problema:** Valores controlables se serializan en scripts con `JSON_UNESCAPED_SLASHES` sin escape de etiquetas; nombres de usuarios se insertan posteriormente mediante `innerHTML`.
- **Evidencia:** El filtro GET llega a `json_encode` dentro de `<script>`; el nombre de la configuración OnlyOffice usa `@json` con flags que reemplazan el escape seguro por defecto. El selector interpola `user.label` como HTML. El backend conserva nombres como texto sin transformar su semántica, lo cual exige escape contextual al usarlos.
- **Riesgo real:** XSS reflejado o almacenado que ejecuta acciones con la sesión del lector. No se requiere que la cookie sea legible por JavaScript para realizar requests del mismo origen.
- **Escenario:** Víctima autenticada abre un enlace con un filtro que termina el bloque script; o un nombre persistido con contenido HTML alcanza un selector administrativo. La escritura de nombres requiere su permiso correspondiente; no se afirma que cualquier usuario pueda renombrar a otros.
- **Solución recomendada:** Serialización segura para HTML mediante `Js::from`/flags adecuados y uso de nodos/textContent para texto visible. No intentar resolver todos los contextos prohibiendo caracteres legítimos en nombres.
- **Código sugerido:**

```blade
const config = {{ Illuminate\Support\Js::from($editorConfig) }};
```

```javascript
const label = document.createElement('span');
label.textContent = user.label;
btn.append(label);
```

### BACK-031 — La extensión de sesión funciona como login alternativo sin CSRF

- **Severidad:** MEDIO.
- **Estado:** CONFIRMADO EN CÓDIGO; no se ejecutó navegación de víctima real.
- **Archivo:** `routes/web.php:54`; `Nova/Controllers/NovaAuthController.php:121`.
- **Método/función/ruta:** `extendSession`; POST `/session/extend`.
- **Problema:** La ruta está fuera de `nova.auth`, excluye CSRF y, cuando no hay usuario en sesión, acepta identidad del request y autentica credenciales.
- **Evidencia:** El método usa datos enviados como alternativa a `nova_user`, llama al intento de autenticación y establece una sesión. El límite de cinco intentos no verifica el origen del login.
- **Riesgo real:** Login CSRF: una víctima puede terminar autenticada en una cuenta conocida por el atacante y registrar allí información pensando que usa la propia.
- **Escenario:** Un formulario externo envía credenciales válidas de la cuenta del atacante al endpoint cuando no existe sesión NOVA utilizable. No es un bypass de contraseña; usa la del atacante. SameSite no impide necesariamente establecer una nueva sesión después de ese POST.
- **Solución recomendada:** Toda autenticación anónima debe usar el flujo normal protegido por CSRF. Para extender una sesión expirada, diseñar un desafío ligado al navegador/sesión que no convierta el endpoint en un login arbitrario sin protección.
- **Código sugerido:** Requerir identidad de sesión/challenge validado y rechazar la variante anónima; conservar contraseña, regeneración y throttling.

### BACK-032 — La autorización TIC acepta coincidencias de nombre pese a un ID ajeno

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN CÓDIGO; filtro real reproducido en memoria.
- **Archivo:** `RedmineTic/Repositories/RedmineDataRepository.php:2919`; `RedmineTic/Support/TextSupport.php:91`.
- **Método/función/ruta:** `filterReportsByUserScope` y `nameTokensMatch`; consultas y acciones que reutilizan el alcance de reportes TIC.
- **Problema:** Tras no coincidir el ID asignado, el filtro intenta coincidencias de nombre aunque el reporte tenga un ID válido distinto. El nombre aproximado participa en autorización.
- **Evidencia:** La prueba con actor ID 42, nombre sintético Ana Pérez, y reporte asignado a ID 99 con el mismo nombre devolvió acceso permitido. El matcher admite coincidencias de tokens, no identidad única.
- **Riesgo real:** Acceso horizontal a reportes de homónimos, incluyendo acciones que se basan en el conjunto filtrado.
- **Escenario:** Dos cuentas comparten nombre/apellidos frecuentes. Una recibe visibilidad de los registros de la otra aun cuando la relación por ID los distingue.
- **Solución recomendada:** Usar el ID como autoridad. Si existe ID ajeno, denegar sin fallback. Reservar matching de nombres para conciliación/importación supervisada, no para autorización runtime.
- **Código sugerido:**

```php
if ($assignedId !== '') {
    return $assignedId === $actorRedmineId;
}
return false; // Resolver asignaciones históricas mediante una migración controlada.
```

### BACK-033 — Gestionar el estado de usuarios TIC modifica el estado global NOVA

- **Severidad:** ALTO.
- **Estado:** CONFIRMADO EN CÓDIGO; no se bloquearon ni reactivaron cuentas.
- **Archivo:** `RedmineTic/Controllers/RedmineDashboardController.php:252`, `RedmineTic/Controllers/RedmineDashboardController.php:302`; `RedmineTic/Repositories/RedmineUserRepository.php:304`, `RedmineTic/Repositories/RedmineUserRepository.php:344`; `Nova/Controllers/NovaAdministrationController.php:365`.
- **Método/función/ruta:** `userAction`, `toggleUserStatus`; POST `/redmine_tic/app/usuarios` con `toggle_status`.
- **Problema:** Un permiso modular de edición de usuarios llega a actualizar `usuarios_nova.estado`, además del estado del perfil TIC, sin exigir la autorización global del caso administrativo equivalente.
- **Evidencia:** El controlador exige `usuarios` y `usuarios_editar`. El repositorio localiza el UUID central del usuario del proyecto y actualiza ambas tablas. No añade una guarda de administrador/root central antes de la segunda escritura.
- **Riesgo real:** Un gestor de usuarios TIC puede bloquear el acceso global de una cuenta o reactivarla fuera del ámbito de su módulo.
- **Escenario:** Actor con administración modular, sin administración global, cambia el estado de un usuario listado en TIC. Ese usuario pierde acceso a otros módulos por el estado central. El objetivo debe resolverse como usuario del proyecto; no se afirma acceso arbitrario a cualquier UUID desconocido.
- **Solución recomendada:** El cambio operativo TIC debe afectar solo su perfil. Reservar bloqueo/reactivación global a un caso de uso central con autorización explícita y auditoría diferenciada.
- **Código sugerido:** Separar operaciones `cambiarEstadoEnModulo` y `cambiarEstadoGlobal`, con políticas independientes y una prueba que garantice que la primera no actualiza `usuarios_nova.estado`.

## 14. Mejoras recomendadas

Estas recomendaciones son propuestas; no fueron implementadas.

1. **Unificar la autorización del caso de uso.** Resolver cuenta vigente, acceso, permiso de acción y recurso por ID antes de escribir o llamar integraciones. Compartirla entre web, consola y bot; negar explícitamente estados bloqueados.
2. **Separar identidad global de administración modular.** Reservar rol root, permiso total, bloqueo central y revocación de sesiones a las políticas centrales. Eliminar escrituras globales incidentales desde permisos de módulo.
3. **Hacer las escrituras puntuales y detectar conflictos.** Reservar envíos, persistir resultados por reporte, controlar versiones de usuarios/reportes y definir conciliación de tickets con resultado remoto incierto.
4. **Cerrar las salidas de secretos/contenido activo.** Retirar y rotar material sensible bajo autorización, limitar flash/logs, corregir serialización contextual y aislar documentos activos. La rotación de la clave de aplicación requiere un plan para datos ya cifrados.
5. **Uniformar contratos HTTP y validación.** CSRF por endpoint real, Form Requests o validadores equivalentes por acción, fechas estrictas y resultados que distingan validación, permiso, conflicto y fallo externo.
6. **Acotar trabajo por request.** Queries filtradas/paginadas, cargas por lote, transferencias con streams y límite de bytes, lotes con deadline o ejecución durable.
7. **Añadir pruebas negativas específicas.** Usuario sin permiso, scope propio con ID ajeno, homónimos, perfil bloqueado, rol revocado, login CSRF, grupo Telegram, callback no vinculado, fallos de persistencia y concurrencia. Ejecutarlas con datos sintéticos y servicios externos simulados.

## 15. Mejoras opcionales

No se cuentan como vulnerabilidades adicionales ni son requisitos para corregir todos los hallazgos:

- Descomponer gradualmente el controlador TIC y los servicios extensos por casos de uso después de fijar contratos y cobertura; evitar una reescritura general sin evidencia de beneficio.
- Documentar la matriz de permisos y la semántica de edición colectiva de horas extra en una fuente mantenida junto a las políticas.
- Añadir inventario/SBOM y comprobación automatizada de dependencias con avisos actuales; separar riesgo del paquete de alcanzabilidad en NOVA.
- Incorporar métricas de duración, consultas, tamaño de lote y resultados inciertos por integración, con identificadores de correlación libres de secretos.
- Reducir `base_path` en health a una señal funcional cuando el consumidor no necesite rutas locales.
- Revisar CSP y origen separado de documentos como defensa adicional, sin sustituir escape contextual ni autorización.
- Revisar inventario de rutas/aliases y documentación histórica para reducir ambigüedad entre implementación legacy y nativa, sin borrar consumidores activos.

## 16. Plan de corrección

Orden por severidad y, dentro de ella, seguridad e integridad antes de disponibilidad/mantenibilidad. El orden no autoriza aplicar cambios ni rotaciones en esta etapa. Las comprobaciones pendientes deben realizarse en un entorno autorizado con datos sintéticos o copia saneada.

| Orden | Severidad | Hallazgos | Trabajo propuesto | Criterio de aceptación |
| --- | --- | --- | --- | --- |
| 1 | CRÍTICO | Ninguno confirmado | No hay corrección crítica sustentada actualmente. Escalar si se confirma exposición pública/alcance superior de BACK-011. | Evidencia adicional documentada antes de reclasificar. |
| 2 | ALTO | BACK-011 | Contener distribución de secretos/dumps, evaluar exposición, planificar y ejecutar rotación/saneamiento autorizados. | Copias distribuibles sin secretos; credenciales sustituidas; datos cifrados siguen recuperables; DocumentRoot/aliases comprobados. |
| 3 | ALTO | BACK-001, BACK-002, BACK-033 | Cerrar autorización por panel y escalamiento modular a privilegios/estado central. | Matriz negativa de acciones/campos/roles: 403 sin escrituras ni llamadas; cambio modular no toca estado global. |
| 4 | ALTO | BACK-003, BACK-004 | Aplicar rol vigente y denegación de perfil bloqueado. | Sesión degradada pierde privilegios al siguiente request; perfil bloqueado no activa fallback. |
| 5 | ALTO | BACK-005, BACK-032, BACK-006 | Restringir recursos por ID y exigir permiso de edición en entrada consolidada; definir política colectiva. | IDs ajenos/homónimos rechazados; rutas central y nativas autorizan igual; edición colectiva requiere política explícita. |
| 6 | ALTO | BACK-014, BACK-013 | Escrituras puntuales/versionadas y reserva de envíos con conciliación. | Dos escrituras concurrentes no restauran hashes; dos envíos crean un solo ticket; resultado incierto queda identificable sin reenvío ciego. |
| 7 | ALTO | BACK-007, BACK-030, BACK-010 | Restaurar CSRF nativo, escape contextual y aislamiento de archivos activos. | POST sin token rechazado; datos sintéticos no alteran scripts/DOM; documento activo no ejecuta código con origen NOVA. |
| 8 | ALTO | BACK-012, BACK-008 | Validar TLS CORE y aplicar políticas a Telegram/CLI. | Certificado inválido rechazado; acceso revocado no permite comandos ni creación de reportes. |
| 9 | ALTO | BACK-009 | Verificar bindings/política de grupos y aislar identidad de remitente. | Comandos personales solo privados o remitente individual verificado; escenario grupal documentado como corregido o no aplicable. |
| 10 | MEDIO | BACK-015, BACK-020 | Vincular callback firmado al documento/mensaje y retirar capacidades de logs. | JWT de otro propósito/payload distinto rechazado; logs nuevos no contienen tokens utilizables. |
| 11 | MEDIO | BACK-016, BACK-031, BACK-025, BACK-017 | Revocación de sesiones, login CSRF, política común y flash sin secretos. | Otras sesiones invalidadas; login alternativo forjado rechazado; límites idénticos; sesión sin secretos en `_old_input`. |
| 12 | MEDIO | BACK-026, BACK-019 | Disponibilidad modular central y conservación de auditoría. | URL nativa respeta módulo deshabilitado; tráfico rutinario no borra eventos de seguridad fuera de política. |
| 13 | MEDIO | BACK-027, BACK-028 | Validación estricta y resultados de persistencia verificables. | Fecha/hora inválida devuelve validación sin cambios; fallo parcial no produce éxito y permite diagnóstico. |
| 14 | MEDIO | BACK-018 | Retorno de respuestas por el ciclo Laravel. | Sesión, cabeceras y tracking comprobados en respuestas AJAX/descarga sin `exit`. |
| 15 | MEDIO | BACK-021, BACK-022, BACK-023, BACK-024 | Consultas acotadas, paginación SQL, deadlines y streaming. | Costos medidos con fixtures grandes; memoria/tiempo total limitados; comportamiento parcial definido. |
| 16 | BAJO | BACK-029 | Resolver contrato del guard/API residual o retirarlo si está sin uso. | API deliberadamente soportada con pruebas o ruta/configuración residual eliminada tras verificar consumidores. |

**Verificación de entrega.** El informe contiene los 33 IDs y las 16 secciones solicitadas. Cada hallazgo registra severidad, estado, archivo, entrada/método, evidencia, riesgo, escenario y solución; los fragmentos son propuestas no aplicadas. Se comprobó existencia del informe, referencias de archivo/línea y conservación del código fuente y del informe frontend previo mediante comparación de hashes. No se corrigió código, no se modificó otro archivo y no se realizaron operaciones sobre datos o integraciones reales.
