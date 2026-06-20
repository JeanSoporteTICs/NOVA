# NOVA UI Template

Este proyecto usa `public/assets/nova-ui.css` como capa de estilo compartida para NOVA y módulos legacy.

## Como usarlo

Incluye la hoja despues de Bootstrap y despues del `theme.css` del modulo:

```html
<link href="/NOVA/public/assets/nova-ui.css" rel="stylesheet">
```

En Blade usa:

```blade
<link href="{{ asset('assets/nova-ui.css') }}" rel="stylesheet">
```

## Convenciones

- Botones: usar `.btn`, `.btn-primary`, `.btn-outline-secondary`, `.btn-success`, `.btn-warning`, `.btn-danger`.
- Tablas: usar `.table`; envolver tablas anchas con `.table-responsive` o `.nova-table-wrap`.
- Modales: usar estructura Bootstrap estándar `.modal-content`, `.modal-header`, `.modal-body`, `.modal-footer`.
- Formularios: usar `.form-label`, `.form-control`, `.form-select`, `.form-check-input`.
- Contenedores: usar `.card` o `.nova-card` solo para elementos enmarcados, no para secciones completas.
- Estados: usar `.badge` o `.nova-badge` para contadores y estados cortos.

## Sistema S34 basado en Redmine TIC

Redmine TIC es la referencia visual global. Para nuevas pantallas o ajustes de estructura, preferir estas clases compartidas:

- `.nova-system-hero`: hero azul profundo para NOVA, Mantencion, EMACH y Telegram.
- `.nova-system-head`: encabezado operativo con icono, titulo, descripcion y metrica.
- `.nova-system-card`: card blanca con borde suave y sombra sutil.
- `.nova-system-toolbar`: fila de acciones responsive.
- `.nova-filter-panel`: panel para filtros y formularios de busqueda.
- `.nova-status-badge`: estado corto con variantes `is-success`, `is-warning`, `is-danger`, `is-info`.
- `.nova-log-panel`: salida de logs o consola con fondo oscuro.

La misma capa expone alias globales para patrones TIC: `.rm-module-head`, `.rm-module-head-icon`, `.rm-module-meter`, `.rm-module-grid`, `.rm-info-card` y `.rm-form-shell`.

## Tokens

La plantilla define variables `--nova-*` para colores, radios, sombras, foco y fuente. Los modulos pueden usar estas variables sin redefinir paletas propias.
