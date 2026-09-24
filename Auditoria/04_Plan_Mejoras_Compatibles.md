# Plan de implementación de mejoras compatibles con la lógica actual

Fecha: 11 de septiembre de 2026.

Fuente principal: [Auditoría de Base de Datos](03_Auditoria_Base_Datos.md). Complemento puntual: [Auditoría Backend](02_Auditoria_Backend.md), especialmente BACK-023 sobre esperas al sincronizar históricos.

## 1. Objetivo y alcance

Implementar primero las mejoras de seguridad, integridad y rendimiento que permitan a NOVA seguir realizando las mismas operaciones, con los mismos datos, permisos, formularios y reglas de negocio. Cada entrega debe ser pequeña, verificable y reversible de forma independiente.

Este documento es **un plan**, no una ejecución de las correcciones. No se modifican código, credenciales, datos ni esquema al prepararlo. Las auditorías se conservan como evidencia del estado revisado.

“Mantener el funcionamiento” significa conservar los resultados de las operaciones válidas y sus contratos. Los casos defectuosos de fallo o concurrencia —guardar parcialmente, pisar otra contraseña o enviar dos veces el mismo reporte— deben corregirse con un resultado definido. No se puede garantizar riesgo cero: la compatibilidad se demuestra mediante pruebas de comportamiento y comparación de resultados, antes de desplegar.

**Primera implementación recomendada:** preparar una base de comparación, proteger conexiones y secretos, sustituir escrituras globales por puntuales y hacer atómico el guardado de permisos. Continuar con protección de envíos y mejoras de consulta. La conciliación de históricos y las nuevas restricciones de negocio quedan fuera de esa primera entrega.

## 2. Reglas que deben mantenerse

| Área | Invariante de compatibilidad | Comprobación |
| --- | --- | --- |
| Identidad | `usuarios_nova` sigue siendo la fuente central. Se conservan UUID, usuario, RUT, ID Redmine y las identidades de login actualmente admitidas. | Mismas cuentas resueltas para cada entrada válida; mismos permisos centrales y modulares. |
| Contraseñas e integraciones | Cambiar una cuenta no debe modificar contraseñas, credenciales o datos de otras. Se conserva el cambio propio de contraseña y la propiedad personal de secretos. | Dos usuarios, cambios concurrentes y lectura posterior de ambas fichas. |
| Módulos | TIC y Mantención conservan reportes, catálogos, permisos y flujos independientes. | Fixtures con IDs numéricos coincidentes en ambos módulos. |
| Rutas y formularios | Se mantienen URLs, nombres de campos, formatos de respuestas y navegación habitual. | Misma operación desde dashboard, manual, histórico y aliases activos. |
| Hora extra | Al activarla, el flujo actual propone `1`; al desactivarla, limpia tiempo estimado. Los normalizadores actuales conservan una cantidad explícita cuando corresponde. | Pruebas de toggle y de guardado con valor vacío, `1` y una cantidad explícita válida. |
| Horas extra compartidas | Se conserva la jornada por usuario/fecha, el origen de cada reporte y el comportamiento actual de edición por fecha. | Un grupo con reportes TIC y Mantención mantiene sus vínculos y el alcance de la operación actual. |
| Horarios y fechas | Se mantienen formatos admitidos, tratamiento de vacíos, cruce de medianoche y cálculo actual de minutos. | Comparación de entradas/salidas de los parsers y casos de jornada nocturna. |
| Redmine | Mismos proyecto, asignado, categorías, estado, prioridad, descripción y tiempo enviados para un reporte válido. | Comparación del payload con un cliente externo simulado. |
| CORE, Nextcloud, EMACH y Telegram | Se conservan identidad externa, uso de credenciales propias y operaciones existentes. | Pruebas por consumidor al cambiar conexión y persistencia. |
| Histórico/listados | Mismos registros autorizados, filtros, orden, totales y páginas. Los detalles siguen completos aunque la consulta de listado sea más pequeña. | Comparación antes/después con datos sintéticos y casos límite de paginación. |

**Precisión importante sobre hora extra:** no convertir este trabajo en una regla nueva de “siempre exactamente una hora”. `MantencionHoursExtraFields::normalize` y las pruebas TIC conservan cantidades explícitas como `2.5` o `3.5`; los toggles sí establecen `1` al activar. Se debe preservar cada comportamiento de su flujo, no uniformarlos por inferencia.

**Límite de seguridad:** esta compatibilidad no equivale a declarar correctos todos los permisos actuales. Las vulnerabilidades de autorización de BACK-001/002/005/032/033 siguen abiertas y requieren un trabajo específico. Este plan se centra en los hallazgos DB; no debe cerrar vulnerabilidades backend por conservar sus pantallas o contratos.

## 3. Orden de implementación

| Orden | Lote | Prioridad | Resultado esperado | Dependencias |
| --- | --- | --- | --- | --- |
| 0 | P00 — Referencia y recuperación | Prerrequisito | Pruebas, datos de comparación y entorno aislado disponibles. | Ninguna. |
| 1 | P01 — Cuenta DB, TLS y secretos | Muy alta | Menor alcance de una fuga sin alterar funciones de NOVA. | P00 e inventario de todos los consumidores. |
| 2 | P02 — Escrituras puntuales | Muy alta | Editar/enviar un registro no sobrescribe otros. | P00; no requiere esperar a cambios funcionales de P01. |
| 3 | P03 — Permisos y configuración atómicos | Muy alta | Guardado completo o rollback, sin estados parciales ocultos. | P00; límites de escritura definidos en P02 cuando se compartan métodos. |
| 4 | P04 — Proteger envíos y acotar históricos | Alta | Un envío simultáneo no se duplica; una plataforma caída no prolonga toda la sincronización. | P02 y contrato explícito de resultado incierto. |
| 5 | P05 — Integridad de horas extra sin reinterpretar datos | Alta | Fallos intermedios no pierden vínculos ni afectan otro módulo. | P00/P02; pruebas de comportamiento actual del grupo compartido. |
| 6 | P06 — Consultas compatibles | Media | Mismos resultados con menos consultas, filas y memoria. | P00; comparación de resultados y planes. |
| 7 | P07 — Recuperación y migraciones reproducibles | Alta para operación | Poder reconstruir y volver de un despliegue sin ejecutar limpiezas sobre datos válidos. | Inventario P00; ensayo aislado desde el comienzo. |
| 8 | P08 — Cambios condicionados | Diferida | Evaluar restricciones, conciliación e índices con evidencia. | Decisiones y mediciones indicadas en sección 5. |

El orden es de liberación sugerido, no de espera obligatoria entre trabajos independientes. El inventario de P01 y los ensayos de recuperación P07 comienzan en P00. Si certificados o cuentas requieren gestión externa, P02/P03 pueden avanzar en el entorno aislado. Ningún bloqueo de infraestructura justifica mezclar una corrección de negocio no definida.

## 4. Trabajo concreto por lote

### P00 — Capturar el comportamiento y preparar recuperación

**Objetivo:** saber qué debe seguir igual y disponer de una vuelta real antes de tocar persistencia.

1. Registrar revisión de código, configuración efectiva por consumidor y esquema actual, sin valores secretos en el repositorio.
2. Verificar de nuevo en solo lectura los hallazgos sensibles a datos. Los 28 reportes con varias jornadas son un resultado de la auditoría del 11 de septiembre, no un conteo permanente.
3. Preparar una instancia MariaDB aislada, sin credenciales ni red hacia Redmine/Telegram/CORE reales. Usar datos sintéticos; cualquier copia operativa debe custodiarse y sanearse según su uso.
4. Crear y probar la restauración del respaldo pertinente en ese entorno. Conservar definición de tablas, índices, FK y triggers. Mantener secretos/certificados fuera de Git y del directorio publicado.
5. Capturar casos de comportamiento válidos y de concurrencia mediante las pruebas de sección 6. El resultado de referencia no debe convertir una vulnerabilidad en una obligación de compatibilidad.

**No requiere cambio de esquema activo.** Entregable: matriz de casos y resultados, inventario y procedimiento de recuperación probado. No se ejecuta `migrate:fresh` ni un rollback histórico sobre la base de trabajo.

### P01 — Reducir exposición de DB y secretos

**Hallazgos:** DB-001, DB-002, DB-003.

**Áreas:** `config/database.php`, variables efectivas de Apache/PHP, servicios Telegram/Monitor, tareas programadas, integraciones de respaldo y artefactos de despliegue.

1. Inventariar qué lee/escribe cada proceso, incluidos escritores diferidos que se invocan desde métodos aparentemente de lectura.
2. Preparar una cuenta de aplicación dedicada, limitada al esquema/operaciones requeridos. Separar cuentas de migración y administración. Conservar las escrituras legítimas; no suponer que todos los procesos son de solo lectura.
3. Probar todos los consumidores con esa cuenta en el entorno aislado antes del cambio de configuración activa. Comprobar que no tenga administración global ni delegación de privilegios.
4. Configurar TLS y verificación del certificado por consumidor; probar certificados institucionales y nombres reales del servidor. Verificar el cifrado de la sesión de cada proceso. Exigirlo primero a la cuenta/procesos preparados, evitando cortar clientes pendientes con un requisito global prematuro.
5. Preparar un artefacto de despliegue sin `.env` de respaldo ni dumps públicos. Custodiar los respaldos legítimos antes de retirar copias. Coordinar rotaciones y limpieza del historial Git como acciones separadas y revisables.
6. Tratar APP_KEY de forma especial: la primera contención no debe reemplazarla a ciegas. Diseñar y probar recuperación/recifrado de secretos y consumidores antes de una rotación. La exposición identificada sigue pendiente hasta completar esa corrección.

**Aceptación:** login, cambio propio de contraseña, edición de usuario, dashboards, reportes, horas extra, integraciones y workers funcionan con la misma lógica; cada consumidor demuestra cuenta limitada y TLS; el artefacto no contiene secretos/dumps innecesarios.

**Vuelta:** corregir credenciales/permisos de una cuenta dedicada segura; no usar como solución permanente restaurar una credencial expuesta. Mantener una vía administrativa de recuperación separada del runtime. Las rotaciones externas solo se ejecutan después de tener sustitución y recuperación comprobadas.

### P02 — Persistir exclusivamente lo que se está cambiando

**Hallazgos:** DB-005; preparación de DB-008/021.

**Archivos principales:** `Nova/Repositories/NovaUserRepository.php`, `Nova/Services/NovaUserService.php`, `RedmineMantencion/Repositories/MantencionReportRepository.php`, `RedmineMantencion/Services/MantencionRedmineSyncService.php` y llamadores de `save_messages`.

1. Enumerar campos y proyecciones que cada caso de uso debe actualizar: alta, edición, contraseña, integración, estado, envío y archivo de reporte.
2. Conservar normalizadores/reglas de dominio existentes y sustituir el recorrido de todas las cuentas/reportes por comandos de escritura de la entidad y campos afectados.
3. Mantener propagaciones legítimas al módulo de la cuenta editada, sin volver a guardar fichas de terceros ni sobrescribir secretos personales ajenos a esa operación.
4. Para escrituras sobre el mismo campo de la misma entidad, comparar una versión o valor esperado y devolver conflicto explícito. Si se requiere columna de versión, hacer una migración aditiva y compatible; no asumir que el timestamp de segundos distingue cualquier carrera.
5. Actualizar todas las entradas del escritor afectado: web, legacy, comandos y workers. Evitar que quede un camino viejo que siga guardando snapshots completos.
6. Mantener estados, IDs y payloads actuales; posponer cambio de claves/UNIQUE hasta P08.

**Aceptación:** editar A mientras B cambia contraseña conserva ambos cambios; dos reportes distintos guardados a la vez conservan sus resultados; un conflicto sobre el mismo campo no se resuelve silenciosamente pisando datos; operaciones ajenas no reescriben hashes ni fechas de actualización de otros usuarios.

**Vuelta:** revertir el código del lote conservando las escrituras válidas ya realizadas. Una columna aditiva compatible puede permanecer temporalmente. No restaurar toda la BD desde un backup anterior y perder operación posterior para deshacer un cambio de código.

### P03 — Guardados atómicos y errores verificables

**Hallazgo:** DB-006.

**Archivos principales:** `RedmineTic/Repositories/RedminePermissionRepository.php`, `RedmineTic/Repositories/RedmineUserRepository.php`, `RedmineMantencion/Repositories/MantencionConfigRepository.php` y controladores que interpretan sus resultados.

1. Mantener el mismo conjunto de permisos, roles y valores aceptados por los flujos legítimos; no redistribuir permisos entre NOVA/TIC/Mantención dentro de este lote.
2. Validar el conjunto solicitado antes de escribir. Obtener las claves deseadas de esa entrada validada, nunca de la lista de escrituras que alcanzaron a funcionar.
3. Guardar perfil y permisos relacionados dentro de una transacción. Eliminar capturas internas que oculten una falla necesaria para el rollback, propagando un resultado seguro al controlador.
4. Aplicar la misma atomicidad a un cambio multiparámetro de configuración cuando represente una sola operación. No envolver llamadas de red en la transacción.
5. Conservar respuesta normal y mensaje de éxito de una operación exitosa. En fallo, devolver el error en el canal existente, sin anunciar un guardado que no ocurrió ni exponer SQL/secretos.

**Aceptación:** una falla simulada en la mitad deja perfil, permisos y configuración exactamente como antes; no se borra la clave que falló; no hay éxito ficticio; una operación completa produce el mismo estado que el flujo válido anterior.

**Vuelta:** revertir código después de comprobar que no hay transacciones activas del despliegue. Revisar cualquier estado parcial anterior con un procedimiento aparte; este lote no lo “repara” por inferencia.

### P04 — Protección de envíos y espera acotada de históricos

**Hallazgo DB:** DB-021. **Complemento:** BACK-023 y la revisión específica de los históricos TIC/Mantención.

Este lote se divide en dos entregas para no mezclar creación de tickets con consultas de estado.

**P04-A: envío de reportes.**

1. Introducir una reserva durable que todos los caminos de envío consulten. La exclusión debe ser por `(módulo, reporte)` mientras exista un intento activo o incierto, incluso si llegan identificadores de intento distintos. El identificador de intento conserva la trazabilidad. Una transacción breve debe permitir un solo envío concurrente y terminar antes de la llamada externa.
2. Conservar los estados de negocio visibles y el payload remoto. Si falta almacenamiento para distinguir intento iniciado, confirmado e incierto, proponer estructura aditiva interna, con migración y justificación; no publicar automáticamente un estado nuevo que las pantallas desconozcan.
3. Guardar la respuesta por reporte tan pronto como exista confirmación. Un fallo de logging no debe impedir registrar un ticket aceptado.
4. Separar rechazo previo al envío, éxito confirmado y timeout después de una posible aceptación. El tercero requiere conciliación; no debe convertirse automáticamente en un nuevo POST.
5. Rechazar el doble envío simultáneo del mismo intento. Antes de cambiar un reenvío secuencial actualmente expuesto al usuario, comprobar si existe un caso legítimo de “crear otro ticket”; ese comportamiento requiere contrato específico y no se elimina por suposición.

**Aceptación:** dos solicitudes simultáneas del mismo reporte producen una sola llamada de creación; envíos distintos siguen funcionando; los datos enviados y el resultado exitoso coinciden con la referencia; un resultado incierto queda identificable y conciliable.

**P04-B: sincronización de históricos.**

1. Reutilizar o extraer la comprobación breve de disponibilidad de API Redmine con credenciales del usuario. Comprobar la API necesaria, no solo que la portada HTML devuelve 200. Una prueba inicial exitosa no elimina la necesidad de timeout en consultas posteriores.
2. Fijar como presupuesto inicial de implementación: hasta **5 segundos** para la comprobación previa y **30 segundos en total, incluida esa comprobación**, para una ejecución automática completa por pantalla. Validarlo con la red institucional; si el historial excede ese presupuesto, conservar lo ya consultado y definir una continuación que retome pendientes mediante los controles existentes, sin reiniciar siempre desde el primer reporte ni crear reintentos ilimitados.
3. Mantener los límites individuales actuales y cancelar el resto ante caída de transporte general. Diferenciar timeout/conexión, 401/403 y 404 de un ticket: un ticket inexistente no demuestra caída de toda la plataforma.
4. Añadir timeout/cancelación al request del navegador y plazo en backend; cancelar `fetch` por sí solo no detiene PHP. No continuar acumulando esperas por todos los grupos después de detectar una caída general.
5. Conservar el último estado válido y el Redmine ID de cada reporte. Un fallo de sincronización no debe vaciar estados locales ni marcar reportes procesados como error de envío.
6. Atender por separado la sincronización automática y el botón completo TIC, que pagina por la API. Al agotar el plazo, informar resultado parcial/incompleto sin anunciar sincronización total.

**Aceptación:** con Redmine disponible, los mismos estados/IDs se actualizan; con servicio caído, el indicador de carga termina dentro del presupuesto y queda un mensaje comprensible; 404 de un ticket no bloquea los demás; consultas lentas no destruyen información previa. El límite total es una corrección explícita del comportamiento ante demora, no una modificación del cálculo o contenido de reportes.

**Vuelta:** detener nuevos envíos y conciliar intentos en curso antes de revertir código de P04-A; conservar el registro de intentos. P04-B puede revertirse por separado y no requiere restauración de datos. No reutilizar un respaldo antiguo como mecanismo para “desenviar” tickets remotos.

### P05 — Integridad de horas extra preservando las reglas actuales

**Hallazgos:** DB-007, DB-011 y parte de DB-012. **No incluye conciliación de DB-004.**

**Archivos principales:** `Nova/Repositories/HorasExtraRepository.php`, `RedmineTic/Repositories/RedmineHoursExtraRepository.php`, `RedmineMantencion/Repositories/MantencionHoursExtraRepository.php` y métodos de borrado de reportes que dejan vínculos.

1. Caracterizar con pruebas cuándo se crea, mueve, elimina y actualiza una jornada; registrar por separado la regla de fechas y la precedencia de horarios de cada llamador.
2. Hacer indivisible la operación actual de grupos/vínculos, manteniendo el mismo resultado cuando no hay concurrencia. Bloquear entidades en un orden consistente y evitar borrar un grupo entre un EXISTS y un attach concurrente.
3. Mantener los vínculos de otros reportes/módulos al borrar un reporte autorizado. Retirar únicamente los vínculos del origen e ID realmente borrado; no procesar por ID numérico sin origen.
4. Ante carrera de creación para usuario no nulo, recuperar la fila ganadora si corresponde a la misma identidad existente, en lugar de abandonar silenciosamente el vínculo.
5. Mantener la edición por fecha y la semántica vigente del horario. La precedencia entre “horario confirmado” y “hora del reporte” se documenta como decisión pendiente; no cambiarla aprovechando la incorporación de transacciones.
6. Conservar registros actuales, incluidos vínculos múltiples y el grupo vacío observado. Cualquier saneamiento irá por P08 con selección explicada.

**Aceptación:** el mismo conjunto de entradas válidas termina en las mismas jornadas/horarios; un fallo inyectado deja intacta la situación previa; borrar un reporte no deja vínculo huérfano ni elimina reportes del otro origen; los conteos históricos solo cambian por operaciones explícitas del usuario.

