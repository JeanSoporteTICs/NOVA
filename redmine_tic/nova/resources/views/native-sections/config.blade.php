@php
    $activePanel = request('panel', 'resumen');
    $panels = [
        'resumen' => ['label' => 'Resumen', 'icon' => 'bi-speedometer2'],
        'conexion' => ['label' => 'Conexion', 'icon' => 'bi-plug'],
        'proyecto' => ['label' => 'Proyecto', 'icon' => 'bi-kanban'],
        'redmine' => ['label' => 'Redmine', 'icon' => 'bi-list-check'],
        'campos' => ['label' => 'Campos', 'icon' => 'bi-ui-checks-grid'],
        'retencion' => ['label' => 'Retencion', 'icon' => 'bi-stopwatch'],
        'webhook' => ['label' => 'Webhook', 'icon' => 'bi-send'],
        'mantencion' => ['label' => 'Mantencion', 'icon' => 'bi-tools'],
        'roles' => ['label' => 'Roles y Permisos', 'icon' => 'bi-shield-check'],
        'usuarios-permisos' => ['label' => 'Usuarios y permisos', 'icon' => 'bi-person-lock'],
        'categorias' => ['label' => 'Categorias', 'icon' => 'bi-tags'],
        'unidades' => ['label' => 'Unidades', 'icon' => 'bi-building'],
    ];
    if (!array_key_exists($activePanel, $panels)) {
        $activePanel = 'resumen';
    }
    $configRoute = static fn (string $panel) => $redmineRoute('redmine.native.section', ['section' => 'configuracion', 'panel' => $panel]);
    $maintenanceSections = [
        'archivados' => 'Archivados',
        'pendientes' => 'Pendientes activos',
        'horas_extras' => 'Horas extra',
        'configuraciones' => 'Configuraciones',
    ];
    $scopePermissions = [
        'mensajes' => 'Reportes',
        'horas_extra' => 'Horas extra',
        'historico_scope' => 'Historico',
    ];
    $viewPermissions = [
        'mensajes_acceso' => 'Reportes',
        'horas_extra' => 'Horas extra',
        'historico' => 'Historico',
        'historico_acciones' => 'Acciones historico',
        'estadisticas' => 'Estadisticas',
        'estadisticas_manual' => 'Redmine API',
        'usuarios' => 'Usuarios',
        'categorias' => 'Categorias',
        'unidades' => 'Unidades',
        'simulador' => 'Webhook',
        'actividad' => 'Actividad',
        'configuracion' => 'Configuracion',
    ];
    $dataActionPermissions = [
        'reportes_editar' => 'Editar reportes',
        'reportes_eliminar' => 'Eliminar reportes',
        'horas_extra_editar' => 'Editar horas extra',
        'horas_extra_eliminar' => 'Eliminar horas extra',
        'usuarios_editar' => 'Editar usuarios',
        'usuarios_eliminar' => 'Eliminar usuarios',
    ];
    $configPermissions = [
        'cfg_resumen' => 'Resumen',
        'cfg_conexion' => 'Conexion',
        'cfg_proyecto' => 'Proyecto',
        'cfg_redmine' => 'Redmine',
        'cfg_campos' => 'Campos personalizados',
        'cfg_retencion' => 'Retencion',
        'cfg_webhook' => 'Webhook',
        'cfg_sesion' => 'Sesion',
        'cfg_mantencion' => 'Mantencion',
        'cfg_trackers' => 'Trackers',
        'cfg_prioridades' => 'Prioridades',
        'cfg_estados' => 'Estados',
        'cfg_roles' => 'Roles y Permisos',
        'cfg_usuarios' => 'Usuarios y permisos',
        'cfg_categorias' => 'Categorias',
        'cfg_unidades' => 'Unidades',
    ];
    $rolePermissionRows = [
        ['label' => 'Reportes', 'access' => 'mensajes_acceso', 'edit' => 'reportes_editar', 'delete' => 'reportes_eliminar', 'scope' => 'mensajes', 'scope_input' => 'mensajes'],
        ['label' => 'Horas extra', 'access' => 'horas_extra', 'edit' => 'horas_extra_editar', 'delete' => 'horas_extra_eliminar', 'scope' => 'horas_extra', 'scope_input' => 'horas'],
        ['label' => 'Historico', 'access' => 'historico', 'edit' => 'historico_acciones', 'delete' => null, 'scope' => 'historico_scope', 'scope_input' => 'historico'],
        ['label' => 'Estadisticas', 'access' => 'estadisticas', 'edit' => null, 'delete' => null, 'scope' => null, 'scope_input' => null],
        ['label' => 'Redmine API', 'access' => 'estadisticas_manual', 'edit' => null, 'delete' => null, 'scope' => null, 'scope_input' => null],
        ['label' => 'Usuarios', 'access' => 'usuarios', 'edit' => 'usuarios_editar', 'delete' => 'usuarios_eliminar', 'scope' => null, 'scope_input' => null],
        ['label' => 'Categorias', 'access' => 'categorias', 'edit' => null, 'delete' => null, 'scope' => null, 'scope_input' => null],
        ['label' => 'Unidades', 'access' => 'unidades', 'edit' => null, 'delete' => null, 'scope' => null, 'scope_input' => null],
        ['label' => 'Webhook', 'access' => 'simulador', 'edit' => null, 'delete' => null, 'scope' => null, 'scope_input' => null],
        ['label' => 'Actividad', 'access' => 'actividad', 'edit' => null, 'delete' => null, 'scope' => null, 'scope_input' => null],
        ['label' => 'Configuracion', 'access' => 'configuracion', 'edit' => null, 'delete' => null, 'scope' => null, 'scope_input' => null],
    ];
@endphp

<nav class="rm-config-nav mb-4" aria-label="Opciones de configuracion">
    @foreach ($panels as $key => $panel)
        <a class="rm-config-nav-link {{ $activePanel === $key ? 'active' : '' }}" href="{{ $configRoute($key) }}">
            <i class="bi {{ $panel['icon'] }}"></i>{{ $panel['label'] }}
        </a>
    @endforeach
</nav>

@if ($activePanel === 'resumen')
    @php
        $summaryWebhook = data_get($config, 'webhook_url', '') ?: ($webhookUrl ?? 'http://localhost:8000/webhook');
        $summaryMaintenance = !empty($config['maintenance_mode']);
    @endphp
    <section class="rm-config-summary">
        <div class="rm-config-summary-kpis">
            <article class="rm-summary-kpi">
                <span class="is-blue"><i class="bi bi-kanban"></i></span>
                <div><small>Proyecto</small><strong>{{ data_get($config, 'project_id', '-') ?: '-' }}</strong></div>
            </article>
            <article class="rm-summary-kpi">
                <span class="is-cyan"><i class="bi bi-tags"></i></span>
                <div><small>Categorias</small><strong>{{ count($categories) }}</strong></div>
            </article>
            <article class="rm-summary-kpi">
                <span class="is-green"><i class="bi bi-building"></i></span>
                <div><small>Unidades</small><strong>{{ count($units) }}</strong></div>
            </article>
            <article class="rm-summary-kpi">
                <span class="{{ $summaryMaintenance ? 'is-orange' : 'is-slate' }}"><i class="bi bi-tools"></i></span>
                <div><small>Mantencion</small><strong>{{ $summaryMaintenance ? 'Activa' : 'Inactiva' }}</strong></div>
            </article>
        </div>

        <div class="rm-config-summary-grid">
            <article class="card nova-card rm-panel rm-summary-card">
                <div class="rm-summary-card-head">
                    <span><i class="bi bi-hdd-network"></i></span>
                    <div>
                        <h2>Conexion Redmine</h2>
                        <p>Endpoints usados para enviar y sincronizar datos.</p>
                    </div>
                </div>
                <div class="rm-summary-list">
                    <div><span>Proyecto</span><strong>{{ data_get($config, 'project_name', '-') ?: '-' }}</strong></div>
                    <div><span>URL issues</span><strong>{{ data_get($config, 'platform_url', '-') ?: '-' }}</strong></div>
                    <div><span>URL categorias</span><strong>{{ data_get($config, 'categories_url', '-') ?: '-' }}</strong></div>
                    <div><span>URL unidades</span><strong>{{ data_get($config, 'unidades_url', '-') ?: '-' }}</strong></div>
                </div>
            </article>

            <article class="card nova-card rm-panel rm-summary-card">
                <div class="rm-summary-card-head">
                    <span><i class="bi bi-sliders"></i></span>
                    <div>
                        <h2>Operacion</h2>
                        <p>Parametros locales activos del modulo.</p>
                    </div>
                </div>
                <div class="rm-summary-operation-grid">
                    <div><span>Retencion</span><strong>{{ data_get($config, 'retencion_horas', 24) }} hora(s)</strong></div>
                    <div><span>Sesion</span><strong>NOVA global</strong></div>
                    <div><span>Roles</span><strong>{{ count($roles) }} perfil(es)</strong></div>
                    <div><span>Webhook</span><strong>{{ $summaryWebhook }}</strong></div>
                </div>
            </article>
        </div>
    </section>
