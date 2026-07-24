# PROD-05 — Smoke tests funcionales

**Reglas:** ejecutar solo en Staging autorizado, con cuentas/fixtures sintéticos y servicios externos sandbox o deshabilitados. No guardar secretos ni datos personales en la evidencia. Un crítico fallido implica NO-GO o rollback.

## Críticas

| ID | Objetivo | Resultado esperado | Evidencia requerida |
|---|---|---|---|
| C01 | HTTPS y FQDN | certificado/cadena válidos; HTTP redirige; Host inválido rechazado | salida TLS/HTTP redactada |
| C02 | Raíz web segura | DocumentRoot es `public/`; `.env`, `.git`, manifests privados, dumps, logs y backups responden 403/404 | códigos HTTP y config redactada |
| C03 | Login válido | usuario sintético activo inicia sesión y llega a NOVA | hora, usuario sintético, status/captura sin cookie |
| C04 | Login inválido/bloqueado | credenciales inválidas y usuario bloqueado son rechazados sin filtrar detalles | status/mensaje redactado y audit event |
| C05 | Logout/CSRF | logout POST invalida sesión; GET no cierra sesión; mutaciones sin CSRF fallan | secuencia HTTP sin tokens |
| C06 | Autorización server-side | usuario sin permiso recibe 403 al acceder directamente; admin autorizado entra | matriz usuario sintético/ruta/status |
| C07 | Home y todos los módulos registrados | NOVA Home, Redmine TIC, Redmine Mantención, EMACH, Telegram, Procedimientos, Horas Extra, Mis integraciones y Administración respetan autenticación/autorización y cargan sin 500 | matriz módulo/ruta/rol/status, captura y revisión de consola |
| C08 | Migraciones | 0 pendientes; schema, FK e índices esperados | `migrate:status` y controles DBA |
| C09 | Integridad de datos | conteos críticos, FK, huérfanos, duplicados y estados coinciden con baseline | consulta/acta DBA redactada |
| C10 | Secretos y errores | HTML/JSON/logs no revelan secretos, paths ni stack traces; debug desactivado | muestreo redactado y configuración booleana |
| C11 | Persistencia | lectura de documento/registro sintético restaurado funciona y no altera datos reales | ID sintético y hash/resultado |
| C12 | Health/readiness | app, DB y filesystem pasan sin exponer detalles sensibles | respuesta redactada/status |
| C13 | Rollback | release anterior vuelve, caches cargan y smoke crítico pasa dentro del umbral | tiempo, enlace/releases, resultados C01–C12 aplicables |

## Importantes

| ID | Objetivo | Resultado esperado | Evidencia requerida |
|---|---|---|---|
| I01 | Cookies/headers | Secure, HttpOnly, SameSite y headers de seguridad aprobados | headers sin valores de sesión |
| I02 | Assets | manifest y CSS/JS responden 200; sin sourcemaps ni 404 | listado de requests/consola |
| I03 | Sesión | extensión requiere autenticación/password y conserva throttling | status y tiempos redactados |
| I04 | Reportes TIC | listado/filtros/operación autorizada sobre fixture funcionan | fixture, antes/después y auditoría |
| I05 | Mantención | listado y operación autorizada sobre fixture funcionan | fixture y evidencia redactada |
| I06 | Horas extra | consulta/edición sintética respeta asignado y permisos | fixture y matriz de permisos |
| I07 | Usuarios/roles | cambio sintético aplica permisos canónicos y revierte/limpia | IDs sintéticos y valores no secretos |
| I08 | Procedimientos | navegación/lectura autorizada y denegación funcionan; sin servicio real si no hay sandbox | status y captura |
| I09 | Integraciones | UI muestra configurado/pendiente sin revelar secreto; llamadas reales bloqueadas | configuración booleana y logs |
| I10 | Scheduler/Telegram | existe una sola instancia; no hay duplicados ni envío real | estado supervisor/heartbeat sandbox |
| I11 | Logs | eventos esperados aparecen, rotación/permisos correctos, sin secretos | muestra redactada y owner/mode |
| I12 | Filesystem | solo runtime requerido es escribible por servicio | owners/modes y prueba controlada |
| I13 | Backup/restore | hash coincide, restore íntegro y RPO/RTO medidos | actas 02 y 06 |

## Deseables

| ID | Objetivo | Resultado esperado | Evidencia requerida |
|---|---|---|---|
| D01 | Navegadores | Chromium, Edge y Firefox sin errores funcionales principales | matriz/capturas |
| D02 | Responsive/accesibilidad | escritorio/móvil, teclado, foco, contraste y reduced-motion utilizables | checklist QA |
| D03 | Rendimiento | login/home/módulos dentro de SLO definido | p50/p95/p99 y carga usada |
| D04 | Fallos de sandbox | timeouts/429/500 no agotan workers ni duplican operaciones | logs/métricas redactados |
| D05 | Observabilidad | alertas de prueba llegan al canal sandbox y se cierran | ID de alerta y tiempos |
| D06 | Capacidad | disco, inodos, conexiones y workers conservan margen aprobado | dashboard antes/durante/después |

## Registro de ejecución

Copiar las filas aplicables a `docs/PROD05_EVIDENCE/05-smoke.md`. Marcar N/A solo con responsable y aprobación. No reemplazar una prueba crítica por una deseable.

El resultado GO de esta matriz evalúa exclusivamente Staging y preparación de liberación; no autoriza ni ejecuta un despliegue en Producción.