**Vuelta:** revertir el código sin retirar vínculos válidos creados posteriormente. Las correcciones transaccionales no requieren deduplicar ni hacer NOT NULL la columna de usuario.

### P06 — Mejoras de consulta con equivalencia demostrada

**Hallazgos:** DB-015, DB-016, DB-017; preparación de DB-019.

1. Corregir primero bindings de ID textual donde el esquema real es VARCHAR, conservando normalización y ceros/formatos válidos. Comparar planes y resultados; no migrar todas las columnas a entero.
2. Precargar en una consulta los datos de integración necesarios para un listado y resolver secretos únicamente al usarlos. Mantener memoria/caché por request; nunca compartir un token entre usuarios por una clave de caché incompleta.
3. Sustituir búsquedas de una identidad por consultas puntuales solo después de probar todos los identificadores de login aceptados. Cuando haya ambigüedad de normalización, conservar ese comportamiento de resolución hasta el trabajo específico de identidad, sin añadir un fallback que amplíe acceso.
4. Mover filtros y paginación a SQL por pantalla, empezando por el histórico Mantención. Conservar alcance autorizado, empates de orden, valores NULL, totales, filtros y tamaño de página. No aprovecharlo para modificar las reglas de autorización.
5. Separar columnas de listado de las de detalle sin retirar información visible. Mantener la carga completa de detalle al abrirla.
6. Medir consultas, filas y memoria antes/después. No agregar ni retirar índices en la misma entrega de refactor de consultas: así se puede identificar qué produjo una mejora o regresión.

**Aceptación:** mismos IDs en el mismo orden, mismos totales y mismo detalle; ningún registro ajeno añadido; queries de identidad/listado no crecen una por usuario; no empeora el camino más frecuente ni se degrada otro módulo.

**Vuelta:** revertir la consulta o proyección puntual. No requiere restaurar datos. Los candidatos de índices se evalúan después, en P08.

### P07 — Recuperación reproducible y rollbacks revisados

**Hallazgos:** DB-009, DB-010 y DB-023.

1. Comparar el esquema activo con una instalación en blanco en una instancia descartable; incluir tipos, PK, FK, índices y triggers.
2. Documentar el camino de restauración para una copia actual y el de actualización desde estados antiguos. No aplicar automáticamente la migración histórica de vaciado a una base restaurada con reportes válidos.
3. Preparar una línea base de estructura sin datos ni definers/secretos de entorno. Mantener el esquema funcional existente como referencia; no alinear tipos de producción al dump o a una migración antigua por conveniencia.
4. Corregir y probar el método inexistente del rollback en entorno aislado, conservando índices preexistentes que esa migración no hubiera creado. No ejecutar ese rollback sobre la base operativa para demostrar que funciona.
5. Mantener el ledger de migraciones y documentar cómo convive la línea base con él. No reescribir migraciones aplicadas ni marcar filas a mano sin una estrategia de compatibilidad explícita.

**Aceptación:** una restauración aislada conserva reportes, claves y relaciones; la estructura obtenida coincide con la referencia; los ensayos de rollback no eliminan objetos ajenos ni reejecutan limpiezas irreversibles.

**Vuelta:** las pruebas usan una instancia descartable. La base operativa solo recibe migraciones aditivas o correctivas individualmente revisadas y probadas, cuando se implementen; no un “reset” para igualar entornos.

## 5. Cambios que se posponen para conservar la lógica

| Hallazgo / propuesta | Motivo para posponer | Evidencia o decisión necesaria |
| --- | --- | --- |
| DB-004: limpiar las 28 asociaciones o UNIQUE(origen,reporte_id) | Podría eliminar una jornada histórica válida o cambiar cardinalidad de importación. | Determinar si un reporte puede pertenecer a más de una fecha; listar vínculos a conservar con justificación. |
| DB-007: dar prioridad al horario manual frente al reporte | Cambia un resultado de negocio, aunque el código/comentarios sugieran una inconsistencia. | Definir autoridad del horario por flujo y ejemplos esperados. La atomicidad sí entra en P05. |
| DB-008: UNIQUE de origen/CORE | NULL, claves de fuente y reconciliación CORE necesitan contrato exacto. | Demostrar identidad completa y comportamiento de registros manuales/legacy antes del DDL. |
| DB-012: usuario obligatorio en jornadas | Alteraría el tratamiento de cuentas eliminadas y asignados sin resolver. | Política de grupos sin identidad y conservación histórica. |
| DB-013/014: UNIQUE/FK compuestas en catálogos | Puede rechazar nombres/claves o referencias toleradas en importaciones actuales. | Contrato de módulo/tipo/clave externa y revisión de todos los escritores. |
| DB-018: retirar índices por prefijo | Un índice corto puede servir a una consulta no cubierta en el ensayo. | Planes, uso real y comprobación de dependencias; rollback de recreación probado. |
| DB-019: índices adicionales | Primero debe estabilizarse la consulta paginada. | Medición antes/después, costo de escritura y ventana DDL; conservar solo lo que aporte beneficio. |
| DB-020: aumentar/cambiar retención | Cambia almacenamiento y política de conservación de datos. | Plazo, volumen, acceso y archivo acordados; no purgar ni recuperar por inferencia. |
| DB-022: rechazar formatos antes normalizados | Cambia qué entradas acepta actualmente el sistema. | Inventario de formatos válidos y decisión explícita sobre entradas inválidas; no introducir CHECK masivos en la primera entrega. |

Posponer no cierra el hallazgo. DB-004, DB-008 y las partes funcionales de DB-007/012 siguen abiertos hasta resolver las decisiones y demostrar la corrección. Las medidas compatibles deben prevenir pérdida parcial y reducir exposición mientras esas decisiones se resuelven.

## 6. Validación de compatibilidad

### Entorno de pruebas

Usar MariaDB aislada para índices, FK, bloqueos, UNIQUE, transacciones y migraciones; SQLite en memoria no demuestra equivalencia en esos puntos. Todos los servicios externos deben sustituirse por respuestas simuladas en las pruebas automáticas.

`tests/TestCase.php` comprueba que el nombre de DB sea de testing y `phpunit.xml` fuerza `nova_testing`, pero **el nombre no garantiza que host/servidor sean aislados**. Verificar destino y usuario efectivos antes de arrancar Laravel o la suite. Los ensayos de `tests/Production/S31MigrationCompatibilityTest.php` crean y eliminan bases: ejecutarlos solo en la instancia descartable preparada, con variables de prueba separadas.

### Casos mínimos por área

| Área | Escenarios obligatorios | Apoyo en pruebas existentes |
| --- | --- | --- |
| Autenticación e identidad | Login por identidades admitidas, bloqueo de cuenta, cambio propio, edición de otra cuenta simultánea. | `AuthTest`, `OwnPasswordTest`, `NovaUserIdentityTest`, `ModuleAccessTest`. |
| Permisos/configuración | Guardado correcto, fallo en la segunda fila, omisión voluntaria de clave, rol/perfil y configuración sin cambios parciales. | `Phase3aPermissionsTest`, `RedmineTicPermissionsTest`; ampliar con rollback real. |
| Reportes | Crear/editar/archivar/borrar un registro sin tocar otros; misma selección y payload en ambos módulos. | `RedmineReportIndividualOperationsTest`, `RedmineReportMassOperationsTest`, `RedmineReportMappingTest`. |
| Envíos | Selección vacía, caída previa, caída intermedia, éxito remoto y fallo local, dos solicitudes del mismo reporte, reportes de módulos diferentes. | `RedmineSendReportsTargetedPersistenceTest`, `RedmineIssueSenderServiceTest`, `RedmineTicAvailabilityTest`, `MantencionRedmineAvailabilityTest`. |
| Históricos Redmine | Disponible, conexión rechazada, timeout, 401/403, ticket 404, lote parcial, expiración del plazo y conservación del estado previo. | `RedmineIssueStatusServiceTest`; ampliar endpoint y navegador para ambos módulos. |
| Hora extra | Toggle SI/NO, valor inicial 1, cantidad explícita válida, jornada nocturna, grupos compartidos, fallo entre detach/attach, borrado del origen correcto. | `MantencionHoursExtraFieldsTest`, `RedmineTicHoursExtraEstimatedTimeTest`, `RedmineTicHoursExtraTest`. |
| Listados | Filtros combinados, alcance propio, empates, NULL, primera/última página, totales y detalle. | Pruebas de histórico/estadísticas existentes y nuevas comparaciones de resultado. |
| Infraestructura | Web, listeners, monitor, comandos y backup con cuenta/TLS previstos; sin mensajes ni tickets externos desde las pruebas. | `ServerMonitorAccessTest`, `SharedRedmineCredentialTest`, `ProductionArtifactPolicyTest` y ensayos controlados por consumidor. |

Los nombres anteriores son clases/archivos existentes para partir de ellos, no una afirmación de que ya cubran todos los casos. Varias pruebas actuales inspeccionan texto de código; las garantías de concurrencia/rollback necesitan pruebas de comportamiento sobre MariaDB y al menos dos conexiones independientes.

### Criterio para aprobar una entrega

1. Pruebas afectadas y casos de regresión pasan en entorno aislado.
2. Operaciones válidas producen los mismos campos, relaciones, payloads y respuestas esperadas, salvo metadatos técnicos documentados.
3. Los únicos cambios funcionalmente visibles son los definidos para errores, conflictos o espera excesiva del lote; no se modifica una regla de negocio pendiente.
4. Ningún secreto aparece en logs, snapshots de tests, fixtures ni artefactos de Git.
5. Se documentan resultado, limitaciones, medición pertinente y vuelta de ese lote. No exigir ejecutar toda la plataforma repetidamente sin cambios que lo justifiquen.

## 7. Despliegue y vuelta por entrega

- Entregar cada lote como cambio separado; revisar primero código, migración si existe, pruebas y procedimiento de vuelta. Preparar el resultado completo antes de solicitar la aprobación final de una acción operativa que la requiera.
- Publicar con un mecanismo breve de coordinación de escritores cuando cambien contratos de persistencia. Web, procesos CLI y workers que comparten tablas deben usar una versión compatible; no dejar listeners con el escritor antiguo durante una migración de concurrencia.
- Mantener las estructuras nuevas aditivas mientras conviven versiones; retirar columnas/índices solo en otra entrega, si está justificado y validado.
- Verificar después del despliegue: acceso normal, una operación controlada por flujo afectado, errores de BD, tiempos y estado de workers. Las pruebas reales que creen tickets o notifiquen personas necesitan alcance explícito; preferir simulación o entorno de integración preparado.
- Ante regresión, detener el camino afectado y revertir código/configuración compatible. Conservar datos válidos posteriores al despliegue. Para envíos externos, conciliar antes de reanudar; para credenciales expuestas, corregir hacia una configuración segura.
- No usar un rollback global ni una restauración completa como respuesta automática: ambos podrían borrar actividad reciente o duplicar efectos remotos.

## 8. Seguimiento y primera entrega propuesta

| Hallazgos | Tratamiento en este plan | Estado inicial |
| --- | --- | --- |
| DB-001/002/003 | P01, seguridad de infraestructura conservando contratos. | Pendiente de implementación y prueba por consumidor. |
| DB-005 | P02, escritura puntual y conflicto controlado. | Primera corrección de persistencia recomendada. |
| DB-006 | P03, transacción y resultado verdadero. | Primera corrección de integridad recomendada. |
| DB-021 | P04-A, protección concurrente y conciliación remota. | Pendiente; no resolver solo con UNIQUE de ticket. |
| BACK-023 en históricos | P04-B, disponibilidad y plazos. | Complemento operativo ya solicitado para revisión; aquí queda planificado. |
| DB-007/011/012 | P05, solo parte compatible de atomicidad/cleanup/carrera. | Cierre parcial; decisiones de horario y NULL siguen abiertas. |
| DB-015/016/017 | P06, consultas equivalentes. | Pendiente de comparación y pruebas. |
| DB-009/010/023 | P07, reconstrucción y retorno seguros. | Ensayos aislados desde P00. |
| DB-004/008/013/014/018/019/020/022 | P08 y condiciones de sección 5. | Diferidos; sin modificaciones automáticas de datos o reglas. |

**Paquete inicial concreto:** P00, preparación P01 y correcciones P02/P03, en entregas independientes. Es el conjunto que protege contraseñas, permisos y datos de terceros sin necesitar decidir la cardinalidad de horas extra ni cambiar formatos, catálogos o estructura de reportes. P04 y P05 siguen después de verificar esos contratos.

**Definición de terminado del plan:** cada mejora importante tiene alcance, dependencias, archivos principales, pruebas y vuelta; cada cambio que podría modificar lógica actual queda identificado y condicionado. Un hallazgo se marcará corregido únicamente cuando su implementación y validación estén completas, nunca solo por figurar en este documento.

## 9. Primera entrega implementada — 11 de septiembre de 2026

El usuario autorizó comenzar la implementación después de preparar este plan.
Esta entrega modifica código y pruebas; las secciones anteriores conservan el
alcance del plan completo. No se ejecutaron modificaciones sobre la base
operativa ni rotaciones de credenciales.

### Cambios realizados

- **P00, preparación local:** instancia MariaDB temporal sin red y datos
  sintéticos; instalación limpia mediante las migraciones existentes y ensayo de
  respaldo/restauración. Se compararon estructura y checksums de filas de la
  instalación temporal y su restauración. Esto no equivale a validar un respaldo
  operativo ni resuelve la divergencia de esquema DB-009.
- **P02, edición NOVA:** el guardado administrativo deja de enviar la colección
  completa al escritor. Una edición escribe solamente cambios en los campos
  que administra; no vuelve a guardar email, Chat ID, integraciones ni hashes
  sin solicitud de contraseña. Compara los campos modificados con la lectura
  inicial bajo bloqueo de la fila; un conflicto devuelve error y una cuenta
  eliminada durante la operación no se recrea.
- **P02, creación TIC:** el alta persiste únicamente la entrada que se está
  creando, conservando identidad, estado y proyección existentes.
- **P03, permisos TIC:** transacciones para permisos por perfil, roles completos
  y edición de un rol. Las claves a conservar provienen de la entrada
  normalizada; una escritura o eliminación fallida revierte el conjunto. Se
  mantienen los valores booleanos/scopes y el comportamiento existente de una
  entrada de permisos vacía.
- **P03, perfil TIC:** cada usuario se persiste en una transacción que incluye
  perfil y permisos. El error llega al flujo administrativo, que deja de
  responder éxito ante un fallo. La eliminación de roles también convierte
  fallos de persistencia en una respuesta de error controlada.

### Validación obtenida

- Las primeras pruebas reprodujeron siete fallos con el código previo.
- Pruebas nuevas de persistencia: **12 aprobadas, 60 aserciones**, incluyendo
  escrituras intercaladas por dos conexiones reales y errores SQL inducidos.
- Regresiones de autenticación, cambio propio de contraseña, acceso, identidad,
  EMACH, usuarios y permisos TIC: **95 aprobadas y 7 omitidas**, 287 aserciones.
- Una expectativa anterior en `RedmineTicUsersTest` fallaba también con las
  clases originales. Se actualizó para verificar la integración compartida
  `tipo=redmine` más la fila legacy creada por ese caso; no se modificó la
  lógica de sincronización para hacer pasar la prueba.
- Sintaxis PHP y revisión de espacios del diff correctas. El formato global de
  los repositorios legacy conserva deuda previa; no se reformatearon completos.

La validación local utilizó PHP 8.2 y MariaDB 10.4 de LAMPP. Aún corresponde
validar en un entorno aislado equivalente a MariaDB 12.3 del entorno auditado
antes de una liberación de producción. No hubo envíos de tickets ni mensajes.

### Alcance pendiente y vuelta

DB-005 sigue **parcialmente corregido**: faltan los escritores de reportes
Mantención, la persistencia implícita de deduplicación de `NovaUserRepository::all`
y la revisión de otros caminos que guardan snapshots. Por ello no se declara
cerrado el riesgo global de sobrescritura. El control de conflicto implementado
cubre cambios durante el procesamiento de la petición, no la antigüedad de una
pestaña abierta antes de enviar el formulario.

DB-006 queda cubierto en sus escritores de permisos TIC; **P03 no está completo**:
la configuración multiparámetro y opciones Mantención siguen pendientes. Los
helpers auxiliares que ocultan fallos de integración/acceso en la persistencia
TIC también requieren revisión separada. P01 conserva pendientes inventario
operativo, cuenta dedicada, TLS y rotaciones; P04–P08 conservan su estado previo.

Esta entrega no agrega ni modifica esquema. Para volver, revertir sus cambios
de código de forma coordinada, conservando los datos válidos posteriores; no
restaurar la BD como reversión de estos cambios. La siguiente entrega propuesta
completa la revisión de escritores pendientes de P02 y el guardado atómico de
configuración Mantención antes de abordar los envíos P04.

## 10. Segunda entrega implementada — 14 de septiembre de 2026

Esta entrega completa los escritores de Mantención, deduplicación NOVA y
configuración Mantención señalados como pendientes en la sección 9. Ese apartado
conserva el estado histórico de la primera entrega; no se declara cerrada la
auditoría completa. No se modificaron datos ni esquema de la base operativa.

### Cambios realizados

- **P02, reportes Mantención:** edición, actualización CORE, reinicio de errores
  y archivado escriben únicamente los cambios del reporte correspondiente,
  comparando los campos modificados con los valores originales bajo bloqueo.
  Se conservan cambios concurrentes en otros campos y reportes; un registro
  eliminado durante la operación no se recrea. Una actualización CORE iniciada
  sobre un pendiente no restaura ese estado si otro proceso ya lo procesó.
  La creación manual guarda únicamente el nuevo reporte y la eliminación deja
  de volver a guardar los reportes restantes.
- **P02, resultado de envío Mantención:** cada respuesta de Redmine se persiste
  antes de continuar. Si existe aceptación remota y falla la escritura local,
  se detiene el lote y se conserva el ID recibido en la respuesta y el mensaje
  de error. Las pruebas simulan Redmine; no se crearon tickets reales. Esto no
  implementa aún una reserva durable ni garantiza evitar envíos simultáneos P04.
- **P02, deduplicación NOVA:** la persistencia implícita vuelve a leer usuarios e
  integraciones dentro de una transacción con bloqueo y escribe solamente las
  fichas resultantes que cambian. Se conservan las reglas actuales de combinación,
  las identidades originales y la proyección de lectura ante un fallo; no se
  ejecuta limpieza histórica ni se cambian criterios de identidad.
- **P03, configuración Mantención:** parámetros, reemplazo de opciones y sus
  valores predeterminados se confirman o revierten juntos. El formulario principal
  incluye la sincronización de destinatarios en la misma transacción. Los flujos
  de configuración, mantenimiento y Nextcloud informan el fallo de guardado;
  desaparecen los mensajes de éxito después de una escritura fallida.
- **Compatibilidad:** no se cambian permisos, fórmulas de horas extra, payloads
  Redmine, tipos de configuración, retención ni reglas de creación de reportes.
  Ante conflictos o errores de persistencia se informa el resultado real.

### Validación obtenida

- Suite aislada de persistencia completa: **26 aprobadas, 176 aserciones**.
  Incluye dos conexiones reales con escrituras intercaladas, conflictos,
  eliminación concurrente, errores SQL inducidos, rollback de configuración y
  respuestas Redmine simuladas con fallo local posterior.
