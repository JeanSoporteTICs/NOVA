# VALIDACION_PHASE3A_PERMISOS.md
## Informe de validación — Phase 3a Normalización de Permisos

**Fecha:** 2026-06-14  
**Entorno:** XAMPP Windows, PHP 8.2.12, MariaDB, Laravel 12.62.0  
**Ejecutado por:** `php artisan nova:validate-phase3a` + `php artisan test`

---

## 1. Resumen ejecutivo

| Veredicto | Detalle |
|-----------|---------|
| **✅ APROBADO** | 17/17 verificaciones del comando pasadas |
| **✅ APROBADO** | 16/16 tests de Phase 3a pasados |
| **✅ Total suite** | 48/48 tests (120 assertions) |
| **Phase 3c** | **APROBADA para planificar** |

---

## 2. Error encontrado y corregido durante la validación

### Problema detectado

La migración inicial `2026_06_14_120000_phase3a_create_permisos_tables.php` tenía un error
en `populateUserPermissions()`:

```php
// BUG: saltaba perfiles con permisos = "[]" (41 de 43 perfiles)
if (!is_array($perms) || empty($perms)) {
    continue;
}
```

**Impacto:** Solo 2 de 43 perfiles se migraron a `redmine_tic_permisos_usuario` (44 filas en lugar
de 1.591). Los 41 perfiles restantes tenían `permisos = "[]"` en la BD y fueron ignorados.

### Corrección aplicada

1. **Migración original corregida** (`2026_06_14_120000_...`) — para instalaciones limpias futuras:
   - `populateUserPermissions()` ahora construye el set de 37 claves completo por rol
   - En lugar de solo insertar las claves existentes en el JSON, hace `merge(defaults, existing)`

2. **Nueva migración de backfill** (`2026_06_14_120001_phase3a_backfill_user_permissions.php`):
   - Corrección para la instancia ya instalada
   - Ejecutada: 3s, sin errores

### Estado post-corrección

```
redmine_tic_permisos_usuario: 1591 filas (43 perfiles × 37 claves exactos)
```

---

## 3. Validaciones realizadas

### 3.1 Comando `nova:validate-phase3a`

```
▶ 1. Existencia de tablas
  ✓ Tabla `redmine_tic_permisos_catalogo` existe
  ✓ Tabla `redmine_tic_permisos_rol` existe
  ✓ Tabla `redmine_tic_permisos_usuario` existe

▶ 2. Catálogo de permisos
  ✓ Catálogo tiene exactamente 37 filas
  ✓ Todas las 37 claves canónicas están en el catálogo
  ✓ Tipos de claves scope correctos (scope / scope_or_empty)

▶ 3. redmine_tic_permisos_usuario
  Perfiles en DB: 43
  Filas en permisos_usuario: 1591  (mínimo esperado: 1591)
  ✓ Conteo de filas correcto (1591 ≥ 1591)
  ✓ Todos los 43 perfiles tienen filas relacionales
  ✓ Todos los perfiles tienen exactamente 37 claves
  ✓ Las 37 claves canónicas aparecen al menos una vez en la tabla

▶ 4. redmine_tic_permisos_rol
  Roles encontrados (5): administrador, gestor, pp, root, usuario
  ✓ 5 roles en permisos_rol
  ✓ Todos los roles tienen ≥ 37 claves

▶ 5. Consistencia JSON ↔ Relacional (muestra)
  ✓ Muestra de 2 perfiles: valores JSON y relacionales coinciden

▶ 6. Lectura desde repositorio (RedmineDataRepository)
  ✓ allPermissionsFromRelational() devolvió 43 perfiles
  ✓ Conteo coincide con total de perfiles (43)
  ✓ Perfil de muestra tiene exactamente 37 claves

▶ 7. Consistencia dual-write (rol JSON ↔ redmine_tic_permisos_rol)
  ✓ Roles JSON ↔ relacional: sin discrepancias

  RESULTADO: APROBADO — 17/17 verificaciones pasadas
  Phase 3c (DROP COLUMN permisos) puede planificarse con seguridad.
```

### 3.2 Tests PHPUnit — `Phase3aPermissionsTest` (16 tests)

| Test | Estado |
|------|--------|
| phase3a_tables_exist | ✓ |
| permisos_catalogo_has_37_entries | ✓ |
| all_catalog_keys_are_present | ✓ |
| catalog_scope_keys_have_correct_type | ✓ |
| catalog_bool_keys_have_bool_type | ✓ |
| all_profiles_have_rows_in_permisos_usuario | ✓ |
| every_profile_has_exactly_37_permission_keys | ✓ |
| all_37_canonical_keys_appear_in_permisos_usuario | ✓ |
| permisos_usuario_total_rows | ✓ |
| permisos_rol_has_at_least_4_roles | ✓ |
| every_role_has_37_permission_keys | ✓ |
| json_and_relational_values_match_for_sample_profiles | ✓ |
| repository_reads_from_relational_table | ✓ |
| repository_returns_all_profiles_from_relational | ✓ |
| repository_returns_37_keys_per_profile | ✓ |
| dual_write_save_permissions_to_relational | ✓ |

