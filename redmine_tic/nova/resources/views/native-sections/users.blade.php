@php
    $activeUsers = collect($users)->filter(fn ($user) => (($user['estado_usuario'] ?? $user['estado'] ?? 'activo') === 'activo'))->count();
    $bannedUsers = collect($users)->filter(fn ($user) => (($user['estado_usuario'] ?? $user['estado'] ?? '') === 'baneado'))->count();
    $usersWithTelegram = collect($users)->filter(fn ($user) => trim((string) ($user['telegram_chat_id'] ?? data_get($user, 'telegram_settings.chat_id', ''))) !== '')->count();
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

<section class="row g-3 mb-4" aria-label="Resumen usuarios">
    <div class="col-12 col-md-4">
        <article class="card nova-card rm-stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="rm-stat-icon is-success"><i class="bi bi-person-check"></i></span>
                <div><strong class="fs-2 lh-1">{{ $activeUsers }}</strong><div class="fw-bold nova-muted mt-2">Activos</div></div>
            </div>
        </article>
    </div>
    <div class="col-12 col-md-4">
        <article class="card nova-card rm-stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="rm-stat-icon is-pending"><i class="bi bi-telegram"></i></span>
                <div><strong class="fs-2 lh-1">{{ $usersWithTelegram }}</strong><div class="fw-bold nova-muted mt-2">Con Chat ID</div></div>
            </div>
        </article>
    </div>
    <div class="col-12 col-md-4">
        <article class="card nova-card rm-stat-card">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="rm-stat-icon is-danger"><i class="bi bi-person-x"></i></span>
                <div><strong class="fs-2 lh-1">{{ $bannedUsers }}</strong><div class="fw-bold nova-muted mt-2">Baneados</div></div>
            </div>
        </article>
    </div>
</section>