- Regresiones afectadas: **127 aprobadas y 7 omitidas, 540 aserciones**.
  Comprenden autenticación, contraseña propia, acceso, identidad, EMACH, permisos
  y usuarios TIC, CORE, campos de horas extra, disponibilidad Redmine,
  configuración, permisos de dashboard y protección CSRF de Mantención.
- Dos pruebas CORE fallaban también con el repositorio original: su preparación
  no registraba el módulo Mantención en una instalación limpia. Se agregó esa
  precondición únicamente dentro de la transacción de las pruebas; no se alteró
  el registro operativo de módulos para hacerlas pasar.
- Los casos que simulan funciones legacy se aíslan en procesos PHP separados.
  Sintaxis PHP y revisión de espacios del diff verificadas.

Total de ambas suites en esta entrega: **153 aprobadas, 7 omitidas y 716
aserciones**. Se utilizó PHP 8.2 y MariaDB 10.4 temporal sin red, con datos
sintéticos. Sigue pendiente comprobar compatibilidad en un entorno aislado
equivalente a MariaDB 12.3 del entorno auditado antes de liberar en producción.

### Límites, siguiente paso y vuelta

Los caminos anteriores quedan cubiertos en P02/P03. No se afirma resolver toda
escritura concurrente de NOVA: el control de reportes compara cambios durante la
petición y la configuración se guarda atómicamente, sin versionar formularios
abiertos anteriormente. La inserción concurrente de reportes CORE nuevos
(DB-008), los helpers auxiliares de integración/acceso TIC y la atomicidad de
grupos de horas extra mantienen su revisión pendiente. No se corrigieron datos
históricos ni se modificó la cardinalidad de horas extra.

El siguiente paso es **P04**: reserva durable de envío y conciliación ante
resultados remotos inciertos, junto con disponibilidad y plazos totales en la
sincronización de históricos de ambos módulos. P01 conserva la configuración
operativa de cuenta dedicada, TLS y rotaciones; P05–P08 siguen según su alcance
original, incluida la revisión de equivalencia del esquema.

La vuelta de esta entrega consiste en revertir sus cambios de código de forma
coordinada entre web y procesos, conservando la primera entrega y los datos
válidos posteriores. No requiere migración ni restauración de la BD. Si hubo
aceptación remota sin persistencia local, conciliar el ticket antes de reanudar.


## 11. Tercera entrega — P04 implementado y validado localmente

Fecha: 14 de septiembre de 2026. El usuario autorizó continuar con P04. Se
implementaron P04-A y P04-B conservando payloads, permisos, estados visibles y
reenvíos secuenciales existentes. La activación operativa requiere la migración
aditiva; **no se ejecutó sobre la base operativa**.

### P04-A: reserva y conciliación

- `redmine_send_attempts` conserva un registro por intento, con exclusión única
  por módulo/reporte mientras `reservation=1`. Las reservas se adquieren dentro
  de una transacción corta; ningún POST queda dentro de esa transacción. Se
  verifica que el reporte siga disponible y conserve el estado/ticket leído.
- Un segundo emisor concurrente no crea otro ticket. Después de confirmar y
  guardar un resultado se permiten los reenvíos secuenciales existentes; una
  petición iniciada antes de la finalización no reutiliza la lectura antigua.
- La evidencia de respuesta se registra antes del guardado del reporte, logging
  y consultas auxiliares. Un fallo local posterior conserva el ticket confirmado
  y bloquea otro POST. Un timeout, 5xx, respuesta 201 incompleta o proceso
  interrumpido queda reservado para revisión; no se libera por un temporizador.
- La conciliación operativa se consulta con `redmine:reconcile-send`. Aplicarla
  requiere indicar intento, resultado verificado, nota y que el emisor terminó
  o fue detenido. La vinculación de ticket y liberación son atómicas; no se
  elimina la evidencia previa ni se cambia un archivado a procesado.
- La tabla nueva se justifica por la necesidad de conservar intentos aunque
  falle el guardado del reporte o este sea eliminado. No contiene secretos ni
  payloads; no se mezcló con logs de seguridad ni se añadieron estados de negocio.

### P04-B: históricos

- Comprobación de API (JSON `issues`) con token personal, máximo 5 segundos.
  Se usa un presupuesto externo de 29 segundos para dejar margen de respuesta
  dentro de los 30 segundos del navegador, incluyendo la comprobación inicial.
- La sincronización visible de ambos módulos hace una sola solicitud acotada y
  retorna estados consultados, error y pendientes. El navegador termina su carga,
  conserva el estado conocido y permite continuar únicamente los pendientes.
- Un fallo general detiene el resto; 404 de un ticket no detiene otros. No se
  vacían estados ni IDs, ni se convierten reportes procesados en errores de envío.
- La sincronización completa TIC también tiene plazo. Conserva el offset en la
  sesión por usuario/proyecto y guarda los estados de páginas ya consultadas;
  informa parcial/incompleto hasta finalizar. La paginación usa orden por ID.
- Mantención mantiene la reaplicación del filtro de estado después de completar
  la consulta. La actualización local de tabla no inicia otra ronda automática.

### Validación y límites

- Persistencia MariaDB aislada: **34 pruebas aprobadas, 237 aserciones**.
  Incluye emisores Mantención superpuestos con conexiones distintas, exclusión
  por módulo/reporte, reservas inciertas y rollback real de conciliación.
- Regresiones afectadas: **156 aprobadas, 7 omitidas, 749 aserciones**. Incluyen
  envíos TIC superpuestos y conservación del payload, disponibilidad, históricos,
  plazos con reloj simulado y regresiones de las entregas anteriores.
- Total PHPUnit: **190 aprobadas, 7 omitidas, 986 aserciones**.
- Chromium con Playwright: continuación desde IDs pendientes, caída sin reintentos
  automáticos, aborto a 30 segundos, conservación de estados visibles y formulario
  completo TIC. HTML y respuestas simulados; no hubo navegación autenticada a
  plataformas operativas ni tickets/mensajes reales.
- Migración `up()` aplicada en `nova_testing`; `down()` ensayado en esquema
  descartable vacío. El comando de conciliación se verificó en esa instancia.
- El presupuesto limita HTTP externo; no garantiza tiempos de cola de Apache,
  bloqueo de sesión o fallos ajenos en BD. Validar comportamiento en red
  institucional y MariaDB 12.3 antes de activar producción; las pruebas locales
  usan PHP 8.2 y MariaDB 10.4.
- La conciliación remota es manual y explícita, no una búsqueda automática que
  infiera que un timeout significa ausencia de ticket. La reserva protege los
  emisores NOVA actualizados; versiones antiguas o creaciones directas en Redmine
  no quedan cubiertas.

### Activación, vuelta y siguiente etapa

El procedimiento y comandos están en `README.md`, apartado “Envíos e históricos
Redmine”. Antes de activar: respaldo SQL operativo, migración aditiva validada y
actualización coordinada de emisores. La tabla ausente bloquea el POST de forma
segura. La activación en la BD operativa queda pendiente de esta entrega de código.

Para volver, detener emisores y conciliar intentos en curso; conservar el registro
de intentos y los tickets aceptados. `down()` rechaza eliminar una tabla con
registros. No restaurar un respaldo antiguo para intentar deshacer tickets remotos.
Los ajustes de lectura de históricos pueden revertirse por separado.

La siguiente implementación es **P05**, integridad de horas extra dentro del
alcance compatible. P01 y las revisiones pendientes de P02/P03/P06–P08 conservan
su estado; esta entrega no cierra la auditoría completa.

## 12. Cuarta entrega — P05 implementado y validado localmente

Fecha: 14 de septiembre de 2026. Se implementó la parte compatible de integridad
de horas extra: atomicidad de DB-007, eliminación acotada de DB-011 y coordinación
de creación para usuario no nulo de DB-012. Se conserva la lógica de negocio;
DB-004 y las decisiones sobre precedencia de horarios y usuario NULL siguen
pendientes de sus etapas correspondientes.

### Cambios implementados

- El repositorio compartido coordina grupos/vínculos mediante transacciones y
  bloqueos. Los errores dentro de una operación compuesta provocan su rollback,
  en lugar de permitir guardar una parte y anunciar éxito.
- Las transiciones bloquean el reporte, el usuario de destino cuando existe y
  los grupos implicados. La creación para usuario no nulo reutiliza la jornada
  ganadora; la recuperación de clave duplicada exige la misma identidad.
- La vinculación y la limpieza de grupos comparten el bloqueo de la jornada.
  La comprobación de vínculos usa lectura actual antes de eliminarla; un attach
  tardío a una jornada eliminada falla explícitamente. No se limpian grupos
  vacíos ajenos a la operación.
- Los repositorios de reportes eliminan el reporte y sus vínculos en una sola
  transacción. La selección usa los IDs reales de las filas y el origen TIC o
  Mantención; un ID solicitado pero excluido por módulo/estado no pierde vínculos.
- El archivado y sus horas extra se confirman juntos. Una escritura fallida no
  incrementa el conteo de archivados. La desactivación y la edición de jornadas
  por fecha también revierten sus escrituras asociadas si falla una parte.
- Los llamadores afectados comprueban el resultado de persistencia antes de
  anunciar éxito. No se incorporan llamadas externas dentro de las transacciones.

### Compatibilidad conservada

- TIC conserva sus reglas de fecha, parseo de horas y retiro/reconstrucción de
  vínculos, incluida la recreación de una jornada que solo contenía ese reporte.
- Mantención conserva vínculos anteriores al sincronizar una jornada nueva.
- Un horario entrante no vacío conserva su prioridad actual; un extremo vacío
  mantiene el almacenado. La edición por fecha continúa afectando a todos los
  usuarios con vínculos de ese origen en la fecha, incluso jornadas compartidas.
- No cambian la fórmula de minutos, los horarios nocturnos ni los valores de
  hora extra/tiempo estimado de cada flujo. Se mantienen jornadas con usuario
  NULL, duplicados históricos y vínculos múltiples. No se conciliaron datos.

### Evidencia de validación

- Cuatro pruebas de caracterización, **30 aserciones**, pasan tanto con los tres
  repositorios de horas extra originales de HEAD como con la implementación
  nueva. Cubren las diferencias entre módulos, edición por fecha y usuario NULL.
- Cuatro pruebas de fallo inyectado fallan con los repositorios originales:
  transición TIC, vinculación Mantención, segunda actualización de una fecha y
  limpieza tras desvincular. Con los cambios verifican rollback completo.
- Suite de persistencia MariaDB aislada: **50 aprobadas, 330 aserciones**;
  incluye 16 pruebas de P05 y las regresiones de persistencia anteriores. Dos
  procesos independientes verifican creación concurrente y attach frente a
  eliminación, esperando un bloqueo real antes de confirmar el resultado.
- Regresiones de autenticación, permisos, usuarios, CORE, reportes, horas extra,
  envíos e históricos: **179 aprobadas, 7 omitidas, 812 aserciones**.
- Total de validación final: **229 aprobadas, 7 omitidas, 1.142 aserciones**.
  Los errores iniciales por restricción del socket del sandbox se resolvieron
  ejecutando estas mismas suites con acceso a la instancia temporal.
- Se usaron PHP 8.2 y MariaDB 10.4, datos sintéticos y fallos simulados. No se
  realizaron envíos externos ni cambios en la base operativa. La validación de
  carga y compatibilidad con MariaDB 12.3 operativo permanece pendiente.

### Activación, vuelta y siguiente etapa

P05 no añade migraciones ni exige saneamiento de datos. La protección concurrente
requiere actualizar conjuntamente los escritores que usan estos repositorios.
La migración aditiva de P04 continúa pendiente de activación en la BD operativa;
esta entrega no la ejecuta.

La vuelta consiste en revertir coordinadamente el código de P05, conservando los
vínculos válidos creados posteriormente. No restaurar un respaldo antiguo ni
eliminar jornadas para deshacer estos cambios.

La siguiente implementación es **P06: mejoras de consulta con equivalencia
demostrada**, empezando por medir resultados y planes antes de cambiar consultas.
P01 y los pendientes de las demás etapas conservan su alcance; no se declara
cerrada la auditoría completa.

## 13. Quinta entrega — P06, consultas compatibles de usuarios e histórico

Fecha: 15 de septiembre de 2026. Se implementó la entrega de consultas sin
modificar índices, migraciones ni datos operativos. La equivalencia se verificó
en la instancia MariaDB descartable con datos sintéticos.

### Cambios y alcance

- **DB-015:** los dos métodos de notificación TIC usan bindings string para
  `asignado_a VARCHAR(80)`. Conservan la validación y el trim de entrada. No se
  equiparan `123`, `0123` y `123x` por conversión numérica implícita, ni se migran
  los valores almacenados. El EXPLAIN de búsqueda por asignado pasa de recorrido
  de índice (`index`) a búsqueda por referencia (`ref`) en la prueba aislada.
- **DB-016:** la consulta TIC de credenciales deja de crecer una por usuario.
  El lote aplica la misma elección canónica/heredada que la lectura individual,
  incluidos secretos cifrados y rotaciones entre llamadas. Las pantallas y la
  resolución de nombres usan una proyección sin password ni consulta de secretos.
  El contrato legacy de `users()` sigue disponible y no hay caché global de tokens.
- `NovaUserRepository::find()` conserva las comparaciones normalizadas y el
  orden anterior sobre una proyección de identidad. Después carga la ficha e
  integraciones del usuario elegido. Si hay claves normalizadas duplicadas,
  mantiene `all()` y su reparación existente. `userForSession()` deja de cargar
  dos veces el directorio para obtener el usuario de una misma lectura.
- **DB-017, histórico Mantención:** el controlador usa
  `MantencionHistoryRepository`. La selección conserva los predicados anteriores
  en `MantencionHistoricoService::filterRows()`, sobre metadatos reducidos. SQL
  restringe por claves seleccionadas y aplica orden/página; solo los IDs visibles
  cargan `r.*` y descripciones para el modal. Se mantienen fuentes, duplicados,
  coincidencias por nombre, valores NULL, empates, totales y opciones de selectores.
- No se cambia el alcance histórico: sigue asignado al usuario según las reglas
  existentes. La fuente de horas extra conserva la misma selección previa; no
  se introduce ni se corrige una política de autorización aprovechando el refactor.

### Mediciones reproducibles

Las pruebas nuevas están en `tests/Integration/UserReadQueryTest.php`,
`ReportQueryBindingTest.php` y `HistoryReadQueryTest.php`; los comandos y métricas
temporales se describen en `tests/Integration/README.md`.

| Escenario sintético | Antes | Después |
|---|---:|---:|
| 100 usuarios TIC: consultas de credenciales | 100 | 1 |
| 100 usuarios TIC: consultas totales, incluidas comprobaciones de esquema | 309 | 11 |
| Histórico de 1.585 reportes: filas completas transferidas | 1.598 | 25 |
| Histórico: consultas totales | 16 | 9 |
| Histórico: pico adicional de memoria | 49.654.720 bytes | 4.364.048 bytes |

La medición de usuarios ejecutó el repositorio anterior guardado antes de P06:
el test detectó las 100 consultas frente a la expectativa de una. Con el nuevo
código pasó. En el histórico se comparan los lectores anteriores, aún usados
por otros flujos, con el nuevo lector de página. EXPLAIN muestra que la consulta
de detalle pasa de `ALL`/filesort sobre reportes a `range` por PRIMARY para 25 IDs.
La proyección de metadatos aún recorre candidatos; no se afirma que ese recorrido
desaparezca ni que el plan aislado sea idéntico al de producción.

Las duraciones observadas variaron entre ejecuciones y no se usan como umbral ni
como promesa de latencia. Las reducciones de consultas, filas completas y memoria
sí se comprobaron junto con los resultados.

### Validación

- Persistencia aislada: **60 aprobadas, 837 aserciones**. Incluye las fases
  anteriores y diez pruebas nuevas de P06. El histórico compara 57 combinaciones
  de filtros/páginas, además de carga grande y prueba negativa de alcance.
- Regresiones de autenticación, permisos, usuarios, horas extra, CORE, envíos,
  históricos, credenciales y notificadores: **216 aprobadas, 7 omitidas,
  993 aserciones**.
- Total: **276 aprobadas, 7 omitidas, 1.830 aserciones**.
- Se actualizaron dos expectativas de texto del test del notificador: binding
  string de P06 y argumento de persistencia de estados ya cambiado en P04. No se
  alteró el notificador para acomodar esas expectativas.
- PHP 8.2 y MariaDB 10.4. No se enviaron tickets ni notificaciones, ni se accedió
  a la base operativa para ejecutar pruebas.

### Límites conservados y siguientes pasos

Esta entrega es compatible y **no cierra completamente DB-016/DB-017**: la
proyección de identidad y los metadatos del histórico todavía crecen con el
directorio/historial. No se sustituyeron transliteración, deduplicación ni
coincidencias por nombres por una collation SQL aproximadamente equivalente.
Una búsqueda por descripción necesita leer ese contenido. Si Carbon normaliza
una fecha legacy de forma distinta al orden SQL, o hay 30.000 claves seleccionadas
o más, se conserva el recorte PHP para evitar cambios de orden/límites del driver;
la lectura de detalles sigue acotada a la página.

Los demás listados (por ejemplo actividad TIC) conservan su revisión específica
pendiente. El detalle visible no se retiró ni se introdujo una llamada adicional
al abrir el modal. Antes de extender consultas directas de identidad o filtros
SQL nativos a esos caminos, ampliar sus caracterizaciones y medir por pantalla.

La siguiente etapa del plan es **P07: recuperación reproducible y revisión de
rollbacks**. Los pendientes señalados de P06 y las decisiones de índices de P08
se mantienen explícitos. La migración operativa de P04 también continúa pendiente.
La vuelta de esta entrega es solo de código, sin restaurar datos ni índices.

## 14. Sexta entrega — P07, recuperación reproducible y retorno conservador

Fecha: 15 de septiembre de 2026. Se completó la implementación y el ensayo
**aislado** de P07. La base operativa solo se consultó para capturar metadatos y
su historial de migraciones; no se exportaron filas de la aplicación ni se
modificaron datos, índices, triggers o migraciones de ese entorno.

### Cambios y evidencia

- **DB-009:** referencia revisada en `database/baselines/2026-09-15/`, sin datos
  operativos, DEFINER, contadores AUTO_INCREMENT ni secretos de entorno.
  `SchemaBaseline` captura tipos, defaults, PK, índices, FK, checks, collations,
  triggers y ledger. El manifiesto y el SQL se comprueban conjuntamente.
- Se ejecutó toda la cadena de migraciones sobre una base vacía en MariaDB
  12.3.2 y se guardó la comparación en `migration-comparison.json`. La cadena
  deja IDs enteros donde el origen usa VARCHAR, omite la FK de asignado TIC y
  los siete triggers, y difiere en índices y auditoría de actualización.
  P04 aparece como diferencia aditiva esperada, todavía pendiente en el origen.
  La cadena también terminó en MariaDB 10.4, sin que ello acredite equivalencia.
