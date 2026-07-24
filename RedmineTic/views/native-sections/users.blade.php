@php
    $activeUsers = collect($users)->filter(fn ($user) => (($user['estado_usuario'] ?? $user['estado'] ?? 'activo') === 'activo'))->count();
    $bannedUsers = collect($users)->filter(fn ($user) => (($user['estado_usuario'] ?? $user['estado'] ?? '') === 'baneado'))->count();
    $importPreview = session('redmine_import_preview');
    $usersMaintenanceLocked = !empty($redmineMaintenance['enabled']);
@endphp

<section class="rm-module-head">
    <span class="rm-module-head-icon is-green"><i class="bi bi-people"></i></span>
    <div>
        <small>Acceso del proyecto</small>
        <h2>Usuarios TIC</h2>
        <p>Administra usuarios del modulo, estado operativo, rol y datos de integracion.</p>
    </div>
    <div class="rm-module-meter">
        <strong>{{ count($users) }}</strong>
        <span>registros</span>
    </div>
</section>

<section class="nova-user-summary-grid mb-3" id="tic-user-status-filters" aria-label="Resumen usuarios">
    <article class="nova-user-summary-card is-enabled is-active" data-filter="activo" role="button" tabindex="0">
        <div class="nova-user-summary-icon"><i class="bi bi-person-check"></i></div>
        <div>
            <span>Usuarios activos</span>
            <strong>{{ $activeUsers }}</strong>
        </div>
    </article>
    <article class="nova-user-summary-card is-banned" data-filter="baneado" role="button" tabindex="0">
        <div class="nova-user-summary-icon"><i class="bi bi-person-x"></i></div>
        <div>
            <span>Usuarios baneados</span>
            <strong>{{ $bannedUsers }}</strong>
        </div>
    </article>
</section>

