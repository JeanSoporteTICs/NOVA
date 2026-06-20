# Migracion Laravel 9 → 12

Fecha inicio: 2026-06-12
Fecha completada: 2026-06-13

## Contexto inicial

| Item | Valor |
|------|-------|
| Laravel | 9.52.21 |
| PHP real | 8.2.12 |
| PHP platform (composer.json) | 8.0.30 (incorrecto) |
| phpunit | ^9.5.10 |
| Tests pasando | 32 (ExampleTest x2 + NovaAuditRepositoryTest x1 + NovaValidationTest x2 + AuthTest x20 + ExampleTest feature x1 + ModuleAccessTest x11) |

**Backups creados antes de cualquier cambio:**
- `composer.json.backup-L9`
- `composer.lock.backup-L9`

---

## Paso 0 — Correccion de platform.php

**Problema:** `config.platform.php` estaba en `8.0.30` aunque PHP real es `8.2.12`. Esto forzaba a Composer a resolver dependencias como si el entorno fuera PHP 8.0, impidiendo instalar paquetes que requieren PHP >=8.1.

**Solucion:** Cambiar `"php": "8.0.30"` a `"php": "8.2.12"` en la seccion `config.platform` de `composer.json`.

---

## Etapa 1 — Laravel 9 → 10

### Cambios en composer.json

| Paquete | Antes | Despues |
|---------|-------|---------|
| php | ^8.0.2 | ^8.1 |
| laravel/framework | ^9.19 | ^10.0 |
| laravel/sanctum | ^3.0 | ^3.3 |
| laravel/tinker | ^2.7 | ^2.8 |
| phpunit/phpunit | ^9.5.10 | ^10.5 |
| nunomaduro/collision | ^6.1 | ^7.0 |
| spatie/laravel-ignition | ^1.0 | ^2.3 |
| mockery/mockery | ^1.4.4 | ^1.5.1 |
| laravel/sail | ^1.0.1 | ^1.26 |
| laravel/pint | ^1.0 | ^1.10 |

### Cambios de codigo L10

- `app/Http/Kernel.php`: `$routeMiddleware` renombrado a `$middlewareAliases` (Laravel 10 depreca el nombre antiguo).
- `phpunit.xml`: elemento `<coverage>` actualizado al formato PHPUnit 10 (se usa `<source>` en lugar de `<coverage><include>`).

### Resultado

- `composer update` exitoso. Laravel 9.52.21 → 10.50.2
- **32 tests pasando, 62 assertions, 1.03s**

---

## Etapa 2 — Laravel 10 → 11

### Cambios en composer.json

| Paquete | Antes | Despues |
|---------|-------|---------|
| php | ^8.1 | ^8.2 |
| laravel/framework | ^10.0 | ^11.0 |
| laravel/sanctum | ^3.3 | ^4.0 |
| laravel/tinker | ^2.8 | ^2.9 |
| nunomaduro/collision | ^7.0 | ^8.1 |
| spatie/laravel-ignition | ^2.3 | ^2.4 |
| mockery/mockery | ^1.5.1 | ^1.6.11 |
| laravel/sail | ^1.26 | ^1.31 |
| laravel/pint | ^1.10 | ^1.13 |

### Nota sobre estructura de la aplicacion en L11

En L11 las **aplicaciones nuevas** usan `bootstrap/app.php` unificado sin `app/Http/Kernel.php`, `app/Console/Kernel.php`, etc. Sin embargo, las aplicaciones que **actualizan desde L9/L10** conservan su estructura anterior sin cambios obligatorios. NOVA mantiene su estructura existente: `app/Http/Kernel.php`, `app/Console/Kernel.php`, `app/Exceptions/Handler.php`.

### Resultado

- `composer update` exitoso. Laravel 10.50.2 → 11.54.0
- **32 tests pasando, 62 assertions, 1.19s**

---

## Etapa 3 — Laravel 11 → 12

### Cambios en composer.json

| Paquete | Antes | Despues |
|---------|-------|---------|
| laravel/framework | ^11.0 | ^12.0 |
| laravel/tinker | ^2.9 | ^2.10 |
| laravel/pint | ^1.13 | ^1.13 (sin cambio — pint no tiene v2.x) |
| laravel/sail | ^1.31 | ^1.41 |
| mockery/mockery | ^1.6.11 | ^1.6.12 |
| nunomaduro/collision | ^8.1 | ^8.6 |
| phpunit/phpunit | ^10.5 | ^11.5 |
| spatie/laravel-ignition | ^2.4 | **REMOVIDO** |

### Problema encontrado y solucion

**Problema:** El plan inicial incluia `laravel/pint ^2.0`. No existe version 2.x de pint; el paquete permanece en la linea 1.x. Composer rechaza la resolucion con error inmediato.

**Solucion:** Mantener `laravel/pint ^1.13`. No hay cambio funcional.

### Por que se elimina spatie/laravel-ignition

`spatie/laravel-ignition ^2.x` requiere `laravel/framework ^10.0|^11.0`. Laravel 12 no esta en ese rango. Al remover el paquete, Composer puede resolver todas las dependencias sin conflicto. En produccion, Laravel 12 muestra errores con su propio handler nativo sin necesidad de Ignition.

### Resultado

- `composer update` exitoso. Laravel 11.54.0 → 12.62.0
- **32 tests pasando, 62 assertions, 1.48s**
- Sin vulnerabilidades de seguridad (`No security vulnerability advisories found`)

---

## Estado final

| Item | Antes | Despues |
|------|-------|---------|
| Laravel | 9.52.21 | **12.62.0** |
| PHP requerido | ^8.0.2 | ^8.2 |
| PHP platform config | 8.0.30 | 8.2.12 |
| PHPUnit | ^9.5 | **^11.5** (11.5.55) |
| Collision | ^6.1 | ^8.6 (8.9.4) |
| Sanctum | ^3.0 | **^4.0** (4.3.2) |
| spatie/laravel-ignition | ^1.0 | removido |
| Tests | 32 pasando | **32 pasando** |
| Vulnerabilidades | 1 advisory | **0** |

---

## Resumen de cambios de codigo

| Archivo | Cambio | Etapa |
|---------|--------|-------|
| `composer.json` | platform.php 8.0.30 → 8.2.12 | Paso 0 |
| `app/Http/Kernel.php` | `$routeMiddleware` → `$middlewareAliases` | L9→L10 |
| `phpunit.xml` | `<coverage>` → `<source>` (PHPUnit 10 format) | L9→L10 |
| `composer.json` | Versiones de paquetes (3 etapas) | L9→L12 |

No fue necesario modificar ningun archivo de logica de negocio. La estructura antigua de app (Kernel.php, Handler.php, Console/Kernel.php) es totalmente compatible con Laravel 12 en modo upgrade.

---

## Rollback

Si algo falla:
```bash
cp composer.json.backup-L9 composer.json
cp composer.lock.backup-L9 composer.lock
php composer.phar install --no-scripts
```
