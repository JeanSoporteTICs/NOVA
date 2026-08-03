<?php
require_once __DIR__ . '/../../controllers/auth.php';
require_once __DIR__ . '/../../controllers/maintenance.php';
auth_start_session();
$h = $h ?? fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$activeNav = $activeNav ?? '';
$sessionTimeout = function_exists('app')
    ? app(\App\Modulos\Nova\Repositories\NovaSettingsRepository::class)->sessionTimeout()
    : auth_config_timeout();
$lastActivity = function_exists('session')
    ? (int) session('nova_last_activity', time())
    : (int) ($_SESSION['last_activity'] ?? time());
$remaining = max(0, $sessionTimeout - (time() - $lastActivity));
$role = auth_get_user_role();
$maintenanceSettings = maintenance_mode_settings();
$maintenanceMode = !empty($maintenanceSettings['enabled']);
$maintenanceUntil = maintenance_mode_until_text();
$maintenanceNoticeKey = sha1(($maintenanceSettings['started_at'] ?? '') . '|' . ($maintenanceSettings['until'] ?? '') . '|' . ($maintenanceMode ? '1' : '0'));
$mantencionBaseUrl = function_exists('url') ? rtrim(url('/redmine-mantencion'), '/') : '/redmine-mantencion';
$mantencionAppUrl = function_exists('url') ? rtrim(url('/redmine-mantencion/app'), '/') : '/redmine-mantencion/app';
$novaHomeUrl = function_exists('route') ? route('home') : '/NOVA/public/index.php';
$novaLogoutUrl = function_exists('route') ? route('logout') : $mantencionBaseUrl . '/logout.php';
$novaSessionExtendUrl = function_exists('route') ? route('session.extend') : $mantencionBaseUrl . '/session_extend.php';
$novaLoginUrl = function_exists('route') ? route('login') : $novaHomeUrl . '/login';
$novaCsrfToken = function_exists('csrf_token') ? csrf_token() : '';
$novaSessionIdentity = function_exists('session') ? (string) session('nova_user.id', '') : (string) ($_SESSION['user']['id'] ?? '');
$navItems = [
    ['key' => 'mensajes', 'label' => 'Reportes', 'href' => $mantencionAppUrl, 'icon' => 'bi-inboxes', 'can' => auth_can('mensajes_acceso')],
    ['key' => 'manual', 'label' => 'Pendiente manual', 'href' => '../Pendientes/manual.php', 'icon' => 'bi-pencil-square', 'can' => auth_can('simulador')],
    ['key' => 'horas', 'label' => 'Horas extra', 'href' => '../HorasExtra/horas_extra.php', 'icon' => 'bi-clock-history', 'can' => auth_can('horas_extra')],
    ['key' => 'historico', 'label' => 'Hist&oacute;rico', 'href' => '../Historico/historico.php', 'icon' => 'bi-archive', 'can' => auth_can('historico')],
    ['key' => 'mis_integraciones', 'label' => 'Cuentas conectadas', 'href' => $mantencionAppUrl . '/mis-integraciones', 'icon' => 'bi-person-lock', 'can' => auth_can('mis_integraciones')],
    ['key' => 'usuarios', 'label' => 'Usuarios', 'href' => '../Usuarios/usuarios.php', 'icon' => 'bi-people', 'can' => auth_can('usuarios')],
    [
        'key' => 'integraciones',
        'label' => 'Integraciones',
        'href' => '#',
        'icon' => 'bi-diagram-3',
        'can' => auth_can('integraciones_nextcloud'),
        'children' => [
            [
                'key' => 'integraciones_nextcloud',
                'label' => 'Nextcloud',
                'href' => '#',
                'icon' => 'bi-cloud',
                'can' => auth_can('integraciones_nextcloud'),
                'children' => [
                    ['key' => 'integraciones_nextcloud_usuarios', 'label' => 'Nextcloud', 'href' => '/redmine-mantencion/app/integraciones-nextcloud-usuarios', 'icon' => 'bi-cloud-plus', 'can' => auth_can('integraciones_nextcloud')],
                    ['key' => 'integraciones_nextcloud_grupos', 'label' => 'Grupos', 'href' => $mantencionAppUrl . '/configuracion?panel=nextcloud', 'icon' => 'bi-people', 'can' => auth_can('integraciones_nextcloud')],
                    ['key' => 'integraciones_nextcloud_historial', 'label' => 'Historial', 'href' => '/redmine-mantencion/app/integraciones-nextcloud-historial', 'icon' => 'bi-clock-history', 'can' => auth_can('integraciones_nextcloud')],
                ],
            ],
        ],
    ],
    ['key' => 'configuracion', 'label' => 'Configuraci&oacute;n', 'href' => '../Configuracion/configuracion.php', 'icon' => 'bi-sliders', 'can' => auth_can('configuracion') || auth_can('categorias')],
    ['key' => 'estadisticas', 'label' => 'Estad&iacute;sticas', 'href' => '../Estadisticas/estadisticas.php', 'icon' => 'bi-bar-chart-line', 'can' => auth_can('estadisticas')],
    ['key' => 'security', 'label' => 'Actividad reciente', 'href' => '../Security/activity.php', 'icon' => 'bi-activity', 'can' => auth_can('actividad')],
];
?>
<nav class="navbar navbar-expand-lg navbar-dark sb-navbar sb-native-navbar">
  <div class="container-fluid px-4">
    <div class="sb-navbar-top">
      <a class="navbar-brand sb-navbar-brand" href="<?= $h($mantencionAppUrl) ?>">
        <span class="sb-brand-mark"><i class="bi bi-layout-sidebar-inset"></i></span>
        <span>Redmine Mantenci&oacute;n</span>
      </a>
      <button class="nova-sidebar-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#novaSidebar" aria-controls="novaSidebar" aria-label="Abrir men&uacute; lateral">
        <i class="bi bi-list"></i>
      </button>
      <div class="sb-nav-actions d-flex align-items-center gap-2">
        <span class="sb-session-badge badge bg-light text-dark d-inline-flex align-items-center gap-1" id="session-timer" data-remaining="<?= $h($remaining) ?>" data-timeout="<?= $h($sessionTimeout) ?>">
          <i class="bi bi-clock"></i><span id="session-timer-text">--:--</span>
        </span>
        <?php if ($maintenanceMode): ?>
          <span class="sb-maintenance-badge d-none d-md-inline-flex" title="Mantenci&oacute;n activa<?= $maintenanceUntil !== '' ? ' hasta ' . $h($maintenanceUntil) : '' ?>">
            <i class="bi bi-tools"></i>
            <span>Mantenci&oacute;n activa<?= $maintenanceUntil !== '' ? ' hasta ' . $h($maintenanceUntil) : '' ?></span>
          </span>
        <?php endif; ?>
        <?php
          $navNombre   = trim((string)($_SESSION['user']['nombre'] ?? ''));
          $navApellido = trim((string)($_SESSION['user']['apellido'] ?? ''));
          $navDisplay  = trim($navNombre . ($navApellido !== '' ? ' ' . $navApellido : ''));
          if ($navDisplay === '') {
              $navDisplay = trim((string)($_SESSION['user']['id'] ?? '')) !== '' ? 'usuario' : '';
          }
        ?>
        <?php if ($navDisplay !== ''): ?>
          <span class="sb-user-pill text-white-50 small d-none d-sm-inline"><i class="bi bi-person-circle"></i> <strong><?= $h($navDisplay) ?></strong></span>
        <?php endif; ?>
        <a class="btn btn-outline-light btn-sm sb-nova-home-btn" href="<?= $h($novaHomeUrl) ?>"><i class="bi bi-house-door"></i> <span>NOVA</span></a>
        <form method="POST" action="<?= $h($novaLogoutUrl) ?>" style="display:inline">
          <input type="hidden" name="_token" value="<?= function_exists('csrf_token') ? htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') : '' ?>">
          <button type="submit" class="btn btn-outline-light btn-sm sb-logout-btn"><i class="bi bi-box-arrow-right"></i> <span>Salir</span></button>
        </form>
      </div>
    </div>
  </div>
