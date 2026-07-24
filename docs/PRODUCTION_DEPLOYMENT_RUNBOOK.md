# Runbook de despliegue, rollback e incidentes — NOVA

**Estado:** propuesta derivada de auditoría; debe ensayarse en staging antes de usarse.  
**Decisión vigente:** **NO-GO**. Este documento no autoriza un despliegue.  
**Principio:** nunca desplegar desde el working tree actual. Usar un commit/tag aprobado y un artefacto inmutable cuyo web root sea exclusivamente `public/`.

## 1. Variables operacionales

Completar en el ticket, no en este archivo:

```text
RELEASE_ID=<tag-o-sha>
RELEASE_DIR=<ruta-absoluta/releases/RELEASE_ID>
CURRENT_LINK=<ruta-absoluta/current>
SHARED_DIR=<ruta-absoluta/shared>
BACKUP_ID=<timestamp-release>
DB_NAME=<nombre-produccion>
MAINTENANCE_SECRET=<valor-en-boveda>
```

No usar rutas ambiguas, `~`, globs ni variables sin resolver en comandos destructivos. Adaptar binarios PHP/Composer al host; en el entorno auditado PHP es `/opt/lampp/bin/php`.

## 2. Criterios de abortar antes de empezar

Abortar y declarar NO-GO si ocurre cualquiera:

- PR-001–PR-007 abierto;
- árbol/artefacto contiene `.env`, dumps, logs, backups o `.git`;
- no hay restore exitoso y reciente del mismo tipo de backup;
- no se conoce versión productiva exacta o baseline de migraciones;
- suite/CI no pasa sobre DB aislada;
- falta responsable DBA, Infra, Seguridad o negocio;
- no existe release anterior compatible y recuperable;
- no están definidos umbrales y canal de incidente.

## 3. Pre-deploy (T-7 días a T-15 minutos)

### 3.1 Preparar release

1. Congelar alcance en PR/commit y crear tag/release aprobado.
2. En CI limpio, construir mediante:

```bash
ops/production/build-release.sh --ref <tag-o-commit> --output <directorio-absoluto-nuevo>
```

   El builder rechaza working trees sucios y outputs dentro del repositorio. La opción `--dependency-source` es solo para validación estructural local; está prohibida para el artefacto oficial.
3. En CI limpio:
   - validar Composer;
   - ejecutar audits;
   - instalar Composer desde lockfile sin dev y con autoloader autoritativo;
   - ejecutar `npm ci` y `npm run build`;
   - ejecutar sintaxis, suite, migración limpia y upgrade;
   - generar manifest/checksums.
4. Confirmar que `ops/production/verify-release.sh <release>` y `sha256sum --check MANIFEST.sha256` pasan.
5. Construir artefacto excluyendo expresamente:
   - `.env*` salvo plantilla no sensible si se requiere;
   - `.git`, `.github` si no es operacional;
   - `tests`, documentación interna no necesaria y herramientas dev;
   - `*.sql`, `*.bak`, `*.log`, backups, caches y archivos temporales;
   - `node_modules` y servidor Vite.
6. Escanear artefacto con la herramienta corporativa de secretos y verificar que `public/` es la única raíz publicada.

### 3.1.1 Reglas de logs y evidencia histórica

- Nunca copiar `RedmineMantencion/data/backups`, logs OnlyOffice/legacy, `storage/logs`, dumps o documentos históricos al release.
- Antes de dejar de trackear evidencia histórica, copiarla a bóveda forense cifrada, registrar hash/retención/acceso y obtener aprobación.
- En logs futuros eliminar query strings completas y redactar `Authorization`, Bearer, cookies, tokens, passwords, JWT y URLs de descarga/callback.
- La evaluación/rotación de secretos históricos es una acción operacional separada; este runbook no la ejecuta automáticamente.

### 3.2 Validar infraestructura

1. Confirmar FQDN, certificado, cadena, HSTS, proxy y Host allowlist.
2. Confirmar PHP/extensiones, OPcache, límites y `display_errors=Off`.
3. Confirmar usuario/grupo de servicio, permisos y espacio/inodos.
4. Confirmar acceso mínimo DB y usuario separado para migraciones.
5. Confirmar cron único `schedule:run` y estado esperado de `nova-telegram`.
6. Confirmar monitoreo y dashboard para HTTP, DB, host, disco, scheduler e integraciones.

### 3.3 Backup consistente

1. Anunciar inicio de ventana.
2. Registrar conteos/checksums lógicos de tablas críticas.
3. Activar mantenimiento y detener writers externos:

```bash
/opt/lampp/bin/php artisan down --secret="<desde-boveda>" --render="errors::503"
docker compose -f docker-compose.telegram.yml stop nova-telegram
```

Detener también el scheduler o bloquear sus comandos según el supervisor real. No matar procesos sin identificar PID/propietario.

