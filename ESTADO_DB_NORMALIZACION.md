# ESTADO_DB_NORMALIZACION.md
## Estado de normalización y limpieza de la base de datos NOVA

**Fecha:** 2026-06-15  
**Sesión:** S28 (actualizado desde S27)  
**Entorno:** XAMPP Windows, PHP 8.2.12, MariaDB, Laravel 12.62.0

---

## 1. Resumen ejecutivo

| Estado | Detalle |
|--------|---------|
| **✅ Normalización completada** | Todas las columnas JSON reemplazadas han sido eliminadas |
| **✅ Phase 2 ejecutada** | datos_extra y report_ids eliminados de 5 tablas |
| **✅ Phase 3a validada** | Tablas relacionales de permisos: 1591 filas, 43×37 exactos |
| **✅ Phase 3c ejecutada** | permisos JSON y roles JSON eliminados de la DB |
| **✅ Config Mantencion migrada** | configuracion.json → configuraciones_modulo (modulo_id=2, 39 filas) |
| **✅ Datos operacionales limpiados** | 6 tablas vacías, storage limpio |
| **✅ Tests** | 47/47 passed + 1 skipped contextual |
| **✅ horas_extras DROP (S28)** | Tabla huérfana eliminada; total tablas BD: 21 |

---

## 2. Tablas conservadas (datos de configuración)

| Tabla | Filas | Tipo | Descripción |
|-------|-------|------|-------------|
| `usuarios_nova` | 58 | CONFIG | Identidad central de usuarios NOVA |
| `modulos_nova` | 5 | CONFIG | Registro de módulos del sistema |
| `permisos_usuario_modulo` | 69 | CONFIG | Acceso por usuario a cada módulo |
| `integraciones_usuario` | 69 | CONFIG | Credenciales de integración por usuario |
| `configuraciones_modulo` | 59 | CONFIG | Config de módulos TIC (20) y Mantencion (39) |
| `modulo_opciones` | 12 | CONFIG | Trackers, prioridades y estados de TIC |
| `redmine_tic_permisos_catalogo` | 37 | CONFIG | Catálogo de 37 permisos canónicos |
| `redmine_tic_permisos_rol` | 185 | CONFIG | Permisos por rol (5 roles × 37 claves) |
| `redmine_tic_permisos_usuario` | 1591 | CONFIG | Permisos por perfil (43 perfiles × 37 claves) |
| `redmine_tic_perfiles_usuario` | 43 | CONFIG | Perfiles TIC: id, nombre, rol, estado |
| `catalogos_modulo` | 646 | CONFIG | Catálogos TIC: categorías (276) + unidades (370) |
| `categorias` | 500 | CONFIG | Categorías normalizadas de Mantencion |
| `unidades` | 616 | CONFIG | Unidades normalizadas de Mantencion |

---

## 3. Tablas vaciadas (datos operacionales eliminados)

| Tabla | Filas antes | Filas después | Razón |
|-------|------------|---------------|-------|
| `redmine_tic_reportes` | 729 | 0 | Historial de reportes TIC (726 archivados + 3 pendientes de prueba) |
| `redmine_mantencion_reportes` | 275 | 0 | Historial de reportes Mantencion (275 procesados) |
| `redmine_tic_horas_extra_grupos` | 37 | 0 | Grupos de horas extra (datos de prueba) |
| `redmine_tic_horas_extra_grupo_reportes` | 121 | 0 | Pivot derivado de los grupos |
| `redmine_tic_activity_logs` | 5 | 0 | Logs de auditoría (datos de prueba) |

**Nota:** Las tablas conservan su estructura (schema) para uso futuro en producción.

---

## 3b. Tablas eliminadas (DROP en S28)

| Tabla | Filas antes del DROP | Migración | Razón |
|-------|---------------------|-----------|-------|
| `horas_extras` | 0 (vaciada en S27) | `2026_06_15_100000_drop_horas_extras_orphaned_table` | Tabla huérfana de Mantención: sin lectores ni escritores activos en runtime; `reporte_local_id` referenciaba `local_id` eliminado en S22; `datos_extra` eliminada en Phase 2; creada en S12 como bridge de migración JSON, ya cumplió su función |

