# HORAS_EXTRA_MODELO.md
## Análisis del modelo de horas extra en NOVA

**Fecha:** 2026-06-15  
**Sesión:** S28 — análisis + DROP ejecutado  
**Entorno:** Laravel 12.62.0, PHP 8.2.12, MariaDB

---

## 1. Modelo actual — tres tablas con propósitos distintos

Existen tres tablas relacionadas con horas extra en la BD. Sirven a **módulos distintos** con **modelos conceptuales distintos**. No son redundantes entre sí, pero tampoco son coherentes entre sí.

---

### 1.1 `horas_extras` — Mantención

**Módulo:** Redmine Mantención  
**Estado: ELIMINADA (DROP ejecutado en S28 — migración `2026_06_15_100000_drop_horas_extras_orphaned_table`)**

**Schema:**

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | bigint PK | |
| `modulo_id` | bigint FK→modulos_nova | |
| `proyecto` | varchar(180) | nombre del proyecto |
| `project_id` | varchar(80) | ID externo Redmine |
| `usuario_id` | bigint FK→usuarios_nova | |
| `id_redmine_asignado` | varchar(80) | redmine_id del asignado |
| `numero_ticket_redmine` | int | |
| `reporte_local_id` | varchar(120) | ⚠ HUÉRFANO — referencia a `local_id` eliminado en S22 |
| `fecha` | date | |
| `hora_inicio` | time | |
| `hora_termino` | time | nombre distinto a grupos TIC (`hora_fin`) |
| `cantidad` | decimal(10,2) | horas trabajadas |
| `source_path` | varchar(255) | ruta del JSON de origen |
| `origen_hash` | char(64) UNIQUE | hash del registro migrado |
| `creado_at` / `actualizado_at` | timestamp | |

**Índices:** fecha, id_redmine_asignado, modulo_id, numero_ticket_redmine, origen_hash (UNIQUE), project_id, reporte_local_id, source_path, usuario_id

**Modelo conceptual:** registro individual de hora extra por persona, proyecto, fecha y rango horario. Un registro = un técnico + un ticket + una jornada.

**Historia:** Creada en S12 (`normalize_redmine_mantencion_data.php`) para normalizar los archivos `horasExtras/YYYY/mes.json`. Columna `datos_extra` eliminada en S27 Phase 2.

**Problemas detectados:**

1. **Sin lectores activos.** Nada en runtime lee de esta tabla. Las vistas de Mantención (`historico.php`) siguen leyendo de `redmine-mantencion/data/horasExtras/*.json`. El controlador `maintenance.php` define `horas_extras` como sección de paths `['horasExtras']`, apuntando a archivos JSON, no a la BD.

2. **Sin escritores activos.** Ningún controlador escribe en esta tabla. Solo fue poblada por la migración de S12.

3. **Columna `reporte_local_id` huérfana.** Referenciaba la columna `redmine_tic_reportes.local_id` que fue eliminada en S22. Es un varchar sin FK formal; apunta a nada.

4. **Datos eliminados en S27.** La limpieza operacional vació la tabla. Los archivos `horasExtras/*.json` del storage también fueron eliminados.

5. **Nombre inconsistente con el modelo TIC.** Usa `hora_termino` en vez de `hora_fin`.

---

### 1.2 `redmine_tic_horas_extra_grupos` — TIC

**Módulo:** Redmine TIC  
**Estado: ACTIVA**

**Schema:**

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | bigint PK | |
| `modulo_id` | bigint FK→modulos_nova | |
| `fecha` | date | fecha de la jornada de hora extra |
| `hora_inicio` | time | inicio de la jornada |
| `hora_fin` | time | fin de la jornada |
| `creado_at` / `actualizado_at` | timestamp | |

**Índices:** PRIMARY, UNIQUE (modulo_id, fecha)

**Modelo conceptual:** una "sesión" de horas extra por fecha. Responde: "el día X, el módulo TIC trabajó hora extra de HH:MM a HH:MM".

