<?php
// Sistema visual NOVA v2: tema claro/oscuro (antes de pintar), Plus Jakarta Sans y nova-v2.css.
// Incluir dentro de <head>, después de nova-ui.css y de los temas propios del módulo.
// Solo actúa en páginas con <body class="nova-v2"> y el contenido dentro de #page-content.
$novaV2Asset = static function (string $relative): string {
    $file = function_exists('public_path') ? public_path($relative) : __DIR__.'/../../../public/'.$relative;
    $url = function_exists('asset') ? asset($relative) : '/NOVA/public/'.$relative;

    return htmlspecialchars($url.'?v='.(@filemtime($file) ?: '1'), ENT_QUOTES, 'UTF-8');
};
include __DIR__.'/nova-v2-theme-head.php';
?>
<link href="<?= $novaV2Asset('assets/fonts/plus-jakarta-sans/plus-jakarta-sans.css') ?>" rel="stylesheet">
<link href="<?= $novaV2Asset('assets/nova-v2.css') ?>" rel="stylesheet">