<section id="tabla-usuarios" class="nova-table-card">
    <div class="nova-table-toolbar">
        <span class="nova-table-toolbar-title">Usuarios TIC</span>
        <div class="nova-table-search">
            <i class="bi bi-search"></i>
            <input id="tic-user-search" type="search" placeholder="Buscar nombre, ID o rol">
        </div>
        <span class="nova-user-meta ms-auto">{{ count($users) }} registro(s)</span>
        <form method="post" action="{{ $redmineRoute('redmine.native.users.action') }}">
            @csrf
            <input type="hidden" name="action" value="preview_redmine">
            <button class="btn-nova btn-nova-info" type="submit" @disabled($usersMaintenanceLocked) title="{{ $usersMaintenanceLocked ? 'Modulo en mantencion' : 'Importar usuarios desde Redmine' }}"><i class="bi bi-cloud-download"></i>Importar Redmine</button>
        </form>
        <button class="btn-nova btn-nova-success" type="button" id="new-user-button" @disabled($usersMaintenanceLocked) title="{{ $usersMaintenanceLocked ? 'Modulo en mantencion' : 'Nuevo usuario' }}"><i class="bi bi-plus-circle"></i>Nuevo</button>
    </div>
    <div class="table-responsive">
        <table id="tic-user-table" class="nova-user-table">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th class="nova-col-hide-sm">Telegram</th>
                    <th>Rol</th>
                    <th>Estado TIC</th>
                    <th class="nova-col-hide-md">Ultimo ingreso</th>
                    <th class="nova-col-actions text-center">Acciones</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($users as $user)
                @php
                    $name = trim(($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')) ?: 'Sin nombre';
                    $state = strtolower(trim((string) ($user['estado_usuario'] ?? $user['estado'] ?? 'sin estado')));
                    $telegramChatId = trim((string) ($user['telegram_chat_id'] ?? data_get($user, 'telegram_settings.chat_id', '')));
                    $stateClass = $state === 'activo' ? 'is-activo' : ($state === 'baneado' ? 'is-baneado' : 'is-pending');
                    $rolNova = strtolower(trim((string) ($user['rol_nova'] ?? $user['rol'] ?? 'usuario')));
                    $rolBadge = match ($rolNova) {
                        'root' => 'is-root',
                        'admin', 'administrador' => 'is-admin',
                        'gestor' => 'is-gestor',
                        default => 'is-usuario',
                    };
                    $userInitials = strtoupper(mb_substr($user['nombre'] ?? 'U', 0, 1) . mb_substr($user['apellido'] ?? '', 0, 1));
                    $ultimoLogin = trim((string) ($user['ultimo_login_at'] ?? ''));
                @endphp
                <tr data-user-status="{{ $state === 'baneado' ? 'baneado' : 'activo' }}" data-search="{{ strtolower($name . ' ' . ($user['id'] ?? '') . ' ' . ($user['rol'] ?? '') . ' ' . $state) }}">
                    <td>
                        <div class="nova-user-cell">
                            <div class="nova-user-avatar">{{ $userInitials }}</div>
                            <div>
                                <div class="nova-user-name">{{ $name }}</div>
                                <div class="nova-user-meta">ID: {{ $user['id'] ?? '-' }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="nova-col-hide-sm nova-user-meta">{{ $telegramChatId !== '' ? $telegramChatId : '—' }}</td>
                    <td><span class="nova-badge {{ $rolBadge }}">{{ $user['rol'] ?? 'sin rol' }}</span></td>
                    <td><span class="nova-badge {{ $stateClass }}" title="Estado en Redmine TIC">{{ $state }}</span></td>
                    <td class="nova-col-hide-md">
                        @if ($ultimoLogin !== '')
                            <span class="nova-date-meta">{{ \Carbon\Carbon::parse($ultimoLogin)->format('d/m/Y H:i') }}</span>
                        @else
                            <span class="nova-date-meta">—</span>
                        @endif
                    </td>
                    <td>
                        <div class="nova-table-actions justify-content-center">
                            <button class="btn-action btn-action-edit" type="button"
                                data-user-edit
                                data-id="{{ $user['id'] ?? '' }}"
                                data-rut="{{ $user['rut'] ?? '' }}"
                                data-nombre="{{ $user['nombre'] ?? '' }}"
                                data-apellido="{{ $user['apellido'] ?? '' }}"
                                data-telegram-chat-id="{{ $telegramChatId }}"
                                data-rol="{{ $user['rol'] ?? 'usuario' }}"
                                data-estado="{{ $state }}"
                                data-api="{{ $user['api'] ?? '' }}"
                                @disabled($usersMaintenanceLocked)
                                title="{{ $usersMaintenanceLocked ? 'Modulo en mantencion' : 'Editar usuario' }}"
                                aria-label="Editar usuario">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn-action btn-action-delete"
                                type="button"
                                data-delete-user
                                data-id="{{ $user['id'] ?? '' }}"
                                data-nombre="{{ $name }}"
                                @disabled($usersMaintenanceLocked)
                                title="{{ $usersMaintenanceLocked ? 'Modulo en mantencion' : 'Quitar acceso al proyecto' }}"
                                aria-label="Quitar acceso al proyecto">
                                <i class="bi bi-person-x"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="nova-table-empty"><i class="bi bi-people" style="font-size:1.4rem;display:block;margin-bottom:.4rem;opacity:.35"></i>No hay usuarios registrados.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</section>

<div class="modal fade detail-drawer-modal" id="tic-import-users-modal" tabindex="-1" aria-labelledby="tic-import-users-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable detail-drawer-dialog">
        <form class="modal-content" method="post" action="{{ $redmineRoute('redmine.native.users.action') }}">
            @csrf
            <input type="hidden" name="action" value="sync_redmine">
            <div class="modal-header">
                <h5 class="modal-title" id="tic-import-users-title">Importar usuarios desde Redmine</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                @if (is_array($importPreview) && count($importPreview) > 0)
                    <div class="nova-table-toolbar mb-3">
                        <span class="nova-table-toolbar-title">Selecciona usuarios</span>
                        <div class="nova-table-search">
                            <i class="bi bi-search"></i>
                            <input id="tic-import-user-search" type="search" placeholder="Buscar nombre o ID" aria-label="Buscar usuario para importar">
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="tic-import-select-all">Seleccionar todos</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="tic-import-clear-all">Limpiar</button>
                    </div>
                    <div class="table-responsive">
                        <table class="nova-user-table">
                            <thead>
                                <tr>
                                    <th style="width:48px"></th>
                                    <th>Usuario Redmine</th>
                                    <th>Estado NOVA</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($importPreview as $item)
                                    @php
                                        $status = (string) ($item['status'] ?? 'new');
                                        $checked = $status === 'new' ? 'checked' : '';
                                        $label = match ($status) {
                                            'current' => 'Ya tiene acceso',
                                            'revoked' => 'Existe sin acceso',
                                            default => 'Nuevo, se creara baneado',
                                        };
                                        $badge = match ($status) {
                                            'current' => 'is-activo',
                                            'revoked' => 'is-baneado',
                                            default => 'is-usuario',
                                        };
                                        $fullName = trim((string) ($item['nombre'] ?? '') . ' ' . (string) ($item['apellido'] ?? ''));
                                    @endphp
                                    <tr data-import-search="{{ strtolower($fullName . ' ' . ($item['id'] ?? '')) }}">
                                        <td>
                                            <input class="form-check-input tic-import-user-check" type="checkbox" name="remote_user_ids[]" value="{{ $item['id'] ?? '' }}" {{ $checked }}>
                                        </td>
                                        <td>
                                            <div class="nova-user-name">{{ $fullName !== '' ? $fullName : 'Sin nombre' }}</div>
                                            <div class="nova-user-meta">{{ $item['id'] ?? '-' }}</div>
                                        </td>
                                        <td><span class="nova-badge {{ $badge }}">{{ $label }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif (is_array($importPreview))
                    <div class="nova-table-empty">No hay usuarios importables desde Redmine.</div>
                @else
                    <div class="nova-table-empty">Presiona Importar Redmine para cargar la lista.</div>
                @endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i>Cancelar</button>
                <button type="submit" class="btn-nova btn-nova-primary" {{ empty($importPreview) || $usersMaintenanceLocked ? 'disabled' : '' }}>
                    <i class="bi bi-cloud-download"></i>Importar seleccionados
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Modal confirmacion quitar acceso --}}
<div class="modal fade" id="delete-user-modal" tabindex="-1" aria-labelledby="delete-user-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="delete-user-modal-title"><i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Quitar acceso</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Quitar el acceso de <strong id="delete-user-name"></strong> al proyecto TIC?</p>
                <p class="small nova-muted mt-2 mb-0">Solo se quita su permiso para ver este modulo. El usuario central en NOVA no se elimina.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i>Cancelar</button>
                <form id="delete-user-form" method="post" action="{{ $redmineRoute('redmine.native.users.action') }}" class="m-0">
                    @csrf
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="delete-user-id" value="">
                    <button type="submit" class="btn-nova btn-nova-danger" @disabled($usersMaintenanceLocked)><i class="bi bi-person-x"></i>Quitar acceso</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade detail-drawer-modal" id="usuario-modal" tabindex="-1" aria-labelledby="user-form-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable detail-drawer-dialog">
        <form class="modal-content" method="post" action="{{ $redmineRoute('redmine.native.users.action') }}" id="user-form">
            @csrf
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="_creating" value="1">
            <div class="modal-header">
                <div>
                    <p class="detail-drawer-kicker">Usuario TICS</p>
                    <h2 class="modal-title" id="user-form-title">
                        <span class="detail-drawer-icon"><i class="bi bi-person-gear"></i></span>
                        <span data-user-form-title-text>Nuevo usuario</span>
                    </h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12 col-md-4" data-create-field data-edit-field>
                        <label class="form-label" for="user-id">ID / RUT sin DV</label>
                        <input class="form-control" id="user-id" name="id" placeholder="Nuevo si queda vacio">
                    </div>
                    <div class="col-12 col-md-4" data-create-field>
                        <label class="form-label" for="user-rut">RUT</label>
                        <input class="form-control" id="user-rut" name="rut">
                    </div>
                    <div class="col-12 col-md-4" data-create-field>
                        <label class="form-label" for="user-telegram-chat-id">Chat ID Telegram</label>
                        <input class="form-control" id="user-telegram-chat-id" name="telegram_chat_id" placeholder="7449883192">
                    </div>
                    <div class="col-12 col-md-6" data-create-field data-edit-field>
                        <label class="form-label" for="user-nombre">Nombre</label>
                        <input class="form-control" id="user-nombre" name="nombre" required>
                    </div>
                    <div class="col-12 col-md-6" data-create-field data-edit-field>
                        <label class="form-label" for="user-apellido">Apellido</label>
                        <input class="form-control" id="user-apellido" name="apellido">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="user-rol">Rol</label>
                        <select class="form-select" id="user-rol" name="rol">
                            @foreach (array_unique(array_merge(array_keys($roles), ['usuario'])) as $role)
                                <option value="{{ $role }}">{{ $role }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-md-6" data-create-field>
                        <label class="form-label" for="user-estado">Estado</label>
                        <select class="form-select" id="user-estado" name="estado_usuario">
                            <option value="activo">activo</option>
                            <option value="baneado">baneado</option>
                        </select>
                    </div>
                    <div class="col-12" data-create-field>
                        <label class="form-label" for="user-api">API</label>
                        <input class="form-control" id="user-api" name="api">
                    </div>
                    <div class="col-12" data-edit-role-note hidden>
                        <div class="nova-alert-card is-info mb-0">
                            <i class="bi bi-person-lock"></i>
                            <span>En edicion solo se modifica el rol dentro del proyecto. La identidad, estado e integraciones personales se mantienen sin cambios.</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i>Cancelar</button>
                <button class="btn-nova btn-nova-primary" type="submit" @disabled($usersMaintenanceLocked)><i class="bi bi-save"></i>Guardar usuario</button>
            </div>
        </form>
    </div>
</div>

<script>
    (() => {
        const form = document.getElementById('user-form');
        const title = document.querySelector('[data-user-form-title-text]');
        const modal = document.getElementById('usuario-modal');
        const newButton = document.getElementById('new-user-button');
        const hasImportPreview = @json(is_array($importPreview));
        const usersMaintenanceLocked = @json($usersMaintenanceLocked);
        if (!form) return;

        const setValue = (name, value) => {
            if (form.elements[name]) form.elements[name].value = value || '';
        };

        const openModal = () => {
            if (!modal) return;
            if (window.appUi?.openModal) {
                window.appUi.openModal(modal);
                return;
            }
            if (window.bootstrap?.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modal).show();
                return;
            }
            modal.classList.add('show');
            modal.removeAttribute('aria-hidden');
            modal.setAttribute('aria-modal', 'true');
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
        };

        const resetForm = () => {
            form.reset();
            setEditMode(false);
            setValue('id', '');
            setValue('_creating', '1');
            if (title) title.textContent = 'Nuevo usuario';
        };

        const setEditMode = (isEdit) => {
            ['id', 'rut', 'telegram_chat_id', 'nombre', 'apellido', 'estado_usuario', 'api'].forEach((name) => {
                const field = form.elements[name];
                if (!field) return;
                if (field.tagName === 'SELECT') {
                    field.disabled = isEdit;
                } else {
                    field.readOnly = isEdit;
                }
            });
            form.querySelectorAll('[data-create-field]').forEach((field) => {
                field.hidden = isEdit;
            });
            form.querySelectorAll('[data-edit-field]').forEach((field) => {
                field.hidden = false;
            });
            if (form.elements.nombre) {
                form.elements.nombre.required = !isEdit;
            }
            const note = form.querySelector('[data-edit-role-note]');
            if (note) note.hidden = !isEdit;
        };

        newButton?.addEventListener('click', () => {
            if (usersMaintenanceLocked) return;
            resetForm();
            openModal();
        });

        // Modal confirmar eliminación
        const deleteModal = document.getElementById('delete-user-modal');
        const deleteNameEl = document.getElementById('delete-user-name');
        const deleteIdEl = document.getElementById('delete-user-id');

        document.querySelectorAll('[data-delete-user]').forEach((button) => {
            button.addEventListener('click', () => {
                if (usersMaintenanceLocked) return;
                if (deleteNameEl) deleteNameEl.textContent = button.dataset.nombre || 'este usuario';
                if (deleteIdEl) deleteIdEl.value = button.dataset.id || '';
                if (deleteModal && window.appUi?.openModal) {
                    window.appUi.openModal(deleteModal);
                    return;
                }
                if (deleteModal && window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(deleteModal).show();
                    return;
                }
                deleteModal?.classList.add('show');
            });
        });

    document.querySelectorAll('[data-user-edit]').forEach((button) => {
            button.addEventListener('click', () => {
                if (usersMaintenanceLocked) return;
                setValue('id', button.dataset.id);
                setValue('rut', button.dataset.rut);
                setValue('nombre', button.dataset.nombre);
                setValue('apellido', button.dataset.apellido);
                setValue('telegram_chat_id', button.dataset.telegramChatId);
                setValue('rol', button.dataset.rol || 'usuario');
                setValue('estado_usuario', button.dataset.estado || 'activo');
                setValue('api', button.dataset.api);
                setValue('_creating', '0');
                setEditMode(true);
                if (title) title.textContent = 'Editar usuario';
                openModal();
            });
        });

        // ── Preservar posición de scroll entre acciones ─────────────────
        const SCROLL_KEY = 'nova_tic_users_scroll';

        const savedY = sessionStorage.getItem(SCROLL_KEY);
        if (savedY !== null) {
            sessionStorage.removeItem(SCROLL_KEY);
            const pos = parseInt(savedY, 10);
            if (pos > 0) window.scrollTo({ top: pos, behavior: 'instant' });
        }

        document.querySelectorAll('#tabla-usuarios form, #delete-user-form, #tic-import-users-modal form').forEach((f) => {
            f.addEventListener('submit', () => {
                sessionStorage.setItem(SCROLL_KEY, String(Math.round(window.scrollY)));
            });
        });

        const ticSearch = document.getElementById('tic-user-search');
        const statusFilters = document.getElementById('tic-user-status-filters');
        const ticFilterRows = Array.from(document.querySelectorAll('#tic-user-table tbody tr[data-search]'));
        const normalizeFilterText = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        let activeStatus = statusFilters?.querySelector('[data-filter].is-active')?.getAttribute('data-filter') || 'activo';
        const applyTicFilters = () => {
            const q = normalizeFilterText(ticSearch?.value || '');
            ticFilterRows.forEach((row) => {
                const statusMatches = (row.getAttribute('data-user-status') || 'activo') === activeStatus;
                const textMatches = q === '' || normalizeFilterText(row.dataset.search || '').includes(q);
                row.style.display = statusMatches && textMatches ? '' : 'none';
            });
        };
        ticSearch?.addEventListener('input', applyTicFilters);
        statusFilters?.addEventListener('click', (event) => {
            const card = event.target.closest('[data-filter]');
            if (!card) return;
            activeStatus = card.getAttribute('data-filter') || 'activo';
            statusFilters.querySelectorAll('[data-filter]').forEach((item) => item.classList.toggle('is-active', item === card));
            applyTicFilters();
        });
        statusFilters?.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            const card = event.target.closest('[data-filter]');
            if (!card) return;
            event.preventDefault();
            card.click();
        });
        applyTicFilters();

        const importModal = document.getElementById('tic-import-users-modal');
        if (importModal) {
            const checks = Array.from(importModal.querySelectorAll('.tic-import-user-check'));
            const importRows = Array.from(importModal.querySelectorAll('tbody tr[data-import-search]'));
            const importSearch = document.getElementById('tic-import-user-search');
            const submit = importModal.querySelector('button[type="submit"]');
            const updateImportSubmit = () => {
                if (submit && checks.length > 0) {
                    submit.disabled = !checks.some((check) => check.checked);
                }
            };
            const applyImportSearch = () => {
                const q = normalizeFilterText(importSearch?.value || '');
                importRows.forEach((row) => {
                    row.style.display = q === '' || normalizeFilterText(row.getAttribute('data-import-search') || '').includes(q) ? '' : 'none';
                });
            };
            importSearch?.addEventListener('input', applyImportSearch);
            document.getElementById('tic-import-select-all')?.addEventListener('click', () => {
                importRows.forEach((row) => {
                    if (row.style.display === 'none') return;
                    const check = row.querySelector('.tic-import-user-check');
                    if (check) check.checked = true;
                });
                updateImportSubmit();
            });
            document.getElementById('tic-import-clear-all')?.addEventListener('click', () => {
                checks.forEach((check) => { check.checked = false; });
                updateImportSubmit();
            });
            checks.forEach((check) => check.addEventListener('change', updateImportSubmit));
            updateImportSubmit();
            const openImportModal = (attempt = 0) => {
                if (!hasImportPreview) return;
                if (window.appUi?.openModal) {
                    window.appUi.openModal(importModal);
                    return;
                }
                if (window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(importModal).show();
                    return;
                }
                if (attempt < 20) {
                    window.setTimeout(() => openImportModal(attempt + 1), 50);
                }
            };
            openImportModal();
        }

    })();
</script>
