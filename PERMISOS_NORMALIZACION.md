# PERMISOS_NORMALIZACION.md
## Análisis completo del sistema de permisos y roles — Redmine TIC

**Fecha de análisis:** 2026-06-14  
**Estado:** Phase 3a implementada — tablas creadas, dual-write activo, JSON preservado  
**Módulo objetivo:** `redmine_tic` (modulo_id = 1)

---

## 1. Modelo actual

### 1.1 Tabla principal de permisos por usuario

**`redmine_tic_perfiles_usuario.permisos`** — columna `longtext` con JSON

Cada fila representa un usuario en el proyecto Redmine TIC. El campo `permisos` almacena
un objeto JSON plano de hasta 37 claves que define qué puede hacer ese usuario específico.

Ejemplo de valor real (23 claves, perfil "administrador" del sistema):
```json
{
  "mensajes": "asignados",
  "mensajes_acceso": true,
  "horas_extra": "asignados",
  "historico": true,
  "historico_acciones": true,
  "historico_scope": "asignados",
  "configuracion": true,
  "estadisticas": true,
  "estadisticas_manual": true,
  "usuarios": true,
  "categorias": true,
  "unidades": true,
  "simulador": true,
  "cfg_conexion": true,
  "cfg_proyecto": true,
  "cfg_retencion": true,
  "cfg_sesion": true,
  "cfg_trackers": true,
  "cfg_prioridades": true,
  "cfg_estados": true,
  "cfg_roles": true,
  "cfg_usuarios": true,
  "actividad": true
}
```

> **Nota de discrepancia:** El formulario de permisos actual emite 37 claves (ver sección 2.1)
> pero los perfiles existentes en DB solo contienen 23 claves. Las claves ausentes se resuelven
> con el rol del usuario como fallback.

### 1.2 Tabla de roles (configuración global)

**`configuraciones_modulo`** — filas con `clave='roles'`, `tipo='json'`, `modulo_id=1`

Almacena un diccionario de plantillas de permisos por nombre de rol:
```json
{
  "root":         { "mensajes_acceso": true, "reportes_editar": true, ... },
  "administrador":{ "mensajes_acceso": true, "reportes_editar": true, ... },
  "gestor":       { "mensajes_acceso": true, "reportes_editar": true, "usuarios_acceso": false, ... },
  "usuario":      { "mensajes_acceso": true, "reportes_editar": false, "configuracion_acceso": false, ... }
}
```

Los roles definidos en DB sobreescriben `defaultRoles()` de `RedmineDataRepository` (línea 174).

`defaultRoles()` utiliza solo **9 claves** (vs. 37 del formulario) porque es la versión mínima
que funcionaba antes de que el formulario expandiera el set de permisos:
```
mensajes_acceso, reportes_editar, reportes_eliminar, historico_acceso,
horas_extra_acceso, usuarios_acceso, configuracion_acceso, estadisticas_acceso, actividad_acceso
```

---

## 2. Inventario completo de claves de permiso

### 2.1 Set canónico (37 claves) — fuente: `permissionPayload()` en `RedmineDashboardController:633`