@endif

@if ($activePanel === 'conexion')
    <form class="card nova-card rm-panel" method="post" action="{{ $redmineRoute('redmine.native.config.action') }}">
        @csrf
        <div class="rm-section-head"><div><h2>Conexion API</h2><p>Endpoints y token usados por NOVA para comunicarse con Redmine.</p></div></div>
        <div class="row g-3">
            @foreach (['platform_url' => 'URL issues', 'platform_token' => 'Token global', 'categories_url' => 'URL categorias', 'unidades_url' => 'URL unidades'] as $field => $label)
                <div class="col-12 {{ $field === 'platform_token' ? '' : 'col-xl-6' }}">
                    <label class="form-label">{{ $label }}</label>
                    <input class="form-control" name="{{ $field }}" value="{{ data_get($config, $field, '') }}">
                </div>
            @endforeach
            <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar conexion</button></div>
        </div>
    </form>
@endif

@if ($activePanel === 'proyecto')
    <form class="card nova-card rm-panel" method="post" action="{{ $redmineRoute('redmine.native.config.action') }}">
        @csrf
        <div class="rm-section-head"><div><h2>Proyecto</h2><p>Proyecto y parametros base para crear tickets.</p></div></div>
        <div class="row g-3">
            @foreach (['project_name' => 'Nombre proyecto', 'project_id' => 'ID proyecto', 'tracker_id' => 'Tracker por defecto', 'priority_id' => 'Prioridad por defecto', 'status_id' => 'Estado inicial'] as $field => $label)
                <div class="col-12 col-md-6 col-xl-4">
                    <label class="form-label">{{ $label }}</label>
                    <input class="form-control" name="{{ $field }}" value="{{ data_get($config, $field, '') }}">
                </div>
            @endforeach
            <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar proyecto</button></div>
        </div>
    </form>
@endif

@if ($activePanel === 'redmine')
    <section class="row g-3">
        @foreach (['trackers' => ['label' => 'Trackers', 'icon' => 'bi-diagram-3'], 'prioridades' => ['label' => 'Prioridades', 'icon' => 'bi-exclamation-triangle'], 'estados' => ['label' => 'Estados', 'icon' => 'bi-kanban']] as $field => $optionGroup)
            @php
                $label = $optionGroup['label'];
                $defaultKey = ['trackers' => 'tracker_id', 'prioridades' => 'priority_id', 'estados' => 'status_id'][$field];
            @endphp
            <div class="col-12 col-xl-4">
                <article class="card nova-card rm-panel rm-option-panel h-100">
                    <i class="bi {{ $optionGroup['icon'] }} rm-option-panel-icon" aria-hidden="true"></i>
                    <div class="rm-section-head">
                        <div>
                            <h2>{{ $label }}</h2>
                            <p>{{ count(data_get($config, $field, [])) }} opcion(es) configurada(s)</p>
                        </div>
                    </div>
                    <button class="rm-redmine-card-hit" type="button" data-bs-toggle="modal" data-bs-target="#rm-options-{{ $field }}" aria-label="Abrir {{ $label }}"></button>
                    <div class="modal fade detail-drawer-modal" id="rm-options-{{ $field }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-scrollable detail-drawer-dialog">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <div>
                                        <p class="detail-drawer-kicker">Catalogo Redmine</p>
                                        <h2 class="modal-title">
                                            <span class="detail-drawer-icon"><i class="bi {{ $optionGroup['icon'] }}"></i></span>
                                            {{ $label }}
                                        </h2>
                                    </div>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="detail-drawer-panel d-flex justify-content-end mb-3">
                                        <button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#rm-option-create-{{ $field }}">
                                            <i class="bi bi-plus-lg"></i>Agregar
                                        </button>
                                    </div>
                    <div class="rm-option-list detail-drawer-panel">
                        @forelse (data_get($config, $field, []) as $option)
                            @php
                                $modalId = 'rm-option-edit-' . $field . '-' . $loop->index;
                            @endphp
                            <article class="rm-option-card">
                                <div class="rm-option-card-main">
                                    <span class="rm-option-code">#{{ $option['id'] ?? '-' }}</span>
                                    <div class="rm-option-copy">
                                        <strong>{{ $option['nombre'] ?? '-' }}</strong>
                                        <span>{{ !empty($option['default']) ? 'Opcion predeterminada' : 'Opcion disponible' }}</span>
                                    </div>
                                </div>
                                <div class="rm-option-card-actions">
                                    <button class="btn btn-primary nova-btn-icon" type="button" data-bs-toggle="modal" data-bs-target="#{{ $modalId }}" title="Ver y editar" aria-label="Ver y editar">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <button class="btn {{ !empty($option['default']) ? 'btn-warning' : 'btn-outline-secondary' }} nova-btn-icon rm-default-option" type="button" data-default-group="{{ $field }}" data-default-value="{{ $option['id'] ?? '' }}" data-default-target="rm-default-selected-{{ $field }}" title="Marcar default" aria-label="Marcar default">
                                        <i class="bi {{ !empty($option['default']) ? 'bi-star-fill' : 'bi-star' }}"></i>
                                    </button>
                                    <form method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'redmine']) }}">
                                        @csrf
                                        <input type="hidden" name="opt_type" value="{{ $field }}">
                                        <input type="hidden" name="opt_id" value="{{ $option['id'] ?? '' }}">
                                        <button class="btn btn-danger nova-btn-icon" name="opt_action" value="delete" type="submit" title="Eliminar" aria-label="Eliminar" onclick="return confirm('Eliminar esta opcion?')"><i class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                            </article>
                        @empty
                            <div class="rm-empty-state">Sin opciones configuradas.</div>
                        @endforelse
                    </div>
                    <div class="rm-option-foot detail-drawer-panel mt-3">
                        <span>ID predeterminado</span>
                        <strong>{{ data_get($config, $defaultKey, '-') ?: '-' }}</strong>
                    </div>
                                </div>
                                <div class="modal-footer">
                                    <form class="m-0" method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'redmine']) }}">
                                        @csrf
                                        <input type="hidden" name="opt_type" value="{{ $field }}">
                                        <input type="hidden" name="opt_action" value="set_default">
                                        <input id="rm-default-selected-{{ $field }}" type="hidden" name="opt_id" value="{{ data_get($config, $defaultKey, '') }}">
                                        <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar cambios</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    @foreach (data_get($config, $field, []) as $option)
                        @php
                            $modalId = 'rm-option-edit-' . $field . '-' . $loop->index;
                        @endphp
                        <div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <form class="modal-content" method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'redmine']) }}">
                                    @csrf
                                    <input type="hidden" name="opt_type" value="{{ $field }}">
                                    <input type="hidden" name="opt_action" value="update">
                                    <div class="modal-header">
                                        <h2 class="modal-title fs-5">Editar {{ strtolower($label) }}</h2>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="row g-3">
                                            <div class="col-12 col-md-4">
                                                <label class="form-label">ID</label>
                                                <input class="form-control" name="opt_id" value="{{ $option['id'] ?? '' }}" readonly>
                                            </div>
                                            <div class="col-12 col-md-8">
                                                <label class="form-label">Nombre</label>
                                                <input class="form-control" name="opt_nombre" value="{{ $option['nombre'] ?? '' }}" required>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-check rm-modal-check">
                                                    <input class="form-check-input" type="checkbox" name="opt_default" value="1" @checked(!empty($option['default']))>
                                                    <span class="form-check-label">Usar como opcion predeterminada</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                                        <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar cambios</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    @endforeach
                    <div class="modal fade" id="rm-option-create-{{ $field }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <form class="modal-content" method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'redmine']) }}">
                                @csrf
                                <input type="hidden" name="opt_type" value="{{ $field }}">
                                <input type="hidden" name="opt_action" value="create">
                                <div class="modal-header">
                                    <h2 class="modal-title fs-5">Agregar {{ strtolower($label) }}</h2>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row g-3">
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">ID</label>
                                            <input class="form-control" name="opt_id" required>
                                        </div>
                                        <div class="col-12 col-md-8">
                                            <label class="form-label">Nombre</label>
                                            <input class="form-control" name="opt_nombre" required>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-check rm-modal-check">
                                                <input class="form-check-input" type="checkbox" name="opt_default" value="1">
                                                <span class="form-check-label">Usar como opcion predeterminada</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
                                    <button class="btn btn-success" type="submit"><i class="bi bi-plus-lg"></i>Agregar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </article>
            </div>
        @endforeach
    </section>
