<?php
/**
 * Nextcloud personal file-browser partial.
 * Included by procedimientos.php (main view) when the user is not in
 * public-share mode, not editing, and not viewing a single document.
 *
 * Expects these variables from the parent view:
 *   $csrf          string  CSRF token (empty for public shares — but this
 *                          partial is never included for public shares)
 *   $canEditProcedures  bool
 */

$h = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

$ncUserId           = function_exists('auth_get_user_id') ? (string) auth_get_user_id() : '';
$ncHasCredentials   = isset($ncHasCredentialsOverride) ? (bool) $ncHasCredentialsOverride : ($ncUserId !== ''
    && function_exists('nextcloud_credentials_has_saved')
    && nextcloud_credentials_has_saved($ncUserId));

$ncAjaxUrl          = isset($ncAjaxUrlOverride) ? (string) $ncAjaxUrlOverride : (function_exists('legacy_app_url')
    ? legacy_app_url('views/Procedimientos/nc_browser_ajax.php')
    : '/redmine-mantencion/views/Procedimientos/nc_browser_ajax.php');
$ncIntegracionesUrl = isset($ncIntegracionesUrlOverride) ? (string) $ncIntegracionesUrlOverride : (function_exists('legacy_app_url')
    ? legacy_app_url('views/Integraciones/Nextcloud.php')
    : '/redmine-mantencion/views/Integraciones/Nextcloud.php');
$ncEditorUrl = isset($ncEditorUrlOverride) ? (string) $ncEditorUrlOverride : '';
?>

<!-- ══════════════════════════════════════════════════════════════════
     Nextcloud personal file-browser section
     ══════════════════════════════════════════════════════════════════ -->