**Reversibilidad:** `down()` en la migración recrea la estructura exacta original (13 columnas + 7 índices + 2 FKs nullable).

---

## 4. Columnas JSON eliminadas (normalización schema)

| Tabla | Columna eliminada | Reemplazada por | Migración |
|-------|------------------|-----------------|-----------|
| `categorias` | `datos_extra` | columna `predeterminado` (Phase 1b) | 110000 |
| `unidades` | `datos_extra` | columna `predeterminado` (Phase 1b) | 110000 |
| `redmine_mantencion_reportes` | `datos_extra` | columnas específicas (Phase 1b) | 110000 |
| `horas_extras` | `datos_extra` | columnas específicas (Phase 1b) | 110000 |
| `redmine_tic_horas_extra_grupos` | `report_ids` | pivot `redmine_tic_horas_extra_grupo_reportes` | 110001 |
| `redmine_tic_perfiles_usuario` | `permisos` | `redmine_tic_permisos_usuario` (Phase 3a) | Phase 3c |

---

## 5. Filas JSON eliminadas de configuraciones_modulo

| Fila eliminada | clave | Por qué | Migración |
|----------------|-------|---------|-----------|
| modulo_id=1, clave=`trackers` | JSON blob | reemplazado por `modulo_opciones` (Phase 1c) | 110002 |
| modulo_id=1, clave=`prioridades` | JSON blob | reemplazado por `modulo_opciones` (Phase 1c) | 110002 |
| modulo_id=1, clave=`estados` | JSON blob | reemplazado por `modulo_opciones` (Phase 1c) | 110002 |
| modulo_id=1, clave=`roles` | JSON blob | reemplazado por `redmine_tic_permisos_rol` (Phase 3a) | Phase 3c |

---

## 6. Datos de configuración protegidos

### configuraciones_modulo — modulo_id=1 (Redmine TIC)
20 filas: platform_url, platform_token, project_id, tracker_id, priority_id, cf_solicitante, cf_unidad, cf_hora_extra, status_id, category_id, retencion_horas, session_timeout, project_name, source_mode, core_enabled, core_admin_url, core_sync_minutes, maintenance_mode, maintenance_until, core_historico_url

### configuraciones_modulo — modulo_id=2 (Mantencion)
39 filas migradas desde `configuracion.json` en storage (excluyendo cache/estado transitorio):
- Conexión: platform_url, platform_token, project_id, tracker_id, priority_id, status_id, etc.
- OnlyOffice: onlyoffice_url, onlyoffice_app_url, onlyoffice_jwt_secret
- Nextcloud: nextcloud_url, nextcloud_admin_user, nextcloud_default_group, etc.
- Arrays de configuración: trackers (4), prioridades (5), estados (1)
- Custom fields: cf_solicitante, cf_unidad, cf_hora_extra
- Procedimientos: procedures_storage, procedures_nextcloud_root

**Excluidos del copy (cache/estado):** nextcloud_cached_groups, nextcloud_cached_groups_at, core_last_sync, core_last_error

### redmine_mantencion_storage — entradas conservadas
| path | Razón para conservar |
|------|---------------------|
| `configuracion.json` | Fuente activa para controladores Mantencion (bridge mientras no se actualicen controladores) |
| `roles.json` | Configuración de roles Mantencion (pendiente migrar a redmine_tic_permisos_rol para modulo_id=2) |

---

## 7. Migraciones ejecutadas

### Sesión 27