- `nova:database-baseline capture|verify|bootstrap` permite capturar/verificar sin
  escribir en BD y reconstruir únicamente un destino completamente vacío. El
  bootstrap verifica el esquema antes de instalar el ledger capturado con sus
  nombres y lotes; no ejecuta las migraciones históricas. Se probaron los comandos
  completos de bootstrap, verify y comprobación de actualización en otro destino
  temporal, además de los ensayos automatizados.
- **DB-010:** `UpgradeSafety` y `nova:database-upgrade-check` detectan la limpieza
  histórica pendiente con datos. El evento de entrada de `migrate` bloquea antes
  de iniciar la cadena; el evento de migración comprueba nuevamente la limpieza
  al ejecutarla desde el migrador. Se probó una conexión explícita distinta de la
  predeterminada y `--force`, conservando los reportes y el ledger.
  El `up()` de la limpieza histórica permanece intacto.
- **DB-023:** se corrigió únicamente `down()` de la migración de índices. El
  `up()` original no registró propiedad, por lo que el downgrade conserva tanto
  índices preexistentes como añadidos. No llama al método inexistente de
  Blueprint ni adivina qué objetos puede eliminar. `up/down/up` mantiene índices
  y datos; retirar físicamente uno exige una migración posterior revisada.

### Restauración y validación

`DatabaseRecoveryTest` y `DatabaseUpgradeCommandTest` añaden nueve pruebas.
La reconstrucción coincide con la referencia, incluidos los siete triggers y
el ledger. Un respaldo SQL real de una base sintética se restaura después de
vaciar exclusivamente esa base descartable: se conservan reportes TIC y
Mantención, IDs textuales con cero inicial, vínculos de horas extra, usuarios,
módulos y ledger. Se comparan filas y estructura; una actualización comprueba
el trigger y una inserción huérfana confirma que la FK sigue activa.

- Suite de persistencia en MariaDB 12.3.2: **69 aprobadas, 893 aserciones**.
- Regresiones de autenticación, permisos, usuarios, reportes, horas extra, CORE,
  envíos e históricos: **337 aprobadas, 7 omitidas, 1.474 aserciones**.
- Total de esas dos suites: **406 aprobadas, 7 omitidas, 2.367 aserciones**.
- Verificación adicional en MariaDB 10.4: las siete protecciones pasan; los dos
  ensayos de reconstrucción completa se omiten por incompatibilidad de versión.
  La prueba de collation no soportada verifica fallo antes del primer DDL y
  recuperación de las variables de sesión SQL.
- Sintaxis de los nueve PHP de P07, formato de los archivos nuevos/proveedor y
  `git diff --check` correctos. Hashes de las auditorías 01, 02 y 03 intactos.

El procedimiento funcional se mantiene en el README, y los requisitos de prueba
están en `tests/Integration/README.md`. Se distinguen respaldo completo con datos,
bootstrap de estructura vacía y actualización desde estados antiguos. La línea
base no contiene configuraciones/usuarios para una instalación lista para usar.

### Límites, vuelta y siguiente etapa

El respaldo operativo completo con archivos y APP_KEY todavía requiere su propio
ensayo protegido; no se afirma haber restaurado datos ni credenciales reales.
El DDL de MariaDB no es transaccional: un fallo intermedio deja el destino de
bootstrap para revisión, sin instalar el ledger si no coincide el esquema.
La collation de los triggers requiere un servidor compatible con la referencia;
no se degrada silenciosamente para MariaDB 10.4.

El guard cubre la limpieza identificada, no toda migración destructiva ni SQL
manual. No habilita `migrate:fresh`, `refresh` o rollbacks históricos sobre datos
válidos. Las versiones antiguas con datos y ledger perdido requieren recuperar
su historial o preparar una conversión específica: no copiarles el ledger actual.
La vuelta de código de esta entrega no exige eliminar índices ni restaurar datos;
retirar el guard elimina una protección y debe revisarse antes de otra migración.

La siguiente etapa es **P08: evaluar cambios condicionados con evidencia**, según
la sección 5. No implica autorización automática para saneamientos, nuevas
restricciones, cambios de retención o borrado de índices. Se mantienen pendientes
las acciones operativas de P01, la activación de P04 y los límites explícitos de
P06. Esta entrega no declara cerrada toda la auditoría.

## 15. Séptima entrega — P08, evidencia para cambios condicionados

Fecha: 16 de septiembre de 2026. Se implementó y ejecutó la evaluación compatible
con la lógica actual. **No se cierra la conciliación ni se autorizan restricciones
nuevas por el resultado de esta etapa.** No hubo DML, DDL, purgas, tickets ni
notificaciones sobre el entorno operativo.

### Diagnóstico reproducible y observaciones

Se añadió `nova:database-review`, con salida tabular o JSON agregado y selección
de conexión. `ConditionalChangeReview` usa únicamente consultas directas,
metadatos y una transacción READ ONLY / REPEATABLE READ; evita los lectores de
aplicación con efectos diferidos. Cada consulta tiene límite de tiempo. Un error
o esquema incompleto no se presenta como cero casos; se restaura la sesión y se
retorna fallo. La salida no contiene usuarios, textos de reportes, claves externas
reales ni secretos. Los comandos y límites están en el README.

La lectura operativa completó las 27 comprobaciones. Resultados observados en
esta ejecución (no constituyen invariantes ni mediciones de crecimiento):

| Área | Evidencia agregada | Tratamiento compatible |
| --- | --- | --- |
| DB-004 | 28 pares origen/reporte en varias jornadas; 28 vínculos TIC con fecha distinta a la actual del reporte; 1 jornada vacía. | Conservar vínculos. Definir cardinalidad histórica y justificar la jornada a conservar antes de corregir. |
| DB-012 | 0 jornadas sin usuario en el snapshot. | No imponer NOT NULL: la FK admite SET NULL y el caso sigue formando parte del contrato. |
| DB-008 | 0 claves completas módulo/fuente/fuente_id repetidas; 0 claves CORE módulo/id_core repetidas; 0 identidades de fuente incompletas. | La ausencia de duplicados no prueba seguridad concurrente. Definir identidad y revisar escritores/importadores antes del UNIQUE. |
| DB-013 | 0 claves externas no NULL repetidas por módulo; 26 categorías y 90 unidades con módulo/clave NULL o clave vacía. | Pueden ser entradas manuales válidas. No exigir clave externa ni deduplicar por nombre. |
| DB-014 | 0 inconsistencias en las tres referencias TIC y la categoría Mantención examinadas. | Mantener pendientes contrato módulo/tipo y cobertura de todos los escritores para una FK compuesta. |
| DB-018 | 4 índices no únicos con prefijo cubierto. | Mantenerlos: no se ha demostrado desuso en todos los consumidores ni ensayado retirar individualmente esos cuatro índices. |
| DB-020 | 500 eventos globales conservados; el repositorio mantiene el recorte existente. | No cambiar retención ni inferir cuántos eventos fueron purgados. |
| DB-022 | 0 booleanos fuera de 0/1, estimaciones negativas u horas fuera del reloj en los campos examinados. | No añadir CHECK ni validación estricta: los formatos previamente normalizados no son detectables solo leyendo valores guardados. |

La autoridad del horario manual (DB-007) conserva su decisión funcional pendiente.
Los horarios nocturnos se informan separadamente, sin tratarlos como inválidos.
Los grupos sin usuario, entradas manuales y catálogos de otros módulos se cubren
con datos sintéticos aunque no aparezcan en el snapshot observado.

### DB-019: ensayo del índice de histórico

Se reconstruyó la referencia P07 en MariaDB 12.3.2 y se cargaron 10.000 reportes
sintéticos, dos módulos, fechas nulas/empatadas y vínculos de horas extra.
Se evaluó `(modulo_id,estado,fecha_reporte,id)` sobre las consultas reales de
candidatos y página de `MantencionHistoryRepository`, antes y después de crearlo.
Se repitió con un 10 % archivado y con todos archivados, y se ensayó su retirada
verificando la restitución del esquema completo. No se alteraron las consultas
runtime para favorecer al índice.

En la ejecución final, las medianas locales de tres repeticiones fueron:

| Escenario / consulta | Sin candidato | Con candidato |
| --- | ---: | ---: |
| 10 % archivado: metadatos | 2,71 ms | 2,37 ms |
| 10 % archivado: página real | 2,54 ms | 2,78 ms |
| Todos archivados: metadatos | 19,64 ms | 19,00 ms |
| Todos archivados: página real | 18,36 ms | 17,66 ms |
| Actualizar fecha de 1.000 filas, 10 % archivado | 2,85 ms | 6,05 ms |
| Actualizar fecha de 1.000 filas, todos archivados | 2,95 ms | 5,90 ms |

Las duraciones son orientativas, de caché local caliente, sin umbral de aceptación.
El plan selectivo usa el candidato y reduce las lecturas `Handler_read_next`
de 3.090 a 2.091 en tres ejecuciones; permanece el filesort de la unión. Con
todos archivados, las consultas reales conservan ALL/filesort y las mismas
lecturas Handler. El espacio estimado adicional del índice fue 376.832 bytes en
ambas distribuciones. La consulta simple LIMIT 25 mejoró considerablemente, pero
no representa los filtros/orden/unión completos de P06.

**Decisión:** no añadir una migración de índice todavía. La ganancia parcial no
justifica extrapolar rendimiento de la consulta simple a la pantalla, mientras
el costo de escritura aumenta. Se requieren distribución/carga representativas
y estabilizar los filtros SQL pendientes de P06. El ensayo se reproduce con
`ConditionalIndexEvaluationTest`; las métricas completas quedan en el directorio
temporal, sin datos operativos. No se probaron retiradas de índices existentes.

### Validación y estado del plan

- Ocho pruebas nuevas: **8 aprobadas, 186 aserciones**. Incluyen conservación
  exacta de filas/esquema, FK semánticas, privacidad de salida, rechazo real de
  INSERT en la transacción de revisión y cancelación real de consulta por timeout.
- Suite completa de persistencia en MariaDB 12.3.2: **77 aprobadas,
  1.079 aserciones**, incluidas las fases anteriores.
- El comando registrado se ejecutó también por Artisan sobre la base temporal
  reconstruida en P07: 27 comprobaciones evaluadas, salida JSON válida.
- No se modificaron lectores/escritores de los módulos, formularios, horarios,
  permisos, retención ni índices operativos. No se añadieron migraciones.

La parte de evaluación de P08 queda entregada. Para implementar una corrección
condicionada, el siguiente paso concreto es acordar el tratamiento histórico de
los 28 reportes TIC y preparar una selección revisable de vínculos antes de
cualquier saneamiento; un conteo por sí solo no decide qué vínculo eliminar.
Las restantes condiciones de la sección 5 siguen abiertas. P01 conserva sus
acciones operativas pendientes, P04 su activación y P06 sus límites declarados.
No existe una P09 definida ni se declara cerrada la auditoría completa.

La vuelta de esta entrega consiste en retirar el comando/repositorio de revisión
y sus pruebas/documentación, sin restaurar datos ni revertir DDL. Se reutilizó el
contenedor temporal de P07; no se volvió a intentar detenerlo después del rechazo
de permiso de esa etapa.

## 16. P08 — Decisión de fecha y conciliación TIC aplicada

Fecha: 16 de septiembre de 2026. El usuario definió conservar la fecha del
reporte, coincidente con su fecha de inicio. Esa decisión resuelve la ambigüedad
de fecha de los 28 casos identificados; no cambia la autoridad de los horarios
manuales ni las otras decisiones funcionales de P08.

Se preparó una selección de 28 reportes con exactamente 28 vínculos a conservar
y 28 a retirar, sin bloqueos por fecha o propietario. Tras las pruebas y la
autorización operativa, se aplicó el lote con respaldo previo y transacción.
La verificación por hash confirmó que los 28 reportes permanecieron idénticos;
se conservaron fechas, horarios y grupos, además de todos los vínculos de otros
orígenes. La comprobación global posterior mostró **0 reportes con varias
jornadas, 0 vínculos TIC con fecha distinta y 0 vínculos TIC huérfanos**.

Quedaron seis jornadas vacías (una ya existía); se conservaron porque retirar un
vínculo no autoriza eliminar horarios históricos. No se alteraron índices,
restricciones, retención, reportes remotos ni datos de Mantención.

Se añadió `HoursDateReconciler`, con selección explícita, huella de revisión,
bloqueos, respaldo de filas del pivot y comprobación posterior. La vuelta con
`restore()` se ensayó exclusivamente en la instancia descartable y rechaza un
respaldo corrupto o cambios posteriores incompatibles. El respaldo durable del
lote y su recibo están en almacenamiento privado según el README, excluidos de
Git; la copia inicial se generó fuera del proyecto.

Para prevenir vínculos nuevos a una fecha distinta, el repositorio TIC valida
la fecha al adjuntar y el importador legacy valida contra el reporte persistido.
Un paquete inconsistente se revierte completo con mensaje explícito. Se adaptó
la prueba histórica que antes permitía varias fechas: ahora prueba idempotencia
con jornadas coincidentes y un caso separado comprueba rechazo y rollback del
paquete incorrecto. No se modificó la fecha de un reporte para acomodar la
jornada de un respaldo.

Validación final: **83 pruebas de persistencia aprobadas, 1.117 aserciones**,
además de **3 pruebas de importación aprobadas, 28 aserciones** en la base temporal.
Los nuevos casos cubren conservación exacta, origen Mantención con el mismo ID,
selección desactualizada, falta de vínculo correcto, fallo en el segundo retiro,
respaldo obligatorio, restauración exacta y rechazo de respaldo alterado.
Sintaxis PHP y revisión de whitespace correctas; auditorías originales intactas.

DB-004 queda corregido para el lote observado con la regla acordada. La prevención
aplicada cubre las vías TIC revisadas; no se afirma una restricción universal en
BD frente a SQL manual. Permanecen abiertos los demás pendientes de P08 y las
acciones operativas/de rendimiento ya señaladas de P01, P04 y P06.

## 17. P01 — Preparación y pruebas sin certificados

El 16-09-2026 el responsable confirmó acceso administrativo al servidor y decidió
continuar sin certificados. TLS se aplaza expresamente: no se considera resuelto
DB-003 ni se cambian los criterios históricos de cierre. La inspección previa
observó una conexión con privilegios globales y sin cifrado de sesión.

Se implementó `nova:database-security-check`, de solo lectura y con salida
agregada sin concesiones originales ni hashes. Evalúa concesiones directas DML
en el esquema y reporta por separado el cifrado observado. El estado de salida
se refiere a permisos directos; no certifica roles implícitos, TLS ni otros
consumidores. La cuenta administrativa no pasa esa política.

Los Compose de Telegram y Monitor usan `nova_app` como valor por defecto y
respetan el `DB_USERNAME` explícito. No se editaron `.env`, cuentas del servidor
ni credenciales activas; tampoco se reiniciaron servicios. El inventario y el
procedimiento de transición están en `ops/database-security/README.md`, con
cuentas separadas para migración/administración y revisión de cada consumidor.

El builder excluye `database/baselines/` del artefacto web sin retirar las
migraciones PHP ni flexibilizar el rechazo de dumps. El respaldo de esquema P07
queda como material administrativo separado. Se construyó un artefacto sintético
desde un repositorio temporal limpio; no se generó una liberación oficial desde
el árbol de trabajo modificado.

Validación: **87 pruebas de persistencia aprobadas (1.151 aserciones)**, incluidas
cuatro pruebas nuevas de cuenta limitada sobre MariaDB aislada, y **45 pruebas
de autenticación, contraseña propia y accesos aprobadas (106 aserciones)**.
La revisión de permisos y las pruebas de artefacto suman **4 aprobadas (65
aserciones)**; otra prueba de producción queda omitida por su requisito externo.
Los casos nuevos demuestran login/contraseña/edición, integración cifrada,
reportes/horas/transacciones y denegación real de DDL, delegación y lectura de
usuarios del servidor. No sustituyen las pruebas de cada worker desplegado.

P01 queda **preparado y probado parcialmente; activación operativa pendiente**:
falta aprovisionar y verificar la cuenta limitada en cada proceso real, ejecutar
las rotaciones y custodia de secretos/dumps, y tratar APP_KEY con recuperación
probada. TLS queda aplazado por decisión del responsable. P04 y las optimizaciones
pendientes de P06 no se activaron en esta entrega.

## 18. P04 — Migración operativa aplicada con respaldo verificado

Fecha: 16-09-2026. El responsable autorizó respaldar la base operativa, aplicar
la migración de intentos y verificar los emisores/conciliación. No se activó P01
ni se modificó el transporte sin TLS elegido anteriormente.

### Respaldo y ensayo previo

La inspección encontró únicamente P04 pendiente, con todas las tablas InnoDB.
Se generó un dump consistente con datos, triggers, definiciones y ledger; se
cifró con GPG/AES256 y una clave aleatoria almacenada separadamente. El primer
intento falló por una opción no soportada por el cliente de dump; no generó una
copia utilizable ni alteró la base. Se corrigió la invocación y se impuso un
límite de ejecución del proceso de respaldo/restauración.

El respaldo válido se descifró y su SHA-256 coincidió con el SQL original. Se
restauró exclusivamente en una base aleatoria de MariaDB 12.3.2, con datadir
temporal, red deshabilitada y scheduler de eventos inactivo. Coincidieron las
34 tablas, 15.969 filas, siete triggers y el ledger; la comparación de huellas
confirmó también que el origen no cambió durante la copia. En ese destino se
ensayó `up()` de P04 y su `down()` sobre la tabla nueva vacía, comprobando que los
datos y el esquema previo permanecieran intactos. El SQL sin cifrar, el archivo
temporal de conexión y la base restaurada se eliminaron al terminar.

Custodia local fuera de Git/DocumentRoot: el respaldo cifrado y recibos están en
`/home/jean/.local/share/nova-backups/p04-20260916-r2/`; la clave está separada en
`/home/jean/.local/share/nova-backup-keys/`. Directorios 0700 y archivos 0600.
El respaldo de BD no incluye archivos del aplicativo ni APP_KEY, por lo que no
cierra la recuperación integral pendiente de P07.

### Activación y verificación

Se ejecutó Artisan con `--path` a la única migración autorizada y `--force`, tras
comprobar respaldo, hash de migración, destino efectivo, esquema y ledger. La
protección `UpgradeSafety` permaneció activa. Finalizó a las 18:36:47 UTC, batch
72. Las únicas escrituras registradas crearon `redmine_send_attempts`, sus índices
y la correspondiente entrada de `migrations`; no hubo DML sobre reportes.

La comparación posterior confirmó:

- Datos y estructuras preexistentes intactos, incluidos triggers y relaciones.
- Índice UNIQUE de reserva `(modulo_id, report_key, reservation)` correcto.
- Solo P04 añadido al ledger; cero migraciones pendientes en esa comprobación.
- `redmine:reconcile-send` terminó correctamente, sin intentos ni reservas pendientes.
- Cero solicitudes de creación de tickets a Redmine durante la intervención.

Las pruebas específicas sumaron **48 aprobadas y 274 aserciones**: 20 de
reservas/conciliación/persistencia Mantención y 28 de emisor TIC, construcción de
payloads, disponibilidad Mantención y plazos de históricos. Se verificó bloqueo
de solicitudes superpuestas, conservación de resultados inciertos y ausencia de
reintentos automáticos, con transporte simulado y base aislada.