<section class="nc-browser-section card shadow-sm mb-4" id="nc-browser-section">
  <div class="nc-browser-head">
    <span class="nc-browser-icon"><i class="bi bi-cloud-fill"></i></span>
    <div>
      <h2 class="mb-0">Archivos Nextcloud</h2>
      <p class="mb-0 text-muted small">Explorador de archivos de su cuenta personal de Nextcloud.</p>
    </div>
  </div>

  <?php if (!$ncHasCredentials): ?>
  <!-- ── Credential gate ──────────────────────────────────────────── -->
  <div class="nc-browser-gate text-center p-5">
    <div class="nc-gate-icon mb-3"><i class="bi bi-key-fill"></i></div>
    <p class="nc-gate-msg mb-4">
      Debe configurar sus credenciales de Nextcloud antes de usar esta sección.
    </p>
    <a href="<?= $h($ncIntegracionesUrl) ?>" class="btn-nova btn-nova-primary">
      <i class="bi bi-gear-fill"></i>Configurar credenciales Nextcloud
    </a>
  </div>

  <?php else: ?>
  <!-- ── Browser ──────────────────────────────────────────────────── -->
  <div
    id="nc-browser"
    data-ajax="<?= $h($ncAjaxUrl) ?>"
    data-csrf="<?= $h($csrf ?? '') ?>"
    data-can-edit="<?= !empty($canEditProcedures) ? '1' : '0' ?>"
    data-editor-url="<?= $h($ncEditorUrl) ?>"
  >
    <!-- Toolbar -->
    <div class="nc-toolbar d-flex align-items-center gap-2 flex-wrap px-3 py-2 border-bottom">
      <nav id="nc-breadcrumb" aria-label="Ruta actual" class="nc-breadcrumb flex-grow-1"></nav>
      <div class="d-flex gap-2 align-items-center">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="nc-refresh-btn" title="Actualizar" aria-label="Actualizar">
          <i class="bi bi-arrow-clockwise"></i>
        </button>
        <?php if ($canEditProcedures): ?>
        <button type="button" class="btn-nova btn-nova-primary" id="nc-mkdir-btn">
          <i class="bi bi-folder-plus"></i> Nueva carpeta
        </button>
        <label class="btn-nova btn-nova-info mb-0" for="nc-upload-input" role="button">
          <i class="bi bi-upload"></i> Subir
          <input type="file" id="nc-upload-input" class="visually-hidden" multiple>
        </label>
        <?php endif; ?>
      </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs px-3 pt-1 border-bottom-0 d-none" id="nc-tabs" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" id="nc-tab-files-btn" data-bs-toggle="tab" data-bs-target="#nc-pane-files" type="button" role="tab">
          <i class="bi bi-folder2-open"></i> Mis archivos
        </button>
      </li>
      <li class="nav-item d-none" role="presentation">
        <button class="nav-link" id="nc-tab-shared-btn" data-bs-toggle="tab" data-bs-target="#nc-pane-shared" type="button" role="tab">
          <i class="bi bi-share"></i> Compartidos conmigo
        </button>
      </li>
    </ul>

    <div class="tab-content">
      <div class="tab-pane fade show active p-3" id="nc-pane-files" role="tabpanel">
        <div id="nc-file-list" class="nc-file-list"></div>
      </div>
      <div class="tab-pane fade p-3" id="nc-pane-shared" role="tabpanel">
        <div id="nc-shared-list" class="nc-file-list"></div>
      </div>
    </div>

    <!-- Status bar -->
    <div id="nc-status" class="nc-status d-none" role="status" aria-live="polite"></div>

    <div class="nc-busy-overlay" id="nc-busy-overlay" role="status" aria-live="polite" aria-hidden="true">
      <div class="nc-busy-card blue-on-white">
        <div class="group" aria-hidden="true">
          <div class="centerCircle"></div>
          <div class="leftCircle"></div>
          <div class="rightCircle"></div>
        </div>
        <div class="nc-busy-text" id="nc-busy-text">Consultando Nextcloud...</div>
      </div>
    </div>
  </div><!-- /#nc-browser -->

  <!-- ── Modals ──────────────────────────────────────────────────── -->

  <!-- Mkdir -->
  <div class="modal fade" id="ncMkdirModal" tabindex="-1" aria-labelledby="ncMkdirLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="ncMkdirLabel"><i class="bi bi-folder-plus"></i> Nueva carpeta</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <label for="ncMkdirName" class="form-label">Nombre</label>
          <input type="text" class="form-control" id="ncMkdirName" maxlength="255" placeholder="Nombre de la carpeta" autocomplete="off">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn-nova btn-nova-primary" id="ncMkdirConfirm"><i class="bi bi-folder-plus"></i>Crear</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Rename -->
  <div class="modal fade" id="ncRenameModal" tabindex="-1" aria-labelledby="ncRenameLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="ncRenameLabel"><i class="bi bi-pencil"></i> Renombrar</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <label for="ncRenameName" class="form-label">Nuevo nombre</label>
          <input type="text" class="form-control" id="ncRenameName" maxlength="255" autocomplete="off">
          <input type="hidden" id="ncRenameTarget">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn-nova btn-nova-primary" id="ncRenameConfirm"><i class="bi bi-pencil"></i>Renombrar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Move / copy -->
  <div class="modal fade" id="ncTransferModal" tabindex="-1" aria-labelledby="ncTransferLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="ncTransferLabel"><i class="bi bi-folder-symlink"></i> Mover o copiar</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="ncTransferPath">
          <input type="hidden" id="ncTransferOperation">
          <div class="mb-3">
            <label class="form-label">Elemento</label>
            <input type="text" class="form-control" id="ncTransferName" readonly>
          </div>
          <div>
            <label for="ncTransferDestination" class="form-label">Carpeta destino</label>
            <input type="hidden" id="ncTransferDestination">
            <div class="nc-destination-picker">
              <div class="nc-destination-head">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="ncTransferUp" title="Subir un nivel" aria-label="Subir un nivel">
                  <i class="bi bi-arrow-up"></i>
                </button>
                <div class="nc-destination-path" id="ncTransferPathLabel">/</div>
              </div>
              <div class="nc-destination-list" id="ncTransferFolderList">
                <div class="nc-loading"><span class="spinner-border spinner-border-sm me-2" role="status"></span>Cargando...</div>
              </div>
            </div>
            <div class="form-text">Seleccione una carpeta. Puede entrar a subcarpetas antes de confirmar.</div>
          </div>
          <div class="d-flex flex-wrap gap-2 mt-3">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="ncTransferRoot">Raiz</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="ncTransferCurrent">Carpeta actual</button>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn-nova btn-nova-primary" id="ncTransferConfirm"><i class="bi bi-arrow-left-right"></i>Aplicar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Delete confirmation -->
  <div class="modal fade" id="ncDeleteModal" tabindex="-1" aria-labelledby="ncDeleteLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header border-bottom-0">
          <h5 class="modal-title text-danger" id="ncDeleteLabel"><i class="bi bi-trash3"></i> Confirmar eliminación</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body pt-0">
          <p>¿Desea eliminar <strong id="ncDeleteTargetName"></strong>?</p>
          <p class="text-danger small mb-0">Esta acción no se puede deshacer en Nextcloud.</p>
          <input type="hidden" id="ncDeletePath">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn-nova btn-nova-danger" id="ncDeleteConfirm"><i class="bi bi-trash3"></i>Eliminar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Share -->
  <div class="modal fade" id="ncShareModal" tabindex="-1" aria-labelledby="ncShareLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="ncShareLabel"><i class="bi bi-share"></i> Compartir</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="ncSharePath">

          <div class="mb-4">
            <p class="fw-semibold mb-2 small text-uppercase text-muted">Enlace público</p>
            <div class="input-group">
              <input type="text" class="form-control form-control-sm" id="ncShareLinkUrl" readonly placeholder="Haga clic en 'Crear enlace'">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="ncShareLinkCopy" disabled title="Copiar enlace" aria-label="Copiar enlace"><i class="bi bi-clipboard"></i></button>
            </div>
            <div class="mt-2">
              <button type="button" class="btn btn-sm btn-primary" id="ncShareLinkCreate">
                <i class="bi bi-link-45deg"></i> Crear enlace público
              </button>
            </div>
          </div>

          <hr>

          <div>
            <p class="fw-semibold mb-2 small text-uppercase text-muted">Compartir con usuario Nextcloud</p>
            <div class="input-group">
              <select class="form-select form-select-sm" id="ncShareUser">
                <option value="">Cargando usuarios...</option>
              </select>
              <button type="button" class="btn btn-outline-primary btn-sm" id="ncShareUserBtn">
                <i class="bi bi-person-plus"></i> Compartir
              </button>
            </div>
            <div class="form-text">Solo aparecen usuarios activos con credenciales Nextcloud configuradas.</div>
            <div id="ncShareUserResult" class="mt-1 small"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

  <?php endif; // $ncHasCredentials ?>