**Restricción clave:** la constraint UNIQUE `(modulo_id, fecha)` garantiza exactamente **un grupo por día**. No es posible registrar dos sesiones de hora extra en el mismo día para el mismo módulo.

---

### 1.3 `redmine_tic_horas_extra_grupo_reportes` — Pivot M2M

**Módulo:** Redmine TIC  
**Estado: ACTIVA**

**Schema:**

| Columna | Tipo | Notas |
|---------|------|-------|
| `id` | bigint PK | |
| `grupo_id` | bigint FK→horas_extra_grupos | ON DELETE CASCADE implícito por código |
| `reporte_id` | bigint FK→redmine_tic_reportes | |
| `creado_at` | timestamp | |

**Índices:** PRIMARY, UNIQUE (grupo_id, reporte_id), idx_hegr_reporte (reporte_id)

**Modelo conceptual:** qué reportes TIC estuvieron incluidos en una sesión de hora extra. Un reporte puede pertenecer a un solo grupo (la constraint UNIQUE lo garantiza).

**Origen:** reemplazó la columna JSON `report_ids` en `redmine_tic_horas_extra_grupos` (Phase 1a S24, Phase 2a S27).

---

## 2. Diagrama de relaciones

```
modulos_nova (1)
    │
    ├── horas_extras          [Mantención — ELIMINADA en S28]
    │
    └── redmine_tic_horas_extra_grupos (0..*)   [TIC, ACTIVA]
            │    [UNIQUE: modulo_id, fecha]
            │
            └── redmine_tic_horas_extra_grupo_reportes (0..*)   [pivot, ACTIVA]
                        └── reporte_id → redmine_tic_reportes
```

---

## 3. Problemas detectados

| # | Problema | Tabla | Severidad |
|---|----------|-------|-----------|
| P1 | `horas_extras` no tiene lectores ni escritores activos en runtime | `horas_extras` | Alta |
| P2 | `horas_extras.reporte_local_id` huérfano — `local_id` eliminado en S22 | `horas_extras` | Alta |
| P3 | `datos_extra` eliminado en Phase 2 — la información contextual que contenía no fue promovida a columnas propias | `horas_extras` | Media |
| P4 | Nombres inconsistentes: `hora_termino` (Mantención) vs `hora_fin` (TIC) para el mismo concepto | `horas_extras` | Baja |
| P5 | Un solo grupo por día (`UNIQUE modulo_id, fecha`) — no soporta dos turnos en el mismo día | `redmine_tic_horas_extra_grupos` | Baja |
| P6 | `syncHoursExtraForReport` ejecuta 6–8 queries por reporte (remove + upsert grupo + get id + upsert pivot) | código | Baja |
| P7 | `horas_extras` modela un concepto completamente distinto al de TIC (individual vs grupo) — no unificable trivialmente | ambas | Informativo |

---

## 4. Tablas necesarias

### Necesarias para TIC (activas)

- **`redmine_tic_horas_extra_grupos`** — necesaria. Almacena la sesión de hora extra por fecha. El código TIC lee y escribe activamente.
- **`redmine_tic_horas_extra_grupo_reportes`** — necesaria. Pivot que relaciona grupos con reportes TIC. Tiene FKs formales con índice de búsqueda por reporte.

### Eliminadas (S28)

- **`horas_extras`** — eliminada mediante `DROP TABLE` en S28. Era un puente de migración JSON que cumplió su función: sin lectores ni escritores activos, vaciada en S27, `reporte_local_id` huérfano, `datos_extra` eliminado en Phase 2. Migración: `2026_06_15_100000_drop_horas_extras_orphaned_table` (incluye `down()` con DDL exacto para revertir si necesario).

---

## 5. Tablas redundantes

No hay redundancia entre las tres tablas — modelan cosas distintas:

| Tabla | Concepto |
|-------|----------|
| `horas_extras` | Hora extra individual por técnico/proyecto/ticket (Mantención) |
| `redmine_tic_horas_extra_grupos` | Sesión colectiva de hora extra por fecha (TIC) |
| `redmine_tic_horas_extra_grupo_reportes` | Qué reportes TIC pertenecen a esa sesión |

Sin embargo, `horas_extras` es **funcionalmente huérfana**: su modelo ya no tiene soporte en código. No es redundante, es **obsoleta**.

---

## 6. Análisis de fusión

### ¿Fusionar `redmine_tic_horas_extra_grupos` + pivot en una sola tabla?

**Propuesta hipotética:**

```sql
-- Una sola tabla: hora_extra_reporte
id, modulo_id, reporte_id, fecha, hora_inicio, hora_fin, creado_at
```

| Ventaja | Desventaja |
|---------|------------|
| Una sola tabla en lugar de dos | `hora_inicio`/`hora_fin` se repiten por cada reporte de la misma fecha |
| Lectura directa: `WHERE reporte_id = ?` sin JOIN | Si 10 reportes comparten una sesión, se almacenan 10 filas con los mismos horarios |
| Sin lógica de "grupo vacío → borrar grupo" | Un cambio en el horario de la sesión requiere UPDATE de N filas |
| Writes más simples: un solo upsert | Rompe la idea de "sesión" como entidad propia |

**Veredicto:** La fusión simplifica writes pero introduce redundancia en `hora_inicio`/`hora_fin`. Con el volumen actual (décenas de reportes, no millones), la redundancia es aceptable. Pero el diseño actual con dos tablas es correcto según 3FN y no debería fusionarse por una ligera simplificación de código.

### ¿Fusionar `horas_extras` con las tablas TIC?

No tiene sentido. Son modelos de dominio distintos:
- TIC: horas extra son un atributo de un grupo de reportes en una fecha
- Mantención: horas extra son tiempo individual de un técnico en un ticket concreto

Fusionar implicaría columnas nullable en exceso para soportar ambos modelos. Es peor que mantenerlas separadas (o eliminar la inactiva).

---

## 7. Propuesta recomendada

### Acción 1 — DROP `horas_extras` (RECOMENDADO)

La tabla cumplió su función como destino temporal de la migración JSON en S12. Con S27:
- Los datos de origen (JSON) fueron eliminados del storage.
- La tabla fue vaciada.
- Ningún código activo la lee ni escribe.
- La columna `reporte_local_id` está huérfana.

**Migración propuesta:**

```php
// 2026_06_15_xxxxx_drop_horas_extras_orphaned_table.php
Schema::dropIfExists('horas_extras');
```

Antes de ejecutar: confirmar que ningún controlador legacy de Mantención referencia la tabla en tiempo de ejecución (grep confirmado: ninguno).

Si en el futuro Mantención necesita tracking de horas extra, diseñar desde cero. Una opción es usar la misma estructura TIC (grupos + pivot) con `modulo_id=2`, o una tabla dedicada con diseño limpio.

### Acción 2 — DROP columna `reporte_local_id` de `horas_extras`

Si por alguna razón se decide conservar `horas_extras`, la columna `reporte_local_id` debe eliminarse primero. Es un varchar sin FK que apunta a datos que no existen.

```php
Schema::table('horas_extras', function (Blueprint $table): void {
    $table->dropIndex('horas_extras_reporte_local_id_index');
    $table->dropColumn('reporte_local_id');
});
```

### Acción 3 — No tocar `redmine_tic_horas_extra_grupos` ni pivot

Las tablas TIC están bien diseñadas, activas y tienen FKs correctos. No requieren cambios de modelo.

### Acción 4 — (Opcional) Mejora de rendimiento en `syncHoursExtraForReport`

El método ejecuta 6–8 queries por sync. Se puede optimizar agrupando en una transacción y cacheando el `grupo_id` localmente. Esto es un refactor de código, no de schema.