@endif

@if ($activePanel === 'campos')
    <form class="card nova-card rm-panel" method="post" action="{{ $redmineRoute('redmine.native.config.action') }}">
        @csrf
        <div class="rm-section-head"><div><h2>Campos personalizados</h2><p>IDs de campos Redmine usados al crear tickets.</p></div></div>
        <div class="row g-3">
            @foreach (['cf_solicitante' => 'Solicitante', 'cf_unidad' => 'Unidad', 'cf_unidad_solicitante' => 'Unidad solicitante', 'cf_hora_extra' => 'Hora extra'] as $field => $label)
                <div class="col-12 col-md-6 col-xl-3">
                    <label class="form-label">{{ $label }}</label>
                    <input class="form-control" name="{{ $field }}" value="{{ data_get($config, $field, '') }}">
                </div>
            @endforeach
            <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar campos</button></div>
        </div>
    </form>
@endif

@if ($activePanel === 'retencion')
    <form class="card nova-card rm-panel" method="post" action="{{ $redmineRoute('redmine.native.config.action') }}">
        @csrf
        <div class="rm-section-head"><div><h2>Retencion</h2><p>Controla el archivado automatico de reportes procesados. La sesion la administra NOVA.</p></div></div>
        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label class="form-label">Horas antes de archivar procesados</label>
                <input class="form-control" type="number" min="1" name="retencion_horas" value="{{ data_get($config, 'retencion_horas', 24) }}">
            </div>
            <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar retencion</button></div>
        </div>
    </form>
@endif

@if ($activePanel === 'webhook')
    @php
        $webhookValue = old('webhook_url', data_get($config, 'webhook_url', $webhookUrl ?? 'http://localhost:8000/webhook'));
        $testedWebhookUrl = (string) session('redmine_webhook_tested_url', '');
        $webhookCanSave = $testedWebhookUrl !== '' && hash_equals($testedWebhookUrl, (string) $webhookValue);
        $webhookTestResult = session('redmine_webhook_test_result');
    @endphp
    <form class="card nova-card rm-panel" method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'webhook']) }}" data-webhook-config-form>
        @csrf
        <div class="rm-section-head"><div><h2>Webhook</h2><p>Endpoint usado para enviar mensajes al servicio Python.</p></div></div>
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="config-webhook-url">URL del webhook</label>
                <div class="rm-webhook-test-row">
                    <input class="form-control" id="config-webhook-url" name="webhook_url" value="{{ $webhookValue }}" placeholder="http://localhost:8000/webhook" required data-webhook-url-input data-tested-url="{{ $testedWebhookUrl }}">
                    <button class="btn btn-outline-primary" type="submit" name="config_action" value="test_webhook" data-webhook-test-button><i class="bi bi-plug"></i>Probar conexion</button>
                </div>
                <div class="form-text fw-semibold">Esta URL se usa como valor predeterminado en la vista Webhook.</div>
            </div>
            @if (is_array($webhookTestResult))
                <div class="col-12">
                    <div class="alert {{ !empty($webhookTestResult['ok']) ? 'alert-success' : 'alert-danger' }} small fw-semibold mb-0">
                        HTTP {{ $webhookTestResult['http_code'] ?? 0 }} - {{ !empty($webhookTestResult['ok']) ? 'Conexion validada.' : ($webhookTestResult['error'] ?? 'Conexion no validada.') }}
                    </div>
                </div>
            @endif
            <div class="col-12">
                <button class="btn btn-primary" type="submit" name="config_action" value="save_webhook" data-webhook-save-button @disabled(!$webhookCanSave)><i class="bi bi-save"></i>Guardar webhook</button>
                <span class="ms-2 text-muted fw-semibold" data-webhook-save-hint>{{ $webhookCanSave ? 'Conexion probada.' : '' }}</span>
            </div>
        </div>
    </form>

    <div class="modal fade" id="webhook-test-loading-modal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rm-webhook-test-modal">
                <div class="modal-body">
                    <div class="rm-webhook-data-animation" aria-hidden="true">
                        <div class="rm-webhook-node is-client"><i class="bi bi-pc-display"></i></div>
                        <div class="rm-webhook-flow">
                            <span class="rm-webhook-packet is-send"></span>
                            <span class="rm-webhook-packet is-send is-delay"></span>
                            <span class="rm-webhook-packet is-receive"></span>
                            <span class="rm-webhook-packet is-receive is-delay"></span>
                        </div>
                        <div class="rm-webhook-node is-server"><i class="bi bi-hdd-network"></i></div>
                    </div>
                    <strong>Probando conexion webhook</strong>
                    <div class="rm-webhook-test-steps">
                        <span><i class="bi bi-upload"></i>Enviando datos</span>
                        <span><i class="bi bi-download"></i>Recibiendo datos</span>
                    </div>
                    <div class="rm-redmine-send-bar"><i></i></div>
                </div>
            </div>
        </div>
    </div>
@endif

@if ($activePanel === 'mantencion')
    <section class="row g-3">
        <div class="col-12 col-xl-5">
            <form class="card nova-card rm-panel h-100" method="post" action="{{ $redmineRoute('redmine.native.config.action') }}" data-maintenance-allowed="1">
                @csrf
                <div class="rm-section-head"><div><h2>Mantencion</h2><p>Senala si el modulo debe operar como no disponible temporalmente.</p></div></div>
                <div class="row g-3">
                    <div class="col-12">
                        <input type="hidden" name="maintenance_mode" value="0">
                        <label class="rm-maintenance-switch">
                            <span>
                                <strong>Modo mantenimiento</strong>
                                <small>{{ !empty($config['maintenance_mode']) ? 'Activo: la edicion esta bloqueada.' : 'Inactivo: el modulo opera normalmente.' }}</small>
                            </span>
                            <span class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="maintenance_mode" value="1" @checked(!empty($config['maintenance_mode'])) aria-label="Activar modo mantenimiento" data-maintenance-mode-switch>
                            </span>
                        </label>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Mantencion hasta</label>
                        <input class="form-control" type="datetime-local" name="maintenance_until" min="{{ now('America/Santiago')->format('Y-m-d\TH:i') }}" value="{{ old('maintenance_until', data_get($config, 'maintenance_until', '')) }}" data-maintenance-until>
                    </div>
                    <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar mantencion</button></div>
                </div>
            </form>
        </div>
        <div class="col-12 col-xl-7">
            <article class="card nova-card rm-panel h-100">
                <div class="rm-section-head"><div><h2>Exportar e importar</h2><p>Respalda o restaura datos del proyecto Redmine dentro de NOVA.</p></div></div>
                <div class="alert alert-warning small mb-3">La importacion puede sobrescribir configuraciones y fusionar archivados u horas extra. Exporta un respaldo antes de importar.</div>
                <div class="row g-3">
                    <div class="col-12 col-lg-6">
                        <form class="rm-maintenance-box h-100" method="post" action="{{ $redmineRoute('redmine.native.config.export') }}" data-maintenance-allowed="1">
                            @csrf
                            <h3><i class="bi bi-download"></i> Exportar</h3>
                            <p>Descarga un respaldo JSON con las secciones seleccionadas.</p>
                            @foreach ($maintenanceSections as $key => $label)
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="maintenance_sections[]" value="{{ $key }}" checked>
                                    <span class="form-check-label">{{ $label }}</span>
                                </label>
                            @endforeach
                            <button class="btn btn-primary w-100 mt-3" type="submit"><i class="bi bi-download"></i>Exportar respaldo</button>
                        </form>
                    </div>
                    <div class="col-12 col-lg-6">
                        <form class="rm-maintenance-box h-100" method="post" action="{{ $redmineRoute('redmine.native.config.import') }}" enctype="multipart/form-data">
                            @csrf
                            <h3><i class="bi bi-upload"></i> Importar</h3>
                            <p>Sube un respaldo JSON generado desde esta misma pantalla.</p>
                            <input class="form-control mb-3" type="file" name="maintenance_file" accept="application/json,.json" required>
                            @foreach ($maintenanceSections as $key => $label)
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="maintenance_sections[]" value="{{ $key }}" checked>
                                    <span class="form-check-label">{{ $label }}</span>
                                </label>
                            @endforeach
                            <button class="btn btn-danger w-100 mt-3" type="submit" onclick="return confirm('La importacion modificara los datos seleccionados. Deseas continuar?')"><i class="bi bi-upload"></i>Importar respaldo</button>
                        </form>
                    </div>
                </div>
            </article>
        </div>
    </section>