### Alcance pendiente de operación

**La migración operativa de P04 está completada.** El código de este workspace
está preparado y probado. No se encontró Apache, PHP workers ni contenedores de
Telegram/Monitor ejecutándose localmente; se solicitó identificar la dirección
y servidor de la instalación utilizada para comprobar sus emisores activos.
Esa verificación en ejecución sigue pendiente y no se sustituye por las pruebas
simuladas. No se inició ni reinició un servicio desconocido ni se desplegó código
a otra copia no identificada.

No borrar la tabla ni restaurar el dump previo para deshacer tickets una vez
que comience la operación: conservar el registro y conciliar cada resultado
incierto con verificación remota explícita y confirmación de fin del emisor.

### Seguimiento P04 — 24-09-2026

La instalación local volvió a tener Apache activo. Las rutas de TIC y Mantención
responden y redirigen al login sin sesión; esta comprobación no ejerce los
emisores autenticados ni acredita el despliegue de Coolify. La migración P04
continúa marcada como aplicada (batch 72). `redmine:reconcile-send`, usado sin
`--apply`, no mostró reservas pendientes; no se crearon tickets ni se liberaron
reservas.

Las pruebas en MariaDB desechable y sin red validaron seis casos de reserva,
concurrencia, respuesta incierta, rechazo definitivo y conciliación atómica
(46 aserciones). Otras 16 pruebas sin BD del emisor y disponibilidad de Redmine
pasaron (45 aserciones). Se preparó `nova_testing` en MariaDB 12.3.2 desechable sin red, con el baseline
SQL sin datos operativos, una fila sintética de módulo TIC y la migración P04.
Los ocho casos TIC de persistencia y envío simulado pasaron (31 aserciones),
incluidos concurrencia, caída de Redmine y bloqueo del reintento incierto. El
primer ensayo había fallado porque el script detectó el servidor temporal del
arranque de MariaDB antes del servidor definitivo; al esperar su arranque completo,
la suite pasó. No se alteró la base operativa ni se enviaron tickets.

Pendiente para cierre operativo: identificar y observar los emisores autenticados
en el despliegue que realmente utiliza la organización y comprobar que ejecutan
esta versión. La ausencia de reservas actuales no demuestra que un emisor remoto
esté protegido por P04. Los tres archivos específicos de P04 (migración,
repositorio de intentos y comando de conciliación) siguen sin seguimiento en Git
al momento de esta revisión. El empaquetador acepta sus rutas, pero construye
exclusivamente desde un commit limpio; por tanto, un release generado ahora desde
Git no incluiría P04 hasta que estos cambios se incorporen a una revisión y se
desplieguen. No se hizo commit ni publicación en este paso.

## 19. Continuación P06 — identidad, histórico Mantención y actividad TIC

**Fecha: 17-09-2026.** Esta entrega amplía las consultas de la sección 13;
mantiene las reglas existentes y no aplica DDL ni reparaciones a la BD operativa.

### Cambios implementados

- `NovaIdentityLookupRepository` comprueba duplicados y selecciona una identidad
  en una sola consulta MariaDB. Reproduce la eliminación de bytes no ASCII y el
  orden de nombres; no introduce coincidencias por transliteración. El repositorio
  principal carga solo la ficha/integraciones elegidas. Cualquier duplicado sigue
  pasando por la resolución y reparación anteriores, incluso si no corresponde
  al identificador solicitado.
- `MantencionHistoryRepository` mueve a SQL el alcance por ID/nombre, filtros,
  deduplicación por fuente/ticket, conteo y ventana de página. Proyecta únicamente
  las columnas necesarias por filtro y conserva detalles completos del modal.
  Comparte el contexto de jornadas entre consultas y agrega pares de selector en
  SQL, manteniendo primera inserción y última etiqueta del recorrido anterior.
  Conserva casos como `101`/`0101`, NULL, fechas cero, caracteres de control,
  categorías sensibles a mayúsculas Unicode y prioridad de reportes/horas extra.
- `HistorySqlText` ejecuta en SQL la normalización del alfabeto compatible y usa
  las funciones legacy para textos excepcionales, incluido mojibake. No sustituye
  los permisos por una collation aproximada ni impone el antiguo límite de
  30.000 claves seleccionadas. Los IDs excepcionales se citan sin convertir
  BIGINT UNSIGNED a enteros PHP.
- `RedmineActivityRepository::search()` cuenta y pagina después del alcance SQL.
  Lee contextos de la página y no carga `linea`; preserva el selector de eventos
  con la misma comparación PHP. Para JSON con claves repetidas/escapadas, IDs no
  textuales o profundidad excesiva usa el decodificador anterior: MariaDB extrae
  la primera clave repetida y PHP conserva la última, diferencia que no debe
  ampliar acceso a registros ajenos.

Los drivers distintos de MySQL/MariaDB conservan sus lectores anteriores. No se
modificaron formularios, permisos, fechas de reportes, retención ni índices.

### Evidencia de compatibilidad y carga

Se ejecutaron **95 pruebas de integración / 1.462 verificaciones** en MariaDB
12.3.2 aislada, sin `.env` operativo ni llamadas a plataformas. Pasaron además
**84 pruebas de regresión / 348 verificaciones** de login, contraseña propia,
acceso a módulos, identidad/credenciales, usuarios TIC, históricos y plazos.
Los cambios posteriores de identidad y JSON se revalidaron en la suite de
integración completa. Sintaxis PHP, formato de archivos nuevos y `git diff
--check` sin errores.

La caracterización del histórico incluye las 57 combinaciones originales y los
casos excepcionales comparados también con la proyección previa de P06. Actividad
compara 98 combinaciones de usuario, alcance, filtro y página, incluyendo claves
JSON ambiguas y un ID superior a PHP_INT_MAX. Se verifican resultados completos,
orden, totales, opciones y aislamiento, no solo texto de consultas.

Muestra sintética del último ensayo (pico adicional de memoria PHP, bytes):

| Ruta | Referencia | Consulta nueva | Tiempo observado de referencia → nueva |
| --- | ---: | ---: | --- |
| Identidad, 2.000 usuarios | 1.379.376 | 68.688 | 4,36 → 6,22 ms |
| Histórico Mantención, proyección P06 previa, 1.585 reportes | 4.320.096 | 1.019.120 | 26,05 → 23,29 ms |
| Histórico Mantención, lector completo anterior a P06 | 49.654.720 | 1.019.120 | 51,25 → 23,29 ms |
| Actividad TIC, 366 registros | 2.552.288 | 227.424 | 2,50 → 2,05 ms |

Mantención carga 25 detalles frente a 1.598 filas completas del lector original;
la proyección anterior de P06 ya cargaba solo 25 detalles. La consulta nueva usa
11 consultas frente a 9 de esa proyección y 16 del lector original. Actividad
usa 6 frente a 1: se intercambia la lectura completa por consultas de alcance,
conteo, opciones y página. Estos números no prometen menos consultas en toda ruta.
Las métricas sintéticas se reproducen con las pruebas y se escriben en el temporal
del sistema, sin contenido operativo.

### Límites y trabajo restante

**P06 todavía no está cerrada.** La mejora demostrada es menor transferencia y
memoria con resultados equivalentes. La búsqueda de identidad aún recorre campos
normalizados y su tiempo local fue mayor que la proyección PHP previa; falta
medir y resolver el criterio de no regresión del camino frecuente con carga y
latencia representativas. No se declara una aceleración universal del login.

Los valores excepcionales, los pares de selectores y las referencias de jornadas
pueden crecer con los datos. El histórico de reportes TIC todavía carga/filtra el
conjunto completo y queda como siguiente pantalla a caracterizar y optimizar;
no se ha cambiado su comportamiento aprovechando esta entrega. Los restantes
listados necesitan el mismo análisis por pantalla antes de declarar DB-017 cerrado.
La evaluación de índices P08 deberá repetirse sobre las nuevas consultas finales;
su ensayo anterior no prueba el beneficio de un índice sobre este SQL nuevo.

La vuelta consiste en restaurar las consultas anteriores por ruta, sin restaurar
datos ni revertir P04. P01 operativo y TLS aplazado conservan su estado previo.

## 20. Continuación P06 — histórico de reportes TIC

**Fecha: 21-09-2026.** Se implementó la página SQL del histórico TIC, conservando
el método `history()` para sus otros consumidores y para compatibilidad.

### Comportamiento y compatibilidad

`RedmineHistoryRepository` selecciona archivados del módulo, resuelve una vez
cada asignado mediante la proyección central y aplica la misma función de alcance
que el lector anterior. Filtra, cuenta y pagina en SQL; hidrata el detalle de los
IDs visibles mediante el repositorio existente. No cambia permisos, estados,
vínculos de horas extra ni la fecha del reporte. Conserva opciones de filtros,
totales de archivados/horas extra, filtros combinados y páginas fuera de rango.

`HistoryFilter` conserva literalmente las reglas de fechas y transliteración de
la pantalla. No se igualan automáticamente mayúsculas acentuadas con minúsculas:
el orden anterior de `strtolower`/`iconv` sigue siendo autoritativo. Para valores
no ASCII la consulta evalúa el contenido excepcional con ese mismo normalizador.
Las fechas cero se seleccionan como texto antes de normalizarlas, evitando que
MariaDB agrupe fechas incompletas distintas en un mismo valor DATE.

Se detectó que el `SELECT * ORDER BY actualizado_at DESC` anterior carece de
desempate total. Con filas empatadas en fecha normalizada, creación y actualización,
una proyección más estrecha altera el orden implícito del filesort. En esos casos
la pantalla conserva el lector anterior completo. No se añadió un orden por ID
que cambiara la paginación existente. Las búsquedas excepcionales y ese fallback
siguen siendo límites de rendimiento explícitos.

El controlador consultaba `dashboardSummary()` solo para mostrar el aviso de
mantención, volviendo a cargar todos los activos y archivados. Ahora usa
`maintenanceStatus()`, que devuelve el mismo dato desde configuración. El resumen
completo conserva su contrato para los demás consumidores.

También se corrigió un defecto de la proyección `users(false)` de P06: consultaba
`usuarios_nova.email` pese a que S31 eliminó esa columna. El catch devolvía una
lista vacía, afectando usuarios y nombres. La columna se incluye únicamente si
existe, conservando instalaciones antiguas y sin volver a leer contraseñas/tokens.
El caso se reproduce y verifica contra la referencia real de esquema, no solo
contra la tabla mínima de los tests anteriores.

### Validación

`TicHistoryReadQueryTest` compara 132 combinaciones de alcance, filtros y páginas
con `history()` y el filtrado original de la vista. Prueba además fechas cero,
Unicode/control, cambios de nombre, NULL, IDs textuales con ceros, referencias
de otro módulo, múltiples jornadas, IDs BIGINT fuera del rango PHP, fallback de
empates y selección del camino SQL desde `nativeSectionData()`. El HTML completo
de la pantalla, incluido detalle/acciones, coincide usando ambas fuentes.

Validación final: **103 pruebas de integración / 1.841 verificaciones** en MariaDB
12.3.2 descartable y **84 pruebas de regresión / 348 verificaciones** de autenticación,
contraseña, acceso a módulos, usuarios, credenciales e históricos. Ambas suites
terminaron sin fallos ni pruebas riesgosas. El render Blade usa sesión y datos
sintéticos; no equivale a una comprobación de la instalación web activa. Sintaxis,
formato de los archivos nuevos y `git diff --check` sin errores.

Ensayo sintético con 1.120 reportes y descripciones grandes, sin empates ambiguos:

| Medida | Lector anterior | Página SQL |
| --- | ---: | ---: |
| Pico adicional de memoria PHP | 104.813.184 bytes | 1.839.344 bytes |
| Detalles cargados por la consulta de página | Conjunto completo | 25 |
| Consultas del recorrido medido | 31 | 31 |
| Duración local observada | 102,20 ms | 41,88 ms |

La reducción medida de memoria es aproximadamente 98 %. No es una garantía de
tiempo para cualquier filtro ni incluye la latencia de una instalación remota.
No se aplicaron índices, migraciones ni cambios de datos operativos.

### Identidad y pendientes

Se amplió el ensayo de identidad a seis lecturas alternadas por tamaño, con RUT
formateado, usuario CORE y nombres Unicode. Medianas locales en milisegundos:

| Usuarios sintéticos | Proyección PHP previa | Consulta SQL actual |
| --- | ---: | ---: |
| 50 | 0,460 | 0,890 |
| 500 | 2,715 | 2,650 |
| 2.000 | 6,905 | 8,185 |

No se demostró una mejora estable de latencia. Se conserva la implementación de
identidad de la entrega anterior, con su reducción de memoria y compatibilidad;
no se deja un ajuste experimental sin beneficio comprobado. El criterio de no
regresión de latencia sigue abierto, al igual que la caracterización de listados
no cubiertos y una eventual política explícita de desempate. Esta entrega avanza
P06 pero no declara cerrada toda la fase. P01 y la comprobación de emisores activos
P04 mantienen los pendientes de operación ya documentados.

## 21. Continuación P06 — latencia, estadísticas TIC y actividad Mantención

**Fecha: 21-09-2026.** Se mantiene la lógica de identidad, permisos, filtros,
fechas, jornadas y orden. No se aplican migraciones, índices, cambios de datos
operativos, reinicios de servicios ni acciones sobre Telegram/Redmine.

### Identidad

`NovaIdentityLookupRepository` conserva una sola consulta y la comparación
normalizada exacta. Un prefiltro LIKE descarta candidatos imposibles; nunca
autoriza por coincidencia aproximada. La detección global de duplicados cuenta
huellas SHA-256 binarias de tamaño fijo en lugar de agrupar expresiones de texto
largo. Una colisión de huella solo activaría el lector/conciliador anterior; no
selecciona una cuenta ni omite una identidad duplicada. Las identidades vacías
tienen una clave auxiliar por ID y no se concilian entre sí. No hay caché global
de unicidad, cuentas ni secretos.

Se comparan doce lecturas alternadas por tamaño con dos referencias: la proyección
PHP anterior y el SQL usado al empezar esta continuación. Medianas locales:

| Usuarios | PHP anterior | SQL anterior | SQL actual |
| --- | ---: | ---: | ---: |
| 50 | 0,350 ms | 0,790 ms | 0,480 ms |
| 500 | 2,030 ms | 2,190 ms | 1,195 ms |
| 2.000 | 6,755 ms | 7,675 ms | 3,465 ms |

El SQL actual mejora los tres tamaños frente al SQL previo. El pico adicional de
memoria del ensayo de 2.000 identidades es 53.232 bytes, frente a 1.379.376 de la
proyección PHP. **No se declara resuelto universalmente el criterio de latencia**:
con 50 usuarios la proyección PHP antigua conserva una ventaja de 0,13 ms en
este ensayo. Falta comprobar el recorrido completo y la latencia del despliegue.
SQL sigue recorriendo claves normalizadas; no es un lookup totalmente indexado.

### Estadísticas TIC

`RedmineReportRepository::statisticsRows()` lee los campos que utilizan los
gráficos y reutiliza la hidratación y agregación existentes. Los textos grandes
quedan fuera de la transferencia. Conserva catálogos heredados, fechas cero,
filtros, nombres y orden de etiquetas. Ante empates de creación/actualización,
esquema incompatible o reportes ya cargados por la instancia, mantiene el lector
anterior. No se cambia el contrato de las estadísticas remotas ni su conexión a
la pantalla.

Con 1.120 reportes sintéticos: pico adicional de memoria de **96.332.544 a
2.148.232 bytes**; duración observada de 45,272 a 21,604 ms. Sigue siendo una
agregación PHP sobre metadatos y el fallback por empates puede leer detalles.

### Actividad Mantención

`MantencionActivityRepository` aplica conteo, opciones y página sobre las claves
autorizadas en SQL. Los administradores no cargan actores antes de paginar. Para
usuarios restringidos lee primero `id/contexto` y conserva el decodificador y
las reglas del servicio. Solo si el actor depende del texto se lee `detalle`
antes de paginar. Los detalles finales y su redacción son los mismos de
`operationalEvent()`. No se modifica `actorMatches()` ni el borrado de eventos.

No se deja la variante que repetía la interpretación JSON en cada consulta:
regresaba la latencia con contextos grandes. La lectura por etapas conserva
duplicados/escapes JSON, números, arrays, profundidad excesiva, nombres derivados
del detalle, IDs con ceros, etiquetas numéricas y reglas de orden/selección PHP.

Ensayo con 1.180 eventos sintéticos:

| Alcance | Memoria anterior → actual | Duración anterior → actual | Consultas anterior → actual |
| --- | ---: | ---: | ---: |
| Administrador | 111.410.464 → 2.892.912 bytes | 157,080 → 6,193 ms | 1 → 3 |
| Usuario restringido | 111.410.520 → 20.662.272 bytes | 157,001 → 40,372 ms | 1 → 5 |

Los contextos, las claves autorizadas y los casos de actor inferido aún crecen
con los candidatos. La reducción no equivale a memoria constante ni a una nueva
columna indexada de actor. Los tiempos son evidencia local, no garantías remotas.

### Revisión de listados y pendientes concretos

| Pantalla/caso | Estado al terminar esta continuación |
| --- | --- |
| Históricos TIC/Mantención | Paginación/filtros SQL ya implementados; conservar y medir excepciones Unicode/fechas y empates. |
| Actividad TIC | Página SQL de la entrega previa, cubierta nuevamente por integración. |
| Actividad Mantención | Página y opciones SQL implementadas aquí; queda el coste de interpretar actores en contextos históricos. |
| Estadísticas TIC | Proyección reducida implementada; agregación PHP y fallback ante empates conservados. |
| Dashboards TIC/Mantención | Cargan conjuntos activos y detalles para filtros, contadores y acciones. Pendiente caracterizar selección masiva y modales antes de recortar consultas. |
| Horas extra TIC/Mantención | Cargan grupos, concilian por fecha y filtran por usuario/mes/año. Pendiente reducir lectura preservando deduplicación, totales y años disponibles. |
| Estadísticas Mantención | Combina reportes, mensajes y horas antes de filtrar. Pendiente proyección equivalente de sus tres fuentes. |
| Usuarios NOVA/TIC/Mantención | TIC ya excluye secretos en pantallas; filtros de estado/búsqueda y totales se calculan con el conjunto cargado. Pendiente caracterizar las demás proyecciones sin cambiar selección ni permisos. |
| Pendiente Manual / reporte rápido | Son formularios con selectores, no históricos paginables. Mantener catálogos y usuarios autorizados; no introducir un LIMIT arbitrario. |
| Auditoría NOVA | `recent()` ya limita y proyecta columnas en SQL; no requiere el mismo cambio de paginación de actividad. |

### Validación y vuelta

**110 pruebas de integración / 2.596 verificaciones**, todas aprobadas en MariaDB
12.3.2 descartable. Incluyen 520 combinaciones de alcance/filtros/páginas de
actividad Mantención, comparación contra el lector anterior, cargas grandes,
identidades largas/vacías, duplicados creados entre lecturas y proyección de
estadísticas. **101 pruebas de regresión / 409 verificaciones**, aprobadas para
autenticación, contraseña, acceso a módulos, usuarios, credenciales, históricos,
estadísticas y horas extra. Formato de archivos nuevos, sintaxis y diff sin errores.