4. Crear dump consistente con la herramienta y flags aprobados por DBA. No incrustar contraseña en el comando, historial o nombre del archivo.
5. Respaldar persistencia de negocio local y configuración compartida; custodiar `APP_KEY` en bóveda separada.
6. Calcular SHA-256, registrar tamaño/duración y copiar a almacenamiento cifrado externo.
7. Validar el dump y, si no hubo restore reciente equivalente, restaurarlo en entorno aislado antes de continuar.

Si cualquier validación falla, mantener el sistema en estado seguro, reanudar versión anterior y cancelar.

## 4. Deploy

### 4.1 Instalar release fuera del path activo

1. Crear `RELEASE_DIR` explícito con propietario/grupo aprobados.
2. Extraer el artefacto y verificar checksums.
3. Enlazar configuración/persistencia compartida, sin copiar secretos al repositorio.
4. Verificar que la configuración efectiva cumple:

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<fqdn>
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=<aprobada>
LOG_CHANNEL=<rotado/centralizado>
```

No imprimir `APP_KEY`, passwords o tokens.

### 4.2 Dependencias y caches

Si las dependencias no vienen dentro del artefacto, ejecutar solo dentro del release y desde lockfile:

```bash
/opt/lampp/bin/php composer.phar install --no-dev --prefer-dist --no-interaction --classmap-authoritative
/opt/lampp/bin/php artisan optimize:clear
```

Los assets deben venir de CI. No ejecutar Vite dev en producción.

### 4.3 Migraciones

1. DBA confirma nuevamente baseline y backup.
2. Archivar salida de:

```bash
/opt/lampp/bin/php artisan migrate:status
/opt/lampp/bin/php artisan migrate --force
```

3. Ejecutar una sola vez. No usar `migrate:fresh`, `db:wipe`, seeders no aprobados ni comandos legacy de importación.
4. Si falla una migración, no repetir a ciegas. Registrar punto exacto, revisar si hubo commit parcial y activar el plan de rollback/restore.

Las migraciones que retiren índices opcionales o provenientes de esquemas legacy deben inspeccionar primero el esquema mediante `Schema::hasIndex()`/`Schema::whenTableHasIndex()` y contemplar nombres explícitos y derivados conocidos. Un `try/catch` dentro del callback de `Schema::table()` no captura errores emitidos cuando Laravel ejecuta después el blueprint.

### 4.4 Activación

1. Generar caches bajo la configuración final:

```bash
/opt/lampp/bin/php artisan config:cache
/opt/lampp/bin/php artisan route:cache
/opt/lampp/bin/php artisan view:cache
```

2. Verificar `storage/` y `bootstrap/cache` escribibles solo por el usuario/grupo necesario.
3. Cambiar `CURRENT_LINK` atómicamente al release nuevo mediante el mecanismo aprobado del host.
4. Recargar Apache/PHP/OPcache de forma graceful.
5. Reanudar scheduler una sola vez y levantar Telegram:

```bash
docker compose -f docker-compose.telegram.yml up -d nova-telegram
/opt/lampp/bin/php artisan up
```

## 5. Post-deploy y smoke tests

Ejecutar desde una red similar a usuarios y registrar HTTP status, hora y evidencia sin datos sensibles:

1. HTTPS válido; HTTP redirige; Host inválido se rechaza.
2. `/.env`, `/.git/HEAD`, `/nova.sql`, `/storage/logs/laravel.log` y paths de backup devuelven 403/404.
3. `/login` carga; cookies son Secure/HttpOnly/SameSite; headers esperados presentes.
4. Login/logout con usuario sintético; usuario bloqueado no ingresa.
5. Usuario sin acceso recibe 403 al navegar directamente.
6. Abrir NOVA y cada módulo permitido; revisar 404 de assets y consola.
7. Health/readiness internos: app, DB, filesystem y servicios críticos; no deben revelar rutas/secretos.
8. Operación CRUD solo sobre fixture de smoke; comprobar auditoría y limpieza.
9. Integraciones únicamente con mock/sandbox. No enviar Telegram/correo ni importar CORE/EMACH reales.
10. Verificar un solo scheduler y heartbeat Telegram, sin duplicados.
11. Comparar conteos/integridad DB con pre-deploy.

Mantener observación intensiva al menos 60 minutos: 5xx, 429, latencia p95/p99, workers, conexiones/locks DB, CPU/RAM/disco, logs, callbacks, scheduler e integraciones.

## 6. Umbrales de rollback

Definir cifras en el ticket. Rollback inmediato ante:

- autenticación o autorización rota;
- exposición de secretos/datos;
- pérdida, corrupción, duplicación material o migración parcial no comprendida;
- 5xx/latencia por sobre umbral sostenido;
- agotamiento de workers/conexiones;
- callbacks/importaciones duplicados;
- imposibilidad de observar el sistema con seguridad.

## 7. Rollback

### 7.1 Solo código, DB compatible

1. Activar mantenimiento y detener scheduler/Telegram.
2. Cambiar atómicamente `CURRENT_LINK` al release anterior.
3. Limpiar/regenerar caches usando configuración del release anterior:

```bash
/opt/lampp/bin/php artisan optimize:clear
/opt/lampp/bin/php artisan config:cache
/opt/lampp/bin/php artisan route:cache
/opt/lampp/bin/php artisan view:cache
```

4. Recargar procesos/OPcache.
5. Reanudar y repetir smoke tests.

### 7.2 DB cambió pero rollback de migración está probado

Usar `migrate:rollback` **solo** si el paso exacto fue ensayado, conserva datos y el DBA lo autoriza. Registrar `--step`/batch exacto; nunca ejecutar rollback genérico a ciegas.

### 7.3 Cambio destructivo o estado incierto

1. Mantener mantenimiento; detener todos los writers.
2. Preservar dump/estado fallido para análisis.
3. Crear DB nueva aislada o reemplazo controlado; no restaurar encima sin procedimiento DBA.
4. Restaurar el backup validado y persistencia correspondiente.
5. Restaurar/configurar la misma `APP_KEY`; comprobar descifrado sintético.
6. Ejecutar verificaciones de integridad y conteos.
7. Activar release anterior compatible, smoke tests y monitoreo.
8. Reabrir tráfico solo con aprobación DBA/Seguridad/Producto.

## 8. Incidentes

### 8.1 Contención

- Seguridad/exposición: retirar tráfico o archivo, preservar evidencia, invalidar tokens/sesiones, rotar secretos por dependencia y evaluar acceso.
- Datos: detener writers, capturar métricas/conteos y evitar “reparaciones” manuales sin plan reversible.
- Integración: deshabilitar módulo/tarea afectada, conservar core funcional y evitar reintentos masivos.
- Capacidad: detener importaciones largas, proteger DB y mantener login/lecturas esenciales.

### 8.2 Registro mínimo

```text
Hora inicio/detección:
Release:
Módulos/usuarios afectados:
Síntoma y evidencia:
Operación/correlation ID:
Posible pérdida o exposición:
Acciones y responsable:
Decisión rollback/restore:
Hora recuperación:
```

No copiar secretos, tokens ni payloads completos al ticket.

### 8.3 Cierre

Confirmar integridad, invalidación/rotación, monitoreo estable y comunicación. Crear postmortem con causa, detección, impacto, timeline, acciones y responsables/fechas.

## 9. Validación de restauración periódica

Como mínimo según RPO/RTO aprobado:

1. seleccionar backup sin alterar producción;
2. verificar hash/cifrado/acceso;
3. restaurar DB y archivos en red aislada;
4. aplicar `APP_KEY` de prueba/custodia controlada;
5. ejecutar integridad, login sintético, permisos, reportes, documentos y descifrado;
6. medir RTO y calcular RPO real;
7. destruir de forma segura el entorno restaurado;
8. archivar acta y corregir desviaciones.

## 10. Firmas de ejecución

| Hito | Responsable | Inicio/fin | Resultado | Evidencia |
|---|---|---|---|---|
| Backup validado | | | | |
| Restore probado | | | | |
| Release instalado | | | | |
| Migraciones | | | | |
| Smoke tests | | | | |
| Observación | | | | |
| GO final o rollback | | | | |

## 11. Baseline técnico validado en PROD-04

Antes de promover un release, conservar como mínimo el baseline de `docs/PROD04_TEST_AND_DEPENDENCY_REMEDIATION.md`: suite sin fallos, Composer sin advisories, build desde lockfiles, scanner/manifest, 56 migraciones limpias, caches y smoke. Vite/esbuild son tooling exclusivamente de build/desarrollo: queda prohibido ejecutar o publicar su servidor en producción; el DocumentRoot continúa siendo `<release>/public`.

Este baseline no sustituye staging, upgrade desde clon, backup/restore ni rollback operacional. Esos hitos permanecen obligatorios y sin marcar hasta una ejecución aprobada.

## 12. Paquete preparado para PROD-05

PREP-05 no ejecutó Staging ni validaciones operacionales. Cuando se complete el handoff de `docs/PROD05_INFRA_REQUIREMENTS.md`, la ejecución debe usar:

1. `docs/PROD05_EXECUTION.md` para orden, puntos de control y tiempos;
2. `docs/PROD05_RUNBOOK.md` para roles, evidencia, criterios de éxito y detención;
3. `docs/PROD05_SMOKE_TESTS.md` para la matriz funcional;
4. `docs/PROD05_EVIDENCE/` para registrar cada etapa;
5. `docs/PROD05_GO_NOGO_TEMPLATE.md` para la decisión firmada;
6. `scripts/prod/` como ayudas parametrizadas, nunca como sustituto de aprobación DBA/Infra.

Los scripts requieren `PROD05_CONFIRM=PROD05-STAGING`, `APP_ENV=staging`, directorio de evidencia y FQDN no local. Backup/restore usan un archivo externo de opciones DB con modo 0600 y rechazan `DB_PASSWORD` en el entorno. Deploy, migraciones, restore y rollback exigen confirmaciones adicionales explícitas. Esto es una barrera preventiva, no una autorización para ejecutar PROD-05.
