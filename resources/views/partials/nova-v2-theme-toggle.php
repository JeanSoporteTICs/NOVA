<?php
// Interruptor claro/oscuro NOVA v2 (.nova-theme-toggle).
// Incluir en las acciones del navbar. Solo actúa en páginas con <body class="nova-v2">.
?>
<script>
  // Tema NOVA v2: Bootstrap 5.3 en oscuro (data-bs-theme) solo en páginas NOVA v2.
  if (document.body && document.body.classList.contains('nova-v2') && document.documentElement.classList.contains('nova-v2-dark')) {
    document.body.setAttribute('data-bs-theme', 'dark');
  }
</script>
<button type="button" class="nova-theme-toggle" id="mnt-theme-toggle" aria-pressed="false" aria-label="Activar modo oscuro" title="Activar modo oscuro">
  <span class="nova-theme-toggle-track" aria-hidden="true">
    <i class="bi bi-sun-fill nova-theme-toggle-glyph nova-theme-toggle-sun"></i>
    <i class="bi bi-moon-stars-fill nova-theme-toggle-glyph nova-theme-toggle-moon"></i>
    <span class="nova-theme-toggle-thumb"><i class="bi bi-sun" data-theme-icon></i></span>
  </span>
</button>
<script>
(function () {
  const KEY = 'nova-mantencion-theme';
  const root = document.documentElement;
  const toggle = document.getElementById('mnt-theme-toggle');
  if (!toggle || !document.body.classList.contains('nova-v2')) { toggle?.remove(); return; }
  const icon = toggle.querySelector('[data-theme-icon]');
  const saved = () => { try { const v = localStorage.getItem(KEY); return v === 'dark' || v === 'light' ? v : null; } catch (e) { return null; } };
  const apply = (dark, persist) => {
    root.classList.toggle('nova-v2-dark', dark);
    if (dark) document.body.setAttribute('data-bs-theme', 'dark');
    else document.body.removeAttribute('data-bs-theme');
    toggle.classList.toggle('is-dark', dark);
    toggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
    const label = dark ? 'Activar modo claro' : 'Activar modo oscuro';
    toggle.setAttribute('aria-label', label);
    toggle.title = label;
    icon.className = 'bi ' + (dark ? 'bi-moon-stars' : 'bi-sun');
    if (persist) { try { localStorage.setItem(KEY, dark ? 'dark' : 'light'); } catch (e) { /* solo en memoria */ } }
    document.dispatchEvent(new CustomEvent('mnt-theme-change', { detail: { dark } }));
  };
  apply(root.classList.contains('nova-v2-dark'), false);
  toggle.addEventListener('click', () => {
    apply(!root.classList.contains('nova-v2-dark'), true);
    icon.classList.remove('nova-theme-icon-swap');
    requestAnimationFrame(() => icon.classList.add('nova-theme-icon-swap'));
  });
  icon.addEventListener('animationend', () => icon.classList.remove('nova-theme-icon-swap'));
  // Sin preferencia guardada se sigue al sistema, también si cambia en vivo.
  window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', event => {
    if (saved() === null) apply(event.matches, false);
  });
})();
</script>