Las pruebas usaron configuración aislada y datos sintéticos; no verifican la
instalación Coolify activa. La vuelta restaura estas consultas/lectores sin
revertir datos ni P04. P01 operativo, TLS aplazado y comprobación de emisores P04
conservan su estado. P06 avanza con mejoras medidas, pero permanece abierta por
los pendientes explícitos de esta sección.


## 22. P06 — Lectura de horas extra TIC/Mantención (2026-09-21)

### Cambios y compatibilidad

- TIC consulta en SQL únicamente los archivados cuyos IDs aparecen en los grupos
  del origen TIC. Conserva el módulo, el estado, la conversión entera de IDs del
  repositorio compartido y el orden de los vínculos. El histórico completo mantiene
  su lector habitual. No cambia la conciliación por fecha de inicio.
- Mantención construye una relación de reportes a grupos y recorre una sola vez
  los resultados SQL, transformando cada reporte una vez. Conserva el orden
  `fecha_reporte DESC, id DESC` dentro de cada grupo, los grupos con varias jornadas,
  los IDs públicos derivados de fuente y la precedencia de horarios al conciliar.
- Se mantienen las diferencias anteriores: TIC conserva grupos vacíos en su lector
  base y Mantención los omite; las reglas de módulo, asignado, fecha y hora siguen
  siendo las de cada módulo. No se modifican fechas ni escrituras, y no hay migración.

El filtrado por usuario sigue ocurriendo después de conciliar. Adelantarlo sin
más cambiaría qué horario prevalece cuando varios grupos de la misma fecha
aportan reportes de usuarios distintos. Esta entrega no redefine esa regla.

### Evidencia local

`HoursExtraReadQueryTest`: **6 pruebas / 79 verificaciones**. Compara estructuras
completas contra copias de los lectores previos, 42 combinaciones de usuario y
filtros TIC, conciliación por usuario Mantención, vínculos múltiples, fechas cero,
grupos sin reportes y BIGINT fuera del rango PHP. Los detalles de los reportes
siguen disponibles y las modificaciones de un grupo no afectan otra copia.

| Ensayo sintético | Pico adicional anterior → actual | Tiempo observado anterior → actual |
| --- | ---: | ---: |
| TIC: 1.036 reportes, 1.000 archivados extensos sin vínculos | 97.218.360 → 93.960 bytes | 53,900 → 3,997 ms |
| Mantención: 36 reportes y 74 grupos, con referencias repetidas | 7.420.624 → 1.257.976 bytes | 22,876 → 6,545 ms |

Estos ensayos usan MariaDB 12.3.2 descartable y datos sintéticos. Los tiempos son
orientativos, no umbrales; las pruebas exigen equivalencia y reducción de memoria.
La suite completa de integración pasa: **116 pruebas / 2.675 verificaciones**.
Las regresiones de autenticación, contraseña, permisos de módulo, horas extra,
históricos y envíos pasan: **71 pruebas / 243 verificaciones**. La base desechable
necesitó también la migración P04, ausente en su baseline, para ejecutar las pruebas
de envío; se aplicó solo allí. Sintaxis, formato de pruebas nuevas y diff verificados.

### Pendientes y reversión

P06 sigue abierta. Horas extra aún lee los detalles de todos los reportes
vinculados antes de filtrar usuario/mes/año; los grupos, pivotes y listas de IDs
crecen con ese conjunto. Falta separar metadatos de los detalles visibles,
preservando precedencia, deduplicación y años disponibles. Continúan los pendientes
de dashboards, estadísticas Mantención, proyecciones de usuarios y comprobación
de latencia en el despliegue descritos en la sección 21.

La reversión restaura estos dos lectores sin tocar datos ni esquema. No se
modificaron la BD operativa, los servicios, P01 ni P04 durante esta continuación.


## 23. P06 — Detalles de horas extra después de filtrar (2026-09-21)

### Implementación

Las pantallas TIC y Mantención ahora leen los metadatos de todos los grupos y
reportes vinculados para conciliar las jornadas con las reglas existentes.
El filtrado por usuario/mes/año, la precedencia de horarios, los años disponibles
y el orden se mantienen. Solo después se consultan los detalles necesarios.

TIC proyecta `id`, `asignado_a` y `fecha_inicio`, aplica la conciliación anterior
y carga por ID los reportes visibles. Los nombres y catálogos finales siguen
resolviéndose con el hidratador habitual. No modifica el lector completo de
`hoursExtra()` ni los consumidores de histórico/archivado.

Mantención proyecta ID de BD, ID público de fuente y asignado. La selección de
mes/año y el orden ascendente se trasladaron del controlador al servicio sin
cambiar las reglas. Para un ID público visible se recuperan todos los registros
que contribuyen a su combinación de campos no vacíos. Esto conserva los detalles
cuando dos registros comparten `fuente_id` y sus asignados individuales difieren.
Los encabezados y horarios se toman de la conciliación completa de metadatos,
no de una conciliación recortada que pudiera alterar la hora del día.

El controlador mantiene autorización, CSRF, bloqueo de mantenimiento, actualización
de horarios y elección del período después de editar. La lectura de pantalla se
hace una vez, después de ese flujo. El HTML y los cálculos EMACH no se modifican.
Ambos recorridos agrupan sus lecturas en una transacción. La consistencia ante
actualizaciones simultáneas se verificó con REPEATABLE READ de la instancia
MariaDB descartable; no se cambió el aislamiento ni la configuración operativa.

### Validación

`HoursExtraReadQueryTest`: **10 pruebas / 209 verificaciones**. Incluye 42
combinaciones de usuario/filtro TIC, 35 de Mantención, equivalencia de todos los
campos finales, años y totales, grupos vacíos, fechas cero, BIGINT, IDs públicos
`0`/vacíos/repetidos, campos vacíos y cambios de asignado/detalle entre las dos
lecturas. Una consulta posterior ve el cambio, sin caché global de resultados.

Carga sintética de 400 reportes vinculados adicionales, repartidos entre reportes
del usuario en otro período y reportes de otro usuario en el período consultado:

| Pantalla | Pico adicional anterior → actual | Tiempo observado anterior → actual |
| --- | ---: | ---: |
| TIC | 44.816.712 → 564.952 bytes | 30,687 → 6,056 ms |
| Mantención | 45.799.720 → 556.080 bytes | 23,694 → 7,858 ms |

Las comparaciones conservan exactamente la respuesta anterior. Los tiempos son
orientativos y locales; el criterio automático exige igualdad y menor memoria.
La suite completa pasa con **120 pruebas de integración / 2.805 verificaciones**
y **74 regresiones / 265 verificaciones** de autenticación, contraseña, acceso,
horas extra, tiempo estimado, históricos y envíos. Sintaxis PHP y formato de
pruebas revisados; diff sin errores. No se usaron servicios externos ni la BD operativa.

### Alcance restante

Se resuelve la carga anticipada de detalles pendiente en la sección 22 para las
pantallas de horas extra. Los metadatos, pivotes y claves siguen creciendo con el
conjunto vinculado; no es paginación de jornadas ni memoria constante. Mantención
puede leer contribuciones de otro período si comparten un ID público visible,
para conservar la combinación existente. Los otros consumidores que solicitan
los grupos completos continúan recibiéndolos completos.

P06 permanece abierta por dashboards, estadísticas Mantención, proyecciones de
usuarios, excepciones de otros lectores y medición en el despliegue. P01/P04 y
TLS aplazado conservan su estado. La reversión consiste en restaurar la lectura
completa de pantalla y el bloque de selección del controlador, sin revertir datos,
índices ni migraciones.

## 24. P06 — Estadísticas Mantención con lectura por etapas (2026-09-22)

### Implementación y compatibilidad

Las estadísticas con filtros consultan primero los campos que utiliza el
normalizador para fecha, categoría, unidad y asignado. Aplican las reglas PHP
anteriores y recuperan después los reportes completos de los IDs seleccionados.
`MantencionReportRepository` conserva los estados y el módulo de cada consulta;
`MantencionHoursExtraRepository` conserva el alcance de su lector, el orden de
los grupos y todas las referencias repetidas. Los IDs de la proyección no forman
parte de la respuesta final. Los BIGINT se mantienen como texto en la selección
de reportes, sin conversión entera ni límite de parámetros de sentencia preparada.

Se mantienen las tres fuentes en su orden: reportes archivados, mensajes activos
y horas extra. No se introduce deduplicación: los reportes presentes en varias
fuentes o jornadas siguen contribuyendo como antes. También se conserva la diferencia
de alcance del lector de horas extra, que no filtra por módulo del reporte.
La respuesta incluye todos los campos originales de los mensajes, no solo conteos.
Los listados y gráficos conservan etiquetas, orden, totales y detalle.

El controlador no cambia: mantiene autorización, filtro forzado del gestor,
validación CSRF y manejo de filtros. La selección usa los mismos normalizadores
CORE/manual, reparación de texto, fechas y comparación de usuario por ID o nombre.
Las lecturas filtradas comparten una transacción; la prueba de concurrencia se
realiza con REPEATABLE READ en MariaDB aislada. No se cambió el aislamiento operativo.

Sin filtros selectivos se conserva el recorrido completo anterior, evitando una
consulta de metadatos que no reduciría los detalles requeridos. Los consumidores
existentes de reportes y horas mantienen sus llamadas y respuesta por defecto.

### Validación y medición

`MantencionStatisticsReadQueryTest`: **6 pruebas / 194 verificaciones**, usando
la referencia MariaDB 12.3.2 y funciones reales sin iniciar el runtime legacy.
Compara las estructuras completas, salvo la etiqueta de hora de actualización,
en 23 combinaciones de filtros y casos adicionales de fechas ausentes/cero,
BIGINT, IDs públicos repetidos, CORE, Unicode, estados y vínculos entre fuentes.
Verifica GET, ausencia de carga de detalles cuando no hay coincidencias, lectura
sin filtros y modificación concurrente entre proyección y detalle.

Ensayo con 490 reportes sintéticos, incluidos 400 con textos extensos fuera del
rango consultado y referencias a tres jornadas:

| Medida | Lector anterior | Lectura por etapas |
| --- | ---: | ---: |
| Pico adicional de memoria | 51.208.632 bytes | 3.850.624 bytes |
| Tiempo observado | 51,605 ms | 43,861 ms |

El criterio automático exige igualdad de resultados y menor memoria; el tiempo
es orientativo, no una garantía de latencia del servidor. La suite completa de
integración termina con **126 pruebas / 2.999 verificaciones**, y las regresiones
con **78 pruebas / 306 verificaciones**. Incluyen estadísticas, filtros y vista,
autenticación, contraseña, permisos, horas extra, históricos y envíos. Sintaxis,
formato de las pruebas nuevas y diff verificados.

### Límites y pendientes

Los filtros y agregados siguen en PHP para conservar las reglas actuales. Los
metadatos crecen con los candidatos y los detalles con los resultados visibles;
un rango amplio puede necesitar casi todos los reportes y sumar consultas frente
al lector previo. No se agrega paginación a los listados de los gráficos.
Falta medir estos recorridos en el despliegue real.

Quedan dentro de P06 los dashboards, las proyecciones/listados pendientes de
usuarios, las excepciones documentadas de otros lectores y la validación en el
servidor. Esta entrega resuelve la carga de detalles previos al filtro de las
estadísticas Mantención. No modifica datos, índices, migraciones, servicios ni
el estado de P01/P04. La reversión restaura el recorrido de tres lecturas completas
sin requerir cambios en la base de datos.

## 25. P06 — Lectura de dashboards TIC/Mantención (2026-09-22)

### Implementación y compatibilidad

Los dashboards leen primero ID, estado y asignado para aplicar sus filtros
actuales en PHP. Solo después recuperan el contenido completo necesario para
la pantalla. No se modifica la lógica de autorización ni se introduce paginación.

- TIC conserva el alcance por ID/nombre, la visibilidad de reportes sin asignar,
  los estados equivalentes, los contadores de todo el alcance y el mapa de errores.
  Los detalles se consultan por los IDs visibles, conservando BIGINT como texto.
  Si existen fechas `creado_at` empatadas, usa el lector completo anterior para
  no cambiar su orden ambiguo. Una caché de reportes ya cargada también conserva
  el recorrido previo; la lectura parcial no ocupa esa caché completa.
- Mantención aplica la selección únicamente en GET/HEAD. Conserva todos los
  estados visibles para las pestañas y todos los procesados, incluso ajenos al
  usuario, para que la retención automática mantenga sus intentos de archivado.
  Usa el ID real de la base para seleccionar detalles, sin confundir registros
  con el mismo `fuente_id`. Los POST mantienen la lectura completa anterior.
  La ausencia del repositorio sigue produciendo una lista vacía.

La selección y la recuperación de detalles comparten una transacción de lectura.
La consistencia se probó bajo REPEATABLE READ en la instancia aislada; no se
modificó el aislamiento operativo. La retención TIC conserva su ejecución previa
y su debounce; la de Mantención se ejecuta después de cerrar la lectura y mantiene
sus transacciones de escritura por reporte. Se conservan selección masiva,
detalles de edición, orden y contadores. El indicador de archivado masivo añadido
anteriormente permanece sin cambios.

### Validación y medición

`DashboardReadQueryTest`: **8 pruebas / 184 verificaciones**. Compara respuestas
completas, 30 combinaciones TIC de usuario/estado, alcances Mantención por ID,
nombre, CORE y RUT, BIGINT, IDs públicos repetidos, caché cargada, fechas empatadas,
repositorio ausente y cambios concurrentes entre consultas. Ejecuta el algoritmo
de retención Mantención con un archivador simulado que registra cada intento y
provoca fallos, comprobando que la selección previa no altera su comportamiento.

Ensayo con 60 reportes base y 400 reportes extensos adicionales fuera del alcance
visible en cada módulo:

| Pantalla | Pico adicional anterior | Pico adicional nuevo | Tiempo local anterior → nuevo |
| --- | ---: | ---: | ---: |
| TIC | 37.517.640 bytes | 464.824 bytes | 23,450 → 4,356 ms |
| Mantención | 39.208.112 bytes | 1.123.376 bytes | 21,948 → 8,728 ms |

El criterio automático exige igualdad de resultados y menor memoria; los tiempos
son orientativos. No representan una medición del servidor operativo. La suite
completa de integración pasa con **134 pruebas / 3.183 verificaciones** y las
regresiones de autenticación, permisos, dashboards, contraseña, horas extra,
históricos, estadísticas y envíos con **101 pruebas / 410 verificaciones**.
Sintaxis de los cuatro archivos PHP modificados, formato de la prueba y diff
verificados. No se modificaron datos, índices, migraciones ni servicios operativos.

### Límites y siguiente trabajo

Los metadatos siguen creciendo con los reportes activos. Los usuarios que ven
casi todos los reportes obtienen menos beneficio y pueden ejecutar consultas
adicionales. Los empates TIC conservan la lectura completa por compatibilidad.
Los detalles de procesados requeridos por retención y los registros de errores
mantienen sus lectores existentes; no se certifica todo el coste del request.
Los POST Mantención tampoco se optimizan en esta entrega.

P06 continúa abierta por las proyecciones/listados de usuarios pendientes,
los costes y casos excepcionales documentados y la medición en el despliegue.
El siguiente bloque es revisar las proyecciones de usuarios que aún leen datos
innecesarios para listados y selectores, manteniendo sus permisos y orden.
P01/P04 conservan su estado. La reversión del cambio de dashboards restaura sus
lecturas completas anteriores y no requiere cambios en la base de datos.

## 26. P06 — Proyecciones de usuarios TIC y selectores Mantención (2026-09-22)

### Implementación y compatibilidad

TIC consulta primero los usuarios con acceso al proyecto y acota a sus IDs la
lectura de perfiles. Los permisos se consultan únicamente para esos perfiles,
en una sola consulta. Los lectores generales conservan su contrato por defecto.
No se cambian campos de respuesta, estados, roles, credenciales, orden de usuarios
ni decodificación de permisos. Se mantienen los fallbacks por perfiles/permisos
ausentes. Si los BIGINT alcanzan el límite entero PHP se conserva el recorrido
anterior, incluido su comportamiento de claves convertidas a entero. No se añade
caché de usuarios/permisos: los cambios se reflejan en la siguiente llamada.

`auth_central_users_for_mantencion` admite una proyección explícita sin secretos.
El modo completo sigue siendo el predeterminado. Dashboard/Pendiente Manual,
estadísticas y configuración solicitan la proyección: conservan identidad,
roles, estado, permisos, usuarios externos CORE/Nextcloud y todos los campos
de respuesta, dejando vacíos `api`, `password`, `core_pass_enc` y
`nextcloud_pass_enc`. En el recorrido ordinario no se consulta `password` ni
`valor_secreto` y no se descifran credenciales. Los permisos leen solo las columnas
que usan sus mapas. Las reglas de inclusión de administradores no cambian.

La consulta previa ordenaba por nombre/apellido sin desempatar por ID. Si existen
empates según la intercalación SQL, Mantención conserva la consulta completa y
vacía los secretos después de proyectar, evitando alterar ese orden. Esta excepción
mantiene su coste anterior y suma la comprobación del empate.

El listado administrativo Mantención permanece con su lectura completa: muestra
indicadores de credenciales y `handle_usuarios` puede migrar credenciales globales
Nextcloud incluso durante GET. Cambiarlo directamente a una proyección vacía
afectaría esos indicadores y esa migración. Login, búsqueda autenticada, guardado
e importación conservan sus llamadas actuales. NOVA administrativo también queda
pendiente: su lector `all()` combina identidades y puede persistir reparaciones.

### Validación y medición

`ProjectUserProjectionTest`: **7 pruebas / 173 verificaciones**, con esquema de
referencia en MariaDB aislada. Compara el listado TIC con una copia del método
anterior, en ambos modos de credenciales. Comprueba cambios de acceso/permisos,
perfiles ausentes, BIGINT, permisos restringidos al proyecto y ausencia de
consultas por usuario. En Mantención compara todos los campos no secretos,
selectores reales, estados, nombres externos, roles, login, nombres empatados,
columnas opcionales y módulo inexistente.

Ensayo con 12 usuarios iniciales y 300 adicionales: los adicionales pertenecen
a Mantención, tienen credenciales extensas y permisos TIC sin acceso a ese proyecto.

| Recorrido | Pico adicional anterior | Pico adicional nuevo | Tiempo local anterior → nuevo |
| --- | ---: | ---: | ---: |
| Listado TIC sin credenciales | 7.369.088 bytes | 60.160 bytes | 8,821 → 1,271 ms |
| Selector Mantención | 29.015.288 bytes | 838.520 bytes | 18,042 → 6,443 ms |

Se exige igualdad de resultados y menor memoria, no un umbral de tiempo. Estos
valores sintéticos no certifican la latencia del servidor ni todos los tamaños
de directorio. La suite completa de integración pasa: **141 pruebas / 3.356
verificaciones**. Sintaxis de los seis archivos de runtime, formato de pruebas
y diff verificados. No se alteraron datos, índices ni servicios operativos.

