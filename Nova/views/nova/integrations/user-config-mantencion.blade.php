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
    $statusFor = function (array $definition, array $state): array {
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
                            <li>Nextcloud</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        @if (session('integration_status'))
            <div class="nova-toast is-success integration-toast" role="status" aria-live="polite">
                <i class="bi bi-check-circle-fill"></i>
                <span>{{ session('integration_status') }}</span>
            </div>
        @endif
        @if (session('integration_error'))
            <div class="nova-toast is-info integration-toast" role="status" aria-live="polite">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span>{{ session('integration_error') }}</span>
            </div>
        @endif

        <section class="integration-grid mb-3">
            @foreach ($integrationDefinitions as $type => $definition)
                @php
                    $state = $integrations[$type] ?? ['stored' => false, 'has_secret' => false, 'has_external_user' => false, 'masked_external_user' => '', 'updated_at' => ''];
                    $stored = (bool) ($state['stored'] ?? false);
                    $maskedExternal = (string) ($state['masked_external_user'] ?? '');
                    $updatedAt = $formatDate($state['updated_at'] ?? '');
                    $status = $statusFor($definition, $state);
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
                                <div class="input-group integration-secret-group">
                                    <input id="{{ $secretInputId }}" class="form-control js-secret-input" name="secret" type="password"
                                           autocomplete="new-password" placeholder="{{ $stored ? '**************' : $definition['secret_label'] }}">
                                    <button class="btn btn-outline-secondary js-toggle-secret" type="button" data-target="{{ $secretInputId }}">
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
                    <div class="offcanvas-footer integration-drawer-footer">
                        <button class="btn btn-primary js-submit-button" type="submit" form="{{ $formId }}">
                            <span class="nova-spinner" aria-hidden="true"></span>
                            <i class="bi bi-save"></i>
                            <span>Guardar</span>
                        </button>
                        <form method="post" action="{{ $postUrl }}" class="integration-delete-form js-integration-delete"
                              data-app-confirm="Eliminar la cuenta {{ $definition['label'] }}?">
                            @csrf
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="type" value="{{ $type }}">
                            <button class="btn btn-outline-danger" type="submit" @disabled(!$stored)>
                                <i class="bi bi-trash"></i>
                                <span>Eliminar</span>
                            </button>
                        </form>
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
                                $status = $statusFor($definition, $state);
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
        const card = event.target.closest('[data-integration-card]');
        if (card && !event.target.closest('button, a, input, select, textarea, label')) {
            const drawer = document.getElementById(card.getAttribute('data-drawer-target'));
            if (drawer && window.bootstrap && window.bootstrap.Offcanvas) {
                window.bootstrap.Offcanvas.getOrCreateInstance(drawer).show();
            }
        }

        const toggle = event.target.closest('.js-toggle-secret');
        if (!toggle) return;

        const input = document.getElementById(toggle.getAttribute('data-target'));
        if (!input || input.value === '') return;

        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        const icon = toggle.querySelector('i');
        const label = toggle.querySelector('span');
        if (icon) icon.className = showing ? 'bi bi-eye' : 'bi bi-eye-slash';
        if (label) label.textContent = showing ? 'Mostrar' : 'Ocultar';
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
        const message = form.getAttribute('data-app-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
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

    window.setTimeout(function () {
        document.querySelectorAll('.integration-toast').forEach(function (toast) {
            toast.classList.add('is-hiding');
            window.setTimeout(function () { toast.remove(); }, 220);
        });
    }, 3600);
}());
</script>
</body>
</html>