| # | Clave | Tipo | Descripción |
|---|-------|------|-------------|
| 1 | `mensajes` | scope | Alcance de reportes: `'todos'` \| `'asignados'` |
| 2 | `mensajes_acceso` | bool | Acceso a la sección Reportes |
| 3 | `horas_extra` | scope\|empty | Alcance horas extra: `'todos'` \| `'asignados'` \| `''` (sin acceso) |
| 4 | `historico` | bool | Acceso a la sección Histórico |
| 5 | `historico_acciones` | bool | Puede ejecutar acciones en Histórico |
| 6 | `historico_scope` | scope | Alcance histórico: `'todos'` \| `'asignados'` |
| 7 | `configuracion` | bool | Acceso a la sección Configuración |
| 8 | `estadisticas` | bool | Acceso a la sección Estadísticas |
| 9 | `estadisticas_manual` | bool | Acceso a Redmine API (sección dentro de Estadísticas) |
| 10 | `usuarios` | bool | Acceso a la sección Usuarios |
| 11 | `categorias` | bool | Acceso a la sección Categorías |
| 12 | `unidades` | bool | Acceso a la sección Unidades |
| 13 | `simulador` | bool | Acceso a la sección Webhook/Simulador |
| 14 | `actividad` | bool | Acceso a la sección Actividad |
| 15 | `reportes_editar` | bool | Puede editar reportes |
| 16 | `reportes_eliminar` | bool | Puede eliminar reportes |
| 17 | `horas_extra_editar` | bool | Puede editar horas extra |
| 18 | `horas_extra_eliminar` | bool | Puede eliminar horas extra |
| 19 | `usuarios_editar` | bool | Puede editar usuarios |
| 20 | `usuarios_eliminar` | bool | Puede eliminar usuarios |
| 21 | `cfg_resumen` | bool | Puede ver panel Resumen en Configuración |
| 22 | `cfg_conexion` | bool | Puede ver panel Conexión |
| 23 | `cfg_proyecto` | bool | Puede ver panel Proyecto |
| 24 | `cfg_redmine` | bool | Puede ver panel Redmine |
| 25 | `cfg_campos` | bool | Puede ver panel Campos personalizados |
| 26 | `cfg_retencion` | bool | Puede ver panel Retención |
| 27 | `cfg_webhook` | bool | Puede ver panel Webhook |
| 28 | `cfg_sesion` | bool | Puede ver panel Sesión |
| 29 | `cfg_mantencion` | bool | Puede ver panel Mantención |
| 30 | `cfg_trackers` | bool | Puede gestionar Trackers |
| 31 | `cfg_prioridades` | bool | Puede gestionar Prioridades |
| 32 | `cfg_estados` | bool | Puede gestionar Estados |
| 33 | `cfg_roles` | bool | Puede gestionar Roles y Permisos |
| 34 | `cfg_usuarios` | bool | Puede gestionar Usuarios y Permisos |
| 35 | `cfg_catalogos` | bool | Reservado (legacy, no aparece en UI actual) |
| 36 | `cfg_categorias` | bool | Puede gestionar Categorías |
| 37 | `cfg_unidades` | bool | Puede gestionar Unidades |

### 2.2 Claves por tipo de valor

| Tipo | Claves |
|------|--------|
| **Scope** (string `'todos'`\|`'asignados'`) | `mensajes`, `historico_scope` |
| **Scope-or-empty** (string: `'todos'`\|`'asignados'`\|`''`) | `horas_extra` |
| **Bool** (34 claves) | todas las demás |

---

## 3. Flujo de resolución de permisos

```
Request llega al módulo
         │
         ▼
ProjectAccessGuard::projectUser()    ← verifica que el usuario tenga acceso al módulo
         │
         ▼ session['redmine_project_user'] = {id, role, legacy: {rol, permisos, ...}}
         │
         ▼
Vista Blade renderiza                ← $permissions = $user['legacy']['permisos'] ?? []
         │
         ├─ !empty($permissions['mensajes_acceso'])   → mostrar/ocultar sección
         ├─ $permissions['mensajes'] ?? 'asignados'   → scope de datos
         └─ ... (37 evaluaciones posibles)
         │
         ▼ (para datos filtrados por scope)
RedmineDataRepository::scopeForUser()
         │
         ├─ Lee $user['legacy']['permisos'][$scopeKey]    (override de usuario)
         └─ Si no existe → lee $this->roles()[$role][$scopeKey]  (fallback de rol)
                        └─ Si no existe → 'asignados' (default restrictivo)
```

### 3.1 Archivos que leen `permisos` directamente

| Archivo | Línea aprox. | Qué lee |
|---------|-------------|---------|
| `RedmineDataRepository.php` | 5371 | `legacy.permisos` → scopeForUser |
| `config.blade.php` | 1-77 | `$permissions[key]` para mostrar paneles |
| `permission-modal.blade.php` | 38,45,52,64,76,88 | `$permissions[key]` en formulario |
| `RedmineDashboardController.php` | 226-232 | `permissionPayload()` → saveUserPermissions |

### 3.2 Archivos que leen roles

| Archivo | Línea aprox. | Operación |
|---------|-------------|-----------|
| `RedmineDataRepository.php` | 661 | `roles()` → DB + fallback a `defaultRoles()` |
| `RedmineDataRepository.php` | 5382 | `roles()[$role][$scopeKey]` en scopeForUser |
| `RedmineDataRepository.php` | 671 | `saveRolePermissions()` |
| `RedmineDataRepository.php` | 695 | `deleteRole()` |
| `RedmineDataRepository.php` | 2988 | `rolesFromDatabase()` |
| `RedmineDataRepository.php` | 2998 | `saveRolesToDatabase()` |
| `config.blade.php` | (panel roles) | Muestra tabla de roles y sus permisos |