La regresión ampliada de usuarios, permisos, login, contraseña, accesos,
configuración, dashboards, credenciales, horas extra y envíos ejecutó 112 casos:
**110 pasaron, 1 omitido por falta de un usuario con rol personalizado y 1 falló**.
El fallo es `RedmineTicPermissionsTest::test_deleting_a_role_removes_it_from_relational_and_json`.
Se reprodujo también cargando copias temporales con los lectores anteriores a
esta entrega, sin revertir archivos del proyecto. Cuando el catálogo relacional
contiene solamente ese rol, su eliminación deja un mapa vacío y
`saveRolesToRelational` no elimina la fila. El resultado informa éxito, pero el
rol continúa presente. Es un defecto previo, no una regresión de estas lecturas;
queda registrado para una corrección funcional separada. La suite ampliada no
se declara completamente verde.

### Pendientes

P06 sigue abierta por la lectura administrativa de NOVA/Mantención, los costes
restantes de dashboards, los caminos excepcionales documentados y las mediciones
del despliegue. El siguiente bloque de rendimiento es separar la proyección del
listado administrativo de sus migraciones/reparaciones e indicadores, conservando
esos efectos y reglas. Debe abordarse también el defecto del último rol con su
prueba específica antes del cierre general del plan. P01/P04 no cambian de estado.

## 27. P06 — Listados administrativos NOVA/Mantención (2026-09-23)

### Implementación y compatibilidad

NOVA dispone de `allForAdministration()`, utilizado por Administración y por
su matriz de accesos en modo explícito de visualización. El recorrido ordinario
consulta identidad sin contraseña y presencia de secretos EMACH/Nextcloud, sin
transferir esos secretos. Conserva los campos externos, fechas, chat, roles y
estados. La proyección entrega las contraseñas vacías e indicadores booleanos;
la vista usa esos indicadores con fallback para el contrato completo anterior.
Los lectores generales `all()` y `matrix()` mantienen sus contratos por defecto.
Se conservan las dos lecturas administrativas independientes, evitando introducir
una caché que suprimiera reparaciones del segundo recorrido.

Las lecturas ordinarias comparten una transacción. Si el servicio de identidad
detecta claves duplicadas, existen nombres empatados según SQL, el driver no es
MySQL/MariaDB o falla la consulta optimizada, se cierra esa lectura y se invoca
`all()`. Su conciliación, bloqueos, guardado y rollback permanecen intactos.
Se conserva incluso la respuesta con RUT original durante una reparación que
persiste su versión canónica. La presencia de secretos usa los mismos seis
caracteres de `trim()` PHP; los tipos de integración conservan sus reglas de
normalización, mayúsculas y precedencia por ID.

Mantención usa la proyección administrativa únicamente en GET/HEAD de Usuarios.
Conserva roles, permisos, estados, nombres y orden. CORE/Nextcloud transfieren
indicadores en vez de sus secretos; las contraseñas NOVA no se consultan. Redmine
continúa leyendo/descifrando los tokens candidatos para mantener la selección
anterior y el indicador correcto en tokens cifrados vacíos, texto heredado o
valores no descifrables. Todos los secretos de la proyección devuelta quedan
vacíos. Los nombres empatados y los drivers/consultas no compatibles conservan
el lector completo antes de preparar esa respuesta.

La migración de credenciales globales Nextcloud mantiene su comprobación y
limpieza de configuración. Si encuentra usuario y secreto global por migrar,
recarga los usuarios completos antes de continuar con el flujo original. Así
no persiste indicadores ni campos vacíos de la proyección. Los POST conservan
la lectura completa anterior, su validación CSRF y las acciones existentes.
La consulta adicional se limita al recorrido excepcional de migración pendiente.

### Validación y medición

`AdministrativeUserReadTest`: **8 pruebas / 131 verificaciones** sobre MariaDB
aislada. Compara campos e indicadores con los lectores completos; los tests del
servicio Mantención ignoran únicamente el orden de las claves de cada fila,
manteniendo el orden de usuarios y tipos de valores. Incluye matriz de accesos,
credenciales vacías/cifradas/heredadas, tipos con espacios, empate de nombres,
reparación NOVA con escritura real y rollback, y cambios concurrentes dentro
de REPEATABLE READ sin modificar el aislamiento operativo.

La migración Mantención se compara con su recorrido anterior mediante el servicio
real, configuración sintética y un guardador que registra el payload completo:
credenciales personales presentes/ausentes, usuario no encontrado y sesión vacía.
Comprueba también que el GET ordinario no guarde proyecciones y que POST conserve
las credenciales reales y CSRF. No se ejecutó la migración contra datos operativos.

Ensayo con 8 usuarios iniciales y 250 adicionales con secretos extensos:

| Listado | Pico adicional anterior | Pico adicional nuevo | Tiempo local anterior → nuevo |
| --- | ---: | ---: | ---: |
| NOVA administrativo | 38.116.872 bytes | 1.190.952 bytes | 24,369 → 13,153 ms |
| Mantención administrativo | 37.829.080 bytes | 1.083.936 bytes | 23,531 → 17,686 ms |

Se exige equivalencia funcional y reducción de memoria; los tiempos son
orientativos, no una garantía de latencia del servidor. La suite de integración
pasa con **149 pruebas / 3.487 verificaciones**. La regresión ampliada ejecuta
122 casos: **120 pasan, 1 omitido por ausencia de un rol personalizado y 1 falla**
por el defecto previo de eliminación del último rol TIC, descrito en la sección 26.
No se declara completamente verde esa suite. Se verificaron sintaxis de los
seis archivos PHP, compilación/sintaxis de las dos vistas, formato de la prueba
nueva y diff. No se modificaron esquema ni datos operativos y no se subieron cambios.

### Pendientes

La optimización ordinaria de estos listados queda implementada. P06 permanece
abierta por los costes restantes de dashboards (retención, registros, acciones
masivas y resultados amplios), los caminos excepcionales conservadores y las
mediciones del despliegue. Los duplicados, empates, migraciones pendientes y
tokens Redmine siguen requiriendo lecturas completas o secretos cuando lo exige
la lógica anterior. El siguiente bloque es revisar las lecturas de registros
y retención de los dashboards. El defecto funcional del último rol continúa
pendiente antes del cierre general; P01/P04 mantienen su estado.

## 28. P06 — Retención y registros de dashboards (2026-09-23)

### Implementación y compatibilidad

Mantención incluye fechas en los metadatos del dashboard. GET/HEAD calcula un
umbral por request, lo usa para seleccionar reportes y lo reutiliza al archivar.
Se conservan todos los reportes visibles y los procesados ajenos ya vencidos;
los ajenos recientes o sin fecha interpretable no cargan detalles. El umbral se
fija antes de leer los reportes: los que vencen mientras se atiende ese request
se consideran en el siguiente. Los POST mantienen la lectura completa y su
cálculo de retención anterior. El lector sin umbral conserva su contrato previo.

La fecha se interpreta con `parse_message_timestamp`, después de la misma
proyección de fechas del repositorio. Se mantienen orden, permisos, IDs públicos
repetidos, umbral inclusivo, mantenimiento, fallos de archivado y transacciones
por reporte/horas extra. No se reemplaza el parser PHP por comparaciones de
fechas SQL ni se modifican fechas de reportes o jornadas.

TIC conserva exactamente la consulta por módulo/estados y la recorre con cursor.
La selección de retención comprueba primero la fecha, usando el mismo fallback
de la hidratación completa; solo prepara nombres/catálogos/detalles de vencidos.
Devuelve la selección completa antes de comenzar las transacciones de escritura.
El debounce de cinco minutos y la invalidación de cachés permanecen intactos.
El cursor PDO continúa siendo buffered: todavía transfiere todos los candidatos
SQL, pero evita retener objetos y reportes hidratados que no se archivan.

Los registros ya estaban limitados a 200 entradas TIC y 20 Mantención. Se
conservan esas ventanas y su orden, sin mover filtros antes del límite. TIC
deja de formatear errores posteriores al octavo de cada reporte, que el contrato
anterior descartaba después de prepararlos. Mantención conserva su lectura de
registros y su filtrado anterior.

### Validación y medición

`DashboardReadQueryTest` tiene ahora 13 pruebas: añade comparación de retención
TIC con el lector completo en UTC y Santiago; umbral exacto y un segundo después;
fallbacks de fechas, estados y módulo; intentos/fallos Mantención incluyendo
reportes ajenos; GET con umbral único y mantenimiento; ventana de 200 logs y ocho
errores; y carga sintética con 400 reportes recientes extensos.

| Retención | Pico adicional anterior | Pico adicional nuevo | Tiempo local anterior → nuevo |
| --- | ---: | ---: | ---: |
| TIC | 43.416.056 bytes | 21.805.328 bytes | 19,568 → 9,721 ms |
| Mantención | 47.935.088 bytes | 1.172.368 bytes | 36,613 → 8,662 ms |

Son ensayos locales orientativos, no una garantía de latencia del servidor.
La suite de integración pasa: **154 pruebas / 3.573 verificaciones**. Las
regresiones específicas de archivado automático, horas extra, permisos de ambos
dashboards y persistencia de envíos pasan: **40 pruebas / 129 verificaciones**.
Se comprueban archivado real, debounce y rollback con la BD descartable. Sintaxis
de cinco archivos PHP, formato de la prueba y `git diff --check` correctos.
El defecto previo del último rol TIC no se abordó ni se incluyó en esta selección
de regresiones; sigue pendiente y no se declara verde toda la suite del proyecto.

### Pendientes

El siguiente bloque son las acciones masivas y los resultados amplios de los
dashboards. Sigue pendiente reducir la transferencia de candidatos de retención
TIC conservando el parser y el orden anteriores, además de medir en el despliegue
y resolver los caminos excepcionales documentados. No se aplicaron cambios de
esquema ni escrituras en datos operativos, no se reiniciaron servicios y no se
subieron cambios. P01/P04 mantienen su estado.

## 29. P06 — Selección y autorización de acciones masivas (2026-09-23)

### Implementación y compatibilidad

Las cuatro acciones masivas del dashboard TIC (archivar, eliminar, enviar y
restablecer errores) solicitan explícitamente la proyección de autorización.
Consulta únicamente `id` y `asignado_a` de los IDs solicitados, con módulo y
condición de reporte activo iguales al lector anterior. Resuelve los nombres y
aplica el mismo filtro de permisos. La comprobación final PHP conserva los IDs
exactos, orden y duplicados de entrada, incluidas diferencias como `5`/`005`.
No se convierten BIGINT a enteros PHP ni se interpola texto sin citar mediante PDO.

La lectura comparte una transacción con la resolución de nombres. Si hay caché
completa previa o falla la proyección, se conserva el lector anterior. El lector
parcial no rellena la caché completa y el método general mantiene su contrato
por defecto. Las escrituras posteriores mantienen las consultas, reservas de
envío, bloqueos, transacciones y efectos de horas extra anteriores. La autorización
no se convierte en un bloqueo de reportes hasta la escritura.

Mantención crea una tabla PHP de IDs seleccionados y la consulta durante el
archivado, eliminación y restablecimiento, en lugar de recorrer todos los IDs
para cada reporte. Se conserva la comparación estricta: un ID entero no coincide
con una selección de cadenas, los ceros iniciales siguen siendo significativos,
y el archivado mantiene su exclusión previa de `0` al normalizar la selección.
Los IDs públicos repetidos, el orden de recorrido, los intentos fallidos y la
reindexación posterior conservan su comportamiento. No se agrupan transacciones
de archivado ni se altera la lectura completa del POST.

### Validación y medición

`MassActionSelectionTest`: **7 pruebas / 97 verificaciones**. Compara la selección
TIC con el lector completo, permisos, nombres, entradas repetidas, IDs alternativos,
BIGINT, caché, fallo de proyección y concurrencia. Compara las escrituras reales
de archivado, borrado y restablecimiento entre ambos recorridos. Mantención
compara archivado con el bucle anterior y ejecuta POST de eliminación y
restablecimiento con repositorios reales, CSRF simulado, permisos denegados y
reportes ajenos/no seleccionados. Las pruebas se ejecutan en MariaDB descartable.

| Ensayo local | Tiempo anterior → nuevo | Pico adicional anterior → nuevo |
| --- | ---: | ---: |
| Autorización TIC, 400 reportes extensos no seleccionados | 25,568 → 1,402 ms | 48.642.384 → 65.544 bytes |
| Selección Mantención, 6.000 IDs / 12.000 reportes | 104,302 → 1,104 ms | 1.968.000 → 2.623.416 bytes |

El ensayo Mantención simula el archivado y mide la selección en PHP; no representa
el tiempo total del lote con escrituras. Su tabla de IDs reduce CPU a cambio de
memoria proporcional a la selección. Los tiempos son orientativos, no garantías
del servidor operativo.

La primera ejecución completa detectó un fallo intermitente en la prueba previa
de nombres empatados de Administración Mantención. Se comprobó que el fallback
usa exactamente la consulta completa original, sin desempate por ID. La prueba
ahora compara SQL/bindings y todos los campos por ID, sin imponer un orden entre
empates no definido por esa consulta. No se cambió el lector ni la lógica de
Administración para resolver esa inestabilidad de prueba.

Validación final: **161 pruebas de integración / 3.672 verificaciones** y
**67 regresiones / 199 verificaciones** de acciones masivas, comportamiento base,
retención, horas extra, permisos y envíos, todas correctas. Sintaxis de cinco
archivos PHP, formato de las pruebas y revisión del diff correctos. El defecto
previo de eliminación del último rol TIC no se incluye en esta selección y sigue
pendiente; no se declara cerrada toda la suite ni toda la auditoría.

### Pendientes

Sigue revisar los resultados amplios y el coste de escrituras por reporte en
lotes grandes; el archivado masivo conserva sus transacciones individuales.
Mantención aún carga todos los mensajes en POST. También quedan la transferencia
de candidatos de retención TIC, los caminos excepcionales y las mediciones en
el despliegue. El siguiente bloque es la lectura de listados grandes y los POST
de dashboard, preservando retención, contadores y permisos. No se modificaron
datos/esquema operativos ni servicios y no se subieron cambios. P01/P04 conservan
su estado.

## 30. P06 — Lectura acotada en POST masivos Mantención (2026-09-23)

### Implementación y compatibilidad

`archive_selected`, `delete_selected` y `reset_errors` usan una proyección interna
de los mensajes. Todos mantienen ID público, origen, estado, asignación y fechas
para permisos, contadores y conciliación; los seleccionados y todos los procesados
conservan su reporte completo. La retención sigue ejecutándose antes de la acción,
con su umbral calculado en el punto anterior del POST, incluso sobre reportes ajenos.
No se omiten procesados recientes porque podrían vencer durante esa lectura.

La selección y sus detalles comparten una transacción de lectura. Se unen por el
ID real de la BD, preservando cada repetición de ID público y el orden fecha/ID.
Un fallo o una selección incompleta de detalles vuelve al lector completo.
Cuando todos los mensajes necesitan detalles se conserva directamente la lectura
completa. Los IDs técnicos de la proyección se retiran antes de entregar los datos
al servicio; las escrituras solo reciben reportes completos.

La autorización, CSRF, respuestas AJAX, mensajes, contadores, transacciones por
reporte, horas extra y éxitos parciales mantienen el flujo anterior. El opt-in se
limita a esas tres acciones; envío, edición individual, hora extra, importaciones,
peticiones con formato de IDs no estándar y lectores generales siguen completos.
No se cambió paginación, visualización ni lógica de permisos.

### Validación

`MantencionBulkReadQueryTest`: **6 pruebas / 213 verificaciones**. Compara campos,
orden, alcances y contadores; selección vacía/completa; IDs públicos repetidos y
BIGINT; fallos de consulta; concurrencia; y los tres POST reales con ambas lecturas.
Verifica estados, jornadas y vínculos con retención activa/desactivada, permiso
permitido/denegado y fallos parciales provocados por trigger. Solo las secuencias
autoincrementales de las jornadas, que avanzan al hacer rollback entre ensayos,
se comparan mediante la identidad usuario/fecha.

En un ensayo con 400 reportes pendientes extensos no seleccionados, el pico
adicional de lectura baja de **49.671.016 a 1.126.528 bytes** y el tiempo local
de **40,025 a 6,293 ms**. Son métricas de lectura sintética, no una garantía del
tiempo total de archivado ni del rendimiento remoto.

La suite completa detectó la misma inestabilidad de nombres empatados de la
sección 29 en `ProjectUserProjectionTest`. Se ajustó esa comprobación para exigir
SQL/bindings idénticos al lector completo, descifrado por fallback y todos los
campos por ID, sin un desempate no definido por SQL. El runtime de usuarios no
se modificó. Validación final: **167 pruebas de integración / 3.887 verificaciones**
y **43 regresiones / 132 verificaciones**, correctas; sintaxis, formato de pruebas
y revisión del diff correctos. El defecto previo del último rol TIC sigue pendiente
y no forma parte de las regresiones seleccionadas de este bloque.

### Pendientes

P06 continúa abierta por los listados grandes, lecturas completas de los otros
POST, el coste de escrituras por reporte en lotes, transferencia de candidatos de
retención TIC, caminos excepcionales conservadores y medición en el despliegue.
La mejora de estas tres lecturas no reduce el número de transacciones de escritura.
El siguiente bloque concreto es revisar las lecturas de edición individual y
hora extra del dashboard, conservando autorización, retención y respuestas.
No se modificaron esquema/datos operativos ni servicios y no se subieron cambios.

## 31. P06 — Lecturas individuales de dashboard (2026-09-23)

### Implementación y compatibilidad

La autorización individual TIC reutiliza la consulta acotada de alcance de las
acciones masivas para un único ID. Conserva módulo, estados activos, asignación,
resolución de nombres, permisos y la comprobación PHP del ID exacto; por ello
`5` y `005` mantienen su diferencia. Si ya existe caché completa o falla la
proyección, conserva el lector anterior.

Los POST individuales `update`, `delete` y `toggle_hora_extra` de Mantención usan
la misma proyección interna de los POST masivos. El reporte solicitado se carga
completo y los reportes procesados también conservan todos sus campos porque la
retención se ejecuta antes de la acción. Los demás candidatos mantienen los datos
necesarios para alcance, fechas, contadores y conciliación. El endpoint nativo
independiente de hora extra, que no ejecuta retención, carga completo solo el
reporte solicitado y usa metadatos para los demás candidatos.

No se cambiaron validación CSRF, permisos, orden, umbral de retención, respuestas,
registros, jornadas ni escrituras. La unión sigue usando el ID interno de BD para
preservar IDs públicos repetidos. Una selección incompleta, excepción o caso en
que todos requieren detalle vuelve al lector completo.

### Validación

`MantencionBulkReadQueryTest` conserva sus seis pruebas y ahora compara seis POST
reales: archivado, borrado masivo, restablecimiento, edición individual, borrado
individual y cambio de hora extra. Ejecuta ambos lectores con retención activa o
desactivada y permisos permitidos o denegados; compara respuestas, reportes,
jornadas, vínculos y registros. También comprueba que el modo sin procesados
completos entregue el reporte elegido sin transportar el detalle extenso de los
demás.