</section>

<!-- ══════════════════════════════════════════════════════════════════
     Scoped styles
     ══════════════════════════════════════════════════════════════════ -->
<?php $procedimientosCssVersion = @filemtime(__DIR__ . '/../../assets/css/procedimientos.css') ?: time(); ?>
<link rel="stylesheet" href="<?= htmlspecialchars($mantencionBaseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/css/procedimientos.css?v=<?= (int)$procedimientosCssVersion ?>">

<!-- ══════════════════════════════════════════════════════════════════
     Browser JavaScript (only loaded when credentials are present)
     ══════════════════════════════════════════════════════════════════ -->
<?php if ($ncHasCredentials): ?>
<script>
(function () {
  'use strict';

  const browser    = document.getElementById('nc-browser');
  if (!browser) return;

  const AJAX       = browser.dataset.ajax;
  const EDITOR     = browser.dataset.editorUrl || '';
  const CSRF       = browser.dataset.csrf;
  const CAN_EDIT   = browser.dataset.canEdit === '1';
  let   currentPath = '/';
  let   transferBrowsePath = '/';
  let   busyCount = 0;
  let   browserLoaded = false;

  // ── Utilities ──────────────────────────────────────────────────────

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function fmtSize(n) {
    n = parseInt(n, 10) || 0;
    if (n >= 1048576) return (n/1048576).toFixed(1) + ' MB';
    if (n >= 1024)    return (n/1024).toFixed(1) + ' KB';
    return n + ' B';
  }

  function fileIconClass(item) {
    if (item.type === 'dir') return ['bi-folder-fill','nc-icon-dir'];
    const ext = (item.name.split('.').pop() || '').toLowerCase();
    if (ext === 'pdf')                          return ['bi-file-earmark-pdf-fill','nc-icon-pdf'];
    if (['doc','docx'].includes(ext))           return ['bi-file-earmark-word-fill','nc-icon-word'];
    if (['xls','xlsx'].includes(ext))           return ['bi-file-earmark-excel-fill','nc-icon-excel'];
    if (['jpg','jpeg','png','gif','webp','svg'].includes(ext)) return ['bi-file-earmark-image-fill','nc-icon-image'];
    if (['ppt','pptx'].includes(ext))           return ['bi-file-earmark-slides-fill','nc-icon-file'];
    return ['bi-file-earmark-fill','nc-icon-file'];
  }

  function showStatus(msg, type) {
    const el = document.getElementById('nc-status');
    if (!el) return;
    el.textContent = msg;
    el.className = 'nc-status ' + (type === 'error' ? 'nc-status-err' : 'nc-status-ok');
    el.classList.remove('d-none');
    clearTimeout(el._t);
    el._t = setTimeout(() => el.classList.add('d-none'), 5000);
  }

  function busyMessage(action) {
    return {
      list: 'Consultando carpetas...',
      shares_with_me: 'Consultando compartidos...',
      share_users: 'Buscando usuarios Nextcloud...',
      download: 'Preparando descarga...',
      mkdir: 'Creando carpeta...',
      rename: 'Renombrando en Nextcloud...',
      transfer: 'Moviendo o copiando...',
      delete: 'Eliminando en Nextcloud...',
      upload: 'Subiendo a Nextcloud...',
      share_user: 'Compartiendo con usuario...',
    }[action] || 'Consultando Nextcloud...';
  }

  function setBusy(state, message) {
    const overlay = document.getElementById('nc-busy-overlay');
    const text = document.getElementById('nc-busy-text');
    if (!overlay) return;
    if (state) {
      busyCount += 1;
      if (text) text.textContent = message || 'Consultando Nextcloud...';
      overlay.classList.add('is-active');
      overlay.setAttribute('aria-hidden', 'false');
    } else {
      busyCount = Math.max(0, busyCount - 1);
      if (busyCount === 0) {
        overlay.classList.remove('is-active');
        overlay.setAttribute('aria-hidden', 'true');
      }
    }
  }

  function setLoading(id) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = '<div class="nc-loading"><span class="spinner-border spinner-border-sm me-2" role="status"></span>Cargando…</div>';
  }

  function renderIdleDirectory() {
    const el = document.getElementById('nc-file-list');
    if (!el) return;
    el.innerHTML = `
      <div class="nc-empty">
        <i class="bi bi-folder2-open" style="font-size:2.5rem;display:block;margin-bottom:.5rem;color:#cbd5e1"></i>
        <div class="fw-semibold mb-2">Cargando archivos de Nextcloud...</div>
      </div>`;
  }

  function getModal(id) {
    const el = document.getElementById(id);
    if (el && el.parentElement !== document.body) {
      document.body.appendChild(el);
    }
    return el ? (bootstrap.Modal.getOrCreateInstance(el)) : null;
  }

  function removePublicShareControls() {
    const linkButton = document.getElementById('ncShareLinkCreate');
    const publicBlock = linkButton?.closest('.mb-4');
    const separator = publicBlock?.nextElementSibling;
    publicBlock?.remove();
    if (separator?.tagName === 'HR') separator.remove();
  }

  function focusWhenShown(modalId, inputId, selectText) {
    const modalEl = document.getElementById(modalId);
    const inputEl = document.getElementById(inputId);
    if (!modalEl || !inputEl) return;
    modalEl.addEventListener('shown.bs.modal', () => {
      if (selectText) {
        inputEl.select();
      } else {
        inputEl.focus();
      }
    }, { once: true });
  }

  // ── API ────────────────────────────────────────────────────────────

  async function apiFetch(params, method, body) {
    method = method || 'GET';
    const url = new URL(AJAX, location.href);
    for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
    const opts = { method };
    if (method === 'POST') {
      if (!body) body = new FormData();
      // Auth uses X-CSRF-Token header
      opts.headers = { 'X-CSRF-Token': CSRF };
      opts.body = body;
    }
    setBusy(true, busyMessage(params.action));
    try {
      const resp = await fetch(url.toString(), opts);
      const ct   = resp.headers.get('Content-Type') || '';
      if (ct.includes('application/json')) {
        return resp.json();
      }
      return { ok: resp.ok, _raw: true };
    } finally {
      setBusy(false);
    }
  }

  // ── Breadcrumb ─────────────────────────────────────────────────────

  function updateBreadcrumb(path) {
    const el = document.getElementById('nc-breadcrumb');
    if (!el) return;
    const parts = path.split('/').filter(Boolean);
    let html = '<a data-nav="/" title="Raíz" aria-label="Raíz"><i class="bi bi-house-fill"></i></a>';
    let accum = '';
    parts.forEach((part, i) => {
      accum += '/' + part;
      html  += '<span class="nc-sep"><i class="bi bi-chevron-right"></i></span>';
      if (i < parts.length - 1) {
        html += `<a data-nav="${esc(accum)}">${esc(part)}</a>`;
      } else {
        html += `<span class="nc-cur">${esc(part)}</span>`;
      }
    });
    el.innerHTML = html;
    el.querySelectorAll('a[data-nav]').forEach(a => {
      a.addEventListener('click', () => loadDirectory(a.dataset.nav));
    });
  }

  // ── Directory listing ──────────────────────────────────────────────

  async function loadDirectory(path, forceRefresh) {
    browserLoaded = true;
    currentPath = path || '/';
    // Expose current path so external code (proc-board upload) can read it
    document.getElementById('nc-browser')?.setAttribute('data-nc-current-path', currentPath);
    setLoading('nc-file-list');
    updateBreadcrumb(currentPath);
    const label = 'nc-list:' + currentPath;
    console.time(label);
    try {
      const params = { action: 'list', path: currentPath };
      if (forceRefresh) params.refresh = '1';
      const data = await apiFetch(params);
      console.timeEnd(label);
      console.log('[NC_PERF] list', { path: currentPath, cached: data.cached, server_ms: data.elapsed_ms, items: (data.items || []).length });
      renderFiles(data);
    } catch {
      console.timeEnd(label);
      renderDirectoryError('Error al conectar con Nextcloud.', false);
    }
  }

  function renderDirectoryError(message, timeout) {
    const el = document.getElementById('nc-file-list');
    if (!el) return;
    const retry = timeout
      ? '<div class="mt-3"><button type="button" class="btn btn-sm btn-outline-primary" id="nc-retry-btn"><i class="bi bi-arrow-clockwise"></i> Reintentar</button></div>'
      : '';
    el.innerHTML = `<div class="nc-empty text-danger"><i class="bi bi-exclamation-triangle"></i> ${esc(message || 'Error al cargar.')} ${retry}</div>`;
    document.getElementById('nc-retry-btn')?.addEventListener('click', () => loadDirectory(currentPath || '/'));
  }

  function showTabs() {
    document.getElementById('nc-tabs')?.classList.remove('d-none');
    document.getElementById('nc-tab-shared-btn')?.closest('li')?.classList.remove('d-none');
  }

  function renderFiles(data) {
    const el = document.getElementById('nc-file-list');
    if (!el) return;
    if (!data.ok) {
      renderDirectoryError(data.error || 'Error al cargar.', !!data.timeout);
      return;
    }
    showTabs();
    const items = data.items || [];
    if (items.length === 0) {
      el.innerHTML = '<div class="nc-empty"><i class="bi bi-folder2-open" style="font-size:2.5rem;display:block;margin-bottom:.5rem;color:#cbd5e1"></i>Carpeta vacía</div>';
      return;
    }
    const grid = document.createElement('div');
    grid.className = 'nc-file-grid';
    items.forEach(item => {
      const [ic, cls] = fileIconClass(item);
      const card = document.createElement('div');
      card.className = 'nc-file-item';
      card.tabIndex  = 0;
      card.setAttribute('role', 'button');
      card.innerHTML = `
        <div class="nc-file-icon ${cls}"><i class="bi ${ic}"></i></div>
        <div class="nc-file-name" title="${esc(item.name)}">${esc(item.name)}</div>
        ${item.type === 'file' ? `<div class="nc-file-size">${fmtSize(item.size)}</div>` : ''}
        <div class="nc-item-actions">
          ${item.type === 'file' && EDITOR && /\.(docx?|xlsx?|pptx?|odt|ods|odp|rtf|txt|csv)$/i.test(item.name || '')
            ? `<button class="nc-btn-edit" title="Editar en linea" aria-label="Editar en linea" data-path="${esc(item.path)}"><i class="bi bi-pencil-square"></i></button>`
            : ''}
          ${item.type === 'file'
            ? `<button class="nc-btn-dl" title="Descargar/Ver" aria-label="Descargar/Ver" data-path="${esc(item.path)}"><i class="bi bi-download"></i></button>`
            : ''}
          <button class="nc-btn-share" title="Compartir" aria-label="Compartir" data-path="${esc(item.path)}" data-name="${esc(item.name)}"><i class="bi bi-share"></i></button>
          <button class="nc-btn-move"  title="Mover" aria-label="Mover" data-path="${esc(item.path)}" data-name="${esc(item.name)}"><i class="bi bi-folder-symlink"></i></button>
          <button class="nc-btn-copy"  title="Copiar" aria-label="Copiar" data-path="${esc(item.path)}" data-name="${esc(item.name)}"><i class="bi bi-copy"></i></button>
          <button class="nc-btn-ren"   title="Renombrar" aria-label="Renombrar" data-path="${esc(item.path)}" data-name="${esc(item.name)}"><i class="bi bi-pencil"></i></button>
          <button class="nc-btn-del"   title="Eliminar"  aria-label="Eliminar" data-path="${esc(item.path)}" data-name="${esc(item.name)}"><i class="bi bi-trash3"></i></button>
        </div>
      `;

      // Main click: navigate (dir) or download (file)
      card.addEventListener('click', e => {
        if (e.target.closest('.nc-item-actions')) return;
        if (item.type === 'dir') {
          loadDirectory(item.path);
        } else if (EDITOR && /\.(docx?|xlsx?|pptx?|odt|ods|odp|rtf|txt|csv)$/i.test(item.name || '')) {
          window.location.href = EDITOR + '?path=' + encodeURIComponent(item.path);
        } else {
          ncDownload(item.path);
        }
      });

      // Action buttons
      card.querySelector('.nc-btn-edit')?.addEventListener('click', e => {
        e.stopPropagation();
        window.location.href = EDITOR + '?path=' + encodeURIComponent(item.path);
      });
      card.querySelector('.nc-btn-dl')?.addEventListener('click', e => { e.stopPropagation(); ncDownload(item.path); });
      card.querySelector('.nc-btn-share').addEventListener('click', e => { e.stopPropagation(); ncShare(item.path, item.name); });
      card.querySelector('.nc-btn-move').addEventListener('click',  e => { e.stopPropagation(); ncTransfer(item.path, item.name, 'move'); });
      card.querySelector('.nc-btn-copy').addEventListener('click',  e => { e.stopPropagation(); ncTransfer(item.path, item.name, 'copy'); });
      card.querySelector('.nc-btn-ren').addEventListener('click',   e => { e.stopPropagation(); ncRename(item.path, item.name); });
      card.querySelector('.nc-btn-del').addEventListener('click',   e => { e.stopPropagation(); ncDelete(item.path, item.name); });

      grid.appendChild(card);
    });
    el.innerHTML = '';
    el.appendChild(grid);
  }

  // ── Shared with me ─────────────────────────────────────────────────

  let sharedLoaded = false;

  async function loadSharedWithMe() {
    setLoading('nc-shared-list');
    console.time('nc-shares');
    try {
      const data = await apiFetch({ action: 'shares_with_me' });
      console.timeEnd('nc-shares');
      console.log('[NC_PERF] shares', { cached: data.cached, server_ms: data.elapsed_ms, shares: (data.shares || []).length });
      sharedLoaded = true;
      renderShares(data);
    } catch {
      console.timeEnd('nc-shares');
      document.getElementById('nc-shared-list').innerHTML =
        '<div class="nc-empty text-danger"><i class="bi bi-exclamation-triangle"></i> Error al cargar compartidos.</div>';
    }
  }

  async function loadShareUsers() {
    const select = document.getElementById('ncShareUser');
    if (!select) return;
    select.innerHTML = '<option value="">Cargando usuarios...</option>';
    select.disabled = true;
    try {
      const data = await apiFetch({ action: 'share_users' });
      const users = data.ok ? (data.users || []) : [];
      if (!users.length) {
        select.innerHTML = '<option value="">No hay usuarios configurados</option>';
        return;
      }
      select.innerHTML = '<option value="">Seleccione usuario</option>' + users.map(user => {
        const label = user.label && user.label !== user.user ? `${user.label} (${user.user})` : user.user;
        return `<option value="${esc(user.user)}">${esc(label)}</option>`;
      }).join('');
      select.disabled = false;
    } catch {
      select.innerHTML = '<option value="">Error al cargar usuarios</option>';
    }
  }

  function renderShares(data) {
    const el = document.getElementById('nc-shared-list');
    if (!el) return;
    if (!data.ok) {
      el.innerHTML = `<div class="nc-empty text-danger">${esc(data.error || 'Error al cargar.')}</div>`;
      return;
    }
    const shares = data.shares || [];
    if (!shares.length) {
      el.innerHTML = '<div class="nc-empty"><i class="bi bi-share" style="font-size:2.2rem;display:block;margin-bottom:.5rem;color:#cbd5e1"></i>No hay archivos compartidos con usted.</div>';
      return;
    }
    el.innerHTML = shares.map(s => {
      const isDir = s.item_type === 'folder';
      const ic    = isDir ? 'bi-folder-fill text-warning' : 'bi-file-earmark-fill text-secondary';
      const dl    = !isDir
        ? `<a href="${esc(AJAX)}?action=download&path=${encodeURIComponent(s.path)}"
              target="_blank" rel="noopener"
              class="btn btn-sm btn-outline-primary ms-auto flex-shrink-0"
              title="Descargar" aria-label="Descargar"><i class="bi bi-download"></i></a>`
        : '';
      return `<div class="nc-shared-item">
        <span class="nc-shared-icon"><i class="bi ${ic}"></i></span>
        <div class="nc-shared-info">
          <div class="nc-shared-name" title="${esc(s.name || s.path)}">${esc(s.name || s.path)}</div>
          <div class="nc-shared-owner"><i class="bi bi-person-fill"></i> ${esc(s.displayname_owner || s.uid_owner)}</div>
        </div>
        ${dl}
      </div>`;
    }).join('');
  }

  // ── Actions ────────────────────────────────────────────────────────

  function ncDownload(path) {
    window.open(AJAX + '?action=download&path=' + encodeURIComponent(path), '_blank', 'noopener');
  }

  function ncShare(path, name) {
    document.getElementById('ncSharePath').value        = path;
    removePublicShareControls();
    document.getElementById('ncShareUserResult').textContent = '';
    loadShareUsers();
    getModal('ncShareModal')?.show();
  }

  function ncRename(path, name) {
    document.getElementById('ncRenameTarget').value = path;
    document.getElementById('ncRenameName').value   = name;
    focusWhenShown('ncRenameModal', 'ncRenameName', true);
    getModal('ncRenameModal')?.show();
  }

  function ncTransfer(path, name, operation) {
    document.getElementById('ncTransferPath').value = path;
    document.getElementById('ncTransferName').value = name;
    document.getElementById('ncTransferOperation').value = operation;
    openTransferBrowser(currentPath || '/');
    const title = document.getElementById('ncTransferLabel');
    const confirm = document.getElementById('ncTransferConfirm');
    if (title) {
      title.innerHTML = operation === 'copy'
        ? '<i class="bi bi-copy"></i> Copiar'
        : '<i class="bi bi-folder-symlink"></i> Mover';
    }
    if (confirm) {
      confirm.innerHTML = operation === 'copy'
        ? '<i class="bi bi-copy"></i> Copiar'
        : '<i class="bi bi-folder-symlink"></i> Mover';
    }
    getModal('ncTransferModal')?.show();
  }

  async function openTransferBrowser(path) {
    transferBrowsePath = path || '/';
    document.getElementById('ncTransferDestination').value = transferBrowsePath;
    const label = document.getElementById('ncTransferPathLabel');
    const list = document.getElementById('ncTransferFolderList');
    const up = document.getElementById('ncTransferUp');
    if (label) label.textContent = transferBrowsePath;
    if (up) up.disabled = transferBrowsePath === '/';
    if (!list) return;
    list.innerHTML = '<div class="nc-loading"><span class="spinner-border spinner-border-sm me-2" role="status"></span>Cargando...</div>';
    try {
      const data = await apiFetch({ action: 'list', path: transferBrowsePath });
      if (!data.ok) {
        list.innerHTML = `<div class="nc-destination-empty text-danger">${esc(data.error || 'No se pudo cargar la carpeta.')}</div>`;
        return;
      }
      const folders = (data.items || []).filter(item => item.type === 'dir');
      if (!folders.length) {
        list.innerHTML = '<div class="nc-destination-empty">Esta carpeta no tiene subcarpetas.</div>';
        return;
      }
      list.innerHTML = '';
      folders.forEach(folder => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'nc-destination-folder';
        button.innerHTML = `<i class="bi bi-folder-fill text-warning"></i><span>${esc(folder.name)}</span>`;
        button.addEventListener('click', () => openTransferBrowser(folder.path));
        list.appendChild(button);
      });
    } catch {
      list.innerHTML = '<div class="nc-destination-empty text-danger">Error al cargar carpetas.</div>';
    }
  }

  function parentPath(path) {
    const parts = String(path || '/').split('/').filter(Boolean);
    parts.pop();
    return parts.length ? '/' + parts.join('/') : '/';
  }

  function ncDelete(path, name) {
    document.getElementById('ncDeletePath').value       = path;
    document.getElementById('ncDeleteTargetName').textContent = name;
    getModal('ncDeleteModal')?.show();
  }

  // ── Event wiring ───────────────────────────────────────────────────

  // Refresh
  document.getElementById('nc-refresh-btn')?.addEventListener('click', () => loadDirectory(currentPath || '/', true));

  // Upload
  document.getElementById('nc-upload-input')?.addEventListener('change', async function () {
    const files = Array.from(this.files);
    if (!files.length) return;
    for (const file of files) {
      showStatus('Subiendo ' + file.name + '…');
      const fd = new FormData();
      fd.append('path', currentPath);
      fd.append('file', file);
      try {
        const data = await apiFetch({ action: 'upload' }, 'POST', fd);
        if (data.ok) {
          showStatus(file.name + ' subido correctamente.');
        } else {
          showStatus(data.error || 'Error al subir ' + file.name, 'error');
        }
      } catch {
        showStatus('Error de red al subir ' + file.name, 'error');
      }
    }
    this.value = '';
    loadDirectory(currentPath);
  });

  // Mkdir
  document.getElementById('nc-mkdir-btn')?.addEventListener('click', () => {
    document.getElementById('ncMkdirName').value = '';
    focusWhenShown('ncMkdirModal', 'ncMkdirName', false);
    getModal('ncMkdirModal')?.show();
  });

  document.getElementById('ncMkdirConfirm')?.addEventListener('click', async () => {
    const name = document.getElementById('ncMkdirName').value.trim();
    if (!name) return;
    getModal('ncMkdirModal')?.hide();
    const fd = new FormData();
    fd.append('path', currentPath);
    fd.append('name', name);
    const data = await apiFetch({ action: 'mkdir' }, 'POST', fd);
    if (data.ok) {
      showStatus('Carpeta "' + name + '" creada.');
      loadDirectory(currentPath);
    } else {
      showStatus(data.error || 'Error al crear carpeta.', 'error');
    }
  });

  document.getElementById('ncMkdirName')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('ncMkdirConfirm')?.click();
  });

  // Rename
  document.getElementById('ncRenameConfirm')?.addEventListener('click', async () => {
    const path = document.getElementById('ncRenameTarget').value;
    const name = document.getElementById('ncRenameName').value.trim();
    if (!path || !name) return;
    getModal('ncRenameModal')?.hide();
    const fd = new FormData();
    fd.append('path', path);
    fd.append('name', name);
    const data = await apiFetch({ action: 'rename' }, 'POST', fd);
    if (data.ok) {
      showStatus('Renombrado correctamente.');
      loadDirectory(currentPath);
    } else {
      showStatus(data.error || 'Error al renombrar.', 'error');
    }
  });

  document.getElementById('ncRenameName')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('ncRenameConfirm')?.click();
  });

  // Move / copy
  document.getElementById('ncTransferRoot')?.addEventListener('click', () => {
    openTransferBrowser('/');
  });

  document.getElementById('ncTransferCurrent')?.addEventListener('click', () => {
    openTransferBrowser(currentPath || '/');
  });

  document.getElementById('ncTransferUp')?.addEventListener('click', () => {
    openTransferBrowser(parentPath(transferBrowsePath));
  });

  document.getElementById('ncTransferConfirm')?.addEventListener('click', async () => {
    const path = document.getElementById('ncTransferPath').value;
    const destination = document.getElementById('ncTransferDestination').value.trim() || '/';
    const operation = document.getElementById('ncTransferOperation').value === 'copy' ? 'copy' : 'move';
    if (!path) return;
    getModal('ncTransferModal')?.hide();
    const fd = new FormData();
    fd.append('path', path);
    fd.append('destination_dir', destination);
    fd.append('operation', operation);
    try {
      const data = await apiFetch({ action: 'transfer' }, 'POST', fd);
      if (data.ok) {
        showStatus(operation === 'copy' ? 'Copiado correctamente.' : 'Movido correctamente.');
        loadDirectory(currentPath);
      } else {
        showStatus(data.error || (operation === 'copy' ? 'Error al copiar.' : 'Error al mover.'), 'error');
      }
    } catch {
      showStatus(operation === 'copy' ? 'Error de red al copiar.' : 'Error de red al mover.', 'error');
    }
  });

  // Delete
  document.getElementById('ncDeleteConfirm')?.addEventListener('click', async () => {
    const path = document.getElementById('ncDeletePath').value;
    if (!path) return;
    getModal('ncDeleteModal')?.hide();
    const fd = new FormData();
    fd.append('path', path);
    const data = await apiFetch({ action: 'delete' }, 'POST', fd);
    if (data.ok) {
      showStatus('Eliminado correctamente.');
      loadDirectory(currentPath);
    } else {
      showStatus(data.error || 'Error al eliminar.', 'error');
    }
  });

  // Share — create public link
  document.getElementById('ncShareLinkCreate')?.addEventListener('click', async () => {
    const path = document.getElementById('ncSharePath').value;
    const fd   = new FormData();
    fd.append('path', path);
    const data = await apiFetch({ action: 'share_link' }, 'POST', fd);
    if (data.ok && data.url) {
      document.getElementById('ncShareLinkUrl').value     = data.url;
      document.getElementById('ncShareLinkCopy').disabled = false;
    } else {
      showStatus(data.error || 'No se pudo crear el enlace.', 'error');
      document.getElementById('ncShareLinkUrl').value = data.error || 'No se pudo crear el enlace.';
    }
  });

  // Share — copy link
  document.getElementById('ncShareLinkCopy')?.addEventListener('click', () => {
    const url = document.getElementById('ncShareLinkUrl').value;
    if (url) {
      navigator.clipboard.writeText(url)
        .then(() => showStatus('Enlace copiado al portapapeles.'))
        .catch(() => showStatus('No se pudo copiar automáticamente.', 'error'));
    }
  });

  // Share — share with user
  document.getElementById('ncShareUserBtn')?.addEventListener('click', async () => {
    const path      = document.getElementById('ncSharePath').value;
    const shareWith = document.getElementById('ncShareUser').value.trim();
    if (!shareWith) return;
    const fd = new FormData();
    fd.append('path', path);
    fd.append('share_with', shareWith);
    const data = await apiFetch({ action: 'share_user' }, 'POST', fd);
    const el   = document.getElementById('ncShareUserResult');
    if (data.ok) {
      el.textContent = '✓ Compartido con ' + shareWith + ' correctamente.';
      el.className   = 'mt-1 small text-success';
    } else {
      el.textContent = data.error || 'No se pudo compartir.';
      el.className   = 'mt-1 small text-danger';
    }
  });

  document.getElementById('ncShareUser')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('ncShareUserBtn')?.click();
  });

  // "Compartidos conmigo" — carga lazy solo al activar la pestaña
  document.getElementById('nc-tab-shared-btn')?.addEventListener('shown.bs.tab', () => {
    if (!sharedLoaded) {
      loadSharedWithMe();
    }
  });

  // Refresh triggered by proc-board upload
  window.addEventListener('nc-browser-refresh', () => {
    if (browserLoaded) {
      loadDirectory(currentPath);
    }
  });

  browser.setAttribute('data-nc-current-path', currentPath);
  renderIdleDirectory();
  loadDirectory(currentPath || '/', true);
})();
</script>
<?php endif; // $ncHasCredentials ?>
