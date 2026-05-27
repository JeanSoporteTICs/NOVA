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

## Tokens

La plantilla define variables `--nova-*` para colores, radios, sombras, foco y fuente. Los modulos pueden usar estas variables sin redefinir paletas propias.
