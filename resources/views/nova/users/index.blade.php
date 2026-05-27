<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Usuarios NOVA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/nova-ui.css') }}" rel="stylesheet">
    <style>
        body { margin: 0; min-height: 100vh; background: #eef3fb; }
        .rm-shell { min-height: 100vh; }
        .rm-navbar { min-height: 68px; background: linear-gradient(115deg, #1f2f56 0%, #314ed8 62%, #4966ff 100%); box-shadow: 0 16px 36px rgba(31, 47, 86, .22); }
        .rm-brand-mark { display: inline-grid; width: 42px; height: 42px; place-items: center; border-radius: 12px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.24); color: #fff; }
        .rm-top-actions { margin-left: auto; display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
        .rm-layout { width: 100%; margin: 0; padding: 24px 24px 44px; }
        .rm-main { min-width: 0; }
        .rm-section-nav { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 18px; }
        .rm-section-nav .nav-link { display: inline-flex; align-items: center; gap: 8px; min-height: 42px; border-radius: 10px; padding: 8px 12px; background: #fff; color: #334155; font-weight: 800; box-shadow: 0 8px 20px rgba(15,23,42,.05); }
        .rm-section-nav .nav-link.active { background: var(--nova-primary); color: #fff; box-shadow: 0 14px 30px rgba(37, 99, 235, .22); }
        .rm-hero { border: 0; color: #fff; background: linear-gradient(130deg, #4f86f7 0%, #2f9ed9 48%, #31c5ae 100%); box-shadow: 0 18px 34px rgba(49, 91, 170, .14); }
        .rm-hero-icon { display: grid; width: 46px; height: 46px; place-items: center; flex: 0 0 auto; border-radius: 14px; background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.28); font-size: 1.25rem; }
        .rm-page-title { margin: 0; color: #fff; font-size: clamp(1.55rem, 3vw, 2.25rem); font-weight: 800; }
        .rm-hero-retention { display: inline-flex; align-items: center; gap: 7px; margin-left: auto; min-height: 36px; padding: 7px 11px; border-radius: 999px; border: 1px solid rgba(255,255,255,.35); background: rgba(255,255,255,.14); color: #fff; font-size: .86rem; font-weight: 900; white-space: nowrap; }
        .rm-work-panel { border-radius: 14px; }
        .rm-panel { padding: 16px; }
        .rm-table-wrap .table thead th { background: #eaf8fd; color: #435061; font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; }
        .rm-section-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
        .rm-section-head h2 { margin: 0; font-size: 1.05rem; font-weight: 800; }
        .rm-section-head p { margin: 4px 0 0; color: var(--nova-muted); }
        .user-grid { display: block; }
        .form-panel { display: flex; flex-direction: column; max-height: calc(100vh - 40px); }
        .form-title { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #e2e8f0; }
        .form-title h2 { margin: 0; font-size: 1.08rem; font-weight: 900; color: #0f172a; }
        .form-section { display: grid; gap: 12px; margin-bottom: 14px; }
        .form-section.is-two { grid-template-columns: 1fr 1fr; }
        .form-section-title { margin: 16px 0 10px; color: #64748b; font-size: .78rem; font-weight: 900; text-transform: uppercase; letter-spacing: .04em; }
        .field { margin-bottom: 12px; }
        .field label { display: block; margin-bottom: 6px; color: #334155; font-size: .86rem; font-weight: 800; }
        .field-help { display: none; margin-top: 6px; color: #b91c1c; font-size: .78rem; font-weight: 800; }
        .form-control.is-invalid + .field-help { display: block; }
        .table td, .table th { vertical-align: middle; }
        .table-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 16px; border-bottom: 1px solid #e2e8f0; background: #f8fafc; }
        .table-panel-head h2 { margin: 0; font-size: 1.05rem; font-weight: 800; color: #0f172a; }
        .user-filters { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
        .user-search { width: min(360px, 100%); position: relative; }
        .user-search i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #64748b; }
        .user-search input { padding-left: 38px; }
        .column-filter { width: 150px; }
        .row-actions { display: flex; gap: 7px; justify-content: flex-end; }
        .nova-toast-stack { position: fixed; right: 22px; bottom: 22px; z-index: 1080; display: grid; gap: 10px; width: min(380px, calc(100vw - 32px)); }
        .nova-toast { border-radius: 16px; border: 1px solid #cbd5e1; background: #fff; box-shadow: 0 20px 50px rgba(15, 23, 42, .18); padding: 14px 16px; display: flex; align-items: flex-start; gap: 10px; font-weight: 800; color: #0f172a; animation: toastIn .18s ease-out; }
        .nova-toast.is-success { border-color: #bbf7d0; background: #f0fdf4; color: #166534; }
        .nova-toast.is-danger { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
        .nova-modal-backdrop { position: fixed; inset: 0; z-index: 1070; display: none; align-items: center; justify-content: center; padding: 18px; background: rgba(15, 23, 42, .54); }
        .nova-modal-backdrop.is-open { display: flex; }
        .nova-confirm { width: min(460px, 100%); border-radius: 18px; border: 0; background: #fff; box-shadow: 0 28px 70px rgba(15, 23, 42, .28); overflow: hidden; }
        .nova-user-form { width: min(760px, 100%); overflow: hidden; }
        .nova-user-form__body { overflow: auto; padding: 22px; }
        .nova-user-form__footer { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 18px; background: #f8fafc; border-top: 1px solid #e2e8f0; }
        .modal-close { border: 0; background: transparent; color: #64748b; font-size: 1.45rem; line-height: 1; padding: 0; }
        .nova-confirm__body { padding: 22px; }
        .nova-confirm__body h2 { margin: 0 0 8px; font-size: 1.1rem; font-weight: 900; }
        .nova-confirm__body p { margin: 0; color: #475569; }
        .nova-confirm__actions { display: flex; justify-content: flex-end; gap: 10px; padding: 14px 18px; background: #f8fafc; border-top: 1px solid #e2e8f0; }
        @keyframes toastIn { from { transform: translateY(10px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        @media (max-width: 900px) {
            .user-grid { grid-template-columns: 1fr; }
            .form-section.is-two { grid-template-columns: 1fr; }
            .table-panel-head { align-items: stretch; flex-direction: column; }
            .user-search, .column-filter, .user-filters { width: 100%; }
        }
    </style>
</head>
<body class="nova-page">
    <div class="rm-shell">
        <nav class="navbar navbar-expand-lg navbar-dark rm-navbar">
            <div class="container-fluid px-4">
                <a class="navbar-brand d-flex align-items-center gap-3 fw-bold" href="{{ route('nova-users.index') }}">
                    <span class="rm-brand-mark"><i class="bi bi-person-gear"></i></span>
                    <span>Usuarios NOVA</span>
                </a>
                <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#novaUsersTopbar" aria-controls="novaUsersTopbar" aria-expanded="false" aria-label="Alternar navegacion">
                    <span class="navbar-toggler-icon"></span>
                </button>
                <div class="collapse navbar-collapse" id="novaUsersTopbar">
                    <div class="rm-top-actions mt-3 mt-lg-0">
                        @include('nova.partials.session-control')
                        <span class="text-white-50 fw-bold"><i class="bi bi-person-circle"></i> {{ session('nova_user.name') }}</span>
                        <a class="btn btn-outline-light" href="{{ route('home') }}"><i class="bi bi-house-door"></i>NOVA</a>
                        <a class="btn btn-outline-light" href="{{ route('logout') }}"><i class="bi bi-box-arrow-right"></i>Salir</a>
                    </div>
                </div>
            </div>
        </nav>

        <div class="rm-layout">
            <main class="rm-main">
                <nav class="rm-section-nav" aria-label="Secciones Usuarios NOVA">
                    <a class="nav-link active" href="{{ route('nova-users.index') }}"><i class="bi bi-people"></i>Usuarios</a>
                    <a class="nav-link" href="{{ route('home') }}"><i class="bi bi-grid"></i>Modulos</a>
                </nav>

                <section class="card rm-hero mb-4">
                    <div class="card-body p-3 p-lg-4 d-flex align-items-center gap-3 flex-wrap">
                        <div class="d-flex align-items-center gap-3">
                            <span class="rm-hero-icon"><i class="bi bi-person-gear"></i></span>
                            <div>
                                <h1 class="rm-page-title">Usuarios NOVA</h1>
                            </div>
                        </div>
                        <span class="rm-hero-retention"><i class="bi bi-shield-check"></i>Acceso principal</span>
                    </div>
                </section>

                <div class="user-grid">
            <div class="nova-modal-backdrop" data-user-modal aria-hidden="true">
            <form class="nova-confirm nova-user-form form-panel" method="post" action="{{ route('nova-users.update') }}">
                @csrf
                <input type="hidden" name="id" data-user-id>
                <input type="hidden" name="redmine_id" data-user-redmine-id>

                <div class="form-title nova-user-form__body" style="margin-bottom: 0;">
                    <h2 data-user-form-title>Crear usuario</h2>
                    <span class="nova-badge" data-user-mode>Nuevo</span>
                    <button class="modal-close" type="button" aria-label="Cerrar" data-user-close>&times;</button>
                </div>

                <div class="nova-user-form__body">
                    <div class="form-section-title">Identificacion</div>
                    <div class="form-section">
                        <div class="field">
                            <label for="rut">RUT</label>
                            <input class="form-control" id="rut" name="rut" required placeholder="12.345.678-9" maxlength="12" data-user-rut>
                            <div class="field-help" data-user-rut-help>Ingrese un RUT valido.</div>
                        </div>
                        <div class="field">
                            <label for="username">Usuario acceso</label>
                            <input class="form-control" id="username" name="username" readonly data-user-username>
                        </div>
                    </div>

                    <div class="form-section is-two">
                        <div class="field">
                            <label for="name">Nombre</label>
                            <input class="form-control" id="name" name="name" required data-user-name>
                        </div>
                        <div class="field">
                            <label for="apellido">Apellidos</label>
                            <input class="form-control" id="apellido" name="apellido" required data-user-apellido>
                        </div>
                    </div>

                    <div class="form-section-title">Acceso</div>
                    <div class="form-section is-two">
                        <div class="field">
                            <label for="role">Permiso vista principal</label>
                            <select class="form-select" id="role" name="role" data-user-role>
                                <option value="usuario">Usuario</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="status">Estado</label>
                            <select class="form-select" id="status" name="status" data-user-status>
                                <option value="activo">activo</option>
                                <option value="baneado">baneado</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-section-title">Clave</div>
                    <div class="form-section">
                        <div class="field">
                            <label for="password">Contrasena</label>
                            <input class="form-control" id="password" name="password" type="password" autocomplete="new-password" placeholder="Dejar vacia al editar">
                        </div>
                        <div class="field">
                            <label for="password_confirmation">Validar contrasena</label>
                            <input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" placeholder="Repetir contrasena">
                        </div>
                    </div>
                </div>
                <div class="nova-user-form__footer">
                    <button class="btn btn-outline-secondary" type="button" data-user-close>Cancelar</button>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar</button>
                </div>
            </form>
            </div>

            <section class="card nova-card rm-work-panel">
                <div class="table-panel-head">
                    <div>
                        <h2>Usuarios registrados</h2>
                        <div class="nova-muted small"><span data-user-count>{{ count($users) }}</span> usuario(s) visibles</div>
                    </div>
                    <div class="user-filters">
                        <div class="user-search">
                            <i class="bi bi-search"></i>
                            <input class="form-control" type="search" placeholder="Buscar por RUT, ID, nombre o apellido" data-user-search>
                        </div>
                        <select class="form-select column-filter" data-role-filter aria-label="Filtrar permiso NOVA">
                            <option value="">Permiso: todos</option>
                            <option value="admin">Admin</option>
                            <option value="usuario">Usuario</option>
                        </select>
                        <select class="form-select column-filter" data-status-filter aria-label="Filtrar estado">
                            <option value="">Estado: todos</option>
                            <option value="activo">activo</option>
                            <option value="baneado">baneado</option>
                        </select>
                        <button class="btn btn-outline-secondary" type="button" data-user-filter-clear title="Limpiar filtros"><i class="bi bi-x-circle"></i></button>
                    </div>
                    <button class="btn btn-primary" type="button" data-user-new><i class="bi bi-plus-circle"></i>Nuevo usuario</button>
                </div>
                <div class="table-responsive rm-table-wrap">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Usuario</th>
                                <th>Nombre</th>
                                <th>ID Redmine</th>
                                <th>Permiso NOVA</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($users as $user)
                            @php($novaRole = in_array(($user['role'] ?? 'usuario'), ['admin', 'administrador', 'gestor', 'root'], true) ? 'admin' : 'usuario')
                            @php($userStatus = $user['status'] ?? 'activo')
                            <tr data-user-row
                                data-user-row-id="{{ $user['id'] ?? '' }}"
                                data-user-row-rut="{{ $user['rut'] ?? '' }}"
                                data-user-row-username="{{ $user['username'] ?? '' }}"
                                data-user-row-role="{{ $novaRole }}"
                                data-user-row-status="{{ $userStatus }}"
                                data-search="{{ strtolower(($user['username'] ?? '') . ' ' . ($user['rut'] ?? '') . ' ' . ($user['redmine_id'] ?? '') . ' ' . ($user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')) }}">
                                <td>
                                    <strong>{{ $user['username'] ?? '' }}</strong>
                                    <div class="nova-muted small">{{ $user['rut'] ?? '' }}</div>
                                </td>
                                <td>{{ trim(($user['name'] ?? '') . ' ' . ($user['apellido'] ?? '')) }}</td>
                                <td>{{ $user['redmine_id'] ?? '-' }}</td>
                                <td><span class="nova-badge {{ $novaRole === 'admin' ? 'is-success' : '' }}">{{ $novaRole === 'admin' ? 'Admin' : 'Usuario' }}</span></td>
                                <td><span class="nova-badge {{ $userStatus === 'baneado' ? 'is-danger' : '' }}">{{ $userStatus }}</span></td>
                                <td>
                                    <div class="row-actions">
                                        <button class="btn btn-sm btn-outline-secondary" type="button"
                                            data-user-edit
                                            data-id="{{ $user['id'] ?? '' }}"
                                            data-redmine-id="{{ $user['redmine_id'] ?? '' }}"
                                            data-username="{{ $user['username'] ?? '' }}"
                                            data-name="{{ $user['name'] ?? '' }}"
                                            data-apellido="{{ $user['apellido'] ?? '' }}"
                                            data-rut="{{ $user['rut'] ?? '' }}"
                                            data-role="{{ $novaRole }}"
                                            data-status="{{ $user['status'] ?? 'activo' }}">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="post" action="{{ route('nova-users.update') }}" data-confirm-form data-confirm-message="{{ $userStatus === 'baneado' ? 'Activar este usuario?' : 'Marcar usuario como baneado?' }}">
                                            @csrf
                                            <input type="hidden" name="action" value="{{ $userStatus === 'baneado' ? 'activate' : 'delete' }}">
                                            <input type="hidden" name="id" value="{{ $user['id'] ?? '' }}">
                                            @if ($userStatus === 'baneado')
                                                <button class="btn btn-sm btn-outline-success" type="submit" title="Activar"><i class="bi bi-check-circle"></i></button>
                                            @else
                                                <button class="btn btn-sm btn-outline-danger" type="submit" title="Banear"><i class="bi bi-slash-circle"></i></button>
                                            @endif
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">No hay usuarios NOVA registrados.</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
                </div>
            </main>
        </div>
    </div>
    <div class="nova-toast-stack" aria-live="polite" aria-atomic="true">
        @if (session('status'))
            <div class="nova-toast is-success" data-toast><i class="bi bi-check-circle-fill"></i><span>{{ session('status') }}</span></div>
        @endif
        @if (session('error'))
            <div class="nova-toast is-danger" data-toast><i class="bi bi-exclamation-triangle-fill"></i><span>{{ session('error') }}</span></div>
        @endif
        @if ($errors->any())
            <div class="nova-toast is-danger" data-toast><i class="bi bi-exclamation-triangle-fill"></i><span>{{ $errors->first() }}</span></div>
        @endif
    </div>
    <div class="nova-modal-backdrop" data-confirm-modal aria-hidden="true">
        <div class="nova-confirm" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
            <div class="nova-confirm__body">
                <h2 id="confirm-title">Confirmar accion</h2>
                <p data-confirm-text>Confirma la accion sobre este usuario.</p>
            </div>
            <div class="nova-confirm__actions">
                <button class="btn btn-outline-secondary" type="button" data-confirm-cancel>Cancelar</button>
                <button class="btn btn-primary" type="button" data-confirm-accept>Confirmar</button>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const form = document.querySelector('.form-panel');
        const formTitle = document.querySelector('[data-user-form-title]');
        const formMode = document.querySelector('[data-user-mode]');
        const userModal = document.querySelector('[data-user-modal]');
        const setValue = (selector, value) => {
            const field = form?.querySelector(selector);
            if (field) field.value = value || '';
        };
        const openUserModal = () => {
            userModal?.classList.add('is-open');
            userModal?.setAttribute('aria-hidden', 'false');
            setTimeout(() => form?.querySelector('[data-user-rut]')?.focus(), 60);
        };
        const closeUserModal = () => {
            userModal?.classList.remove('is-open');
            userModal?.setAttribute('aria-hidden', 'true');
        };
        const resetUserForm = () => {
            form?.reset();
            setValue('[data-user-id]', '');
            setValue('[data-user-redmine-id]', '');
            setValue('[data-user-username]', '');
            rutField?.classList.remove('is-invalid');
            if (formTitle) formTitle.textContent = 'Crear usuario';
            if (formMode) formMode.textContent = 'Nuevo';
        };
        const rutAccessUser = (rut) => {
            const raw = String(rut || '').trim();
            const clean = raw.replace(/[^0-9kK]/g, '').toLowerCase();
            if (!clean) return '';
            return clean.slice(0, -1);
        };
        const formatRut = (rut) => {
            const clean = String(rut || '').replace(/[^0-9kK]/g, '').toUpperCase();
            if (clean.length <= 1) return clean;

            const number = clean.slice(0, -1);
            const dv = clean.slice(-1);
            const dotted = number.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

            return `${dotted}-${dv}`;
        };
        const isValidRut = (rut) => {
            const clean = String(rut || '').replace(/[^0-9kK]/g, '').toLowerCase();
            if (!/^\d{7,8}[0-9k]$/.test(clean)) return false;

            const number = clean.slice(0, -1);
            const dv = clean.slice(-1);
            let factor = 2;
            let sum = 0;

            for (let i = number.length - 1; i >= 0; i -= 1) {
                sum += Number(number[i]) * factor;
                factor = factor === 7 ? 2 : factor + 1;
            }

            const rest = 11 - (sum % 11);
            const expected = rest === 11 ? '0' : rest === 10 ? 'k' : String(rest);
            return expected === dv;
        };
        const normalizeRut = (rut) => String(rut || '').replace(/[^0-9kK]/g, '').toLowerCase();
        const rutHelp = form?.querySelector('[data-user-rut-help]');
        const duplicateRutUser = () => {
            const currentId = form?.querySelector('[data-user-id]')?.value || '';
            const rut = normalizeRut(rutField?.value);
            const username = rutAccessUser(rutField?.value);
            if (!rut || !isValidRut(rutField?.value)) return null;

            return Array.from(document.querySelectorAll('[data-user-row]')).find((row) => {
                const rowId = row.dataset.userRowId || '';
                if (currentId !== '' && rowId === currentId) return false;

                return normalizeRut(row.dataset.userRowRut) === rut
                    || String(row.dataset.userRowUsername || '').toLowerCase() === username;
            }) || null;
        };
        const updateRutState = (showInvalid = true) => {
            if (!rutField) return;
            const hasValue = rutField.value.trim() !== '';
            const valid = isValidRut(rutField.value);
            const duplicate = valid ? duplicateRutUser() : null;

            if (rutHelp) {
                rutHelp.textContent = duplicate ? 'Este RUT ya esta registrado.' : 'Ingrese un RUT valido.';
            }

            rutField.classList.toggle('is-invalid', showInvalid && hasValue && (!valid || duplicate !== null));
            setValue('[data-user-username]', valid ? rutAccessUser(rutField.value) : '');
        };
        const rutField = form?.querySelector('[data-user-rut]');
        rutField?.addEventListener('input', () => {
            const cursorAtEnd = rutField.selectionStart === rutField.value.length;
            rutField.value = formatRut(rutField.value);
            updateRutState(false);
            if (cursorAtEnd) {
                rutField.setSelectionRange(rutField.value.length, rutField.value.length);
            }
        });
        rutField?.addEventListener('blur', () => {
            rutField.value = formatRut(rutField.value);
            updateRutState(true);
        });
        form?.addEventListener('submit', (event) => {
            updateRutState(true);
            if (rutField && (!isValidRut(rutField.value) || duplicateRutUser() !== null)) {
                event.preventDefault();
                rutField.focus();
            }
        });

        document.querySelectorAll('[data-user-edit]').forEach((button) => {
            button.addEventListener('click', () => {
                setValue('[data-user-id]', button.dataset.id);
                setValue('[data-user-redmine-id]', button.dataset.redmineId);
                setValue('[data-user-username]', button.dataset.username);
                setValue('[data-user-name]', button.dataset.name);
                setValue('[data-user-apellido]', button.dataset.apellido);
                setValue('[data-user-rut]', button.dataset.rut);
                if (rutField) {
                    rutField.value = formatRut(rutField.value);
                }
                updateRutState(false);
                setValue('[data-user-role]', button.dataset.role);
                setValue('[data-user-status]', button.dataset.status);
                setValue('#password', '');
                setValue('#password_confirmation', '');
                if (formTitle) formTitle.textContent = 'Editar usuario';
                if (formMode) formMode.textContent = 'Editando';
                openUserModal();
            });
        });

        document.querySelector('[data-user-new]')?.addEventListener('click', () => {
            resetUserForm();
            openUserModal();
        });

        document.querySelectorAll('[data-user-close]').forEach((button) => {
            button.addEventListener('click', () => {
                closeUserModal();
            });
        });

        const normalizeSearch = (value) => String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^0-9a-z k]/g, ' ');
        const rows = Array.from(document.querySelectorAll('[data-user-row]'));
        const visibleCount = document.querySelector('[data-user-count]');
        const searchInput = document.querySelector('[data-user-search]');
        const roleFilter = document.querySelector('[data-role-filter]');
        const statusFilter = document.querySelector('[data-status-filter]');
        const applyUserFilters = () => {
            const query = normalizeSearch(searchInput?.value || '');
            const role = roleFilter?.value || '';
            const status = statusFilter?.value || '';
            let count = 0;

            rows.forEach((row) => {
                const haystack = normalizeSearch(row.dataset.search);
                const matchSearch = query === '' || haystack.includes(query);
                const matchRole = role === '' || row.dataset.userRowRole === role;
                const matchStatus = status === '' || row.dataset.userRowStatus === status;
                const visible = matchSearch && matchRole && matchStatus;
                row.style.display = visible ? '' : 'none';
                if (visible) count += 1;
            });

            if (visibleCount) visibleCount.textContent = String(count);
        };
        searchInput?.addEventListener('input', applyUserFilters);
        roleFilter?.addEventListener('change', applyUserFilters);
        statusFilter?.addEventListener('change', applyUserFilters);
        document.querySelector('[data-user-filter-clear]')?.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            if (roleFilter) roleFilter.value = '';
            if (statusFilter) statusFilter.value = '';
            applyUserFilters();
        });

        document.querySelectorAll('[data-toast]').forEach((toast) => {
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(10px)';
                toast.style.transition = 'opacity .18s ease, transform .18s ease';
                setTimeout(() => toast.remove(), 220);
            }, 4500);
        });

        const confirmModal = document.querySelector('[data-confirm-modal]');
        const confirmText = document.querySelector('[data-confirm-text]');
        const confirmAccept = document.querySelector('[data-confirm-accept]');
        const confirmCancel = document.querySelector('[data-confirm-cancel]');
        let pendingForm = null;

        document.querySelectorAll('[data-confirm-form]').forEach((actionForm) => {
            actionForm.addEventListener('submit', (event) => {
                event.preventDefault();
                pendingForm = actionForm;
                if (confirmText) {
                    confirmText.textContent = actionForm.dataset.confirmMessage || 'Confirma la accion sobre este usuario.';
                }
                confirmModal?.classList.add('is-open');
                confirmModal?.setAttribute('aria-hidden', 'false');
            });
        });

        confirmCancel?.addEventListener('click', () => {
            pendingForm = null;
            confirmModal?.classList.remove('is-open');
            confirmModal?.setAttribute('aria-hidden', 'true');
        });

        confirmAccept?.addEventListener('click', () => {
            const submitForm = pendingForm;
            pendingForm = null;
            confirmModal?.classList.remove('is-open');
            confirmModal?.setAttribute('aria-hidden', 'true');
            submitForm?.submit();
        });
    </script>
</body>
</html>
