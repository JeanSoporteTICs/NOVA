<?php

/*
| Copiar este bloque dentro de config/modules.php.
| Reemplazar "nuevo-proyecto" por el nombre de carpeta dentro de NOVA.
*/

'nuevo-proyecto' => [
    'name' => 'Nuevo Proyecto',
    'description' => 'Descripcion del modulo.',
    'type' => 'legacy',
    'path' => base_path('nuevo-proyecto'),
    'entry' => 'index.php',
    'allowed_static_roots' => [
        'assets',
        'data/procedimientos/documentos',
        'data/procedimientos/imagenes',
    ],
    'allowed_php_roots' => [
        '',
        'views',
        'controllers',
        'app',
    ],
],

