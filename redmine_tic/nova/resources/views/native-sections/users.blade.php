@php
    $activeUsers = collect($users)->filter(fn ($user) => (($user['estado_usuario'] ?? $user['estado'] ?? 'activo') === 'activo'))->count();
    $bannedUsers = collect($users)->filter(fn ($user) => (($user['estado_usuario'] ?? $user['estado'] ?? '') === 'baneado'))->count();
    $usersWithTelegram = collect($users)->filter(fn ($user) => trim((string) ($user['telegram_chat_id'] ?? data_get($user, 'telegram_settings.chat_id', ''))) !== '')->count();
@endphp

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

<section class="card nova-card rm-work-panel">
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
                        <th>RUT</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                @forelse ($users as $user)
                    @php
                        $name = trim(($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')) ?: 'Sin nombre';
                        $state = $user['estado_usuario'] ?? $user['estado'] ?? 'sin estado';
                        $telegramChatId = trim((string) ($user['telegram_chat_id'] ?? data_get($user, 'telegram_settings.chat_id', '')));
                    @endphp
                    <tr>
                        <td><strong>{{ $name }}</strong><div class="small nova-muted">ID: {{ $user['id'] ?? '-' }}</div></td>
                        <td>{{ $telegramChatId !== '' ? $telegramChatId : '-' }}</td>
                        <td>{{ $user['rut'] ?? $user['rut_sin_dv'] ?? '-' }}</td>
                        <td><span class="nova-badge">{{ $user['rol'] ?? 'sin rol' }}</span></td>
                        <td><span class="nova-badge {{ $state === 'activo' ? 'is-success' : '' }}">{{ $state }}</span></td>
                        <td>
                            <div class="nova-row-actions">
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
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="{{ $user['id'] ?? '' }}">
                                    <button class="btn btn-danger nova-btn-icon" type="submit" title="Eliminar usuario" aria-label="Eliminar usuario"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">No hay usuarios registrados.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>

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
    })();
</script>