<section id="tabla-usuarios" class="card nova-card rm-work-panel">
    <div class="card-body p-4">
        <div class="rm-section-head">
            <div>
                <h2>Usuarios registrados</h2>
                <p>{{ count($users) }} registros disponibles.</p>
            </div>
            <div class="rm-form-actions">
                <form method="post" action="{{ $redmineRoute('redmine.native.users.action') }}">
                    @csrf
                    <input type="hidden" name="action" value="sync_redmine">
                    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-arrow-repeat"></i>Sincronizar Redmine</button>
                </form>
                <button class="btn btn-primary" type="button" id="new-user-button" data-nova-modal-open="usuario-modal"><i class="bi bi-plus-circle"></i>Nuevo usuario</button>
            </div>
        </div>

        <div class="table-responsive rm-table-wrap">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Telegram</th>
                        <th>Rol</th>
                        <th>Estado TIC</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($users as $user)
                    @php
                        $name = trim(($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')) ?: 'Sin nombre';
                        $state = strtolower(trim((string) ($user['estado_usuario'] ?? $user['estado'] ?? 'sin estado')));
                        $telegramChatId = trim((string) ($user['telegram_chat_id'] ?? data_get($user, 'telegram_settings.chat_id', '')));
                        $stateClass = $state === 'activo' ? 'is-success' : ($state === 'baneado' ? 'is-danger' : 'is-warning');
                    @endphp
                    <tr>
                        <td><strong>{{ $name }}</strong><div class="small nova-muted">ID: {{ $user['id'] ?? '-' }}</div></td>
                        <td>{{ $telegramChatId !== '' ? $telegramChatId : '-' }}</td>
                        <td><span class="nova-badge">{{ $user['rol'] ?? 'sin rol' }}</span></td>
                        <td><span class="nova-badge {{ $stateClass }}" title="Estado especifico del usuario en Redmine TIC">{{ $state }}</span></td>
                        <td class="text-center">
                            <div class="nova-row-actions justify-content-center">
                                <button class="btn btn-primary nova-btn-icon" type="button"
                                    data-user-edit
                                    data-id="{{ $user['id'] ?? '' }}"
                                    data-rut="{{ $user['rut'] ?? '' }}"
                                    data-nombre="{{ $user['nombre'] ?? '' }}"
                                    data-apellido="{{ $user['apellido'] ?? '' }}"
                                    data-telegram-chat-id="{{ $telegramChatId }}"
                                    data-rol="{{ $user['rol'] ?? 'usuario' }}"
                                    data-estado="{{ $state }}"
                                    data-api="{{ $user['api'] ?? '' }}"
                                    title="Editar usuario"
                                    aria-label="Editar usuario">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <form method="post" action="{{ $redmineRoute('redmine.native.users.action') }}">
                                    @csrf
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="id" value="{{ $user['id'] ?? '' }}">
                                    @if ($state === 'baneado')
                                        <button class="btn btn-success nova-btn-icon" type="submit" title="Activar usuario" aria-label="Activar usuario"><i class="bi bi-person-check"></i></button>
                                    @else
                                        <button class="btn btn-warning nova-btn-icon" type="submit" title="Banear usuario" aria-label="Banear usuario"><i class="bi bi-person-dash"></i></button>
                                    @endif
                                </form>
                                <button class="btn btn-danger nova-btn-icon"
                                    type="button"
                                    data-delete-user
                                    data-id="{{ $user['id'] ?? '' }}"
                                    data-nombre="{{ trim(($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')) ?: 'este usuario' }}"
                                    title="Eliminar del proyecto"
                                    aria-label="Eliminar usuario del proyecto">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="nova-empty"><i class="bi bi-people" style="font-size:1.4rem;display:block;margin-bottom:.4rem;opacity:.35"></i>No hay usuarios registrados.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>

{{-- Modal confirmación eliminar --}}
<div class="modal fade" id="delete-user-modal" tabindex="-1" aria-labelledby="delete-user-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="delete-user-modal-title"><i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>Eliminar del proyecto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">¿Eliminar a <strong id="delete-user-name"></strong> del proyecto TIC?</p>
                <p class="small nova-muted mt-2 mb-0">Se eliminarán su perfil TIC y acceso al módulo. El usuario central en NOVA no se borrará.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i>Cancelar</button>
                <form id="delete-user-form" method="post" action="{{ $redmineRoute('redmine.native.users.action') }}" class="m-0">
                    @csrf
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" id="delete-user-id" value="">
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i>Eliminar del proyecto</button>
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
                <button type="button" class="btn-close" data-nova-modal-close aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="user-id">ID / RUT sin DV</label>
                        <input class="form-control" id="user-id" name="id" placeholder="Nuevo si queda vacio">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="user-rut">RUT</label>
                        <input class="form-control" id="user-rut" name="rut">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="user-telegram-chat-id">Chat ID Telegram</label>
                        <input class="form-control" id="user-telegram-chat-id" name="telegram_chat_id" placeholder="7449883192">
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="user-nombre">Nombre</label>
                        <input class="form-control" id="user-nombre" name="nombre" required>
                    </div>
                    <div class="col-12 col-md-6">
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
                    <div class="col-12 col-md-6">
                        <label class="form-label" for="user-estado">Estado</label>
                        <select class="form-select" id="user-estado" name="estado_usuario">
                            <option value="activo">activo</option>
                            <option value="baneado">baneado</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="user-api">API</label>
                        <input class="form-control" id="user-api" name="api">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" type="button" data-nova-modal-close><i class="bi bi-x-lg"></i>Cancelar</button>
                <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar usuario</button>
            </div>
        </form>
    </div>
</div>

{{-- Botón flotante volver arriba --}}
<button id="users-scroll-top"
    type="button"
    title="Volver arriba"
    aria-label="Volver arriba"
    style="position:fixed;bottom:28px;right:28px;z-index:1050;width:44px;height:44px;min-height:44px!important;border-radius:50%!important;display:none;align-items:center;justify-content:center;padding:0;box-shadow:0 8px 24px rgba(37,99,235,0.35);"
    class="btn btn-primary">
    <i class="bi bi-arrow-up"></i>
</button>

<script>
    (() => {
        const form = document.getElementById('user-form');
        const title = document.querySelector('[data-user-form-title-text]');
        const modal = document.getElementById('usuario-modal');
        const newButton = document.getElementById('new-user-button');
        if (!form) return;

        const setValue = (name, value) => {
            if (form.elements[name]) form.elements[name].value = value || '';
        };

        const openModal = () => {
            if (!modal) return;
            modal.classList.add('show');
            modal.removeAttribute('aria-hidden');
            modal.setAttribute('aria-modal', 'true');
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
        };

        const resetForm = () => {
            form.reset();
            setValue('id', '');
            setValue('_creating', '1');
            if (title) title.textContent = 'Nuevo usuario';
        };

        newButton?.addEventListener('click', () => {
            resetForm();
            openModal();
        });

        // Modal confirmar eliminación
    const deleteModal = document.getElementById('delete-user-modal');
    const deleteNameEl = document.getElementById('delete-user-name');
    const deleteIdEl = document.getElementById('delete-user-id');
    const bsDeleteModal = deleteModal && window.bootstrap ? new bootstrap.Modal(deleteModal) : null;

    document.querySelectorAll('[data-delete-user]').forEach((button) => {
        button.addEventListener('click', () => {
            if (deleteNameEl) deleteNameEl.textContent = button.dataset.nombre || 'este usuario';
            if (deleteIdEl) deleteIdEl.value = button.dataset.id || '';
            bsDeleteModal ? bsDeleteModal.show() : deleteModal?.classList.add('show');
        });
    });

    document.querySelectorAll('[data-user-edit]').forEach((button) => {
            button.addEventListener('click', () => {
                setValue('id', button.dataset.id);
                setValue('rut', button.dataset.rut);
                setValue('nombre', button.dataset.nombre);
                setValue('apellido', button.dataset.apellido);
                setValue('telegram_chat_id', button.dataset.telegramChatId);
                setValue('rol', button.dataset.rol || 'usuario');
                setValue('estado_usuario', button.dataset.estado || 'activo');
                setValue('api', button.dataset.api);
                setValue('_creating', '0');
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

        document.querySelectorAll('#tabla-usuarios form, #delete-user-form').forEach((f) => {
            f.addEventListener('submit', () => {
                sessionStorage.setItem(SCROLL_KEY, String(Math.round(window.scrollY)));
            });
        });

        // ── Botón flotante volver arriba ────────────────────────────────
        const scrollTopBtn = document.getElementById('users-scroll-top');
        const summarySection = document.querySelector('[aria-label="Resumen usuarios"]');

        if (scrollTopBtn && summarySection) {
            const observer = new IntersectionObserver(([entry]) => {
                scrollTopBtn.style.display = entry.isIntersecting ? 'none' : 'flex';
            }, { threshold: 0 });
            observer.observe(summarySection);

            scrollTopBtn.addEventListener('click', () => {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }

    })();
</script>