---

## 8. Riesgos de una sola tabla (colapsar todo)

Si se fusionaran `redmine_tic_horas_extra_grupos` + `redmine_tic_horas_extra_grupo_reportes` en una sola tabla:

| Riesgo | Detalle |
|--------|---------|
| Redundancia de horarios | `hora_inicio`/`hora_fin` repetidos N veces por sesión |
| Update anomaly | Cambiar el horario de una sesión requiere UPDATE masivo |
| Pérdida de entidad "sesión" | Ya no existe un objeto concreto "sesión del día X", solo filas con la misma fecha |
| Migración de código | `hoursExtraFromDatabase()` y `syncHoursExtraForReport()` requieren reescritura completa |
| Pérdida del índice de sesión | Hoy se puede buscar "¿hubo hora extra el día X?" con un query a grupos; en tabla plana requiere GROUP BY |

**Conclusión:** No fusionar.

---

## 9. Riesgos de mantener las tablas actuales

| Riesgo | Detalle | Mitigación |
|--------|---------|-----------|
| `horas_extras` confunde lectores futuros | Parece activa pero no lo es | Documentar o DROP |
| `reporte_local_id` genera confusión | Referencia datos inexistentes | DROP columna |
| Un solo grupo por día en TIC | No soporta dos turnos el mismo día | Si el negocio lo requiere, cambiar UNIQUE a `(modulo_id, fecha, turno)` |
| Write en 6–8 queries por sync | Lento con muchos reportes simultáneos | Envolver en transacción, cachear grupo_id en memoria de la instancia |

---

## 10. Recomendación final

### Tabla por tabla

| Tabla | Acción | Estado |
|-------|--------|--------|
| `horas_extras` | **DROP** ✅ ejecutado S28 | Eliminada — migración `2026_06_15_100000` aplicada, tests: 47 passed + 1 skipped |
| `redmine_tic_horas_extra_grupos` | **CONSERVAR** sin cambios | Activa, bien diseñada, correctamente indexada |
| `redmine_tic_horas_extra_grupo_reportes` | **CONSERVAR** sin cambios | Activa, pivot normalizado con FK e índices correctos |

### Resumen de modelo objetivo

El modelo correcto para horas extra en NOVA es el modelo TIC de dos tablas:

```
modulos_nova (1)
    └── redmine_tic_horas_extra_grupos (N)    ← "sesión de horas extra el día X"
            └── redmine_tic_horas_extra_grupo_reportes (N)  ← "estos reportes pertenecen a esa sesión"
                        └── reporte_id → redmine_tic_reportes
```

Si Mantención necesita tracking de horas extra en el futuro, puede reutilizar este modelo con `modulo_id=2` en `redmine_tic_horas_extra_grupos`, o crear tablas `redmine_mantencion_horas_extra_*` siguiendo el mismo patrón grupo + pivot.

---

## 11. Migración ejecutada en S28

**Archivo:** `database/migrations/2026_06_15_100000_drop_horas_extras_orphaned_table.php`

```php
public function up(): void
{
    Schema::dropIfExists('horas_extras');
}
// down() recrea estructura exacta: 13 columnas + 7 índices + 2 FKs nullable
```

**Verificaciones previas:**
- Grep confirmado: 8 referencias a `horas_extras` en código activo, todas no-DB (array key de sección UI o nombre de función que lee filesystem paths, no `DB::table()`).
- Tabla vacía desde S27. Sin datos que respaldar.
- FKs: ninguna tabla externa apunta INTO `horas_extras`.

**Resultado:**
- Migración: ✅ OK 14ms
- Tests post-migración: 47 passed + 1 skipped (sin regresiones)
- `horas_extras`: NO EXISTE
- `redmine_tic_horas_extra_grupos` y pivot: EXISTEN sin cambios

---

*Análisis realizado en S28 — DROP ejecutado con migración 100000, modelo TIC intacto*