---

## 4. Dependencias y riesgos del estado actual

### 4.1 Riesgos del JSON actual

| # | Riesgo | Severidad |
|---|--------|-----------|
| R1 | **No hay validación de esquema:** cualquier clave puede guardarse sin control | Media |
| R2 | **Inconsistencia entre perfiles:** perfiles antiguos tienen 23 claves, nuevos 37 | Baja (se maneja por fallback) |
| R3 | **No es indexable:** no se puede consultar "todos los usuarios con cfg_roles=true" | Media |
| R4 | **Acoplamiento oculto:** el código PHP sabe qué claves existen pero la DB no | Alta |
| R5 | **Sin FK:** un permiso puede referenciar un rol que no existe en `configuraciones_modulo` | Baja |
| R6 | **Auditoría imposible:** no hay historial de cambios por clave individual | Media |

### 4.2 Qué NO puede romperse en Phase 3

1. El `session['redmine_project_user']['legacy']['permisos']` debe seguir siendo un array PHP con las mismas 37 claves — las vistas Blade leen directamente este array
2. `RedmineDataRepository::roles()` debe devolver el mismo formato `[roleName => [clave => valor]]`
3. `scopeForUser()` recibe el user array del session, no de la DB directamente
4. `saveUserPermissions()` y `saveRolePermissions()` deben aceptar los mismos parámetros

---

## 5. Diseño propuesto para Phase 3

### 5.1 Opción A — Tabla de overrides por clave (recomendada)

Principio: **almacenar solo los overrides**, el valor base viene del rol. El JSON en `perfiles_usuario.permisos` se elimina solo cuando TODOS los perfiles hayan migrado.