</nav>
<div class="nova-layout">
  <aside class="nova-sidebar offcanvas-lg offcanvas-start" id="novaSidebar" tabindex="-1" aria-labelledby="novaSidebarLabel">
    <div class="offcanvas-header d-lg-none border-bottom py-3">
      <strong class="offcanvas-title fw-bold" id="novaSidebarLabel">Redmine Mantenci&oacute;n</strong>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
    </div>
    <nav class="nova-sidebar-body" aria-label="Secciones Redmine Mantenci&oacute;n">
      <?php foreach ($navItems as $item): ?>
        <?php if (!$item['can']) { continue; } ?>
        <?php
          $children = array_values(array_filter($item['children'] ?? [], fn($child) => !empty($child['can'])));
          $isActive = $activeNav === $item['key'] || array_reduce($children, function ($carry, $child) use ($activeNav) {
              $grandChildren = array_values(array_filter($child['children'] ?? [], fn($grandChild) => !empty($grandChild['can'])));
              return $carry || $activeNav === ($child['key'] ?? '') || array_reduce($grandChildren, fn($grandCarry, $grandChild) => $grandCarry || $activeNav === ($grandChild['key'] ?? ''), false);
          }, false);
        ?>
        <?php if ($children): ?>
          <?php $collapseId = 'sbGroup' . ucfirst((string) $item['key']); ?>
          <div class="nova-sidebar-group">
            <a class="nova-sidebar-link <?= $isActive ? 'active' : '' ?>"
               href="#<?= $h($collapseId) ?>"
               data-bs-toggle="collapse"
               aria-expanded="<?= $isActive ? 'true' : 'false' ?>"
               aria-controls="<?= $h($collapseId) ?>">
              <i class="bi <?= $h($item['icon']) ?> nova-sidebar-icon"></i>
              <span><?= $item['label'] ?></span>
              <i class="bi bi-chevron-down nova-sidebar-chevron"></i>
            </a>
            <div class="collapse <?= $isActive ? 'show' : '' ?>" id="<?= $h($collapseId) ?>">
              <div class="nova-sidebar-sub">
                <?php foreach ($children as $child): ?>
                  <?php
                    $grandChildren = array_values(array_filter($child['children'] ?? [], fn($grandChild) => !empty($grandChild['can'])));
                    $childActive = $activeNav === ($child['key'] ?? '') || array_reduce($grandChildren, fn($carry, $grandChild) => $carry || $activeNav === ($grandChild['key'] ?? ''), false);
                  ?>
                  <?php if ($grandChildren): ?>
                    <?php $subCollapseId = 'sbSub' . ucfirst((string) ($child['key'] ?? '')); ?>
                    <a class="nova-sidebar-link <?= $childActive ? 'active' : '' ?>"
                       href="#<?= $h($subCollapseId) ?>"
                       data-bs-toggle="collapse"
                       aria-expanded="<?= $childActive ? 'true' : 'false' ?>">
                      <i class="bi <?= $h($child['icon']) ?> nova-sidebar-icon"></i>
                      <span><?= $child['label'] ?></span>
                      <i class="bi bi-chevron-down nova-sidebar-chevron"></i>
                    </a>
                    <div class="collapse <?= $childActive ? 'show' : '' ?>" id="<?= $h($subCollapseId) ?>">
                      <div class="nova-sidebar-sub">
                        <?php foreach ($grandChildren as $grandChild): ?>
                          <?php $grandActive = $activeNav === ($grandChild['key'] ?? ''); ?>
                          <a class="nova-sidebar-link <?= $grandActive ? 'active' : '' ?>"
                             href="<?= $h($grandChild['href']) ?>"
                             <?= $grandActive ? 'aria-current="page"' : '' ?>>
                            <i class="bi <?= $h($grandChild['icon']) ?> nova-sidebar-icon"></i>
                            <span><?= $grandChild['label'] ?></span>
                          </a>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  <?php else: ?>
                    <a class="nova-sidebar-link <?= $childActive ? 'active' : '' ?>"
                       href="<?= $h($child['href'] ?? '#') ?>"
                       <?= $childActive ? 'aria-current="page"' : '' ?>>
                      <i class="bi <?= $h($child['icon']) ?> nova-sidebar-icon"></i>
                      <span><?= $child['label'] ?></span>
                    </a>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        <?php else: ?>
          <a class="nova-sidebar-link <?= $isActive ? 'active' : '' ?>"
             href="<?= $h($item['href']) ?>"
             <?= $isActive ? 'aria-current="page"' : '' ?>>
            <i class="bi <?= $h($item['icon']) ?> nova-sidebar-icon"></i>
            <span><?= $item['label'] ?></span>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>
  </aside>
  <main class="nova-content" id="nova-main-content">