| Migración | Tipo | Resultado |
|-----------|------|-----------|
| `2026_06_14_110000_phase2_drop_datos_extra_columns` | DROP 4 columnas | ✅ 3s |
| `2026_06_14_110001_phase2a_drop_report_ids` | DROP 1 columna | ✅ 125ms |
| `2026_06_14_110002_phase2d_cleanup_json_config_rows` | DELETE 3 filas | ✅ 13ms |
| `2026_06_15_000000_phase3c_drop_permisos_json` | DROP columna + DELETE fila | ✅ 18ms |
| `2026_06_15_000001_migrate_mantencion_config_to_db` | INSERT 39 filas | ✅ 99ms |
| `2026_06_15_000002_cleanup_operational_data` | DELETE 6 tablas + 20 storage entries | ✅ 326ms |

### Sesión 28

| Migración | Tipo | Resultado |
|-----------|------|-----------|
| `2026_06_15_100000_drop_horas_extras_orphaned_table` | DROP TABLE | ✅ 14ms |

---

## 8. Riesgos pendientes

| Riesgo | Nivel | Mitigación / Pendiente |
|--------|-------|------------------------|
| Controladores Mantencion siguen leyendo `configuracion.json` de storage | Medio | `configuracion.json` conservado como bridge. Pendiente actualizar MantencionConfigRepository para leer de `configuraciones_modulo` |
| `roles.json` en storage no migrado a `redmine_tic_permisos_rol` para modulo_id=2 | Bajo | Pendiente sesión futura: definir schema de roles para Mantencion y migrar |
| `catalogos_modulo` (646 filas) posiblemente redundante con `categorias`/`unidades` | Bajo | Revisar si código TIC aún lee `catalogos_modulo` antes de eliminarlo |
| `_nova_column_backups` creada por Phase 2 con datos de respaldo | Info | Tabla de seguridad, puede eliminarse cuando se confirme estabilidad |
| Un solo grupo de horas extra por día por módulo TIC (UNIQUE modulo_id, fecha) | Bajo | Si el negocio requiere dos turnos el mismo día, cambiar UNIQUE a (modulo_id, fecha, turno) |

---

## 9. Schema final de la DB (tablas con contenido)

```
usuarios_nova              58 filas    — identidad central
modulos_nova               5  filas    — módulos activos
permisos_usuario_modulo    69 filas    — acceso a módulos
integraciones_usuario      69 filas    — tokens por usuario
configuraciones_modulo     59 filas    — config TIC(20) + Mantencion(39)
modulo_opciones            12 filas    — trackers/prioridades/estados TIC
redmine_tic_permisos_catalogo  37 filas — catálogo de permisos
redmine_tic_permisos_rol      185 filas — permisos por rol
redmine_tic_permisos_usuario 1591 filas — permisos por perfil
redmine_tic_perfiles_usuario   43 filas — perfiles TIC
catalogos_modulo             646 filas  — catálogos TIC
categorias                   500 filas  — categorías Mantencion
unidades                     616 filas  — unidades Mantencion
redmine_mantencion_storage     2 filas  — bridge activo (configuracion + roles)
_nova_column_backups        ~1416 filas — backup de columnas eliminadas (seguridad)

— 7 tablas vacías (estructura conservada para producción):
  redmine_tic_reportes, redmine_mantencion_reportes,
  redmine_tic_horas_extra_grupos, redmine_tic_horas_extra_grupo_reportes,
  redmine_tic_activity_logs, migrations (sistema), failed_jobs

— 1 tabla eliminada (S28):
  horas_extras (DROP — huérfana, sin lectores activos, vaciada en S27)
```

**Total tablas BD: 21** (era 22 antes de S28)

---

*Generado en S27 — `php artisan migrate` × 6 migraciones + `php artisan test` 47/47 passed*  
*Actualizado en S28 — `php artisan migrate` × 1 migración (DROP horas_extras) + `php artisan test` 47/47 passed + 1 skipped*  
*Actualizado en S29 — `php artisan migrate` × 1 migración (cleanup redmine_mantencion_reportes, 7 columnas legacy) + `php artisan test` 47 passed + 1 skipped. Ver `LIMPIEZA_REDMINE_MANTENCION_DB.md`.*
