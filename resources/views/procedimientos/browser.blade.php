<link rel="stylesheet" href="{{ url('/redmine-mantencion/assets/css/procedimientos.css') }}?v={{ @filemtime(base_path('RedmineMantencion/assets/css/procedimientos.css')) ?: 1 }}">

<section class="nc-browser-section card shadow-sm mb-4" id="nc-browser-section">
    <div class="nc-browser-head">
        <span class="nc-browser-icon"><i class="bi bi-cloud-fill"></i></span>
        <div><h2 class="mb-0">Archivos Nextcloud</h2><p class="mb-0 text-muted small">Explorador de su cuenta personal de Nextcloud.</p></div>
    </div>

    @if (!$nextcloudConfigured)
        <div class="nc-browser-gate text-center p-5">
            <div class="nc-gate-icon mb-3"><i class="bi bi-key-fill"></i></div>
            <p class="nc-gate-msg mb-4">Debe configurar sus credenciales de Nextcloud antes de usar esta sección.</p>
            <a href="{{ $integrationsUrl }}" class="btn-nova btn-nova-primary"><i class="bi bi-gear-fill"></i>Configurar credenciales</a>
        </div>
    @else
        <div id="nc-browser" data-endpoint="{{ $browserUrl }}" data-editor="{{ $editorUrl }}">
            <div class="nc-toolbar d-flex align-items-center gap-2 flex-wrap px-3 py-2 border-bottom">
                <nav id="nc-breadcrumb" class="nc-breadcrumb flex-grow-1" aria-label="Ruta actual"></nav>
                <button class="btn btn-sm btn-outline-secondary" id="nc-refresh" type="button" title="Actualizar"><i class="bi bi-arrow-clockwise"></i></button>
                <button class="btn-nova btn-nova-primary" id="nc-mkdir" type="button"><i class="bi bi-folder-plus"></i>Nueva carpeta</button>
                <label class="btn-nova btn-nova-info mb-0" for="nc-upload"><i class="bi bi-upload"></i>Subir<input class="visually-hidden" id="nc-upload" type="file" multiple></label>
            </div>
            <ul class="nav nav-tabs px-3 pt-2" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#nc-files-pane" type="button"><i class="bi bi-folder2-open"></i> Mis archivos</button></li>
                <li class="nav-item"><button class="nav-link" id="nc-shared-tab" data-bs-toggle="tab" data-bs-target="#nc-shared-pane" type="button"><i class="bi bi-share"></i> Compartidos conmigo</button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active p-3" id="nc-files-pane"><div id="nc-files" class="nc-file-list"></div></div>
                <div class="tab-pane fade p-3" id="nc-shared-pane"><div id="nc-shared" class="nc-file-list"></div></div>
            </div>
            <div class="nc-status d-none" id="nc-status" role="status" aria-live="polite"></div>
            <div class="nc-busy-overlay" id="nc-busy" aria-hidden="true"><div class="nc-busy-card"><span class="spinner-border text-primary" aria-hidden="true"></span><div id="nc-busy-text">Consultando Nextcloud...</div></div></div>
        </div>
    @endif
</section>

@if ($nextcloudConfigured)
<div class="modal fade" id="nc-action-modal" tabindex="-1" aria-labelledby="nc-action-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title fs-5" id="nc-action-title">Acción Nextcloud</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body"><p id="nc-action-message" class="mb-3"></p><input class="form-control" id="nc-action-input" autocomplete="off"><select class="form-select d-none" id="nc-action-select"></select></div>
        <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="nc-action-confirm" type="button">Continuar</button></div>
    </div></div>