<div class="app-page-loader" id="app-page-loader" aria-hidden="true"></div>
<div class="nova-integration-overlay" id="nova-integration-overlay" role="status" aria-live="polite" aria-hidden="true">
  <div class="nova-integration-card">
    <span class="nova-integration-icon"><i class="bi bi-cloud-arrow-down"></i></span>
    <img class="nova-integration-gif" id="nova-integration-gif" src="" alt="" hidden>
    <strong id="nova-integration-title">Consultando integraci&oacute;n</strong>
    <span id="nova-integration-detail">La operaci&oacute;n puede tardar unos segundos.</span>
    <div class="nova-integration-bar" aria-hidden="true"><i></i></div>
  </div>
</div>
<script>
(function () {
  const integrationOverlay = document.getElementById('nova-integration-overlay');
  window.appUi = window.appUi || {};
  const promoteModalToBody = function (modal) {
    if (!modal || modal.parentElement === document.body) return;
    document.body.appendChild(modal);
  };
  document.addEventListener('click', function (e) {
    const modalTrigger = e.target.closest('[data-bs-toggle="modal"][data-bs-target]');
    if (!modalTrigger) return;
    const selector = modalTrigger.getAttribute('data-bs-target');
    if (!selector || selector.charAt(0) !== '#') return;
    promoteModalToBody(document.querySelector(selector));
  }, true);
  document.addEventListener('click', function (e) {
    const toggle = e.target.closest('.sb-native-menu-wrap .js-submenu-toggle');
    if (toggle) {
      e.preventDefault();
      e.stopImmediatePropagation();
      const li = toggle.closest('li');
      const menu = li ? li.querySelector('.js-submenu-menu') : null;
      if (!menu) return;
      document.querySelectorAll('.sb-native-menu-wrap .js-submenu-menu.show').forEach(function (m) {
        if (m !== menu) m.classList.remove('show');
      });
      const open = menu.classList.toggle('show');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      return;
    }
    const subLink = e.target.closest('.sb-native-menu-wrap .js-submenu-menu a[href]');
    if (subLink) {
      const href = subLink.getAttribute('href');
      if (href && href !== '#') {
        e.stopImmediatePropagation();
      }
    }
  }, true);
  function cleanDropendState() {
    document.querySelectorAll('.sb-native-menu-wrap .js-submenu-menu.show').forEach(function (m) { m.classList.remove('show'); });
    document.querySelectorAll('.sb-native-menu-wrap .js-submenu-toggle[aria-expanded="true"]').forEach(function (t) { t.setAttribute('aria-expanded', 'false'); });
  }
  document.addEventListener('click', function (e) {
    if (e.target.closest('.sb-native-menu-wrap .dropdown')) return;
    document.querySelectorAll('.sb-native-menu-wrap .dropdown-menu.show').forEach(function (menu) { menu.classList.remove('show'); });
    document.querySelectorAll('.sb-native-menu-wrap [data-bs-toggle="dropdown"].show').forEach(function (toggle) {
      toggle.classList.remove('show');
      toggle.setAttribute('aria-expanded', 'false');
    });
    cleanDropendState();
  });
  document.addEventListener('hide.bs.dropdown', function () { cleanDropendState(); });
  window.appUi.setIntegrationLoading = function (state, options) {
    if (!integrationOverlay) return;
    options = options || {};
    const title = document.getElementById('nova-integration-title');
    const detail = document.getElementById('nova-integration-detail');
    const icon = integrationOverlay.querySelector('.nova-integration-icon i');
    const iconContainer = integrationOverlay.querySelector('.nova-integration-icon');
    const gif = document.getElementById('nova-integration-gif');
    if (state) {
      if (title) title.textContent = options.title || 'Consultando integración';
      if (detail) detail.textContent = options.detail || 'La operación puede tardar unos segundos.';
      if (icon) icon.className = 'bi ' + (options.icon || 'bi-cloud-arrow-down');
      if (gif) {
        gif.hidden = !options.image;
        gif.src = options.image || '';
        gif.alt = options.image ? (options.imageAlt || '') : '';
      }
      if (iconContainer) iconContainer.hidden = Boolean(options.image);
      integrationOverlay.classList.toggle('has-media', Boolean(options.image));
      integrationOverlay.classList.add('is-active');
      integrationOverlay.setAttribute('aria-hidden', 'false');
      document.body.classList.add('nova-integration-loading');
    } else {
      integrationOverlay.classList.remove('is-active');
      integrationOverlay.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('nova-integration-loading');
    }
  };
  const integrationCopyForForm = function (form, submitter) {
    const actionInput = form.querySelector('input[name="action"]');
    const action = ((actionInput && actionInput.value) || '') + ' ' + ((submitter && submitter.value) || '') + ' ' + ((submitter && submitter.textContent) || '');
    const lower = action.toLowerCase();
    if (!/(sync|sincron|import|fetch|consult|confirm|core|api)/i.test(lower)) return null;
    if (lower.indexOf('nextcloud') !== -1) return {
      title: lower.indexOf('group') !== -1 ? 'Consultando grupos de Nextcloud' : 'Procesando Nextcloud',
      detail: lower.indexOf('group') !== -1 ? 'Obteniendo los grupos disponibles. Esto puede tardar algunos segundos.' : 'Conectando con Nextcloud y preparando la respuesta.',
      icon: 'bi-cloud-arrow-up',
      image: <?= json_encode(legacy_app_url('assets/img/Nextcloud.gif'), JSON_UNESCAPED_SLASHES) ?>,
      imageAlt: 'Consultando Nextcloud'
    };
    if (lower.indexOf('core') !== -1) return { title: 'Consultando CORE', detail: 'Buscando y normalizando datos recibidos desde CORE.', icon: 'bi-database-down' };
    if (lower.indexOf('redmine') !== -1 || lower.indexOf('sync') !== -1 || lower.indexOf('sincron') !== -1) return { title: 'Sincronizando Redmine', detail: 'Actualizando catálogos y datos desde Redmine.', icon: 'bi-arrow-repeat' };
    if (lower.indexOf('import') !== -1) return { title: 'Importando datos', detail: 'Procesando archivo o datos externos.', icon: 'bi-file-earmark-arrow-up' };
    return { title: 'Consultando integración', detail: 'La operación puede tardar unos segundos.', icon: 'bi-cloud-arrow-down' };
  };
  document.addEventListener('click', function (e) {
    const closeTrigger = e.target.closest('[data-nova-modal-close], [data-bs-dismiss="modal"]');
    if (closeTrigger) {
      e.preventDefault();
      window.appUi.closeModal(closeTrigger.closest('.modal'));
      return;
    }
    const openTrigger = e.target.closest('[data-nova-modal-open]');
    if (openTrigger && !openTrigger.matches('[data-bs-toggle]')) {
      const target = document.getElementById(openTrigger.getAttribute('data-nova-modal-open'));
      if (target) {
        e.preventDefault();
        promoteModalToBody(target);
        window.appUi.openModal(target);
      }
    }
    if (e.target.classList && e.target.classList.contains('modal') && e.target.dataset.novaSessionModal !== '') {
      window.appUi.closeModal(e.target);
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.modal.show:not([data-nova-session-modal])').forEach(window.appUi.closeModal);
  });
  // Show the module-specific overlay only for integration submits.
  document.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    if (e.target.closest('[data-app-no-loading], [data-no-page-loader]')) return;
    const copy = integrationCopyForForm(e.target, e.submitter || document.activeElement);
    if (copy) {
      window.appUi.setIntegrationLoading(true, copy);
      const button = e.submitter || e.target.querySelector('button[type="submit"], button:not([type])');
      if (button && button.classList) button.classList.add('is-submitting');
    }
  });
  window.addEventListener('pageshow', function () { window.appUi?.setIntegrationLoading?.(false); });
  window.addEventListener('load', function () { window.appUi?.setIntegrationLoading?.(false); });
}());
window.addEventListener('load', () => {
  // Navegaci&oacute;n parcial: carga vistas sin recargar navbar/footer si existe #page-content en destino.
  (function partialNav() {
    const enablePartialNav = true;
    const pageContent = document.getElementById('page-content');
    if (!enablePartialNav || !pageContent || !window.history || !window.fetch) return;
    const forceFullPaths = [
      'dashboard/dashboard.php',
      'dashboard.php',
      'horasextra/horas_extra.php',
      'horas_extra.php',
      'pendientes/manual.php',
    ];
    const navLinks = document.querySelectorAll('.nova-sidebar-body a.nova-sidebar-link:not([data-bs-toggle])');
    const setActive = (urlStr) => {
      navLinks.forEach(a => {
        if (a.href === urlStr) a.classList.add('active'); else a.classList.remove('active');
      });
    };
    const executeScripts = (doc) => {
      const pageEl = doc.getElementById('page-content');
      if (pageEl) {
        pageEl.querySelectorAll('script').forEach(old => {
          const s = document.createElement('script');
          if (old.src) s.src = old.src;
          else s.textContent = old.textContent;
          document.body.appendChild(s);
        });
      }
      // Re-disparar eventos para vistas cargadas din&aacute;micamente.
      document.dispatchEvent(new Event('DOMContentLoaded'));
      document.dispatchEvent(new Event('partial:loaded'));
    };
    let _loadPageBusy = false;
    const loadPage = async (url, push) => {
      if (_loadPageBusy) return;
      _loadPageBusy = true;
      const targetPath = (new URL(url, window.location.href)).pathname.toLowerCase();
      if (forceFullPaths.some(p => targetPath.endsWith(p))) {
        window.location.href = url;
        return;
      }
      window.appUi?.setLoading?.(true);
      try {
        const res = await fetch(url, { headers: { 'X-Requested-With': 'partial-nav' } });
        let text = await res.text();
        text = text.replace(/^\uFEFF/, ''); // eliminar BOM inicial
        const doc = new DOMParser().parseFromString(text, 'text/html');
        const newContent = doc.getElementById('page-content');
        if (!newContent) {
          window.location.href = url;
          return;
        }
        if (newContent.querySelectorAll('script').length > 0) {
          window.location.href = url;
          return;
        }
        let contentHtml = (newContent.innerHTML || '').trim();
        contentHtml = contentHtml.replace(/\uFEFF/g, '');
        if (/<!doctype|<html|<head/i.test(contentHtml)) {
          window.location.href = url;
          return;
        }
        pageContent.innerHTML = contentHtml;
        window.NovaSearchSelect?.init(pageContent);
        // limpiar nodos de texto vacíos/BOM
        Array.from(pageContent.childNodes).forEach(n => {
          if (n.nodeType === 3 && /^\s*$/.test(n.textContent.replace(/\uFEFF/g, ''))) {
            n.remove();
          }
        });
        if (doc.title) document.title = doc.title;
        if (push) history.pushState({ url }, '', url);
        setActive(url);
        window.scrollTo(0, 0);
        executeScripts(doc);
      } catch (err) {
        window.location.href = url;
      } finally {
        window.appUi?.setLoading?.(false);
        _loadPageBusy = false;
      }
    };
    const handleClick = (e) => {
      const a = e.currentTarget;
      if (a.target === '_blank') return;
      const url = new URL(a.href, window.location.href);
      if (url.origin !== window.location.origin) return;
      e.preventDefault();
      loadPage(url.toString(), true);
    };
    navLinks.forEach(a => a.addEventListener('click', handleClick));
    window.addEventListener('popstate', (ev) => {
      const url = ev.state?.url || window.location.href;
      loadPage(url, false);
    });
  })();

  // Temporizador de sesión
  const extendBtn = document.getElementById('btn-extend-session');
  const extendPwd = document.getElementById('session-password');
  const extendMsg = document.getElementById('session-msg');
  const closeBtn = document.getElementById('btn-logout-session');
  const el = document.getElementById('session-timer');
  const textEl = document.getElementById('session-timer-text') || el;
  const baseTimeout = el ? (parseInt(el.getAttribute('data-timeout'), 10) || 300) : 300;
  let remaining = el ? (parseInt(el.getAttribute('data-remaining'), 10) || baseTimeout) : baseTimeout;
  let expiresAt = Date.now() + (remaining * 1000);
  const logoutUrl = '<?= $h($novaLogoutUrl) ?>';
  const sessionExtendUrl = '<?= $h($novaSessionExtendUrl) ?>';
  const loginUrl = '<?= $h($novaLoginUrl) ?>';
  const sessionIdentity = '<?= $h($novaSessionIdentity) ?>';
  const csrfToken = '<?= $h($novaCsrfToken) ?>' || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const modalEl = document.getElementById('sessionModal');
  const modal = (window.bootstrap && modalEl) ? new bootstrap.Modal(modalEl) : null;
  const modalTitleEl = modalEl ? modalEl.querySelector('.modal-title') : null;
  const modalBodyTextEl = modalEl ? modalEl.querySelector('.modal-body > p') : null;
  let modalShown = false;
  let sessionExpired = false;

  let tickHandle = null;
  function setTimerAppearance(secondsLeft) {
    if (!el) return;
    if (secondsLeft <= 20) {
      el.className = 'sb-session-badge badge bg-danger text-light d-inline-flex align-items-center gap-1';
    } else if (secondsLeft <= 60) {
      el.className = 'sb-session-badge badge bg-warning text-dark d-inline-flex align-items-center gap-1';
    } else {
      el.className = 'sb-session-badge badge bg-light text-dark d-inline-flex align-items-center gap-1';
    }
  }

  function setModalState(expired) {
    sessionExpired = expired;
    if (modalTitleEl) modalTitleEl.textContent = expired ? 'Sesión expirada' : 'Sesión por expirar';
    if (modalBodyTextEl) {
      modalBodyTextEl.textContent = expired
        ? 'Tu sesión ya expiró. Debes iniciar sesión nuevamente.'
        : 'Tu sesión expira pronto. ¿Deseas continuar?';
    }
    if (extendPwd) {
      extendPwd.disabled = false;
      if (expired && extendMsg) extendMsg.textContent = '';
    }
    if (extendBtn) {
      extendBtn.disabled = false;
      extendBtn.textContent = 'Continuar sesión';
    }
    if (closeBtn) {
      closeBtn.textContent = expired ? 'Cancelar' : 'Cerrar sesión';
    }
  }

  function getRemainingSeconds() {
    return Math.max(0, Math.ceil((expiresAt - Date.now()) / 1000));
  }
  function tick() {
    if (!el) return;
    remaining = getRemainingSeconds();
    if (remaining <= 0) {
      textEl.textContent = '00:00';
      setTimerAppearance(0);
      setModalState(true);
      if (modal && !modalShown) {
        modal.show();
        modalShown = true;
        if (extendPwd) setTimeout(() => extendPwd.focus(), 120);
      }
      return;
    }
    if (modal && !modalShown && remaining <= 60) {
      setModalState(false);
      modal.show();
      modalShown = true;
      if (extendPwd) setTimeout(() => extendPwd.focus(), 120);
    }
    const m = Math.floor(remaining / 60).toString().padStart(2, '0');
    const s = (remaining % 60).toString().padStart(2, '0');
    textEl.textContent = `${m}:${s}`;
    setTimerAppearance(remaining);
    tickHandle = setTimeout(tick, 1000);
  }
  function restartTick() {
    if (tickHandle) clearTimeout(tickHandle);
    tick();
  }

  function syncTimerState() {
    remaining = getRemainingSeconds();
    if (remaining <= 0) {
      restartTick();
      return;
    }
    if (modalShown && remaining > 60 && modal) {
      modal.hide();
      modalShown = false;
      setModalState(false);
      if (extendMsg) extendMsg.textContent = '';
    }
    restartTick();
  }

  function applySessionRefresh(data) {
    if (!data || !data.ok) return false;
    remaining = parseInt(data.remaining ?? data.timeout ?? baseTimeout, 10) || baseTimeout;
    expiresAt = Date.now() + (remaining * 1000);
    modalShown = false;
    setModalState(false);
    if (extendMsg) extendMsg.textContent = '';
    if (modal) modal.hide();
    restartTick();
    return true;
  }

  window.redmineSessionTouch = async function redmineSessionTouch() {
    return {ok: true, timeout: baseTimeout, remaining: getRemainingSeconds()};
  };

  function submitLogout() {
    if (sessionExpired) {
      window.location.assign(loginUrl);
      return;
    }
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = logoutUrl;
    form.style.display = 'none';
    if (csrfToken) {
      const tokenInput = document.createElement('input');
      tokenInput.type = 'hidden';
      tokenInput.name = '_token';
      tokenInput.value = csrfToken;
      form.appendChild(tokenInput);
    }
    document.body.appendChild(form);
    form.submit();
  }

  restartTick();
  document.addEventListener('visibilitychange', syncTimerState);
  window.addEventListener('focus', syncTimerState);
  if (closeBtn) {
    closeBtn.addEventListener('click', submitLogout);
  }
  if (extendBtn && extendPwd) {
    extendBtn.addEventListener('click', async () => {
      if (extendMsg) extendMsg.textContent = '';
      const pwd = extendPwd.value.trim();
      if (!pwd) {
        if (extendMsg) extendMsg.textContent = 'Ingresa tu contraseña.';
        return;
      }
      try {
        const resp = await fetch(sessionExtendUrl, {
          method: 'POST',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken
          },
          credentials: 'same-origin',
          body: JSON.stringify({password: pwd, identity: sessionIdentity})
        });
        const data = await resp.json();
        if (data.ok) {
          remaining = parseInt(data.remaining ?? data.timeout ?? baseTimeout, 10) || baseTimeout;
          expiresAt = Date.now() + (remaining * 1000);
          modalShown = false;
          setModalState(false);
          extendPwd.value = '';
          if (extendMsg) extendMsg.textContent = 'Sesión extendida.';
          restartTick();
          if (modal) setTimeout(() => modal.hide(), 400);
        } else {
          if (extendMsg) extendMsg.textContent = data.msg || 'Contraseña incorrecta.';
        }
      } catch (e) {
        if (extendMsg) extendMsg.textContent = 'No se pudo extender la sesión.';
      }
    });
  }
  if (modalEl) {
    modalEl.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.altKey || event.metaKey) return;
      if (!modalEl.classList.contains('show')) return;
      event.preventDefault();
      if (extendBtn && !extendBtn.disabled) extendBtn.click();
    });
  }
  setModalState(false);
});
</script>

