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
$ncHasCredentials   = $ncUserId !== ''
    && function_exists('nextcloud_credentials_has_saved')
    && nextcloud_credentials_has_saved($ncUserId);

$ncAjaxUrl          = function_exists('legacy_app_url')
    ? legacy_app_url('views/Procedimientos/nc_browser_ajax.php')
    : '/redmine-mantencion/views/Procedimientos/nc_browser_ajax.php';
$ncIntegracionesUrl = function_exists('legacy_app_url')
    ? legacy_app_url('views/Integraciones/Nextcloud.php')
    : '/redmine-mantencion/views/Integraciones/Nextcloud.php';
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
    <?php if (!empty($canEditProcedures)): ?>
    <span id="proc-oo-badge" class="proc-oo-badge ms-auto" aria-live="polite" style="display:none;"></span>
    <?php endif; ?>
  </div>

  <?php if (!$ncHasCredentials): ?>
  <!-- ── Credential gate ──────────────────────────────────────────── -->
  <div class="nc-browser-gate text-center p-5">
    <div class="nc-gate-icon mb-3"><i class="bi bi-key-fill"></i></div>
    <p class="nc-gate-msg mb-4">
      Debe configurar sus credenciales de Nextcloud antes de usar esta sección.
    </p>
    <a href="<?= $h($ncIntegracionesUrl) ?>" class="btn btn-primary">
      <i class="bi bi-gear-fill"></i>&nbsp;Configurar credenciales Nextcloud
    </a>
  </div>

  <?php else: ?>
  <!-- ── Browser ──────────────────────────────────────────────────── -->
  <div
    id="nc-browser"
    data-ajax="<?= $h($ncAjaxUrl) ?>"
    data-csrf="<?= $h($csrf ?? '') ?>"
    data-can-edit="<?= !empty($canEditProcedures) ? '1' : '0' ?>"
  >
    <!-- Toolbar -->
    <div class="nc-toolbar d-flex align-items-center gap-2 flex-wrap px-3 py-2 border-bottom">
      <nav id="nc-breadcrumb" aria-label="Ruta actual" class="nc-breadcrumb flex-grow-1"></nav>
      <div class="d-flex gap-2 align-items-center">
        <button type="button" class="btn btn-sm btn-outline-secondary" id="nc-refresh-btn" title="Actualizar">
          <i class="bi bi-arrow-clockwise"></i>
        </button>
        <?php if ($canEditProcedures): ?>
        <button type="button" class="btn btn-sm btn-outline-primary" id="nc-mkdir-btn">
          <i class="bi bi-folder-plus"></i> Nueva carpeta
        </button>
        <button type="button" class="btn btn-sm btn-success" id="nc-create-office-btn">
          <i class="bi bi-file-earmark-plus"></i> Crear documento
        </button>
        <label class="btn btn-sm btn-primary mb-0" for="nc-upload-input" role="button">
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
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-primary" id="ncMkdirConfirm">Crear</button>
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
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-primary" id="ncRenameConfirm">Renombrar</button>
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
                <button type="button" class="btn btn-sm btn-outline-secondary" id="ncTransferUp">
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
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-primary" id="ncTransferConfirm">Aplicar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Create Office document -->
  <div class="modal fade" id="ncCreateOfficeModal" tabindex="-1" aria-labelledby="ncCreateOfficeLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h5 class="modal-title" id="ncCreateOfficeLabel"><i class="bi bi-file-earmark-plus"></i> Crear documento</h5>
            <div class="text-muted small">Se guardara directamente en la carpeta actual de Nextcloud.</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="ncCreateOfficeName" class="form-label">Nombre</label>
            <input type="text" class="form-control" id="ncCreateOfficeName" maxlength="255" placeholder="Nombre del documento" autocomplete="off">
          </div>
          <label class="form-label">Tipo de archivo</label>
          <div class="row g-2">
            <div class="col-12 col-sm-4">
              <input class="btn-check" type="radio" name="ncCreateOfficeType" id="ncCreateDocx" value="docx" checked>
              <label class="btn btn-outline-primary w-100 text-start p-3" for="ncCreateDocx">
                <i class="bi bi-file-earmark-word me-2"></i> Word
              </label>
            </div>
            <div class="col-12 col-sm-4">
              <input class="btn-check" type="radio" name="ncCreateOfficeType" id="ncCreateXlsx" value="xlsx">
              <label class="btn btn-outline-success w-100 text-start p-3" for="ncCreateXlsx">
                <i class="bi bi-file-earmark-spreadsheet me-2"></i> Excel
              </label>
            </div>
            <div class="col-12 col-sm-4">
              <input class="btn-check" type="radio" name="ncCreateOfficeType" id="ncCreatePptx" value="pptx">
              <label class="btn btn-outline-warning w-100 text-start p-3" for="ncCreatePptx">
                <i class="bi bi-file-earmark-slides me-2"></i> PowerPoint
              </label>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-success" id="ncCreateOfficeConfirm">
            <i class="bi bi-file-earmark-plus"></i> Crear
          </button>
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
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-danger" id="ncDeleteConfirm">Eliminar</button>
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
              <button type="button" class="btn btn-outline-secondary btn-sm" id="ncShareLinkCopy" disabled title="Copiar enlace"><i class="bi bi-clipboard"></i></button>
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
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

  <?php endif; // $ncHasCredentials ?>