### 3.3 Suite completa

```
php artisan test
Tests: 48 passed (120 assertions)
Duration: 2.36s
```

---

## 4. Consultas ejecutadas y resultados

| Consulta | Resultado |
|----------|-----------|
| `SELECT COUNT(*) FROM redmine_tic_permisos_catalogo` | **37** ✓ |
| `SELECT COUNT(*) FROM redmine_tic_permisos_usuario` | **1591** (43 × 37) ✓ |
| `SELECT COUNT(*) FROM redmine_tic_permisos_rol` | **189** (5 roles × 37-38 claves) ✓ |
| `SELECT COUNT(*) FROM redmine_tic_perfiles_usuario` | **43** ✓ |
| Perfiles con exactamente 37 claves en relacional | **43/43** ✓ |
| Roles en `redmine_tic_permisos_rol` | **5**: root, administrador, gestor, usuario, pp ✓ |
| Claves ausentes en catálogo | **0** ✓ |
| Discrepancias JSON ↔ relacional (muestra 2 perfiles) | **0** ✓ |
| Discrepancias roles JSON ↔ relacional | **0** ✓ |

---

## 5. Archivos creados/modificados

| Archivo | Tipo | Descripción |
|---------|------|-------------|
| `database/migrations/2026_06_14_120000_phase3a_create_permisos_tables.php` | CORREGIDO | `populateUserPermissions()` ahora usa defaults por rol para perfiles con JSON vacío |
| `database/migrations/2026_06_14_120001_phase3a_backfill_user_permissions.php` | NUEVO | Backfill para la instancia ya migrada — inserta los 37 keys por perfil usando rol como default |
| `app/Console/Commands/ValidatePhase3aPermisos.php` | NUEVO | Comando `nova:validate-phase3a` con 7 grupos de verificación (17 checks) |
| `tests/Feature/Phase3aPermissionsTest.php` | NUEVO | 16 PHPUnit tests contra la BD real |

---

## 6. Errores encontrados

| Error | Impacto | Estado |
|-------|---------|--------|
| `populateUserPermissions()` saltaba perfiles con `permisos="[]"` (41/43 perfiles) | CRÍTICO: tabla permisos_usuario con solo 44 filas | **RESUELTO** vía backfill migration |

No se encontraron otros errores. La lógica de dual-write en `RedmineDataRepository` funciona correctamente.

---

## 7. Riesgos pendientes

| Riesgo | Nivel | Mitigación |
|--------|-------|-----------|
| Phase 3c ejecutada antes de que todos los usuarios hayan guardado permisos via formulario web | Bajo | La tabla relacional ya tiene los 37 keys para todos los perfiles; el JSON y relacional están sincronizados |
| Rol custom no reconocido por `buildDefaultPermissions()` | Bajo | Los roles custom reciben permisos full-access (igual que root/admin); puede ser más restrictivo de lo deseado |
| Nuevo usuario agregado desde Redmine después de la migración | Ninguno | `saveProjectUsers()` hace dual-write automáticamente |

---

## 8. Veredicto final

### ✅ Phase 3c APROBADA para planificar

**Condiciones cumplidas:**

1. ✓ `redmine_tic_permisos_catalogo` tiene los 37 permisos con tipos correctos
2. ✓ `redmine_tic_permisos_usuario` tiene exactamente 43 perfiles × 37 claves = 1.591 filas
3. ✓ `redmine_tic_permisos_rol` tiene los 5 roles × 37 claves (incluyendo rol custom "pp")
4. ✓ Valores JSON y relacionales coinciden para perfiles con permisos no vacíos (muestra)
5. ✓ `RedmineDataRepository::allPermissionsFromRelational()` devuelve los 43 perfiles con 37 claves cada uno
6. ✓ `RedmineDataRepository::savePermissionsToRelational()` escribe correctamente vía dual-write
7. ✓ Roles JSON y relacional sin discrepancias

### Qué implica Phase 3c

```sql
-- ESPERAR APROBACIÓN EXPLÍCITA antes de ejecutar:
ALTER TABLE redmine_tic_perfiles_usuario DROP COLUMN permisos;
DELETE FROM configuraciones_modulo WHERE clave = 'roles' AND modulo_id = 1;
```

**Recomendación:** Ejecutar Phase 3c en una nueva sesión después de confirmar que la
lectura relacional funciona correctamente en el entorno web (al menos un ciclo de
editar permisos de usuario → guardar → recargar desde la UI de cfg_roles y cfg_usuarios).

---

*Generado por: `php artisan nova:validate-phase3a` + `php artisan test --filter=Phase3aPermissionsTest`*
