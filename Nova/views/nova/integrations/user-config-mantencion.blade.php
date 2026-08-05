<!doctype html>
<html lang="es">
<head>
    @php
        $pageTitle = 'Redmine Mantencion | Cuentas conectadas';
        $includeTheme = true;
    @endphp
    <?php include base_path('RedmineMantencion/views/partials/bootstrap-head.php'); ?>
</head>
<body class="bg-light integration-page integration-page-mantencion">
@php
    $activeNav = 'mis_integraciones';
    $configuredCount = collect($integrations)->filter(fn ($item) => !empty($item['stored']))->count();
    $statusFor = function (string $type, array $definition, array $state): array {
        $stored = (bool) ($state['stored'] ?? false);
        $hasSecret = (bool) ($state['has_secret'] ?? false);
        $hasExternal = (bool) ($state['has_external_user'] ?? false);
        $needsExternal = !empty($definition['external_required']);

        if (!$stored) {
            return ['class' => 'is-empty', 'label' => 'Sin configurar', 'icon' => 'bi-circle'];
        }
        if (!$hasSecret || ($needsExternal && !$hasExternal)) {
            return ['class' => 'is-warning', 'label' => 'Requiere actualizacion', 'icon' => 'bi-circle-fill'];
        }

        return ['class' => 'is-ready', 'label' => 'Configurada', 'icon' => 'bi-circle-fill'];
    };
    $formatDate = function ($value): string {
        $value = trim((string) $value);
        if ($value === '') {
            return '-';
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->timezone('America/Santiago')->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $value;
        }
    };
@endphp
<?php include base_path('RedmineMantencion/views/partials/navbar.php'); ?>