</div>
<script>
(function () {
    'use strict';
    const root = document.getElementById('nc-browser');
    if (!root) return;
    const endpoint = root.dataset.endpoint;
    const editor = root.dataset.editor;
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let currentPath = '/';
    let sharedLoaded = false;

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
    const editable = name => /\.(docx?|xlsx?|pptx?|odt|ods|odp|rtf|txt|csv)$/i.test(name || '');
    const size = bytes => bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : bytes >= 1024 ? (bytes / 1024).toFixed(1) + ' KB' : bytes + ' B';

    function dialog({title, message, value = '', choices = null, danger = false, confirmOnly = false}) {
        return new Promise(resolve => {
            const modalElement = document.getElementById('nc-action-modal');
            const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
            const input = document.getElementById('nc-action-input');
            const select = document.getElementById('nc-action-select');
            const button = document.getElementById('nc-action-confirm');
            document.getElementById('nc-action-title').textContent = title;
            document.getElementById('nc-action-message').textContent = message || '';
            input.classList.toggle('d-none', confirmOnly || Array.isArray(choices));
            select.classList.toggle('d-none', !Array.isArray(choices));
            button.className = danger ? 'btn btn-danger' : 'btn btn-primary';
            button.textContent = danger ? 'Confirmar' : 'Continuar';
            input.value = value;
            if (Array.isArray(choices)) select.innerHTML = choices.map(choice => '<option value="' + escapeHtml(choice.value) + '">' + escapeHtml(choice.label) + '</option>').join('');
            let settled = false;
            const finish = result => { if (settled) return; settled = true; resolve(result); };
            const confirm = () => { const result = confirmOnly ? true : (Array.isArray(choices) ? select.value : input.value.trim()); finish(result); modal.hide(); };
            button.addEventListener('click', confirm, {once: true});
            modalElement.addEventListener('hidden.bs.modal', () => finish(null), {once: true});
            modalElement.addEventListener('shown.bs.modal', () => (Array.isArray(choices) ? select : input).focus(), {once: true});
            modal.show();
        });
    }

    function busy(active, text) {
        const overlay = document.getElementById('nc-busy');
        document.getElementById('nc-busy-text').textContent = text || 'Consultando Nextcloud...';
        overlay.classList.toggle('is-active', active);
        overlay.setAttribute('aria-hidden', active ? 'false' : 'true');
    }

    function notify(message, error = false) {
        const status = document.getElementById('nc-status');
        status.textContent = message;
        status.className = 'nc-status ' + (error ? 'nc-status-err' : 'nc-status-ok');
        clearTimeout(status._timer);
        status._timer = setTimeout(() => status.classList.add('d-none'), 5000);
    }

    async function request(action, method = 'GET', values = {}, loadingText = '') {
        busy(true, loadingText);
        try {
            let url = endpoint;
            const options = {method, headers: {'Accept': 'application/json'}};
            if (method === 'GET') {
                url += '?' + new URLSearchParams({action, ...values});
            } else {
                const form = values instanceof FormData ? values : Object.assign(new FormData(), values);
                if (!(values instanceof FormData)) Object.entries(values).forEach(([key, value]) => form.set(key, value));
                form.set('action', action);
                options.body = form;
                options.headers['X-CSRF-TOKEN'] = token;
            }
            const response = await fetch(url, options);
            const data = await response.json();
            if (!response.ok && !data.error) data.error = 'Error HTTP ' + response.status;
            return data;
        } catch (error) {
            return {ok: false, error: 'No fue posible conectar con Nextcloud.'};
        } finally {
            busy(false);
        }
    }

    function breadcrumbs() {
        const parts = currentPath.split('/').filter(Boolean);
        let accumulated = '';
        document.getElementById('nc-breadcrumb').innerHTML = '<button class="btn btn-sm btn-link p-0" data-path="/">Inicio</button>' + parts.map(part => {
            accumulated += '/' + part;
            return '<i class="bi bi-chevron-right mx-1"></i><button class="btn btn-sm btn-link p-0" data-path="' + escapeHtml(accumulated) + '">' + escapeHtml(part) + '</button>';
        }).join('');
    }

    function icon(item) {
        if (item.type === 'dir') return 'bi-folder-fill text-warning';
        if (/\.pdf$/i.test(item.name)) return 'bi-file-earmark-pdf-fill text-danger';
        if (/\.docx?$/i.test(item.name)) return 'bi-file-earmark-word-fill text-primary';
        if (/\.xlsx?$/i.test(item.name)) return 'bi-file-earmark-excel-fill text-success';
        return 'bi-file-earmark-fill text-secondary';
    }

    async function load(path = '/', refresh = false) {
        currentPath = path;
        breadcrumbs();
        document.getElementById('nc-files').innerHTML = '<div class="nc-loading"><span class="spinner-border spinner-border-sm"></span> Cargando...</div>';
        const data = await request('list', 'GET', {path, refresh: refresh ? '1' : '0'}, 'Consultando carpetas...');
        if (!data.ok) {
            document.getElementById('nc-files').innerHTML = '<div class="nova-empty-state text-danger"><i class="bi bi-exclamation-triangle"></i><p>' + escapeHtml(data.error || 'No se pudo cargar.') + '</p></div>';
            return;
        }
        const items = data.items || [];
        document.getElementById('nc-files').innerHTML = items.length ? '<div class="nc-file-grid">' + items.map(item => `
            <article class="nc-file-item" data-type="${escapeHtml(item.type)}" data-path="${escapeHtml(item.path)}" data-name="${escapeHtml(item.name)}">
                <div class="nc-file-icon"><i class="bi ${icon(item)}"></i></div>
                <div class="nc-file-name" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</div>
                <div class="nc-file-size">${item.type === 'file' ? size(Number(item.size || 0)) : 'Carpeta'}</div>
                <div class="nc-item-actions">
                    ${item.type === 'file' && editable(item.name) ? '<button class="nc-action" data-action="edit" title="Editar"><i class="bi bi-pencil-square"></i></button>' : ''}
                    ${item.type === 'file' ? '<button class="nc-action" data-action="download" title="Descargar"><i class="bi bi-download"></i></button>' : ''}
                    <button class="nc-action" data-action="share" title="Compartir"><i class="bi bi-share"></i></button>
                    <button class="nc-action" data-action="move" title="Mover"><i class="bi bi-folder-symlink"></i></button>
                    <button class="nc-action" data-action="copy" title="Copiar"><i class="bi bi-copy"></i></button>
                    <button class="nc-action" data-action="rename" title="Renombrar"><i class="bi bi-pencil"></i></button>
                    <button class="nc-action" data-action="delete" title="Eliminar"><i class="bi bi-trash3"></i></button>
                </div>
            </article>`).join('') + '</div>' : '<div class="nova-empty-state"><i class="bi bi-folder2-open"></i><p>Carpeta vacía</p></div>';
    }

    async function mutate(action, values, success) {
        const data = await request(action, 'POST', values, 'Actualizando Nextcloud...');
        notify(data.ok ? success : (data.error || 'No se pudo completar la operación.'), !data.ok);
        if (data.ok) load(currentPath, true);
        return data;
    }

    root.addEventListener('click', async event => {
        const breadcrumb = event.target.closest('[data-path]');
        if (breadcrumb && breadcrumb.closest('#nc-breadcrumb')) return load(breadcrumb.dataset.path);
        const card = event.target.closest('.nc-file-item');
        if (!card) return;
        const action = event.target.closest('[data-action]')?.dataset.action;
        const path = card.dataset.path;
        const name = card.dataset.name;
        if (!action) return card.dataset.type === 'dir' ? load(path) : (editable(name) ? location.assign(editor + '?path=' + encodeURIComponent(path)) : window.open(endpoint + '?action=download&path=' + encodeURIComponent(path), '_blank'));
        if (action === 'edit') return location.assign(editor + '?path=' + encodeURIComponent(path));
        if (action === 'download') return window.open(endpoint + '?action=download&path=' + encodeURIComponent(path), '_blank');
        if (action === 'rename') {
            const next = await dialog({title: 'Renombrar', message: 'Ingrese el nuevo nombre.', value: name});
            if (next) await mutate('rename', {path, name: next}, 'Elemento renombrado.');
        }
        if (action === 'move' || action === 'copy') {
            const destination = await dialog({title: action === 'copy' ? 'Copiar' : 'Mover', message: 'Ingrese la carpeta de destino.', value: currentPath});
            if (destination !== null) await mutate('transfer', {path, destination_dir: destination || '/', operation: action}, action === 'copy' ? 'Elemento copiado.' : 'Elemento movido.');
        }
        if (action === 'delete' && await dialog({title: 'Eliminar elemento', message: 'Se eliminará "' + name + '" de Nextcloud.', danger: true, confirmOnly: true})) await mutate('delete', {path}, 'Elemento eliminado.');
        if (action === 'share') {
            const users = await request('share_users', 'GET', {}, 'Consultando usuarios...');
            if (!users.ok || !users.users?.length) return notify('No hay usuarios Nextcloud disponibles.', true);
            const target = await dialog({title: 'Compartir con usuario', message: 'Seleccione el usuario Nextcloud.', choices: users.users.map(user => ({value: user.user, label: user.label + ' — ' + user.user}))});
            if (target) await mutate('share_user', {path, share_with: target}, 'Elemento compartido.');
        }
    });

    document.getElementById('nc-refresh').addEventListener('click', () => load(currentPath, true));
    document.getElementById('nc-mkdir').addEventListener('click', async () => {
        const name = await dialog({title: 'Nueva carpeta', message: 'Ingrese el nombre de la carpeta.'});
        if (name) await mutate('mkdir', {path: currentPath, name}, 'Carpeta creada.');
    });
    document.getElementById('nc-upload').addEventListener('change', async event => {
        for (const file of Array.from(event.target.files || [])) {
            const form = new FormData(); form.set('path', currentPath); form.set('file', file);
            await mutate('upload', form, file.name + ' subido.');
        }
        event.target.value = '';
    });
    document.getElementById('nc-shared-tab').addEventListener('shown.bs.tab', async () => {
        if (sharedLoaded) return;
        const data = await request('shares_with_me', 'GET', {}, 'Consultando compartidos...');
        sharedLoaded = true;
        document.getElementById('nc-shared').innerHTML = data.ok && data.shares?.length ? data.shares.map(share => `<div class="nc-shared-item"><i class="bi ${share.item_type === 'folder' ? 'bi-folder-fill text-warning' : 'bi-file-earmark-fill'}"></i><div class="flex-grow-1"><strong>${escapeHtml(share.name)}</strong><div class="small text-muted">Compartido por ${escapeHtml(share.displayname_owner || share.uid_owner)}</div></div>${share.item_type !== 'folder' ? '<a class="btn btn-sm btn-outline-primary" target="_blank" href="' + endpoint + '?action=download&path=' + encodeURIComponent(share.path) + '"><i class="bi bi-download"></i></a>' : ''}</div>`).join('') : '<div class="nova-empty-state"><i class="bi bi-share"></i><p>No hay archivos compartidos.</p></div>';
    });
    load('/');
}());
</script>
@endif