</section>

<!-- ══════════════════════════════════════════════════════════════════
     Scoped styles
     ══════════════════════════════════════════════════════════════════ -->
<style>
.nc-browser-section { overflow: hidden; }
#nc-browser { position: relative; }
.nc-busy-overlay {
  position: absolute;
  inset: 0;
  z-index: 30;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 1.25rem;
  background: rgba(15, 23, 42, .42);
  backdrop-filter: blur(2px);
}
.nc-busy-overlay.is-active { display: flex; }
.nc-busy-card {
  width: min(320px, calc(100% - 2rem));
  min-height: 168px;
  border-radius: .9rem;
  box-shadow: 0 24px 55px rgba(15, 23, 42, .28);
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: .35rem;
  overflow: hidden;
}
.nc-busy-card.blue-on-white {
  background-color: #fff;
}
.nc-busy-card.blue-on-white .centerCircle {
  background-color: #fff;
}
.nc-busy-card.blue-on-white .centerCircle,
.nc-busy-card.blue-on-white .leftCircle,
.nc-busy-card.blue-on-white .rightCircle {
  border-color: #0082c9;
}
.nc-busy-card .centerCircle,
.nc-busy-card .leftCircle,
.nc-busy-card .rightCircle {
  position: absolute;
  left: 50%;
  border-radius: 50%;
  border: 10px solid;
}
.nc-busy-card .centerCircle {
  width: 32px;
  height: 32px;
  top: 46px;
  margin-left: -26px;
  z-index: 10;
}
.nc-busy-card .leftCircle,
.nc-busy-card .rightCircle {
  top: 55px;
  width: 14px;
  height: 14px;
  animation-name: ncMoveEar;
  animation-duration: 3000ms;
  animation-timing-function: ease;
  animation-fill-mode: forwards;
  animation-delay: 350ms;
}
.nc-busy-card .leftCircle {
  margin-left: -57px;
  transform: translate(40px, 0);
}
.nc-busy-card .rightCircle {
  margin-left: 23px;
  transform: translate(-40px, 0);
}
.nc-busy-card .group {
  position: relative;
  width: 144px;
  height: 112px;
  margin: 0 auto;
  animation-name: ncRotateEars;
  animation-duration: 1800ms;
  animation-timing-function: ease;
  animation-iteration-count: infinite;
  animation-delay: 750ms;
}
.nc-busy-text {
  color: #0f172a;
  font-size: .9rem;
  font-weight: 800;
  text-align: center;
  padding: 0 1rem 1rem;
}
@keyframes ncMoveEar {
  10%, 100% {
    transform: initial;
    opacity: 1;
  }
}
@keyframes ncRotateEars {
  60%, 100% {
    transform: rotate(360deg);
  }
}
@media (prefers-reduced-motion: reduce) {
  .nc-busy-card .group,
  .nc-busy-card .leftCircle,
  .nc-busy-card .rightCircle {
    animation: none;
  }
}
.nc-browser-head {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1.1rem 1.4rem;
  border-bottom: 1px solid rgba(0,0,0,.07);
}
.nc-browser-icon {
  width: 2.4rem; height: 2.4rem;
  border-radius: .7rem;
  background: linear-gradient(135deg, #0ea5e9, #0284c7);
  color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.05rem;
  flex-shrink: 0;
}
.nc-browser-gate .nc-gate-icon { font-size: 2.8rem; color: #94a3b8; }
.nc-browser-gate .nc-gate-msg  { color: #475569; max-width: 36rem; margin-left: auto; margin-right: auto; }

/* Breadcrumb */
.nc-breadcrumb {
  display: flex; align-items: center; gap: .2rem;
  overflow-x: auto; white-space: nowrap; padding: .15rem 0;
  scrollbar-width: none;
}
.nc-breadcrumb::-webkit-scrollbar { display: none; }
.nc-breadcrumb a {
  font-size: .8rem; color: #0ea5e9; text-decoration: none; cursor: pointer;
  padding: .15rem .35rem; border-radius: .35rem; transition: background .15s;
}
.nc-breadcrumb a:hover { background: rgba(14,165,233,.1); }
.nc-breadcrumb .nc-sep  { color: #94a3b8; font-size: .72rem; }
.nc-breadcrumb .nc-cur  { font-size: .8rem; font-weight: 600; color: #334155; padding: .15rem .35rem; }

/* File grid */
.nc-file-list { min-height: 160px; }
.nc-loading {
  display: flex; align-items: center;
  color: #64748b; padding: 2.5rem 0; justify-content: center;
}
.nc-empty {
  text-align: center; color: #94a3b8;
  padding: 3rem 0; font-size: .88rem;
}
.nc-file-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: .85rem;
}
.nc-file-item {
  position: relative;
  display: flex; flex-direction: column; align-items: center;
  gap: .35rem; padding: .9rem .7rem;
  border-radius: .8rem;
  border: 1.5px solid rgba(203,213,225,.6);
  background: rgba(255,255,255,.82);
  cursor: pointer;
  text-decoration: none; color: inherit;
  transition: border-color .17s, box-shadow .17s, background .17s;
  text-align: center; overflow: hidden;
}
.nc-file-item:hover {
  border-color: #0ea5e9;
  box-shadow: 0 4px 18px rgba(14,165,233,.13);
  background: rgba(240,249,255,.92);
}
.nc-file-icon        { font-size: 2.2rem; line-height: 1; }
.nc-icon-dir         { color: #f59e0b; }
.nc-icon-pdf         { color: #ef4444; }
.nc-icon-word        { color: #2563eb; }
.nc-icon-excel       { color: #16a34a; }
.nc-icon-image       { color: #8b5cf6; }
.nc-icon-file        { color: #64748b; }
.nc-file-name {
  font-size: .75rem; font-weight: 600; color: #334155;
  word-break: break-all; max-width: 100%;
  display: -webkit-box; -webkit-line-clamp: 2;
  -webkit-box-orient: vertical; overflow: hidden;
}
.nc-file-size { font-size: .67rem; color: #94a3b8; }

/* Hover action buttons */
.nc-item-actions {
  display: none;
  position: absolute; top: .35rem; right: .35rem;
  gap: .2rem;
  flex-wrap: wrap;
  justify-content: flex-end;
  max-width: 5.4rem;
}
.nc-file-item:hover .nc-item-actions { display: flex; }
.nc-item-actions button {
  width: 1.65rem; height: 1.65rem;
  border: none; border-radius: .38rem;
  background: rgba(255,255,255,.92);
  color: #475569; font-size: .68rem;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; padding: 0;
  transition: background .14s, color .14s;
  line-height: 1;
}
.nc-item-actions button:hover           { background: #0ea5e9; color: #fff; }
.nc-item-actions button.nc-btn-del:hover { background: #ef4444; color: #fff; }

.nc-destination-picker {
  border: 1px solid rgba(148, 163, 184, .45);
  border-radius: .65rem;
  overflow: hidden;
  background: #fff;
}
.nc-destination-head {
  display: flex;
  align-items: center;
  gap: .5rem;
  padding: .5rem;
  border-bottom: 1px solid rgba(148, 163, 184, .25);
  background: #f8fafc;
}
.nc-destination-path {
  min-width: 0;
  flex: 1;
  font-size: .82rem;
  font-weight: 700;
  color: #334155;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.nc-destination-list {
  max-height: 210px;
  overflow-y: auto;
  padding: .45rem;
}
.nc-destination-folder {
  width: 100%;
  border: 0;
  border-radius: .5rem;
  background: transparent;
  color: #334155;
  display: flex;
  align-items: center;
  gap: .55rem;
  min-height: 2.35rem;
  padding: .45rem .55rem;
  text-align: left;
}
.nc-destination-folder:hover {
  background: #eff6ff;
  color: #1d4ed8;
}
.nc-destination-empty {
  padding: 1rem;
  text-align: center;
  color: #64748b;
  font-size: .84rem;
}

/* Status bar */
.nc-status {
  padding: .45rem 1rem; font-size: .8rem;
  border-top: 1px solid rgba(0,0,0,.06);
}
.nc-status-ok  { background: #f0fdf4; color: #15803d; }
.nc-status-err { background: #fef2f2; color: #dc2626; }

/* Shared-with-me list */
.nc-shared-item {
  display: flex; align-items: center; gap: .7rem;
  padding: .55rem .75rem;
  border-radius: .65rem;
  border: 1px solid rgba(203,213,225,.5);
  background: rgba(255,255,255,.8);
  margin-bottom: .45rem; transition: border-color .15s;
}
.nc-shared-item:hover { border-color: #0ea5e9; }
.nc-shared-icon  { font-size: 1.35rem; flex-shrink: 0; }
.nc-shared-info  { flex: 1; min-width: 0; }
.nc-shared-name  { font-size: .8rem; font-weight: 600; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.nc-shared-owner { font-size: .7rem; color: #64748b; }
</style>

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

  function isOfficeFile(item) {
    if (!item || item.type !== 'file') return false;
    const ext = (item.name.split('.').pop() || '').toLowerCase();
    return ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'].includes(ext);
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
      create_office: 'Creando documento Office...',
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
    let html = '<a data-nav="/" title="Raíz"><i class="bi bi-house-fill"></i></a>';
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
      const canEditOffice = CAN_EDIT && isOfficeFile(item);
      const card = document.createElement('div');
      card.className = 'nc-file-item';
      card.tabIndex  = 0;
      card.setAttribute('role', 'button');
      card.innerHTML = `
        <div class="nc-file-icon ${cls}"><i class="bi ${ic}"></i></div>
        <div class="nc-file-name" title="${esc(item.name)}">${esc(item.name)}</div>
        ${item.type === 'file' ? `<div class="nc-file-size">${fmtSize(item.size)}</div>` : ''}
        <div class="nc-item-actions">
          ${item.type === 'file'
            ? `<button class="nc-btn-dl" title="Descargar/Ver" data-path="${esc(item.path)}"><i class="bi bi-download"></i></button>`
            : ''}
          ${canEditOffice
            ? `<button class="nc-btn-office" title="Editar en OnlyOffice" data-path="${esc(item.path)}"><i class="bi bi-pencil-square"></i></button>`
            : ''}
          <button class="nc-btn-share" title="Compartir" data-path="${esc(item.path)}" data-name="${esc(item.name)}"><i class="bi bi-share"></i></button>
          <button class="nc-btn-move"  title="Mover" data-path="${esc(item.path)}" data-name="${esc(item.name)}"><i class="bi bi-folder-symlink"></i></button>
          <button class="nc-btn-copy"  title="Copiar" data-path="${esc(item.path)}" data-name="${esc(item.name)}"><i class="bi bi-copy"></i></button>
          <button class="nc-btn-ren"   title="Renombrar" data-path="${esc(item.path)}" data-name="${esc(item.name)}"><i class="bi bi-pencil"></i></button>
          <button class="nc-btn-del"   title="Eliminar"  data-path="${esc(item.path)}" data-name="${esc(item.name)}"><i class="bi bi-trash3"></i></button>
        </div>
      `;

      // Main click: navigate (dir) or download (file)
      card.addEventListener('click', e => {
        if (e.target.closest('.nc-item-actions')) return;
        if (item.type === 'dir') {
          loadDirectory(item.path);
        } else if (canEditOffice && window.__ncOoAvailable) {
          ncOpenOffice(item.path);
        } else {
          ncDownload(item.path);
        }
      });

      // Action buttons
      card.querySelector('.nc-btn-dl')?.addEventListener('click', e => { e.stopPropagation(); ncDownload(item.path); });
      card.querySelector('.nc-btn-office')?.addEventListener('click', e => { e.stopPropagation(); ncOpenOffice(item.path); });
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
              title="Descargar"><i class="bi bi-download"></i></a>`
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

  async function ncOpenOffice(path) {
    if (!CAN_EDIT) {
      showStatus('Sin permisos para editar procedimientos.', 'error');
      return;
    }
    if (!window.__ncOoAvailable) {
      showStatus('OnlyOffice no está disponible. Se abrirá la descarga.', 'error');
      ncDownload(path);
      return;
    }
    setBusy(true, 'Preparando edición en OnlyOffice...');
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 8000);
    try {
      const fd = new FormData();
      fd.append('action', 'open_office');
      fd.append('csrf_token', CSRF);
      fd.append('path', path);
      const res = await fetch(AJAX, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF },
        body: fd,
        signal: controller.signal,
      });
      const data = await res.json();
      if (!res.ok || !data.ok || !data.edit_url) {
        showStatus(data.error || 'No se pudo abrir OnlyOffice.', 'error');
        return;
      }
      window.location.href = data.edit_url;
    } catch {
      showStatus('No se pudo preparar OnlyOffice a tiempo.', 'error');
    } finally {
      window.clearTimeout(timeout);
      setBusy(false);
    }
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

  // Create Office document
  document.getElementById('nc-create-office-btn')?.addEventListener('click', () => {
    const input = document.getElementById('ncCreateOfficeName');
    if (input) input.value = '';
    const docx = document.getElementById('ncCreateDocx');
    if (docx) docx.checked = true;
    focusWhenShown('ncCreateOfficeModal', 'ncCreateOfficeName', false);
    getModal('ncCreateOfficeModal')?.show();
  });

  document.getElementById('ncCreateOfficeConfirm')?.addEventListener('click', async () => {
    const input = document.getElementById('ncCreateOfficeName');
    const checkedType = document.querySelector('input[name="ncCreateOfficeType"]:checked');
    const title = input ? input.value.trim() : '';
    const type = checkedType ? checkedType.value : 'docx';
    const fd = new FormData();
    fd.append('path', currentPath);
    fd.append('title', title);
    fd.append('document_type', type);
    getModal('ncCreateOfficeModal')?.hide();
    try {
      const data = await apiFetch({ action: 'create_office' }, 'POST', fd);
      if (data.ok) {
        if (window.__ncOoAvailable === false) {
          showStatus('El documento fue creado en Nextcloud, pero OnlyOffice no está disponible para abrirlo en línea.');
        } else {
          showStatus('Documento "' + data.name + '" creado.');
        }
        loadDirectory(currentPath);
      } else {
        showStatus(data.error || 'Error al crear documento.', 'error');
      }
    } catch {
      showStatus('Error de red al crear documento.', 'error');
    }
  });

  document.getElementById('ncCreateOfficeName')?.addEventListener('keydown', e => {
    if (e.key === 'Enter') document.getElementById('ncCreateOfficeConfirm')?.click();
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