`MassActionSelectionTest` extiende la equivalencia TIC a la autorización de un
reporte individual, incluidos IDs con ceros iniciales, reportes ocultos, módulos
ajenos, BIGINT y ausentes. Las regresiones seleccionadas de operaciones
individuales, comportamiento base, horas extra y permisos pasan: **51 pruebas /
198 verificaciones**. La suite de integración completa pasa: **167 pruebas /
3.977 verificaciones**. Sintaxis y revisión del diff son correctas. El defecto
previo al eliminar el último rol TIC no forma parte de estas regresiones y sigue
pendiente.

### Pendientes

P06 continúa abierta por los demás POST que aún requieren lecturas completas,
los resultados visibles muy amplios, el coste de las escrituras por reporte en
lotes, la transferencia SQL de candidatos de retención TIC, los fallbacks
conservadores y las mediciones en el despliegue. El siguiente bloque revisará
`process_selected` y los flujos de importación/configuración, separando los casos
que realmente necesitan todo el conjunto. No se modificaron datos ni esquema
operativos, no se reiniciaron servicios y no se subieron cambios.

## 32. P06 — Lectura acotada antes del envío Mantención (2026-09-23)

`process_selected` se incorporó a la proyección usada por las acciones masivas.
Cada reporte seleccionado conserva todos sus campos para construir el ticket de
Redmine, reservar el intento, persistir su resultado y registrar horas extra. Los
procesados también permanecen completos para que la retención previa mantenga su
comportamiento; los demás candidatos solo aportan identidad, alcance, estado y
fechas. La verificación de disponibilidad y los límites de conexión/envío no se
modificaron.

La prueba de integración compara el POST completo con el lector anterior para
retención activa/inactiva y permiso permitido/denegado. La plataforma sintética
carece deliberadamente de URL Redmine, por lo que ambos recorridos se detienen en
la misma validación previa y no hacen solicitudes externas. Compara redirección,
mensaje, auditoría, CSRF y estado de todas las tablas. `MantencionBulkReadQueryTest`
pasa con **6 pruebas / 314 verificaciones**. La suite de integración completa
pasa con **167 pruebas / 3.994 verificaciones**.

Las acciones CORE conservan el lector completo: una validación correcta sin TOTP
puede importar y reconciliar inmediatamente cualquier reporte existente; el flujo
con TOTP hace lo mismo en la petición siguiente. Reducir esa lectura exige separar
la validación remota de la reconciliación y no se realizará sin una equivalencia
específica. P06 sigue abierta por listados amplios, coste de escrituras por reporte,
transferencia de retención TIC, fallbacks y medición desplegada. No se modificaron
datos/esquema operativos ni servicios y no se subieron cambios.

## 33. P06 — Desvinculación agrupada en borrados masivos (2026-09-23)

### Implementación y compatibilidad

TIC y Mantención ya eliminaban las filas de reporte seleccionadas mediante una
consulta de lote, pero recorrían cada ID para quitar sus vínculos de horas extra.
Cada vuelta repetía comprobaciones de esquema, lecturas, bloqueos y eliminación.
`HorasExtraRepository::detachReportes()` agrupa esos pasos por origen y conjunto
de IDs. Ambos repositorios de reportes lo usan dentro de la misma transacción que
bloquea y elimina los reportes.

Se mantiene el orden de bloqueo: reportes, jornadas por ID ascendente y vínculos.
Los vínculos se vuelven a leer después de esperar los bloqueos. Solo se eliminan
los seleccionados del origen `tic` o `mantencion`; los del otro módulo, reportes
no seleccionados y jornadas históricas vacías no relacionadas permanecen. Cada
jornada afectada se elimina únicamente si ya no tiene vínculos. Una excepción en
la limpieza o en el DELETE de reportes revierte conjuntamente vínculos, jornadas
y reportes, igual que antes.

### Validación y medición

`HoursExtraPersistenceTest` añade una comparación real entre el recorrido anterior
y el agrupado con 40 reportes TIC que comparten jornada. Ambos producen exactamente
el mismo estado de las cuatro tablas y preservan vínculos no seleccionados de TIC
y Mantención. El recorrido anterior ejecuta **362 consultas** y el nuevo **11**
en la instancia MariaDB local descartable. Esta medición representa el caso de
una jornada compartida y no garantiza el tiempo del servidor desplegado.

Las pruebas de persistencia pasan: **17 pruebas / 107 verificaciones**. Las
regresiones de operaciones masivas, comportamiento base, horas extra e individuales
TIC pasan: **48 pruebas / 139 verificaciones**. La suite de integración completa
pasa: **168 pruebas / 4.008 verificaciones**. Sintaxis y formato de los archivos
nuevos/modificados del bloque son correctos; `git diff --check` no presenta errores.

### Pendientes

P06 todavía debe revisar el coste por reporte de archivado y restablecimiento de
errores, donde la atomicidad y los éxitos parciales actuales impiden convertir el
flujo directamente en una única escritura. También quedan listados amplios,
transferencia SQL de candidatos de retención TIC, fallbacks conservadores y
medición en el despliegue. No se modificaron datos/esquema operativos, no se
reiniciaron servicios y no se subieron cambios.

## 34. P06 — Archivado y restablecimiento masivo Mantención (2026-09-23)

### Implementación y compatibilidad

El archivado de cada reporte ahora actualiza únicamente `estado` y
`actualizado_at`, que son las dos columnas que cambiaba el recorrido genérico.
Mantiene la búsqueda por módulo, fuente e ID, el bloqueo de la fila y la
comparación del estado leído con el actual. El servicio conserva una transacción
por reporte junto con la creación de horas extra; por tanto, un fallo sigue
revirtiendo solo ese reporte y permite que el lote continúe.

Para el restablecimiento de errores, `syncMessages()` reutiliza los IDs de
categoría ya preparados y `MantencionReportRepository` comprueba la existencia
de cada columna una sola vez durante la vida de esa instancia. Se siguen
actualizando únicamente los campos modificados, con la misma validación de
concurrencia por reporte. Los nombres anteriores de categoría que no estén en
la selección se resuelven al utilizarlos. No se agrupan escrituras ni se cambia
la política de éxitos parciales.

### Validación y medición

`MantencionPersistenceTest` compara el archivado nuevo con el `updateMessage()`
anterior para estados pendiente, procesado, error y archivado; verifica la fila
completa, ausencia de cambios ante un estado concurrente y número de consultas.
En la BD MariaDB descartable, los estados que requieren escritura bajan de
**35 a 5 consultas** por reporte; el estado ya archivado, de **34 a 4**.

Una comparación con 30 reportes en error ejecuta el recorrido individual y el
lote `syncMessages()` sobre los mismos datos, separando ambos con rollback. Las
filas finales coinciden y las consultas bajan de **1.050 a 93**. Son mediciones
locales de esos datos sintéticos, no una garantía de tiempo en producción.
`MantencionBulkReadQueryTest` conserva la comprobación de POST reales con
retención, permisos y fallos parciales provocados por trigger. La suite completa
de integración pasa: **170 pruebas / 4.095 verificaciones**.

### Pendientes

P06 sigue abierta por listados visibles muy amplios, transferencia SQL de los
candidatos de retención TIC, fallbacks conservadores y medición con datos del
servidor. Las escrituras de archivado siguen teniendo una transacción por
reporte para preservar la semántica actual. No se modificaron datos ni esquema
operativos, no se reiniciaron servicios y no se subieron cambios.

## 35. P06 — Contadores y logs de dashboards amplios (2026-09-23)

### Implementación y compatibilidad

Mantención calcula los tres contadores visibles en una sola pasada por los
reportes y deja de crear tres arreglos filtrados que la vista solo contaba. Usa
el mismo comparador de estados del servicio actual. TIC deja de calcular dos
veces el mismo resumen del alcance; las claves y valores devueltos permanecen
iguales.

El botón «Log» de Mantención solo aparece para reportes visibles con estado
`error`. El controlador entrega esos IDs al lector, que consulta `mantencion_log`
por `canal` e ID en grupos de hasta 500. Conserva el orden `id` de los registros
para cada reporte y la regla anterior de mostrar el último log asociado al ID
público. Vuelve a comprobar en PHP el ID exacto para evitar coincidencias extras
por la intercalación SQL. Si la consulta filtrada falla, usa el lector completo
anterior; cuando no hay errores visibles, no consulta la tabla. El método sin
IDs conserva su contrato completo para otros consumidores.

### Validación y medición

`DashboardReadQueryTest` añade una comparación del mapa de logs con el lector
anterior. Incluye registros repetidos, IDs con distinta capitalización, más de
200 logs extensos ocultos, consulta vacía y fallo sintético de la consulta
filtrada. El texto visible coincide exactamente. Con 201 logs ocultos, el pico
adicional de lectura en la BD MariaDB descartable bajó de **8.040.096 a 51.400
bytes**; es una medición local, no una garantía del servidor. Las pruebas previas
comparan el resumen completo TIC con el lector anterior. La suite de integración
completa pasa: **171 pruebas / 4.112 verificaciones**.

### Pendientes

Cuando todos los reportes son visibles, la vista actual todavía necesita sus
detalles completos para los modales de edición y el HTML crece con cada fila.
Reducir ese coste requiere cargar detalles bajo demanda o paginar, conservando
la selección y las acciones masivas entre páginas; se tratará como un bloque de
interfaz específico. También quedan la transferencia de retención TIC, los
fallbacks de otros listados y la medición desplegada. No se modificaron datos ni
esquema operativos, no se reiniciaron servicios y no se subieron cambios.

## 36. P06 — Preselección SQL de retención TIC (2026-09-23)

El lector de retención TIC excluye en SQL los reportes procesados claramente
recientes antes de abrir el cursor. Conserva el orden y el filtro original de
módulo/estado. Para `procesado_at` (DATETIME) permite un día adicional a partir
del límite calculado en la zona PHP; para el fallback de `actualizado_at`
(TIMESTAMP) permite tres días por una posible diferencia con la zona de la
sesión SQL. Esos márgenes solo agregan falsos candidatos: PHP conserva la
comparación exacta, la fecha nula/cero y el archivado individual anterior. No
se cambian esquema, índices, debounce ni transacciones de escritura.

`DashboardReadQueryTest` compara la respuesta completa, orden y límite inclusivo
con el lector previo en UTC, Santiago y las zonas extremas UTC+14/UTC-12.
Verifica que la consulta aplique ambos límites SQL. Con 400 reportes extensos
procesados después del límite, el pico adicional local bajó de **43.416.056 a
448.776 bytes** y el tiempo observado de **20,63 a 2,91 ms** en MariaDB
descartable. Son datos sintéticos y no una garantía de producción. La suite de
integración pasa: **171 pruebas / 4.129 verificaciones**.

Quedan los listados visibles muy amplios y sus detalles HTML, revisar fallbacks
conservadores de otros recorridos y medir en el servidor. No se tocó la BD
operativa ni se subieron cambios.

## 37. P06 — Texto del modal TIC bajo demanda (2026-09-23)

El dashboard TIC deja de transferir `mensaje` y `descripcion` en la consulta de
los reportes visibles y de duplicarlos en atributos HTML de cada botón. El
resto de campos, orden, contadores, logs y acciones masivas permanece igual.
Al abrir «Detalle / Editar», una lectura puntual devuelve los textos tras
comprobar `mensajes_acceso`, `reportes_editar` y el alcance exacto del ID.
Mientras espera, «Guardar cambios» está deshabilitado; si la lectura falla,
se muestra un error y el formulario sigue bloqueado. La respuesta no se cachea.
El contrato ordinario de `dashboardData()` conserva sus reportes completos;
solo la vista nativa usa la proyección ligera.

Con 200 reportes visibles de texto largo en MariaDB descartable, el pico
adicional de la lectura bajó de **40.116.488 a 915.376 bytes**. Es una prueba
sintética, no una estimación de memoria del navegador ni una garantía del
servidor. La suite de integración pasó: **173 pruebas / 4.161 verificaciones**.
La sintaxis JS y el comportamiento de éxito/error del modal se comprobaron con
una simulación del script real. Playwright no estaba instalado, por lo que la
verificación visual en Chromium queda pendiente.

Falta aplicar el mismo análisis al dashboard Mantención, revisar los fallbacks
y medir el despliegue. No se modificó la BD operativa ni se subieron cambios.

## 38. P06 — Vista previa Mantención bajo demanda (2026-09-23)

El dashboard Mantención conserva todas las filas, filtros, selección, permisos y
acciones, pero deja de imprimir `descripcion`, `preview_rows` y
`preview_columns` dentro del botón de cada reporte. Un GET puntual prepara esos
datos cuando se abre el modal. Cada botón lleva el ID interno de la fila; el
servicio comprueba ese ID contra los candidatos visibles para el usuario y
solo entonces lee el reporte completo. Esto evita confundir dos filas con el
mismo `fuente_id`. La URL exige `mensajes_acceso` y devuelve
`Cache-Control: private, no-store`. Mientras carga, la vista previa, la
descripción y «Guardar cambios» están bloqueados; una falla deja el formulario
sin posibilidad de guardado. Los usuarios sin permiso de edición conservan la
lectura del detalle.

Una prueba con IDs BIGINT y el mismo ID público valida alcance, orden y detalle
por usuario. El script real del modal pasó una simulación de éxito/error, y la
sintaxis JS/PHP pasó. La suite de integración completa pasó: **174 pruebas /
4.287 verificaciones**. Playwright no está instalado para una comprobación
visual en Chromium.

Este cambio reduce HTML y trabajo de generación de vista previa por fila. La
consulta inicial Mantención todavía lee las descripciones, porque también
abastece la retención; separar la lectura visible ligera de los candidatos de
archivado es el siguiente bloque de P06. Quedan además los fallbacks de otros
listados y la medición en el servidor. No se tocó la BD operativa ni se subieron
cambios.

## 39. P06 — Lectura SQL ligera del dashboard Mantención (2026-09-23)

El GET del dashboard separa la misma selección de candidatos: carga completos
los reportes procesados vencidos para la retención y lee sin `descripcion` los
visibles que no necesitan archivado. Conserva la consulta de metadatos, el
orden por fecha/ID interno, el alcance y la selección por ID interno; no usa
`fuente_id` como clave de unión porque puede repetirse. El servicio reconstruye
la secuencia original y, si faltan filas o falla la proyección, vuelve al lector
completo. La retención recibe los mismos reportes y campos; el resultado visible
siempre deja la descripción para la carga puntual del modal. Los POST y otros
consumidores continúan usando el lector completo.

`DashboardReadQueryTest` compara todas las claves visibles y los intentos de
archivado con el recorrido anterior, incluso en mantenimiento. La prueba con
300 reportes visibles de texto largo y dos IDs BIGINT con `fuente_id`
repetido verifica el SQL y midió un pico adicional de **30.247.856 a
2.379.488 bytes** en MariaDB descartable. Las cifras son sintéticas y no predicen el tiempo del servidor.
La suite de integración pasó: **175 pruebas / 4.308 verificaciones**.

Quedan revisar fallbacks conservadores de otros listados, validar el modal en
navegador real y medir latencia/carga en el despliegue. No se modificó la BD
operativa ni se subieron cambios.


## 40. P06 — Fallback del detalle del dashboard TIC (2026-09-24)

La selección ligera TIC ya conocía los reportes visibles, pero el lector de
sus detalles podía capturar un error SQL y devolver `[]`. En ese caso el GET
mostraba una tabla vacía con contadores de reportes existentes. Ahora compara
la cantidad de detalles con la selección visible y, si no coincide, vuelve al
lector completo existente. El recorrido normal, el alcance, los estados, el
orden y los permisos no cambian. Mantención ya hacía esta comprobación.

Una prueba provoca un fallo solo en la consulta de detalles, después de la
selección, y exige la respuesta completa anterior. Pasaron los 19 casos de
`DashboardReadQueryTest` y la suite de integración completa: **176 pruebas /
4.324 verificaciones** en MariaDB descartable. Quedan la revisión de otros
fallbacks, los listados con miles de filas visibles, la validación visual del
modal en navegador real y las mediciones desplegadas. No se tocó la BD
operativa ni se subieron cambios.

## 41. P06 — Fallback de estadísticas y validación visual (2026-09-24)

La lectura filtrada de estadísticas Mantención comparaba candidatos ligeros y
filas completas, pero un fallo capturado por el repositorio en la segunda
consulta podía hacer desaparecer resultados. Ahora, si la cantidad recuperada
no coincide o la lectura por etapas lanza una excepción, usa el lector completo
anterior con los mismos filtros. La prueba provoca un fallo sintético solo en
la consulta de detalles y compara todos los resultados con el recorrido previo.

Los dos modales de detalle bajo demanda se validaron en Chromium con el script
real de las vistas y respuestas sintéticas: carga, éxito, error y respuesta
obsoleta después de abrir otro reporte. También pasó la prueba visual de
archivado masivo en TIC/Mantención con éxito, fallos de red/HTTP/JSON, doble
envío y estado de regreso. No se enviaron peticiones reales. La suite de
integración pasó: **177 pruebas / 4.329 verificaciones**.

P06 queda lista a nivel de código local y pruebas aisladas. Se mantiene la
selección masiva original sobre todos los reportes mostrados: introducir
paginación sin conservar selección entre páginas y el significado de
«Seleccionar todos» cambiaría el comportamiento. El volumen de HTML/DOM de
miles de reportes visibles se debe medir en el despliegue antes de decidir esa
intervención. También falta medir tiempo, memoria y tamaño de respuesta con
datos reales del servidor después de subir los cambios. Estas son validaciones
de despliegue, no verificaciones realizadas aquí; no se declara P06 validada en
producción. No se tocó la BD operativa ni se subieron cambios.

## 42. P06 — Cierre del desarrollo local y criterio de despliegue (2026-09-24)

Se completó la revisión de fallbacks de los lectores SQL de histórico. TIC
convierte una excepción de la página SQL en `null`, que ya activa su histórico
completo; Mantención vuelve a su lector compatible de filtrado/página. Dos
pruebas provocan fallos solo en la proyección optimizada y comparan el
resultado con el recorrido anterior. La suite de integración pasó:
**179 pruebas / 4.340 verificaciones**. Las pruebas de Chromium de modal y
archivado masivo también pasaron.

La consulta de conteo, de solo lectura, a la BD configurada en este entorno
mostró 4 reportes TIC activos (2 pendientes, 2 procesados) y 0 de Mantención;
los históricos tenían 996 y 1.189 archivados respectivamente. La ruta HTTP
local respondió, pero los dashboards requieren sesión. No se accedió a ellos
como usuario ni se midió su tiempo de respuesta autenticado. Con este volumen
activo no hay evidencia de un problema actual de miles de filas visibles; se
conserva el significado vigente de selección y acciones masivas, sin introducir
paginación especulativa. Si el despliegue presenta colas activas mucho mayores,
la paginación tendrá que conservar selección entre páginas y la acción sobre
todos los resultados filtrados.

**Estado P06:** desarrollo local listo para subir, sin cambios pendientes de
código conocidos para los volúmenes observados. La aceptación operativa debe
medir, después del despliegue y con una sesión autorizada, tiempo de respuesta,
tamaño transferido y uso de memoria de los dashboards TIC/Mantención; comprobar
filtros, selección masiva y modales con datos del servidor. Un resultado lento
con miles de activos reabre la optimización de HTML/DOM. Esta validación no se
sustituye por las pruebas sintéticas. Los cambios no se subieron en esta etapa.
