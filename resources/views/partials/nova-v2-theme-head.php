<?php
// Tema claro/oscuro NOVA v2. Incluir dentro de <head>,
// antes de los estilos: marca <html class="nova-v2-dark"> antes de pintar para evitar el destello.
?>
<script>
  // Modo claro/oscuro NOVA v2: se decide antes de pintar para evitar el destello.
  // Preferencia guardada o, si no hay, la del sistema. Solo marca <html>; los colores
  // aplican únicamente con <body class="nova-v2"> (ver resources/views/partials/nova-v2-theme-*.php y public/assets/nova-v2.css).
  (function () {
    try {
      var saved = localStorage.getItem('nova-mantencion-theme');
      var dark = saved ? saved === 'dark' : !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
      document.documentElement.classList.toggle('nova-v2-dark', dark);
    } catch (e) { /* sin storage: queda en claro */ }
  })();
</script>