<?php if ($maintenanceMode): ?>
<div class="modal fade" id="maintenanceNoticeModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-warning-subtle">
        <h5 class="modal-title"><i class="bi bi-tools"></i> Plataforma en mantenci&oacute;n</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">La plataforma se encuentra en mantenci&oacute;n. Mientras est&eacute; activa, no se podr&aacute;n ingresar ni importar datos nuevos.</p>
        <?php if ($maintenanceUntil !== ''): ?>
          <div class="nova-alert-card is-warning mb-0"><i class="bi bi-clock"></i> <span>Hora estimada: <?= $h($maintenanceUntil) ?></span></div>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendido</button>
      </div>
    </div>
  </div>
</div>
<script>
window.addEventListener('load', () => {
  const el = document.getElementById('maintenanceNoticeModal');
  const noticeKey = 'redmine-maintenance-notice:<?= $h($maintenanceNoticeKey) ?>';
  let alreadySeen = false;
  try {
    alreadySeen = window.localStorage.getItem(noticeKey) === '1';
  } catch (error) {
    alreadySeen = false;
  }
  if (el && window.bootstrap && !alreadySeen) {
    window.bootstrap.Modal.getOrCreateInstance(el).show();
    el.addEventListener('hidden.bs.modal', () => {
      try {
        window.localStorage.setItem(noticeKey, '1');
      } catch (error) {}
    }, { once: true });
  }
});
</script>
<?php endif; ?>

<!-- Modal sesión -->
<div class="modal fade" id="sessionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Sesión por expirar</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Tu sesión expira pronto. &iquest;Deseas continuar?</p>
        <div class="mb-3">
          <label class="form-label">Contraseña</label>
          <input type="password" id="session-password" class="form-control" autocomplete="current-password">
          <div class="form-text text-danger" id="session-msg"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" id="btn-logout-session">Cerrar sesión</button>
        <button type="button" class="btn btn-primary" id="btn-extend-session">Continuar sesión</button>
      </div>
    </div>
  </div>
</div>