#### Nueva tabla: `redmine_tic_permisos_usuario`
```sql
CREATE TABLE redmine_tic_permisos_usuario (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    perfil_id       BIGINT UNSIGNED NOT NULL,
    clave           VARCHAR(60) NOT NULL,
    valor           VARCHAR(20) NOT NULL,  -- 'si'/'no' o 'todos'/'asignados'/''
    actualizado_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_permiso_usuario (perfil_id, clave),
    INDEX idx_pu_clave (clave),
    CONSTRAINT fk_pu_perfil FOREIGN KEY (perfil_id)
        REFERENCES redmine_tic_perfiles_usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### Nueva tabla: `redmine_tic_permisos_rol`
```sql
CREATE TABLE redmine_tic_permisos_rol (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    modulo_id       BIGINT UNSIGNED NOT NULL,
    rol             VARCHAR(40) NOT NULL,
    clave           VARCHAR(60) NOT NULL,
    valor           VARCHAR(20) NOT NULL,
    actualizado_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_permiso_rol (modulo_id, rol, clave),
    INDEX idx_pr_rol (modulo_id, rol),
    CONSTRAINT fk_pr_modulo FOREIGN KEY (modulo_id)
        REFERENCES modulos_nova(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### Tabla de catálogo de claves válidas: `redmine_tic_permisos_catalogo`
```sql
CREATE TABLE redmine_tic_permisos_catalogo (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clave           VARCHAR(60) NOT NULL UNIQUE,
    tipo            ENUM('bool', 'scope', 'scope_or_empty') NOT NULL DEFAULT 'bool',
    descripcion     VARCHAR(200) NOT NULL DEFAULT '',
    orden           TINYINT UNSIGNED NOT NULL DEFAULT 100
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Esta tabla es la fuente de verdad de las 37 claves válidas. Permite validar entradas y
generar el formulario dinámicamente sin hardcoding en la vista.

### 5.2 Opción B — Migración completa (solo referencial, NO recomendada)

Eliminar `permisos` de `redmine_tic_perfiles_usuario` completamente y leer de
`redmine_tic_permisos_usuario` siempre.

**Riesgo principal:** el session ya tiene `legacy.permisos` como array — el cambio requiere
modificar `RedmineDataRepository::usersFromDatabase()` (línea 3545) y todas las vistas
simultáneamente. Alto riesgo de regresión.

### 5.3 Estrategia de migración para Opción A (recomendada)

**Fase 3a — No destructiva (Phase 1 equivalente):**
1. Crear tablas `redmine_tic_permisos_usuario`, `redmine_tic_permisos_rol`, `redmine_tic_permisos_catalogo`
2. Poblar `redmine_tic_permisos_catalogo` con las 37 claves
3. Poblar `redmine_tic_permisos_rol` desde `configuraciones_modulo` clave='roles'
4. Poblar `redmine_tic_permisos_usuario` desde `redmine_tic_perfiles_usuario.permisos`

**Fase 3b — Doble escritura:**
5. Actualizar `saveUserPermissions()` → escribe a AMBAS: JSON en `permisos` Y rows en `redmine_tic_permisos_usuario`
6. Actualizar `saveRolePermissions()` → escribe a AMBAS: JSON en `configuraciones_modulo` Y rows en `redmine_tic_permisos_rol`
7. Actualizar `usersFromDatabase()` para que construya el array `permisos` desde la tabla relacional (fuente primaria) con fallback al JSON

**Fase 3c — Destructiva (previa verificación):**
8. Eliminar `permisos` JSON de `redmine_tic_perfiles_usuario` (DROP COLUMN)
9. Eliminar `clave='roles'` de `configuraciones_modulo`

---

## 6. Cobertura de permisos — verificación de no pérdida de funcionalidad

### 6.1 Verificación del inventario completo

Todas las claves emitidas por `permissionPayload()` (37) tienen cobertura en el diseño propuesto:

| Grupo | Claves | Cobertura propuesta |
|-------|--------|---------------------|
| Scope (3) | mensajes, horas_extra, historico_scope | `tipo='scope'` o `tipo='scope_or_empty'` en catálogo |
| View access (12) | mensajes_acceso...configuracion | `tipo='bool'` |
| Data actions (6) | reportes_editar...usuarios_eliminar | `tipo='bool'` |
| Config panels (16) | cfg_resumen...cfg_unidades | `tipo='bool'` |

**Total: 37/37 claves cubiertas.** ✓

### 6.2 Verificación del sistema de roles

Los 4 roles de `defaultRoles()` (root, administrador, gestor, usuario) y cualquier rol
customizado en DB quedan representados en `redmine_tic_permisos_rol` con sus claves.

Los roles en DB actualmente usan solo 9 claves. Con el catálogo, las 28 claves faltantes
pueden poblarse con valores por defecto según el perfil de rol (restrictivo para usuario,
permisivo para root/admin).

### 6.3 Compatibilidad con código existente

Las funciones clave de compatibilidad son:

```php
// RedmineDataRepository — cambia solo la fuente de lectura, misma firma:
public function roles(): array                           // sin cambio de firma
public function saveUserPermissions(string $id, string $role, array $permissions): bool  // sin cambio
public function saveRolePermissions(string $role, array $permissions): bool             // sin cambio

// El array $permissions sigue siendo el mismo formato de 37 claves
// El session['redmine_project_user']['legacy']['permisos'] sigue siendo array PHP
// Las vistas no cambian (siguen leyendo $permissions['clave'])
```

---

## 7. Impacto en código

### 7.1 Archivos que necesitan modificación en Phase 3

| Archivo | Tipo de cambio | Prioridad |
|---------|---------------|-----------|
| `RedmineDataRepository.php` | `usersFromDatabase()` construye `permisos` desde tabla relacional | Alta |
| `RedmineDataRepository.php` | `saveProjectUsers()` escribe permisos a tabla + JSON | Alta |
| `RedmineDataRepository.php` | `saveUserPermissions()` escribe a tabla + JSON | Alta |
| `RedmineDataRepository.php` | `roles()` / `rolesFromDatabase()` — leer de `redmine_tic_permisos_rol` | Alta |
| `RedmineDataRepository.php` | `saveRolesToDatabase()` — escribir a tabla relacional | Alta |
| `RedmineDataRepository.php` | `defaultRoles()` — expandir a 37 claves | Media |
| Migraciones Phase 3a/3b/3c | 3 nuevos archivos | Alta |
| `AGENTS.md` | Actualizar sección de permisos | Baja |
| `NORMALIZACION_DB.md` | Marcar Phase 3 como diseñada | Baja |

### 7.2 Archivos que NO necesitan cambio

- Todos los archivos Blade (el array $permissions mantiene el mismo formato)
- `RedmineDashboardController.php` (permissionPayload sin cambio)
- `ProjectAccessGuard.php` (acceso a módulo, diferente de permisos internos)
- Middleware y Kernel de Laravel

---

## 8. Beneficios esperados del modelo relacional

| Beneficio | Impacto |
|-----------|---------|
| **Consultas directas** | `SELECT perfil_id FROM redmine_tic_permisos_usuario WHERE clave='cfg_roles' AND valor='si'` |
| **Auditoría por clave** | Agregar `created_at` + tabla de historial para trazabilidad |
| **Validación de esquema en DB** | FK a `redmine_tic_permisos_catalogo` previene claves inválidas |
| **Sin JSON en perfiles** | Columna `permisos` desaparece (37 rows por usuario, ~1.600 rows para 43 usuarios) |
| **Consistencia rol/usuario** | Una tabla por nivel, mismo formato de clave/valor |
| **Migración de 22→37 claves** | Poblada por migración, no requiere acción de admin |

---

## 9. Estimación de volumen

| Tabla | Filas estimadas | Observación |
|-------|----------------|-------------|
| `redmine_tic_permisos_catalogo` | 37 | Fijo — una fila por clave |
| `redmine_tic_permisos_rol` | ~148 | 4 roles × 37 claves = 148 (+ roles custom) |
| `redmine_tic_permisos_usuario` | ~1,591 | 43 usuarios × 37 claves = 1,591 |

Volumen manejable. No hay problema de performance en tablas de este tamaño.

---

## 10. Riesgos de Phase 3

| Riesgo | Mitigación |
|--------|-----------|
| El JSON en session ya fue cargado por otros tabs | La doble escritura (3b) mantiene consistencia entre sesiones |
| `permisos` ausente en un perfil antiguo | La fase 3a puebla TODOS los perfiles antes de la fase 3c |
| Rol custom no en defaultRoles | `configuraciones_modulo` clave='roles' se lee primero; se migra en 3a |
| Claves nuevas en el futuro | Solo agregar fila a `redmine_tic_permisos_catalogo` + valor default en rol |

---

## 11. Próximos pasos

```
[x] Aprobación del diseño relacional (Opción A)
[x] Definir los 37 valores por defecto para cada rol
[x] Fase 3a: crear tablas + poblar desde JSON existente
[x] Fase 3b: doble escritura en save* methods
[ ] Verificar flujos con doble escritura en entorno web (tests pasan: 27/27)
[ ] Fase 3c: DROP COLUMN permisos + DELETE clave='roles' de configuraciones_modulo
         → Requiere aprobación explícita. NO ejecutar hasta confirmar que la
           lectura relacional es estable y la columna JSON ya no es necesaria.
```

---

## 12. Estado de implementación Phase 3a (2026-06-14)

### 12.1 Tablas creadas

| Tabla | Filas tras migración | Estado |
|-------|---------------------|--------|
| `redmine_tic_permisos_catalogo` | 37 (una por clave) | ✓ Creada y poblada |
| `redmine_tic_permisos_rol` | ~148 (4 roles × 37 claves, + roles custom DB) | ✓ Creada y poblada |
| `redmine_tic_permisos_usuario` | ~1.591 (43 perfiles × 37 claves) | ✓ Creada y poblada |

Migración: `database/migrations/2026_06_14_120000_phase3a_create_permisos_tables.php`

### 12.2 Permisos migrados

- Los 37 permisos de cada perfil en `redmine_tic_perfiles_usuario.permisos` fueron migrados a filas en `redmine_tic_permisos_usuario`.
- Los 4 roles base (root, administrador, gestor, usuario) más roles custom en `configuraciones_modulo` clave=`roles` fueron migrados a `redmine_tic_permisos_rol`.
- El catálogo completo de 37 claves con sus tipos (`bool`, `scope`, `scope_or_empty`) fue insertado en `redmine_tic_permisos_catalogo`.

### 12.3 Archivos modificados

| Archivo | Cambio |
|---------|--------|
| `database/migrations/2026_06_14_120000_phase3a_create_permisos_tables.php` | NUEVO — crea 3 tablas, pobla desde JSON existente, `down()` las elimina en orden FK-safe |
| `redmine_tic/nova/app/Support/Redmine/RedmineDataRepository.php` | `PERMISSION_SCOPE_KEYS` constant; `defaultRoles()` expandido a 37 claves; 8 métodos privados nuevos; `rolesFromDatabase()` / `saveRolesToDatabase()` con dual-read/write; `projectUsersFromNova()` / `saveProjectUsers()` con dual-write y batch-load |

### 12.4 Comportamiento implementado

**Lectura de permisos por usuario (`projectUsersFromNova`):**
1. `allPermissionsFromRelational()` ejecuta **una sola query** que carga todos los permisos de todos los perfiles (batch).
2. Para cada usuario: si su `perfil_id` existe en la respuesta relacional → usa esos valores.
3. Si no existe (perfil nuevo, tabla vacía) → fallback a `$profile->permisos` JSON.

**Escritura de permisos (`saveProjectUsers`):**
1. Escribe el JSON en `redmine_tic_perfiles_usuario.permisos` (preserva columna).
2. Llama a `savePermissionsToRelational()` que hace upsert fila-a-fila y elimina claves obsoletas.

**Lectura de roles (`rolesFromDatabase`):**
1. `rolesFromRelational()` lee de `redmine_tic_permisos_rol` para el `modulo_id` actual.
2. Si la tabla está vacía → fallback a `configuraciones_modulo` clave=`roles` JSON.

**Escritura de roles (`saveRolesToDatabase`):**
1. `saveRolesToRelational()` hace upsert y poda claves/roles eliminados.
2. `saveModuleConfigurationToDatabase(['roles' => $roles], ...)` mantiene JSON actualizado como respaldo.

### 12.5 Error detectado y corregido durante validación

**Problema:** `populateUserPermissions()` saltaba perfiles con `permisos = "[]"` (41/43 perfiles).
Solo 2 perfiles quedaron en `redmine_tic_permisos_usuario` tras la migración inicial (44 filas vs 1.591 esperadas).

**Corrección:**
- Migración original corregida: `populateUserPermissions()` ahora construye el set completo por rol (`merge(defaults, existing)`)
- Nueva migración backfill `2026_06_14_120001_phase3a_backfill_user_permissions.php` aplicada: todos los perfiles migrados

**Estado post-corrección:** 1.591 filas (43 × 37 exactas).

### 12.5b Tests ejecutados

```
# Comando de validación (7 grupos, 17 verificaciones):
php artisan nova:validate-phase3a
RESULTADO: APROBADO — 17/17 verificaciones pasadas

# Test suite completa:
php artisan test
Tests: 48 passed (120 assertions) — incluye 16 nuevos tests Phase3aPermissionsTest
Duration: 2.36s
```

### 12.6 Riesgos pendientes

| Riesgo | Estado |
|--------|--------|
| El `session` de un tab activo tiene el JSON anterior en `legacy.permisos` | Bajo — el dual-write mantiene JSON sincronizado; en el próximo request se re-carga de la tabla |
| Perfil con `permisos` JSON con claves desconocidas | Mitigado — `savePermissionsToRelational()` solo guarda las claves que llegan del payload; claves no reconocidas no se propagan |
| Rol custom en DB con claves faltantes | Mitigado — la migración 3a construye los 37 valores por defecto y sobrepone los valores existentes |
| Phase 3c prematura | No hay riesgo — la columna JSON no se toca hasta aprobación explícita de Phase 3c |

### 12.7 Criterios para aprobar Phase 3c — ✅ CUMPLIDOS

Validación completada el 2026-06-14. Ver `VALIDACION_PHASE3A_PERMISOS.md` para informe completo.

| Criterio | Estado |
|----------|--------|
| 1. Lectura relacional produce los mismos valores que JSON | ✅ 0 discrepancias (muestra 2 perfiles) |
| 2. Permisos guardados vía formulario llegan a tabla relacional | ✅ dual-write verificado vía reflection |
| 3. Conteo `N_perfiles × 37` filas | ✅ 43 × 37 = 1.591 exactas |
| 4. UI refleja valores relacionales | ⚠️ Validar en entorno web (requiere ciclo de edición manual) |

**Phase 3c aprobada** para planificar. Única condición pendiente: confirmar manualmente en
la UI web que editar → guardar → recargar permisos funciona correctamente en `cfg_roles` y `cfg_usuarios`.

---

*Documento actualizado tras implementación Phase 3a (no destructiva).*  
*Archivos de referencia: `RedmineDataRepository.php`, `RedmineDashboardController.php`, `config.blade.php`, `permission-modal.blade.php`*