<div id="page-content">
    <main class="rm-layout">
        <section class="card card-hero sb-page-hero rm-hero integration-hero mb-3">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3">
                    <span class="rm-hero-icon"><i class="bi bi-person-lock"></i></span>
                    <div>
                        <h1 class="rm-page-title text-white">Cuentas conectadas</h1>
                        <p class="mb-2 text-white-50 fw-semibold">Configure las cuentas personales utilizadas por Redmine Mantencion para conectarse con:</p>
                        <ul class="integration-hero-list">
                            <li>Redmine</li>
                            <li>CORE</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        @if (session('integration_status'))
            <div data-nova-flash="success" data-nova-flash-message="{{ session('integration_status') }}" hidden></div>
        @endif
        @if (session('integration_error'))
            <div data-nova-flash="warning" data-nova-flash-message="{{ session('integration_error') }}" hidden></div>
        @endif

        <section class="integration-grid mb-3">
            @foreach ($integrationDefinitions as $type => $definition)
                @php
                    $state = $integrations[$type] ?? ['stored' => false, 'has_secret' => false, 'has_external_user' => false, 'masked_external_user' => '', 'updated_at' => ''];
                    $stored = (bool) ($state['stored'] ?? false);
                    $maskedExternal = (string) ($state['masked_external_user'] ?? '');
                    $updatedAt = $formatDate($state['updated_at'] ?? '');
                    $status = $statusFor($type, $definition, $state);
                    $hasExternalField = $definition['external_label'] !== '';
                    $drawerId = 'integration-drawer-' . $type;
                    $formId = 'integration-form-' . $type;
                    $secretInputId = 'secret-drawer-' . $type;
                @endphp
                <article class="integration-card nova-card integration-card-summary" role="button" tabindex="0"
                         data-integration-card data-drawer-target="{{ $drawerId }}"
                         aria-controls="{{ $drawerId }}">
                    <div class="integration-card-head">
                        <div class="integration-title">
                            <span class="integration-icon"><i class="bi {{ $definition['icon'] }}"></i></span>
                            <h2>{{ $definition['label'] }}</h2>
                        </div>
                        <span class="integration-card-open" aria-hidden="true"><i class="bi bi-sliders"></i></span>
                    </div>

                    <div class="integration-card-status">
                        <span class="integration-status {{ $status['class'] }}">
                            <i class="bi {{ $status['icon'] }}"></i>{{ $status['label'] }}
                        </span>
                    </div>

                    <dl class="integration-meta">
                        <div>
                            <dt>Usuario</dt>
                            <dd>{{ $hasExternalField ? ($maskedExternal !== '' ? $maskedExternal : '********') : '********' }}</dd>
                        </div>
                        <div>
                            <dt>Ultima actualizacion</dt>
                            <dd>{{ $updatedAt }}</dd>
                        </div>
                    </dl>

                    <div class="integration-card-actions">
                        <button class="btn btn-outline-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#{{ $drawerId }}" aria-controls="{{ $drawerId }}">
                            <i class="bi bi-pencil-square"></i>
                            <span>Editar</span>
                        </button>
                    </div>
                </article>

                <div class="offcanvas offcanvas-end integration-drawer" tabindex="-1" id="{{ $drawerId }}" aria-labelledby="{{ $drawerId }}-title">
                    <div class="offcanvas-header">
                        <div class="integration-drawer-title">
                            <span class="integration-icon"><i class="bi {{ $definition['icon'] }}"></i></span>
                            <div>
                                <h2 class="offcanvas-title" id="{{ $drawerId }}-title">{{ $definition['label'] }}</h2>
                                <span class="integration-status {{ $status['class'] }}">
                                    <i class="bi {{ $status['icon'] }}"></i>{{ $status['label'] }}
                                </span>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
                    </div>
                    <div class="offcanvas-body">
                        <dl class="integration-meta integration-drawer-meta">
                            <div>
                                <dt>Usuario</dt>
                                <dd>{{ $hasExternalField ? ($maskedExternal !== '' ? $maskedExternal : '********') : '********' }}</dd>
                            </div>
                            <div>
                                <dt>Ultima actualizacion</dt>
                                <dd>{{ $updatedAt }}</dd>
                            </div>
                        </dl>

                        <form id="{{ $formId }}" method="post" action="{{ $postUrl }}" class="integration-form js-integration-form">
                            @csrf
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="type" value="{{ $type }}">

                            @if ($hasExternalField)
                                <div>
                                    <label class="form-label" for="external-{{ $type }}">{{ $definition['external_label'] }}</label>
                                    <input id="external-{{ $type }}" class="form-control" name="external_user" autocomplete="username"
                                           value="{{ old('type') === $type ? old('external_user') : '' }}"
                                           placeholder="{{ $maskedExternal !== '' ? $maskedExternal : $definition['external_label'] }}">
                                    @if ($stored)
                                        <div class="form-text">Dejar vacio para conservar el usuario actual.</div>
                                    @endif
                                </div>
                            @else
                                <input type="hidden" name="external_user" value="">
                            @endif

                            <div>
                                <label class="form-label" for="{{ $secretInputId }}">{{ $definition['secret_label'] }}</label>
                                <div class="input-group integration-secret-group" data-secret-wrapper>
                                    <input id="{{ $secretInputId }}" class="form-control" name="secret" type="password"
                                           autocomplete="new-password" placeholder="{{ $stored ? '**************' : $definition['secret_label'] }}"
                                           data-secret-input>
                                    <button class="btn btn-outline-secondary" type="button" data-toggle-secret data-target="{{ $secretInputId }}" aria-controls="{{ $secretInputId }}">
                                        <i class="bi bi-eye"></i><span>Mostrar</span>
                                    </button>
                                </div>
                                @if ($stored)
                                    <div class="form-text">Dejar vacio para conservar la contrasena actual.</div>
                                @else
                                    <div class="form-text">No se muestra ni se precarga ningun secreto guardado.</div>
                                @endif
                            </div>
                        </form>
                    </div>
                    <div class="offcanvas-footer nova-drawer-actions">
                        <form method="post" action="{{ $postUrl }}" class="integration-delete-form js-integration-delete"
                              data-app-confirm="Eliminar la cuenta {{ $definition['label'] }}?">
                            @csrf
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="type" value="{{ $type }}">
                            <button class="btn-nova btn-nova-danger" type="submit" @disabled(!$stored)>
                                <span class="btn-nova-icon"><i class="bi bi-trash"></i></span>
                                <span>Eliminar</span>
                            </button>
                        </form>
                        <button class="btn-nova btn-nova-primary js-submit-button" type="submit" form="{{ $formId }}">
                            <span class="nova-spinner" aria-hidden="true"></span>
                            <span class="btn-nova-icon"><i class="bi bi-check2"></i></span>
                            <span>Guardar</span>
                        </button>
                    </div>
                </div>
            @endforeach
        </section>

        <section class="integration-summary nova-card">
            <div class="rm-section-head">
                <div>
                    <h2><i class="bi bi-list-check"></i> Resumen</h2>
                    <p>Estado de las cuentas personales usadas por Redmine Mantencion.</p>
                </div>
                <span class="badge text-bg-primary rounded-pill">{{ $configuredCount }} activa(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 modules-table">
                    <thead>
                        <tr>
                            <th>Cuenta</th>
                            <th>Usuario</th>
                            <th>Estado</th>
                            <th>Ultima actualizacion</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($integrationDefinitions as $type => $definition)
                            @php
                                $state = $integrations[$type] ?? [];
                                $status = $statusFor($type, $definition, $state);
                            @endphp
                            <tr>
                                <td><strong>{{ $definition['label'] }}</strong></td>
                                <td>{{ $definition['external_label'] === '' ? '********' : (($state['masked_external_user'] ?? '') ?: '********') }}</td>
                                <td>
                                    <span class="integration-status {{ $status['class'] }}">
                                        <i class="bi {{ $status['icon'] }}"></i>{{ $status['label'] }}
                                    </span>
                                </td>
                                <td>{{ $formatDate($state['updated_at'] ?? '') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<?php include base_path('RedmineMantencion/views/partials/bootstrap-scripts.php'); ?>
<script>
(function () {
    document.addEventListener('click', function (event) {
        // Card click — open its drawer (skip when clicking interactive elements)
        const card = event.target.closest('[data-integration-card]');
        if (card && !event.target.closest('button, a, input, select, textarea, label')) {
            const drawer = document.getElementById(card.getAttribute('data-drawer-target'));
            if (drawer && window.bootstrap && window.bootstrap.Offcanvas) {
                window.bootstrap.Offcanvas.getOrCreateInstance(drawer).show();
            }
        }

        // Toggle secret field visibility
        // Uses wrapper scope so multiple forms on the same page never conflict.
        // Bug fix: removed "input.value === ''" guard — field is empty by design
        // (passwords are never pre-populated) so the old check always blocked the toggle.
        const toggleBtn = event.target.closest('[data-toggle-secret]');
        if (!toggleBtn) return;

        event.preventDefault();
        event.stopPropagation();

        const targetId = toggleBtn.getAttribute('data-target') || toggleBtn.getAttribute('aria-controls') || '';
        const wrapper = toggleBtn.closest('[data-secret-wrapper]');
        const input = targetId !== ''
            ? document.getElementById(targetId)
            : wrapper?.querySelector('[data-secret-input]');
        if (!input) return;

        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        toggleBtn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');

        const label = toggleBtn.querySelector('span');
        if (label) label.textContent = isHidden ? 'Ocultar' : 'Mostrar';

        const icon = toggleBtn.querySelector('i');
        if (icon) icon.className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye';
        input.focus();
    });

    document.addEventListener('keydown', function (event) {
        const card = event.target.closest('[data-integration-card]');
        if (!card || (event.key !== 'Enter' && event.key !== ' ')) return;

        event.preventDefault();
        const drawer = document.getElementById(card.getAttribute('data-drawer-target'));
        if (drawer && window.bootstrap && window.bootstrap.Offcanvas) {
            window.bootstrap.Offcanvas.getOrCreateInstance(drawer).show();
        }
    });

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (event.defaultPrevented) {
            return;
        }

        form.querySelectorAll('button[type="submit"]').forEach(function (button) {
            button.disabled = true;
            button.classList.add('is-submitting');
        });
        if (event.submitter) {
            event.submitter.disabled = true;
            event.submitter.classList.add('is-submitting');
        }
    });

    // Snapshot / restore editable fields on offcanvas open / close.
    // On save the page reloads (standard POST redirect), so snapshot is always fresh.
    // On close-without-save we restore to the snapshot taken at open time.
    document.querySelectorAll('.integration-drawer').forEach(function (drawer) {
        const saveForm = drawer.querySelector('.js-integration-form');
        if (!saveForm) return;

        // Snapshot every time the drawer opens
        drawer.addEventListener('show.bs.offcanvas', function () {
            saveForm.querySelectorAll('input:not([type="hidden"]), textarea, select').forEach(function (field) {
                field.dataset.originalValue = field.value;
            });
        });

        // Restore on close — fires for X button, backdrop click, Escape, and programmatic hide
        drawer.addEventListener('hide.bs.offcanvas', function () {
            saveForm.querySelectorAll('input:not([type="hidden"]), textarea, select').forEach(function (field) {
                field.value = Object.prototype.hasOwnProperty.call(field.dataset, 'originalValue')
                    ? field.dataset.originalValue
                    : '';
            });

            // Re-hide any revealed secret fields and reset their toggle buttons
            saveForm.querySelectorAll('[data-secret-input]').forEach(function (secretInput) {
                secretInput.type = 'password';
                const wrapper = secretInput.closest('[data-secret-wrapper]');
                if (!wrapper) return;
                const toggleBtn = wrapper.querySelector('[data-toggle-secret]');
                if (!toggleBtn) return;
                toggleBtn.setAttribute('aria-pressed', 'false');
                const label = toggleBtn.querySelector('span');
                if (label) label.textContent = 'Mostrar';
                const icon = toggleBtn.querySelector('i');
                if (icon) icon.className = 'bi bi-eye';
            });
        });
    });

}());
</script>
</body>
</html>