@endif

@if ($activePanel === 'roles')
    @php
        $roleNames = array_keys($roles);
        $selectedRole = request('role', $roleNames[0] ?? 'usuario');
        if (!array_key_exists($selectedRole, $roles) && $roleNames !== []) {
            $selectedRole = $roleNames[0];
        }
        $selectedPermissions = is_array($roles[$selectedRole] ?? null) ? $roles[$selectedRole] : [];
        $roleFormAction = $redmineRoute('redmine.native.config.action', ['panel' => 'roles', 'role' => $selectedRole]);
        $selectedRoleHasUsers = collect($users ?? [])->contains(fn ($user) => (string) ($user['rol'] ?? '') === (string) $selectedRole);
        $protectedRoles = ['root', 'administrador', 'gestor', 'usuario'];
        $selectedRoleCanDelete = $selectedRole !== '' && !$selectedRoleHasUsers && !in_array($selectedRole, $protectedRoles, true);
    @endphp
    <section class="card nova-card rm-panel">
        <div class="rm-role-toolbar">
            <form method="get" action="{{ $redmineRoute('redmine.native.section', 'configuracion') }}" class="rm-role-select-form">
                <input type="hidden" name="panel" value="roles">
                <label class="form-label" for="rm-role-select">Rol seleccionado</label>
                <div class="rm-role-select-row">
                    <select class="form-select" id="rm-role-select" name="role" onchange="this.form.submit()">
                        @foreach ($roleNames as $roleName)
                            <option value="{{ $roleName }}" @selected($selectedRole === $roleName)>{{ $roleName }}</option>
                        @endforeach
                    </select>
                    <div class="rm-role-summary">
                        <strong>{{ collect($selectedPermissions)->filter(fn ($value, $key) => is_string($key) && !in_array($key, ['mensajes', 'horas_extra', 'historico_scope'], true) && $value === true)->count() }}</strong>
                        <span>activos</span>
                    </div>
                </div>
            </form>
            @if ($selectedRoleCanDelete)
                <form class="rm-delete-role-form" method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'roles']) }}">
                    @csrf
                    <input type="hidden" name="config_action" value="delete_role">
                    <input type="hidden" name="role_name" value="{{ $selectedRole }}">
                    <button class="btn btn-danger nova-btn-icon" type="submit" title="Eliminar rol" aria-label="Eliminar rol" onclick="return confirm('Eliminar este rol?')">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
            @endif
            <form class="rm-create-role-form" method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'roles']) }}">
                @csrf
                <input type="hidden" name="config_action" value="save_role_permissions">
                <label class="form-label" for="rm-new-role">Crear rol</label>
                <div class="input-group">
                    <input class="form-control" id="rm-new-role" name="role_name" placeholder="Nombre del rol">
                    <button class="btn btn-success" type="submit"><i class="bi bi-plus-lg"></i>Crear</button>
                </div>
            </form>
        </div>

        <form method="post" action="{{ $roleFormAction }}">
            @csrf
            <input type="hidden" name="config_action" value="save_role_permissions">
            <input type="hidden" name="role_name" value="{{ $selectedRole }}">
            <div class="rm-section-head mt-3">
                <div><h2>Roles y Permisos</h2><p>Activa vistas y define si el rol puede editar o eliminar datos.</p></div>
                <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar permisos</button>
            </div>
            <div class="rm-scope-panel">
                <h3 class="rm-permission-title">Alcance</h3>
                <div class="rm-scope-grid">
                    @foreach ($rolePermissionRows as $permissionRow)
                        @if ($permissionRow['scope'])
                            <label class="rm-scope-card">
                                <span>{{ $permissionRow['label'] }}</span>
                                <select class="form-select" name="perm_{{ $permissionRow['scope_input'] }}_scope">
                                    <option value="todos" @selected(($selectedPermissions[$permissionRow['scope']] ?? 'asignados') === 'todos')>Todos</option>
                                    <option value="asignados" @selected(($selectedPermissions[$permissionRow['scope']] ?? 'asignados') !== 'todos')>Asignados</option>
                                </select>
                            </label>
                        @endif
                    @endforeach
                </div>
            </div>
            <div class="rm-role-permission-list">
                @foreach ($rolePermissionRows as $permissionRow)
                    <section class="rm-role-permission-item">
                        <div class="rm-role-permission-main">
                            <strong>{{ $permissionRow['label'] }}</strong>
                            <label class="rm-toggle-line">
                                <span>Ver</span>
                            <input class="rm-switch" type="checkbox" name="perm_{{ $permissionRow['access'] }}" value="1" data-role-access-toggle @if ($permissionRow['access'] === 'configuracion') data-config-access-toggle @endif @checked(!empty($selectedPermissions[$permissionRow['access']] ))>
                            </label>
                        </div>

                        @if ($permissionRow['edit'] || $permissionRow['delete'])
                            <div class="rm-role-permission-children" data-role-dependent-actions>
                                @if ($permissionRow['edit'])
                                    <label class="rm-toggle-line rm-role-permission-child">
                                        <span>Editar</span>
                                        <input class="rm-switch" type="checkbox" name="perm_{{ $permissionRow['edit'] }}" value="1" @checked(!empty($selectedPermissions[$permissionRow['edit']]))>
                                    </label>
                                @endif
                                @if ($permissionRow['delete'])
                                    <label class="rm-toggle-line rm-role-permission-child">
                                        <span>Eliminar</span>
                                        <input class="rm-switch" type="checkbox" name="perm_{{ $permissionRow['delete'] }}" value="1" @checked(!empty($selectedPermissions[$permissionRow['delete']]))>
                                    </label>
                                @endif
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>
            <div class="rm-config-permission-panel" data-config-dependent-panel>
                <h3 class="rm-permission-title">Configuracion y subcategorias</h3>
                <div class="rm-permission-grid">
                    @foreach ($configPermissions as $permissionKey => $permissionLabel)
                        <label class="form-check rm-modal-check">
                            <input class="form-check-input" type="checkbox" name="perm_{{ $permissionKey }}" value="1" @checked(!empty($selectedPermissions[$permissionKey]))>
                            <span class="form-check-label">{{ $permissionLabel }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </form>
    </section>
@endif

@if ($activePanel === 'usuarios-permisos')
    @php
        $userOptions = collect($users ?? [])
            ->filter(fn ($user) => strtolower(trim((string) ($user['estado_usuario'] ?? $user['estado'] ?? 'activo'))) === 'activo')
            ->sortBy(fn ($user) => trim(($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')) ?: ($user['id'] ?? ''))
            ->values();
        $firstUser = $userOptions->first();
        $selectedUserId = (string) request('user_id', (string) ($firstUser['id'] ?? ''));
        $selectedUser = $userOptions->first(fn ($user) => (string) ($user['id'] ?? '') === $selectedUserId);
        $selectedUserRole = (string) ($selectedUser['rol'] ?? 'usuario');
        $selectedUserPermissions = is_array($selectedUser['permisos'] ?? null)
            ? $selectedUser['permisos']
            : (is_array($roles[$selectedUserRole] ?? null) ? $roles[$selectedUserRole] : []);
        $selectedUserActiveCount = collect($selectedUserPermissions)->filter(fn ($value, $key) => is_string($key) && !in_array($key, ['mensajes', 'horas_extra', 'historico_scope'], true) && $value === true)->count();
        $selectedUserName = $selectedUser
            ? (trim(($selectedUser['nombre'] ?? '') . ' ' . ($selectedUser['apellido'] ?? '')) ?: ($selectedUser['id'] ?? 'Usuario'))
            : '';
        $userPermissionFormAction = $redmineRoute('redmine.native.config.action', ['panel' => 'usuarios-permisos', 'user_id' => $selectedUserId]);
    @endphp
    <section class="card nova-card rm-panel">
        @if ($selectedUser)
            <div class="rm-role-toolbar rm-user-permission-toolbar">
                <form method="get" action="{{ $redmineRoute('redmine.native.section', 'configuracion') }}" class="rm-role-select-form">
                    <input type="hidden" name="panel" value="usuarios-permisos">
                    <label class="form-label" for="rm-user-permission-select">Usuario activo</label>
                    <div class="rm-role-select-row">
                        <select class="form-select" id="rm-user-permission-select" name="user_id" onchange="this.form.submit()">
                            @foreach ($userOptions as $userOption)
                                @php
                                    $userOptionId = (string) ($userOption['id'] ?? '');
                                    $userOptionName = trim(($userOption['nombre'] ?? '') . ' ' . ($userOption['apellido'] ?? '')) ?: $userOptionId;
                                @endphp
                                <option value="{{ $userOptionId }}" @selected($selectedUserId === $userOptionId)>{{ $userOptionName }}</option>
                            @endforeach
                        </select>
                        <div class="rm-role-summary">
                            <strong data-user-active-count>{{ $selectedUserActiveCount }}</strong>
                            <span>activos</span>
                        </div>
                    </div>
                </form>
                <div class="rm-user-permission-meta">
                    <span><i class="bi bi-person-badge"></i> ID {{ $selectedUser['id'] ?? '-' }}</span>
                    <span><i class="bi bi-shield-check"></i> Rol actual: {{ $selectedUserRole }}</span>
                    <span><i class="bi bi-circle-fill"></i> Activo</span>
                </div>
            </div>

            <form method="post" action="{{ $userPermissionFormAction }}" data-user-permission-form>
                @csrf
                <input type="hidden" name="config_action" value="save_user_permissions">
                <input type="hidden" name="user_id" value="{{ $selectedUserId }}">
                <div class="rm-section-head mt-3">
                    <div>
                        <h2>Usuarios y permisos</h2>
                        <p>{{ $selectedUserName }}. Permisos personalizados para este usuario activo.</p>
                    </div>
                    <div class="rm-user-permission-actions">
                        <label class="rm-inline-field">
                            <span>Rol asignado</span>
                            <select class="form-select" name="user_role" aria-label="Rol asignado" data-user-role-select data-current-user-role="{{ $selectedUserRole }}">
                                @foreach (array_unique(array_merge(array_keys($roles), [$selectedUserRole])) as $roleOption)
                                    <option value="{{ $roleOption }}" @selected($selectedUserRole === $roleOption)>{{ $roleOption }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button class="btn btn-outline-primary" type="button" data-load-role-permissions>
                            <i class="bi bi-arrow-repeat"></i>Cargar permisos del rol
                        </button>
                        <button class="btn btn-outline-secondary" type="button" data-reset-user-permissions>
                            <i class="bi bi-eraser"></i>Limpiar
                        </button>
                        <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i>Guardar permisos</button>
                    </div>
                </div>
                <div class="rm-scope-panel">
                    <h3 class="rm-permission-title">Alcance</h3>
                    <div class="rm-scope-grid">
                        @foreach ($rolePermissionRows as $permissionRow)
                            @if ($permissionRow['scope'])
                                <label class="rm-scope-card">
                                    <span>{{ $permissionRow['label'] }}</span>
                                    <select class="form-select" name="perm_{{ $permissionRow['scope_input'] }}_scope">
                                        <option value="todos" @selected(($selectedUserPermissions[$permissionRow['scope']] ?? 'asignados') === 'todos')>Todos</option>
                                        <option value="asignados" @selected(($selectedUserPermissions[$permissionRow['scope']] ?? 'asignados') !== 'todos')>Asignados</option>
                                    </select>
                                </label>
                            @endif
                        @endforeach
                    </div>
                </div>
                <div class="rm-role-permission-list">
                    @foreach ($rolePermissionRows as $permissionRow)
                        <section class="rm-role-permission-item">
                            <div class="rm-role-permission-main">
                                <strong>{{ $permissionRow['label'] }}</strong>
                                <label class="rm-toggle-line">
                                    <span>Ver</span>
                                    <input class="rm-switch" type="checkbox" name="perm_{{ $permissionRow['access'] }}" value="1" data-role-access-toggle @if ($permissionRow['access'] === 'configuracion') data-config-access-toggle @endif @checked(!empty($selectedUserPermissions[$permissionRow['access']] ))>
                                </label>
                            </div>

                            @if ($permissionRow['edit'] || $permissionRow['delete'])
                                <div class="rm-role-permission-children" data-role-dependent-actions>
                                    @if ($permissionRow['edit'])
                                        <label class="rm-toggle-line rm-role-permission-child">
                                            <span>Editar</span>
                                            <input class="rm-switch" type="checkbox" name="perm_{{ $permissionRow['edit'] }}" value="1" @checked(!empty($selectedUserPermissions[$permissionRow['edit']]))>
                                        </label>
                                    @endif
                                    @if ($permissionRow['delete'])
                                        <label class="rm-toggle-line rm-role-permission-child">
                                            <span>Eliminar</span>
                                            <input class="rm-switch" type="checkbox" name="perm_{{ $permissionRow['delete'] }}" value="1" @checked(!empty($selectedUserPermissions[$permissionRow['delete']]))>
                                        </label>
                                    @endif
                                </div>
                            @endif
                        </section>
                    @endforeach
                </div>
                <div class="rm-config-permission-panel" data-config-dependent-panel>
                    <h3 class="rm-permission-title">Configuracion y subcategorias</h3>
                    <div class="rm-permission-grid">
                        @foreach ($configPermissions as $permissionKey => $permissionLabel)
                            <label class="form-check rm-modal-check">
                                <input class="form-check-input" type="checkbox" name="perm_{{ $permissionKey }}" value="1" @checked(!empty($selectedUserPermissions[$permissionKey]))>
                                <span class="form-check-label">{{ $permissionLabel }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
            </form>
        @else
            <div class="rm-empty-state">Sin usuarios activos registrados.</div>
        @endif
    </section>
@endif

@if ($activePanel === 'categorias')
    @include('redmine_tic::native-sections.categories', ['categories' => $categories ?? []])
@endif

@if ($activePanel === 'unidades')
    @include('redmine_tic::native-sections.units', ['units' => $units ?? []])
@endif

@if ($activePanel === 'redmine')
    <script>
        document.querySelectorAll('.rm-default-option').forEach((button) => {
            button.addEventListener('click', () => {
                const target = document.getElementById(button.dataset.defaultTarget || '');
                if (target) target.value = button.dataset.defaultValue || '';

                document.querySelectorAll(`.rm-default-option[data-default-group="${button.dataset.defaultGroup}"]`).forEach((item) => {
                    item.classList.remove('btn-warning');
                    item.classList.add('btn-outline-secondary');
                    const icon = item.querySelector('i');
                    if (icon) {
                        icon.classList.remove('bi-star-fill');
                        icon.classList.add('bi-star');
                    }
                });

                button.classList.remove('btn-outline-secondary');
                button.classList.add('btn-warning');
                const icon = button.querySelector('i');
                if (icon) {
                    icon.classList.remove('bi-star');
                    icon.classList.add('bi-star-fill');
                }
            });
        });

        const openOptionsModal = @json(session('redmine_open_options'));
        if (openOptionsModal) {
            const modal = document.getElementById(`rm-options-${openOptionsModal}`);
            if (modal) {
                if (window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(modal).show();
                } else {
                    modal.classList.add('show');
                    modal.removeAttribute('aria-hidden');
                    modal.setAttribute('aria-modal', 'true');
                    modal.style.display = 'block';
                    document.body.classList.add('modal-open');
                }
            }
        }
    </script>
@endif

@if (in_array($activePanel, ['categorias', 'unidades'], true))
    <script>
        document.querySelectorAll('[data-catalog-search]').forEach((input) => {
            const panel = input.closest('.rm-catalog-panel');
            const items = Array.from(panel?.querySelectorAll('[data-catalog-item]') || []);
            const empty = panel?.querySelector('[data-catalog-empty]');

            input.addEventListener('input', () => {
                const term = input.value.trim().toLowerCase();
                let visible = 0;
                items.forEach((item) => {
                    const matches = !term || (item.dataset.catalogText || '').includes(term);
                    item.hidden = !matches;
                    if (matches) visible += 1;
                });
                if (empty) empty.hidden = visible > 0;
            });
        });
    </script>
@endif

@if ($activePanel === 'usuarios-permisos')
    <script>
        const rolePermissionPayloads = @json($roles);
        const currentUserPermissionPayload = @json($selectedUserPermissions);
        const rolePermissionScopes = [
            { input: 'perm_mensajes_scope', key: 'mensajes' },
            { input: 'perm_horas_scope', key: 'horas_extra' },
            { input: 'perm_historico_scope', key: 'historico_scope' },
        ];
        const ignoredActivePermissionKeys = ['mensajes', 'horas_extra', 'historico_scope'];

        const applyPermissionPayload = (permissions) => {
            const form = document.querySelector('[data-user-permission-form]');
            if (!form || !permissions) return;

            form.querySelectorAll('input[name^="perm_"][type="checkbox"]').forEach((input) => {
                const permissionKey = input.name.replace(/^perm_/, '');
                input.checked = permissions[permissionKey] === true || (permissionKey === 'horas_extra' && Boolean(permissions[permissionKey]));
            });

            rolePermissionScopes.forEach(({ input, key }) => {
                const select = form.querySelector(`[name="${input}"]`);
                if (!select) return;
                select.value = permissions[key] === 'todos' ? 'todos' : 'asignados';
            });

            const count = Object.entries(permissions).filter(([key, value]) => {
                return !ignoredActivePermissionKeys.includes(key) && value === true;
            }).length;
            const countTarget = document.querySelector('[data-user-active-count]');
            if (countTarget) countTarget.textContent = String(count);

            document.querySelectorAll('.rm-role-permission-item').forEach((item) => {
                syncRoleDependentActions(item);
            });
            syncConfigDependentPanel();
        };

        document.querySelector('[data-load-role-permissions]')?.addEventListener('click', () => {
            const roleSelect = document.querySelector('[data-user-role-select]');
            const roleName = roleSelect?.value || '';
            if (rolePermissionPayloads[roleName]) {
                applyPermissionPayload(rolePermissionPayloads[roleName]);
            }
        });

        document.querySelector('[data-reset-user-permissions]')?.addEventListener('click', () => {
            const roleSelect = document.querySelector('[data-user-role-select]');
            if (roleSelect) {
                roleSelect.value = roleSelect.dataset.currentUserRole || roleSelect.value;
            }
            applyPermissionPayload(currentUserPermissionPayload);
        });

        const openUserPermissions = @json(session('redmine_open_user_permissions'));
        if (openUserPermissions) {
            const trigger = Array.from(document.querySelectorAll('[data-user-permissions-id]')).find((button) => button.dataset.userPermissionsId === openUserPermissions);
            const modal = trigger ? document.querySelector(trigger.dataset.bsTarget || '') : null;
            if (modal) {
                if (window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(modal).show();
                } else {
                    modal.classList.add('show');
                    modal.removeAttribute('aria-hidden');
                    modal.setAttribute('aria-modal', 'true');
                    modal.style.display = 'block';
                    document.body.classList.add('modal-open');
                }
            }
        }
    </script>
@endif

@if (in_array($activePanel, ['roles', 'usuarios-permisos'], true))
    <script>
        const syncRoleDependentActions = (item) => {
            const access = item.querySelector('[data-role-access-toggle]');
            const dependents = item.querySelector('[data-role-dependent-actions]');
            if (!access || !dependents) return;
            dependents.hidden = !access.checked;
            dependents.querySelectorAll('input, select, button').forEach((control) => {
                control.disabled = !access.checked;
            });
        };

        document.querySelectorAll('.rm-role-permission-item').forEach((item) => {
            syncRoleDependentActions(item);
            item.querySelector('[data-role-access-toggle]')?.addEventListener('change', () => syncRoleDependentActions(item));
        });

        const syncConfigDependentPanel = () => {
            const access = document.querySelector('[data-config-access-toggle]');
            const panel = document.querySelector('[data-config-dependent-panel]');
            if (!access || !panel) return;
            panel.hidden = !access.checked;
            panel.querySelectorAll('input, select, button').forEach((control) => {
                control.disabled = !access.checked;
            });
        };
        syncConfigDependentPanel();
        document.querySelector('[data-config-access-toggle]')?.addEventListener('change', syncConfigDependentPanel);

        document.querySelectorAll('[data-nova-modal-close]').forEach((button) => {
            button.addEventListener('click', () => {
                const modal = button.closest('.modal');
                if (!modal) return;
                if (window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(modal).hide();
                    return;
                }
                modal.classList.remove('show');
                modal.setAttribute('aria-hidden', 'true');
                modal.removeAttribute('aria-modal');
                modal.style.display = 'none';
                document.body.classList.remove('modal-open');
            });
        });
    </script>
@endif

@if ($activePanel === 'mantencion')
    <script>
        const maintenanceSwitch = document.querySelector('[data-maintenance-mode-switch]');
        const maintenanceUntil = document.querySelector('[data-maintenance-until]');
        const syncMaintenanceUntil = () => {
            if (!maintenanceSwitch || !maintenanceUntil) return;
            maintenanceUntil.disabled = !maintenanceSwitch.checked;
            maintenanceUntil.required = maintenanceSwitch.checked;
            if (!maintenanceSwitch.checked) {
                maintenanceUntil.value = '';
            }
        };
        maintenanceSwitch?.addEventListener('change', syncMaintenanceUntil);
        syncMaintenanceUntil();
    </script>
@endif

@if ($activePanel === 'webhook')
    <script>
        const webhookUrlInput = document.querySelector('[data-webhook-url-input]');
        const webhookSaveButton = document.querySelector('[data-webhook-save-button]');
        const webhookSaveHint = document.querySelector('[data-webhook-save-hint]');
        const webhookForm = document.querySelector('[data-webhook-config-form]');
        const webhookTestButton = document.querySelector('[data-webhook-test-button]');
        const syncWebhookSaveState = () => {
            if (!webhookUrlInput || !webhookSaveButton) return;
            const canSave = webhookUrlInput.value.trim() !== '' && webhookUrlInput.value.trim() === (webhookUrlInput.dataset.testedUrl || '').trim();
            webhookSaveButton.disabled = !canSave;
            if (webhookSaveHint) {
                webhookSaveHint.textContent = canSave ? 'Conexion probada.' : '';
            }
        };
        webhookUrlInput?.addEventListener('input', syncWebhookSaveState);
        syncWebhookSaveState();

        webhookForm?.addEventListener('submit', (event) => {
            const submitter = event.submitter;
            if (!submitter || submitter !== webhookTestButton) return;

            event.preventDefault();

            let actionInput = webhookForm.querySelector('[data-webhook-config-action]');
            if (!actionInput) {
                actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'config_action';
                actionInput.setAttribute('data-webhook-config-action', '1');
                webhookForm.appendChild(actionInput);
            }

            actionInput.value = 'test_webhook';
            webhookTestButton.disabled = true;

            const modalElement = document.getElementById('webhook-test-loading-modal');
            if (modalElement && window.bootstrap?.Modal) {
                window.bootstrap.Modal.getOrCreateInstance(modalElement).show();
            }

            window.setTimeout(() => {
                webhookForm.submit();
            }, 3000);
        });
    </script>
@endif

<style>
    .rm-config-nav { display: flex; gap: 8px; flex-wrap: wrap; }
    .rm-config-nav-link { display: inline-flex; align-items: center; gap: 8px; min-height: 40px; padding: 8px 12px; border: 1px solid var(--nova-line); border-radius: 10px; background: #fff; color: var(--nova-text); font-weight: 800; text-decoration: none; }
    .rm-config-nav-link.active { background: var(--nova-primary); border-color: var(--nova-primary); color: #fff; }
    .rm-panel { border: 1px solid #d8e3f4; border-radius: 16px; background: linear-gradient(180deg, #fff 0%, #fbfdff 100%); box-shadow: 0 14px 30px rgba(15, 23, 42, .055); }
    .rm-panel > .rm-section-head { align-items: center; margin: -2px 0 16px; padding: 0 0 14px; border-bottom: 1px solid var(--nova-line); }
    .rm-panel > .rm-section-head h2 { color: #0f172a; font-size: 1.08rem; font-weight: 900; }
    .rm-panel > .rm-section-head p { color: var(--nova-muted); font-weight: 700; }
    .rm-panel > .row.g-3,
    .rm-panel form > .row.g-3 { padding: 2px; }
    .rm-panel .form-label { margin-bottom: 6px; color: #334155; font-size: .86rem; font-weight: 900; }
    .rm-panel .form-control,
    .rm-panel .form-select { min-height: 42px; border-color: #d8e3f4; border-radius: 12px; background-color: #fff; font-weight: 700; box-shadow: 0 8px 18px rgba(15, 23, 42, .025); }
    .rm-panel .form-control:focus,
    .rm-panel .form-select:focus { border-color: var(--nova-primary); box-shadow: var(--nova-focus); }
    .rm-panel .form-text { color: var(--nova-muted); }
    .rm-panel .alert { border: 0; border-radius: 13px; padding: 13px 16px; }
    .rm-panel .btn { min-height: 40px; border-radius: 11px; font-weight: 900; }
    .rm-panel > .row.g-3 > .col-12:last-child .btn[type="submit"],
    .rm-panel > .row.g-3 > .col-12:last-child > .btn-primary,
    .rm-panel form > .row.g-3 > .col-12:last-child .btn[type="submit"] { min-width: 190px; }
    .modal-content { border: 0; border-radius: 18px; box-shadow: 0 28px 70px rgba(15, 23, 42, .28); overflow: hidden; }
    .modal-header { background: linear-gradient(180deg, #f8fbff 0%, #eef6ff 100%); border-bottom: 1px solid #d8e3f4; }
    .modal-title { color: #0f172a; font-weight: 900; }
    .modal-body { background: #fff; }
    .modal-footer { background: #f8fbff; border-top: 1px solid #d8e3f4; }
    .rm-config-summary { display: grid; gap: 16px; }
    .rm-config-summary-kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
    .rm-summary-kpi { display: flex; align-items: center; gap: 12px; min-height: 92px; padding: 14px; border: 1px solid #d8e3f4; border-radius: 14px; background: #fff; box-shadow: 0 12px 26px rgba(15, 23, 42, .05); }
    .rm-summary-kpi > span { display: grid; width: 46px; height: 46px; place-items: center; flex: 0 0 auto; border-radius: 14px; color: #fff; font-size: 1.25rem; }
    .rm-summary-kpi > span.is-blue { background: linear-gradient(135deg, #2563eb, #4f86f7); }
    .rm-summary-kpi > span.is-cyan { background: linear-gradient(135deg, #0ea5e9, #14b8a6); }
    .rm-summary-kpi > span.is-green { background: linear-gradient(135deg, #10b981, #22c55e); }
    .rm-summary-kpi > span.is-orange { background: linear-gradient(135deg, #f97316, #f59e0b); }
    .rm-summary-kpi > span.is-slate { background: linear-gradient(135deg, #64748b, #94a3b8); }
    .rm-summary-kpi small { display: block; color: var(--nova-muted); font-size: .8rem; font-weight: 900; }
    .rm-summary-kpi strong { display: block; color: #0f172a; font-size: 1.45rem; line-height: 1.1; font-weight: 900; overflow-wrap: anywhere; }
    .rm-config-summary-grid { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, .85fr); gap: 16px; align-items: stretch; }
    .rm-summary-card { min-width: 0; }
    .rm-summary-card-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; padding-bottom: 14px; border-bottom: 1px solid var(--nova-line); }
    .rm-summary-card-head > span { display: grid; width: 44px; height: 44px; place-items: center; flex: 0 0 auto; border-radius: 14px; background: #eef6ff; color: var(--nova-primary); border: 1px solid #d4e4f7; font-size: 1.2rem; }
    .rm-summary-card-head h2 { margin: 0; color: #0f172a; font-size: 1.05rem; font-weight: 900; }
    .rm-summary-card-head p { margin: 3px 0 0; color: var(--nova-muted); font-weight: 700; }
    .rm-summary-list,
    .rm-summary-operation-grid { display: grid; gap: 10px; }
    .rm-summary-operation-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .rm-summary-list div,
    .rm-summary-operation-grid div { min-width: 0; padding: 12px; border: 1px solid #d8e3f4; border-radius: 12px; background: #f8fbff; }
    .rm-summary-list span,
    .rm-summary-operation-grid span { display: block; margin-bottom: 4px; color: var(--nova-muted); font-size: .78rem; font-weight: 900; text-transform: uppercase; }
    .rm-summary-list strong,
    .rm-summary-operation-grid strong { display: block; min-width: 0; color: #0f172a; font-size: .95rem; line-height: 1.25; font-weight: 900; overflow-wrap: anywhere; word-break: break-word; }
    .rm-maintenance-box { border: 1px solid #d8e3f4; border-radius: 14px; padding: 16px; background: #f8fbff; box-shadow: 0 10px 22px rgba(15, 23, 42, .035); }
    .rm-maintenance-box h3 { display: flex; align-items: center; gap: 8px; margin: 0 0 6px; font-size: 1rem; font-weight: 900; }
    .rm-maintenance-box p { min-height: 42px; margin: 0 0 12px; color: var(--nova-muted); }
    .rm-maintenance-box .form-check { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; font-weight: 700; }
    .rm-maintenance-switch { display: flex; align-items: center; justify-content: space-between; gap: 16px; min-height: 72px; padding: 14px 16px; border: 1px solid #d8e3f4; border-radius: 14px; background: #f8fbff; cursor: pointer; box-shadow: 0 10px 22px rgba(15, 23, 42, .035); }
    .rm-maintenance-switch strong { display: block; color: #0f172a; font-weight: 900; }
    .rm-maintenance-switch small { display: block; margin-top: 3px; color: var(--nova-muted); font-weight: 700; }
    .rm-maintenance-switch .form-switch { margin: 0; padding-left: 0; }
    .rm-maintenance-switch .form-check-input { width: 3.4rem; height: 1.75rem; margin: 0; cursor: pointer; }
    .rm-webhook-test-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(240px, 32%); gap: 16px; align-items: center; }
    .rm-webhook-test-row .btn { min-height: 40px; justify-content: center; }
    .rm-webhook-test-modal { border: 0; border-radius: 18px; box-shadow: 0 28px 70px rgba(15, 23, 42, .28); }
    .rm-webhook-test-modal .modal-body { display: grid; justify-items: center; gap: 12px; padding: 28px; text-align: center; }
    .rm-webhook-test-modal strong { color: #0f172a; font-size: 1.05rem; font-weight: 900; }
    .rm-webhook-data-animation { display: grid; grid-template-columns: 56px minmax(150px, 220px) 56px; align-items: center; gap: 10px; width: min(360px, 100%); min-height: 92px; padding: 10px 12px; border: 1px solid #d8e3f4; border-radius: 18px; background: linear-gradient(180deg, #f8fbff 0%, #eef6ff 100%); }
    .rm-webhook-node { display: grid; width: 56px; height: 56px; place-items: center; border-radius: 16px; color: #fff; font-size: 1.45rem; box-shadow: 0 12px 24px rgba(37, 99, 235, .18); }
    .rm-webhook-node.is-client { background: linear-gradient(135deg, #2563eb, #0ea5e9); }
    .rm-webhook-node.is-server { background: linear-gradient(135deg, #14b8a6, #22c55e); }
    .rm-webhook-flow { position: relative; height: 46px; overflow: hidden; }
    .rm-webhook-flow::before,
    .rm-webhook-flow::after { content: ""; position: absolute; left: 0; right: 0; height: 2px; border-radius: 999px; background: #bfd3ee; }
    .rm-webhook-flow::before { top: 14px; }
    .rm-webhook-flow::after { bottom: 14px; }
    .rm-webhook-packet { position: absolute; width: 13px; height: 13px; border-radius: 4px; box-shadow: 0 0 0 4px rgba(37, 99, 235, .11); }
    .rm-webhook-packet.is-send { top: 8px; left: -16px; background: #2563eb; animation: rm-webhook-send 1.2s ease-in-out infinite; }
    .rm-webhook-packet.is-receive { right: -16px; bottom: 8px; background: #14b8a6; box-shadow: 0 0 0 4px rgba(20, 184, 166, .12); animation: rm-webhook-receive 1.2s ease-in-out infinite; }
    .rm-webhook-packet.is-delay { animation-delay: .58s; }
    .rm-webhook-test-steps { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; width: min(420px, 100%); }
    .rm-webhook-test-steps span { display: flex; align-items: center; justify-content: center; gap: 7px; min-height: 38px; padding: 8px 10px; border: 1px solid #d8e3f4; border-radius: 999px; background: #f8fbff; color: #334155; font-size: .86rem; font-weight: 900; }
    .rm-webhook-test-steps i { color: var(--nova-primary); }
    .rm-webhook-test-modal .rm-redmine-send-bar { width: min(420px, 100%); }
    @keyframes rm-webhook-send {
        0% { left: -16px; opacity: 0; transform: scale(.75); }
        15%, 82% { opacity: 1; transform: scale(1); }
        100% { left: calc(100% + 16px); opacity: 0; transform: scale(.75); }
    }
    @keyframes rm-webhook-receive {
        0% { right: -16px; opacity: 0; transform: scale(.75); }
        15%, 82% { opacity: 1; transform: scale(1); }
        100% { right: calc(100% + 16px); opacity: 0; transform: scale(.75); }
    }
    .rm-option-panel { position: relative; display: flex; flex-direction: row; align-items: center; justify-content: flex-start; gap: 16px; min-height: 150px; transition: box-shadow .16s ease, border-color .16s ease, transform .16s ease; }
    .rm-option-panel::after { content: "Abrir"; position: absolute; right: 16px; bottom: 14px; padding: 5px 9px; border-radius: 999px; background: #eef6ff; color: var(--nova-primary); border: 1px solid #d4e4f7; font-size: .72rem; font-weight: 900; pointer-events: none; }
    .rm-option-panel:hover { border-color: var(--nova-primary); box-shadow: 0 18px 34px rgba(37, 99, 235, .14); transform: translateY(-1px); }
    .rm-option-panel-icon { position: relative; z-index: 2; display: grid; flex: 0 0 58px; width: 58px; height: 58px; place-items: center; border-radius: 16px; background: #eef6ff; color: var(--nova-primary); border: 1px solid #d4e4f7; font-size: 1.8rem; pointer-events: none; }
    .rm-option-panel .rm-section-head { position: relative; z-index: 2; margin-bottom: 0; pointer-events: none; }
    .rm-redmine-card-hit { position: absolute; inset: 0; z-index: 3; border: 0; border-radius: inherit; background: transparent; cursor: pointer; }
    .rm-option-panel .modal { z-index: 1055; text-align: left; }
    .rm-option-list { display: grid; gap: 10px; }
    .rm-option-card { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 13px; border: 1px solid #d8e3f4; border-radius: 14px; background: linear-gradient(180deg, #fff 0%, #f9fbff 100%); box-shadow: 0 10px 22px rgba(15, 23, 42, .045); }
    .rm-option-card-main { display: flex; align-items: center; gap: 12px; min-width: 0; }
    .rm-option-code { display: inline-grid; min-width: 52px; height: 40px; place-items: center; padding: 0 10px; border-radius: 11px; background: #eef6ff; color: #0f315f; border: 1px solid #d4e4f7; font-weight: 900; }
    .rm-option-copy { min-width: 0; }
    .rm-option-copy strong { display: block; color: #0f172a; font-size: 1rem; line-height: 1.15; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .rm-option-copy span { display: block; margin-top: 3px; color: var(--nova-muted); font-size: .82rem; font-weight: 700; }
    .rm-option-card-actions { display: flex; align-items: center; justify-content: flex-end; gap: 7px; flex-wrap: wrap; }
    .rm-option-card-actions form { margin: 0; }
    .rm-option-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: auto; padding: 12px 14px; border-radius: 12px; background: #eef6ff; border: 1px solid #d4e4f7; }
    .rm-option-foot span { color: var(--nova-muted); font-weight: 800; }
    .rm-option-foot strong { display: inline-grid; min-width: 36px; min-height: 32px; place-items: center; border-radius: 999px; background: #fff; color: #0f315f; border: 1px solid #d4e4f7; }
    .rm-modal-check { display: flex; align-items: center; gap: 8px; padding: 12px; border: 1px solid #d8e3f4; border-radius: 12px; background: #f8fbff; font-weight: 800; }
    .rm-modal-check .form-check-input { margin: 0; }
    .rm-permission-title { margin: 0 0 10px; color: #0f172a; font-size: .95rem; font-weight: 900; }
    .rm-permission-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
    .rm-role-toolbar { display: grid; grid-template-columns: minmax(230px, 360px) auto minmax(240px, 1fr); gap: 12px; align-items: end; margin-bottom: 16px; padding: 14px; border: 1px solid #d8e3f4; border-radius: 14px; background: #f8fbff; box-shadow: 0 10px 22px rgba(15, 23, 42, .035); }
    .rm-role-select-form,
    .rm-create-role-form,
    .rm-delete-role-form { margin: 0; }
    .rm-role-select-row { display: grid; grid-template-columns: minmax(120px, 1fr) auto; gap: 8px; align-items: center; }
    .rm-role-summary { display: flex; align-items: baseline; gap: 5px; padding: 8px 10px; border: 1px solid #d8e3f4; border-radius: 10px; background: #fff; white-space: nowrap; }
    .rm-role-summary strong { color: var(--nova-primary); font-size: 1.05rem; line-height: 1; }
    .rm-role-summary span { color: var(--nova-muted); font-weight: 800; }
    .rm-user-permission-toolbar { grid-template-columns: minmax(260px, 420px) 1fr; }
    .rm-user-permission-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; align-self: center; }
    .rm-user-permission-meta span { display: inline-flex; align-items: center; gap: 6px; min-height: 34px; padding: 6px 10px; border: 1px solid #d8e3f4; border-radius: 999px; background: #fff; color: #334155; font-weight: 800; }
    .rm-user-permission-meta i { color: var(--nova-primary); font-size: .8rem; }
    .rm-user-permission-actions { display: grid; grid-template-columns: minmax(150px, 220px) auto auto auto; gap: 10px; align-items: end; }
    .rm-inline-field { display: grid; gap: 4px; margin: 0; }
    .rm-inline-field span { color: var(--nova-muted); font-size: .82rem; font-weight: 900; }
    .rm-catalog-panel { padding: 14px; border: 1px solid #d8e3f4; border-radius: 14px; background: #f8fbff; }
    .rm-catalog-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #d8e3f4; color: var(--nova-muted); flex-wrap: wrap; }
    .rm-catalog-search { position: relative; flex: 0 1 360px; margin: 0; }
    .rm-catalog-search i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--nova-muted); pointer-events: none; }
    .rm-catalog-search .form-control { min-height: 40px; padding-left: 36px; border-radius: 999px; }
    .rm-catalog-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 10px; }
    .rm-catalog-item { display: grid; gap: 7px; align-content: start; min-height: 82px; padding: 11px; border: 1px solid #d8e3f4; border-radius: 12px; background: #fff; box-shadow: 0 8px 18px rgba(15, 23, 42, .025); }
    .rm-catalog-item span { justify-self: start; max-width: 100%; min-height: 26px; padding: 5px 9px; border-radius: 999px; background: #fff; border: 1px solid #d8e3f4; color: var(--nova-primary); font-size: .72rem; font-weight: 900; line-height: 1.1; overflow-wrap: anywhere; }
    .rm-catalog-item strong { min-width: 0; color: #0f172a; font-size: .92rem; line-height: 1.18; overflow-wrap: anywhere; }
    .rm-scope-panel { margin-bottom: 14px; padding: 14px; border: 1px solid #d8e3f4; border-radius: 14px; background: #f8fbff; }
    .rm-scope-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
    .rm-scope-card { display: grid; gap: 6px; margin: 0; padding: 12px; border: 1px solid #d8e3f4; border-radius: 12px; background: #fff; }
    .rm-scope-card span { color: #0f172a; font-weight: 900; }
    .rm-role-permission-list { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
    .rm-role-permission-item { display: grid; gap: 10px; min-height: 126px; padding: 12px; border: 1px solid #d8e3f4; border-radius: 14px; background: #fff; box-shadow: 0 8px 18px rgba(15, 23, 42, .025); }
    .rm-role-permission-main { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .rm-role-permission-main strong { color: #0f172a; font-size: 1rem; font-weight: 900; line-height: 1.15; }
    .rm-toggle-line { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin: 0; color: #334155; font-weight: 800; }
    .rm-role-permission-children { display: grid; gap: 8px; padding-top: 8px; border-top: 1px solid #d8e3f4; }
    .rm-role-permission-child { min-height: 30px; }
    .rm-switch { appearance: none; flex: 0 0 auto; width: 46px; height: 26px; border: 0; border-radius: 999px; background: #cbd5e1; cursor: pointer; position: relative; transition: background .16s ease; }
    .rm-switch::after { content: ""; position: absolute; top: 4px; left: 4px; width: 18px; height: 18px; border-radius: 999px; background: #fff; box-shadow: 0 1px 3px rgba(15,23,42,.24); transition: transform .16s ease; }
    .rm-switch:checked { background: var(--nova-primary); }
    .rm-switch:checked::after { transform: translateX(20px); }
    .rm-switch:focus-visible { outline: none; box-shadow: var(--nova-focus); }
    .rm-config-permission-panel { margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--nova-line); }
    .rm-empty-state { padding: 14px; border: 1px dashed #b7c7df; border-radius: 12px; color: var(--nova-muted); font-weight: 800; background: #f8fbff; }
    @media (max-width: 575.98px) {
        .rm-option-card { align-items: flex-start; flex-direction: column; }
        .rm-option-card-actions { justify-content: flex-start; }
        .rm-permission-grid { grid-template-columns: 1fr; }
        .rm-scope-grid { grid-template-columns: 1fr; }
        .rm-role-permission-list { grid-template-columns: 1fr; }
        .rm-role-toolbar,
        .rm-user-permission-toolbar,
        .rm-user-permission-actions,
        .rm-role-select-row { grid-template-columns: 1fr; }
        .rm-catalog-grid { grid-template-columns: 1fr; }
        .rm-webhook-test-row,
        .rm-webhook-test-steps { grid-template-columns: 1fr; }
        .rm-config-summary-kpis,
        .rm-config-summary-grid,
        .rm-summary-operation-grid { grid-template-columns: 1fr; }
    }
</style>
