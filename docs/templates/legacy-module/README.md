# Plantilla para agregar un modulo legacy a NOVA

Copia esta carpeta como base cuando quieras integrar otro proyecto PHP legacy dentro de NOVA.

## 1. Ubicacion

El proyecto debe quedar dentro de la raiz de NOVA:

```text
C:\xampp\htdocs\NOVA\nuevo-proyecto
```

No usar:

```text
C:\xampp\htdocs\nuevo-proyecto
```

## 2. Registro en NOVA

Agrega el bloque de `module-config.php` dentro de `config/modules.php`.

Reemplaza:

- `nuevo-proyecto`
- `Nuevo Proyecto`
- `Descripcion del modulo`
- `index.php` si el entrypoint tiene otro nombre

## 3. Base URL del proyecto legacy

Si el proyecto tiene un archivo de configuracion, usa el patron de `config-app-example.php`.

La parte importante es:

```php
'base_url' => $env('APP_BASE_URL', function_exists('url') ? url('/nuevo-proyecto') : '/nuevo-proyecto'),
```

Esto evita que el proyecto salte a una carpeta externa de XAMPP.

## 4. Head compartido

En las vistas HTML del proyecto, incluye Bootstrap si lo usas, el CSS propio del modulo y al final `nova-ui.css`.

Usa `bootstrap-head.php` como ejemplo.

## 5. Boton para volver a NOVA

Agrega el snippet de `navbar-nova-button.php` en el navbar del proyecto.

Debe apuntar a:

```php
function_exists('url') ? url('/') : '/NOVA/public'
```

## 6. URL correcta

Abrir siempre desde NOVA:

```text
http://localhost/NOVA/public/nuevo-proyecto
```

