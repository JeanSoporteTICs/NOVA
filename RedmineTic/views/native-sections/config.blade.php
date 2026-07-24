@php
    $activePanel = request('panel', ($allowedConfigPanels[0] ?? ''));
    $panels = [
        'resumen' => ['label' => 'Resumen', 'icon' => 'bi-speedometer2'],
        'conexion' => ['label' => 'Conexion', 'icon' => 'bi-plug'],
        'proyecto' => ['label' => 'Proyecto', 'icon' => 'bi-kanban'],
        'redmine' => ['label' => 'Redmine', 'icon' => 'bi-list-check'],
        'campos' => ['label' => 'Campos', 'icon' => 'bi-ui-checks-grid'],
        'retencion' => ['label' => 'Retencion', 'icon' => 'bi-stopwatch'],
        'mantencion' => ['label' => 'Mantencion', 'icon' => 'bi-tools'],
        'roles' => ['label' => 'Roles y Permisos', 'icon' => 'bi-shield-check'],
        'usuarios-permisos' => ['label' => 'Usuarios y permisos', 'icon' => 'bi-person-lock'],
        'categorias' => ['label' => 'Categorias', 'icon' => 'bi-tags'],
        'unidades' => ['label' => 'Unidades', 'icon' => 'bi-building'],
    ];
    $panels = array_intersect_key($panels, array_flip($allowedConfigPanels ?? []));
    if (!array_key_exists($activePanel, $panels)) {
        $activePanel = (string) (array_key_first($panels) ?? '');
    }
    $configRoute = static fn (string $panel) => $redmineRoute('redmine.native.section', ['section' => 'configuracion', 'panel' => $panel]);
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
        'usuarios' => 'Usuarios',
        'simulador' => 'Webhook',
        'actividad' => 'Actividad',
        'actividad_eliminar' => 'Eliminar bitácora',
        'actividad_todos' => 'Ver todas las bitácoras',
        'mis_integraciones' => 'Mis integraciones',
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
        'cfg_mantencion' => 'Mantencion',
        'cfg_roles' => 'Roles y Permisos',
        'cfg_usuarios' => 'Usuarios y permisos',
        'cfg_categorias' => 'Categorias',
        'cfg_unidades' => 'Unidades',
    ];
    $rolePermissionRows = [
        ['label' => 'Reportes', 'icon' => 'bi-inboxes', 'access' => 'mensajes_acceso', 'edit' => 'reportes_editar', 'delete' => 'reportes_eliminar', 'scope' => 'mensajes', 'scope_input' => 'mensajes'],
        ['label' => 'Horas extra', 'icon' => 'bi-clock-history', 'access' => 'horas_extra', 'edit' => 'horas_extra_editar', 'delete' => 'horas_extra_eliminar', 'scope' => 'horas_extra', 'scope_input' => 'horas'],
        ['label' => 'Historico', 'icon' => 'bi-archive', 'access' => 'historico', 'edit' => 'historico_acciones', 'delete' => null, 'scope' => 'historico_scope', 'scope_input' => 'historico'],
        ['label' => 'Estadisticas', 'icon' => 'bi-bar-chart-line', 'access' => 'estadisticas', 'edit' => null, 'delete' => null, 'scope' => null, 'scope_input' => null],
        ['label' => 'Usuarios', 'icon' => 'bi-people', 'access' => 'usuarios', 'edit' => 'usuarios_editar', 'delete' => 'usuarios_eliminar', 'scope' => null, 'scope_input' => null],
        ['label' => 'Webhook', 'icon' => 'bi-broadcast', 'access' => 'simulador', 'edit' => null, 'delete' => null, 'scope' => null, 'scope_input' => null],
        ['label' => 'Bitácora de actividad', 'icon' => 'bi-activity', 'access' => 'actividad', 'edit' => 'actividad_todos', 'edit_label' => 'Ver todos', 'delete' => 'actividad_eliminar', 'scope' => null, 'scope_input' => null],
        ['label' => 'Mis integraciones', 'icon' => 'bi-person-lock', 'access' => 'mis_integraciones', 'edit' => null, 'delete' => null, 'scope' => null, 'scope_input' => null],
        ['label' => 'Configuracion', 'icon' => 'bi-sliders', 'access' => 'configuracion', 'edit' => null, 'delete' => null, 'scope' => null, 'scope_input' => null],
    ];
@endphp

<div class="rm-config-shell">
    <aside class="rm-config-rail">
        <div class="rm-config-rail-head">
            <span><i class="bi bi-sliders2"></i></span>
            <div>
                <small>Redmine TIC</small>
                <strong>Configuración</strong>
            </div>
        </div>
        <nav class="rm-config-nav" aria-label="Opciones de configuracion">
            @foreach ($panels as $key => $panel)
                <a class="rm-config-nav-link {{ $activePanel === $key ? 'active' : '' }}" href="{{ $configRoute($key) }}" @if ($activePanel === $key) aria-current="page" @endif>
                    <i class="bi {{ $panel['icon'] }}"></i>
                    <span>{{ $panel['label'] }}</span>
                    <i class="bi bi-chevron-right rm-config-nav-chevron"></i>
                </a>
            @endforeach
        </nav>
        <!-- <p class="rm-config-rail-help"><i class="bi bi-info-circle"></i> Los cambios afectan solo al módulo TIC.</p> -->
    </aside>
    <main class="rm-config-content">

@if ($activePanel === 'resumen')
    @php
        $summaryMaintenance = !empty($config['maintenance_mode']);
    @endphp
    <section class="rm-config-summary">
        <div class="rm-feature-head">
            <span class="rm-feature-head-icon is-blue"><i class="bi bi-speedometer2"></i></span>
            <div>
                <small>Centro de control</small>
                <h2>Resumen de configuracion</h2>
                <p>Vista rapida del proyecto, catalogos y parametros operativos de Redmine TIC.</p>
            </div>
            <div class="rm-feature-meter {{ $summaryMaintenance ? 'is-warning' : 'is-ok' }}">
                <strong>{{ $summaryMaintenance ? 'Pausa' : 'OK' }}</strong>
                <span>operacion</span>
            </div>
        </div>
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
                        <p>Endpoints usados para enviar y sincronizar datos. La API Key se configura por usuario.</p>
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
                </div>
            </article>
        </div>
    </section>
@endif

@if ($activePanel === 'conexion')
    @php
        $connectionCards = [
            'platform_url' => ['label' => 'URL issues', 'icon' => 'bi-link-45deg', 'hint' => 'Endpoint principal para crear y consultar tickets.'],
            'categories_url' => ['label' => 'URL categorias', 'icon' => 'bi-tags', 'hint' => 'Fuente remota para sincronizar categorias del proyecto.'],
            'unidades_url' => ['label' => 'URL unidades', 'icon' => 'bi-building', 'hint' => 'Fuente remota para sincronizar unidades disponibles.'],
        ];
        $connectionFilled = collect($connectionCards)->keys()->filter(fn ($field) => trim((string) data_get($config, $field, '')) !== '')->count();
    @endphp
    <section class="card nova-card rm-panel rm-config-feature-form">
        <div class="rm-feature-head">
            <span class="rm-feature-head-icon is-cyan"><i class="bi bi-plug"></i></span>
            <div>
                <small>Integracion Redmine</small>
                <h2>Conexion API</h2>
                <p>Endpoints usados por NOVA para comunicarse con Redmine. Cada usuario debe configurar su API Key personal.</p>
            </div>
            <div class="rm-feature-meter">
                <strong>{{ $connectionFilled }}/{{ count($connectionCards) }}</strong>
                <span>completos</span>
            </div>
        </div>
        <div class="rm-config-card-grid is-two">
            @foreach ($connectionCards as $field => $fieldCard)
                <article class="rm-config-field-card rm-config-readonly-card">
                    <span class="rm-config-field-icon"><i class="bi {{ $fieldCard['icon'] }}"></i></span>
                    <span class="rm-config-field-copy">
                        <strong>{{ $fieldCard['label'] }}</strong>
                        <small>{{ $fieldCard['hint'] }}</small>
                    </span>
                    <span class="rm-config-value">{{ data_get($config, $field, '') ?: 'Sin configurar' }}</span>
                </article>
            @endforeach
        </div>
        <div class="rm-feature-actions">
            <button class="btn-nova btn-nova-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#rm-config-drawer-conexion" aria-controls="rm-config-drawer-conexion">
                <i class="bi bi-pencil-square"></i>Editar conexion
            </button>
        </div>
    </section>
    <div class="offcanvas offcanvas-end integration-drawer rm-config-edit-drawer" tabindex="-1" id="rm-config-drawer-conexion" aria-labelledby="rm-config-drawer-conexion-title">
        <div class="offcanvas-header">
            <div class="integration-drawer-title">
                <span class="integration-icon"><i class="bi bi-plug"></i></span>
                <div>
                    <small>Integracion Redmine</small>
                    <h2 class="offcanvas-title" id="rm-config-drawer-conexion-title">Editar conexion</h2>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <div class="offcanvas-body">
            <form id="rm-config-form-conexion" class="rm-config-drawer-form" method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'conexion']) }}" data-restore-on-close>
                @csrf
                <div class="rm-config-card-grid">
                    @foreach ($connectionCards as $field => $fieldCard)
                        <label class="rm-config-field-card">
                            <span class="rm-config-field-icon"><i class="bi {{ $fieldCard['icon'] }}"></i></span>
                            <span class="rm-config-field-copy">
                                <strong>{{ $fieldCard['label'] }}</strong>
                                <small>{{ $fieldCard['hint'] }}</small>
                            </span>
                            <input class="form-control" name="{{ $field }}" value="{{ data_get($config, $field, '') }}">
                        </label>
                    @endforeach
                </div>
            </form>
        </div>
        <div class="offcanvas-footer nova-drawer-actions rm-config-drawer-actions">
            <button class="btn-nova btn-nova-danger" type="button" data-clear-drawer-form="#rm-config-form-conexion" data-clear-confirm="Eliminar los valores de conexion?">
                <span class="btn-nova-icon"><i class="bi bi-trash"></i></span>
                <span>Eliminar</span>
            </button>
            <button class="btn-nova btn-nova-secondary" type="button" data-bs-dismiss="offcanvas">
                <span class="btn-nova-icon"><i class="bi bi-x-lg"></i></span>
                <span>Cerrar</span>
            </button>
            <button class="btn-nova btn-nova-primary" type="submit" form="rm-config-form-conexion">
                <span class="btn-nova-icon"><i class="bi bi-save"></i></span>
                <span>Guardar</span>
            </button>
        </div>
    </div>
@endif

@if ($activePanel === 'proyecto')
    @php
        $projectCards = [
            'project_name' => ['label' => 'Nombre proyecto', 'icon' => 'bi-kanban', 'hint' => 'Nombre visible del proyecto Redmine en NOVA.'],
            'project_id' => ['label' => 'ID proyecto', 'icon' => 'bi-hash', 'hint' => 'Identificador del proyecto remoto donde se crean tickets.'],
            'tracker_id' => ['label' => 'Tracker por defecto', 'icon' => 'bi-diagram-3', 'hint' => 'Tipo inicial usado para reportes creados desde NOVA.'],
            'priority_id' => ['label' => 'Prioridad por defecto', 'icon' => 'bi-exclamation-triangle', 'hint' => 'Prioridad aplicada al crear nuevos tickets.'],
            'status_id' => ['label' => 'Estado inicial', 'icon' => 'bi-kanban', 'hint' => 'Estado remoto con el que nace cada ticket.'],
        ];
        $projectFilled = collect($projectCards)->keys()->filter(fn ($field) => trim((string) data_get($config, $field, '')) !== '')->count();
    @endphp
    <section class="card nova-card rm-panel rm-config-feature-form">
        <div class="rm-feature-head">
            <span class="rm-feature-head-icon is-blue"><i class="bi bi-kanban"></i></span>
            <div>
                <small>Destino de tickets</small>
                <h2>Proyecto</h2>
                <p>Proyecto y parametros base para crear tickets desde NOVA.</p>
            </div>
            <div class="rm-feature-meter">
                <strong>{{ $projectFilled }}/{{ count($projectCards) }}</strong>
                <span>definidos</span>
            </div>
        </div>
        <div class="rm-config-card-grid is-project">
            @foreach ($projectCards as $field => $fieldCard)
                <article class="rm-config-field-card rm-config-readonly-card">
                    <span class="rm-config-field-icon"><i class="bi {{ $fieldCard['icon'] }}"></i></span>
                    <span class="rm-config-field-copy">
                        <strong>{{ $fieldCard['label'] }}</strong>
                        <small>{{ $fieldCard['hint'] }}</small>
                    </span>
                    <span class="rm-config-value">{{ data_get($config, $field, '') ?: 'Sin configurar' }}</span>
                </article>
            @endforeach
        </div>
        <div class="rm-feature-actions">
            <button class="btn-nova btn-nova-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#rm-config-drawer-proyecto" aria-controls="rm-config-drawer-proyecto">
                <i class="bi bi-pencil-square"></i>Editar proyecto
            </button>
        </div>
    </section>
    <div class="offcanvas offcanvas-end integration-drawer rm-config-edit-drawer" tabindex="-1" id="rm-config-drawer-proyecto" aria-labelledby="rm-config-drawer-proyecto-title">
        <div class="offcanvas-header">
            <div class="integration-drawer-title">
                <span class="integration-icon"><i class="bi bi-kanban"></i></span>
                <div>
                    <small>Destino de tickets</small>
                    <h2 class="offcanvas-title" id="rm-config-drawer-proyecto-title">Editar proyecto</h2>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <div class="offcanvas-body">
            <form id="rm-config-form-proyecto" class="rm-config-drawer-form" method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'proyecto']) }}" data-restore-on-close>
                @csrf
                <div class="rm-config-card-grid">
                    @foreach ($projectCards as $field => $fieldCard)
                        <label class="rm-config-field-card">
                            <span class="rm-config-field-icon"><i class="bi {{ $fieldCard['icon'] }}"></i></span>
                            <span class="rm-config-field-copy">
                                <strong>{{ $fieldCard['label'] }}</strong>
                                <small>{{ $fieldCard['hint'] }}</small>
                            </span>
                            <input class="form-control" name="{{ $field }}" value="{{ data_get($config, $field, '') }}">
                        </label>
                    @endforeach
                </div>
            </form>
        </div>
        <div class="offcanvas-footer nova-drawer-actions rm-config-drawer-actions">
            <button class="btn-nova btn-nova-danger" type="button" data-clear-drawer-form="#rm-config-form-proyecto" data-clear-confirm="Eliminar los valores de proyecto?">
                <span class="btn-nova-icon"><i class="bi bi-trash"></i></span>
                <span>Eliminar</span>
            </button>
            <button class="btn-nova btn-nova-secondary" type="button" data-bs-dismiss="offcanvas">
                <span class="btn-nova-icon"><i class="bi bi-x-lg"></i></span>
                <span>Cerrar</span>
            </button>
            <button class="btn-nova btn-nova-primary" type="submit" form="rm-config-form-proyecto">
                <span class="btn-nova-icon"><i class="bi bi-save"></i></span>
                <span>Guardar</span>
            </button>
        </div>
    </div>
@endif

@if ($activePanel === 'redmine')
    <section class="card nova-card rm-panel rm-config-feature-form rm-redmine-config-page">
        <div class="rm-feature-head">
            <span class="rm-feature-head-icon is-blue"><i class="bi bi-list-check"></i></span>
            <div>
                <small>Catalogo operativo</small>
                <h2>Redmine</h2>
                <p>Trackers, prioridades y estados disponibles para crear reportes.</p>
            </div>
            <div class="rm-feature-meter">
                <strong>{{ count(data_get($config, 'trackers', [])) + count(data_get($config, 'prioridades', [])) + count(data_get($config, 'estados', [])) }}</strong>
                <span>opciones</span>
            </div>
        </div>
        <div class="row g-3">
        @foreach (['trackers' => ['label' => 'Trackers', 'icon' => 'bi-diagram-3'], 'prioridades' => ['label' => 'Prioridades', 'icon' => 'bi-exclamation-triangle'], 'estados' => ['label' => 'Estados', 'icon' => 'bi-kanban']] as $field => $optionGroup)
            @php
                $label = $optionGroup['label'];
                $defaultKey = ['trackers' => 'tracker_id', 'prioridades' => 'priority_id', 'estados' => 'status_id'][$field];
                $optionRows = data_get($config, $field, []);
                $defaultValue = (string) data_get($config, $defaultKey, '');
            @endphp
            <div class="col-12 col-xl-4">
                <article class="rm-config-catalog-card h-100">
                    <i class="bi {{ $optionGroup['icon'] }} rm-option-panel-icon" aria-hidden="true"></i>
                    <div class="rm-config-catalog-copy">
                        <small>Catálogo Redmine</small>
                        <h2>{{ $label }}</h2>
                        <p>{{ count($optionRows) }} {{ count($optionRows) === 1 ? 'opción configurada' : 'opciones configuradas' }}</p>
                    </div>
                    <div class="rm-config-catalog-default">
                        <span>Predeterminada</span>
                        <strong><i class="bi bi-star-fill"></i>{{ $defaultValue !== '' ? '#' . $defaultValue : 'Sin definir' }}</strong>
                    </div>
                    <span class="rm-config-catalog-action">Administrar <i class="bi bi-arrow-right"></i></span>
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
                                        <button class="btn-nova btn-nova-success" type="button" data-bs-toggle="offcanvas" data-bs-target="#rm-option-create-{{ $field }}" aria-controls="rm-option-create-{{ $field }}">
                                            <i class="bi bi-plus-lg"></i>Agregar
                                        </button>
                                    </div>
                    <div class="rm-option-list detail-drawer-panel">
                        @forelse (data_get($config, $field, []) as $option)
                            @php
                                $drawerId = 'rm-option-edit-' . $field . '-' . $loop->index;
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
                                    <button class="btn-action btn-action-edit" type="button" data-bs-toggle="offcanvas" data-bs-target="#{{ $drawerId }}" aria-controls="{{ $drawerId }}" title="Ver y editar" aria-label="Ver y editar">
                                        <i class="bi bi-pencil-square"></i>
                                    </button>
                                    <button class="btn {{ !empty($option['default']) ? 'btn-warning' : 'btn-outline-secondary' }} nova-btn-icon rm-default-option" type="button" data-default-group="{{ $field }}" data-default-value="{{ $option['id'] ?? '' }}" data-default-target="rm-default-selected-{{ $field }}" title="Marcar default" aria-label="Marcar default">
                                        <i class="bi {{ !empty($option['default']) ? 'bi-star-fill' : 'bi-star' }}"></i>
                                    </button>
                                </div>
                            </article>
                        @empty
                            <div class="nova-empty-state">Sin opciones configuradas.</div>
                        @endforelse
                    </div>
                                </div>
                                <div class="modal-footer">
                                    <form class="m-0" method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'redmine']) }}">
                                        @csrf
                                        <input type="hidden" name="opt_type" value="{{ $field }}">
                                        <input type="hidden" name="opt_action" value="set_default">
                                        <input id="rm-default-selected-{{ $field }}" type="hidden" name="opt_id" value="{{ data_get($config, $defaultKey, '') }}">
                                        <button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-save"></i>Guardar cambios</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    @foreach (data_get($config, $field, []) as $option)
                        @php
                            $drawerId = 'rm-option-edit-' . $field . '-' . $loop->index;
                            $editFormId = 'rm-option-edit-form-' . $field . '-' . $loop->index;
                        @endphp
                        <div class="offcanvas offcanvas-end integration-drawer rm-config-edit-drawer" tabindex="-1" id="{{ $drawerId }}" aria-labelledby="{{ $drawerId }}-title" data-bs-backdrop="false">
                            <div class="offcanvas-header">
                                <div class="integration-drawer-title">
                                    <span class="integration-icon"><i class="bi {{ $optionGroup['icon'] }}"></i></span>
                                    <div>
                                        <small>Catalogo Redmine</small>
                                        <h2 class="offcanvas-title" id="{{ $drawerId }}-title">Editar {{ strtolower($label) }}</h2>
                                    </div>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
                            </div>
                            <div class="offcanvas-body">
                                <form id="{{ $editFormId }}" class="rm-config-drawer-form" method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'redmine']) }}" data-restore-on-close>
                                    @csrf
                                    <input type="hidden" name="opt_type" value="{{ $field }}">
                                    <input type="hidden" name="opt_action" value="update">
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
                                </form>
                            </div>
                            <div class="offcanvas-footer nova-drawer-actions rm-config-drawer-actions">
                                <form
                                    class="m-0"
                                    method="post"
                                    action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'redmine']) }}"
                                    data-app-confirm="Eliminar esta opcion?"
                                    @if ((string) data_get($config, $defaultKey, '') === (string) ($option['id'] ?? '') || !empty($option['default']))
                                        data-app-block-message="Esta opcion esta definida como predeterminada. Antes de eliminarla, selecciona otro valor predeterminado para {{ strtolower($label) }}."
                                    @endif
                                >
                                    @csrf
                                    <input type="hidden" name="opt_type" value="{{ $field }}">
                                    <input type="hidden" name="opt_id" value="{{ $option['id'] ?? '' }}">
                                    <input type="hidden" name="opt_action" value="delete">
                                    <button class="btn-nova btn-nova-danger" type="submit">
                                        <span class="btn-nova-icon"><i class="bi bi-trash"></i></span>
                                        <span>Eliminar</span>
                                    </button>
                                </form>
                                <button class="btn-nova btn-nova-secondary" type="button" data-bs-dismiss="offcanvas">
                                    <span class="btn-nova-icon"><i class="bi bi-x-lg"></i></span>
                                    <span>Cerrar</span>
                                </button>
                                <button class="btn-nova btn-nova-primary" type="submit" form="{{ $editFormId }}">
                                    <span class="btn-nova-icon"><i class="bi bi-save"></i></span>
                                    <span>Guardar</span>
                                </button>
                            </div>
                        </div>
                    @endforeach
                    <div class="offcanvas offcanvas-end integration-drawer rm-config-edit-drawer" tabindex="-1" id="rm-option-create-{{ $field }}" aria-labelledby="rm-option-create-{{ $field }}-title" data-bs-backdrop="false">
                        <div class="offcanvas-header">
                            <div class="integration-drawer-title">
                                <span class="integration-icon"><i class="bi {{ $optionGroup['icon'] }}"></i></span>
                                <div>
                                    <small>Catalogo Redmine</small>
                                    <h2 class="offcanvas-title" id="rm-option-create-{{ $field }}-title">Agregar {{ strtolower($label) }}</h2>
                                </div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
                        </div>
                        <div class="offcanvas-body">
                            <form id="rm-option-create-form-{{ $field }}" class="rm-config-drawer-form" method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'redmine']) }}" data-restore-on-close>
                                @csrf
                                <input type="hidden" name="opt_type" value="{{ $field }}">
                                <input type="hidden" name="opt_action" value="create">
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
                            </form>
                        </div>
                        <div class="offcanvas-footer nova-drawer-actions rm-config-drawer-actions">
                            <button class="btn-nova btn-nova-secondary" type="button" data-bs-dismiss="offcanvas">
                                <span class="btn-nova-icon"><i class="bi bi-x-lg"></i></span>
                                <span>Cerrar</span>
                            </button>
                            <button class="btn-nova btn-nova-success" type="submit" form="rm-option-create-form-{{ $field }}">
                                <span class="btn-nova-icon"><i class="bi bi-plus-lg"></i></span>
                                <span>Agregar</span>
                            </button>
                        </div>
                    </div>
                </article>
            </div>
        @endforeach
        </div>
    </section>
@endif

@if ($activePanel === 'campos')
    @php
        $customFieldCards = [
            'cf_solicitante' => ['label' => 'Solicitante', 'icon' => 'bi-person-lines-fill', 'hint' => 'Campo usado para identificar quien solicita el ticket.'],
            'cf_unidad' => ['label' => 'Unidad', 'icon' => 'bi-building', 'hint' => 'Campo donde Redmine recibe la unidad operativa.'],
            'cf_unidad_solicitante' => ['label' => 'Unidad solicitante', 'icon' => 'bi-diagram-3', 'hint' => 'Campo para conservar la unidad declarada por el solicitante.'],
            'cf_hora_extra' => ['label' => 'Hora extra', 'icon' => 'bi-clock-history', 'hint' => 'Campo que marca tickets asociados a horas extra.'],
        ];
        $customFieldFilled = collect($customFieldCards)->keys()->filter(fn ($field) => trim((string) data_get($config, $field, '')) !== '')->count();
    @endphp
    <section class="card nova-card rm-panel rm-config-feature-form">
        <div class="rm-feature-head">
            <span class="rm-feature-head-icon is-blue"><i class="bi bi-ui-checks-grid"></i></span>
            <div>
                <small>Mapeo Redmine</small>
                <h2>Campos personalizados</h2>
                <p>IDs que NOVA usa para enviar cada dato al crear tickets en Redmine.</p>
            </div>
            <div class="rm-feature-meter">
                <strong>{{ $customFieldFilled }}/{{ count($customFieldCards) }}</strong>
                <span>configurados</span>
            </div>
        </div>
        <div class="rm-config-card-grid">
            @foreach ($customFieldCards as $field => $fieldCard)
                <article class="rm-config-field-card rm-config-readonly-card">
                    <span class="rm-config-field-icon"><i class="bi {{ $fieldCard['icon'] }}"></i></span>
                    <span class="rm-config-field-copy">
                        <strong>{{ $fieldCard['label'] }}</strong>
                        <small>{{ $fieldCard['hint'] }}</small>
                    </span>
                    <span class="rm-config-value">{{ data_get($config, $field, '') ?: 'Sin configurar' }}</span>
                </article>
            @endforeach
        </div>
        <div class="rm-feature-actions">
            <button class="btn-nova btn-nova-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#rm-config-drawer-campos" aria-controls="rm-config-drawer-campos">
                <i class="bi bi-pencil-square"></i>Editar campos
            </button>
        </div>
    </section>
    <div class="offcanvas offcanvas-end integration-drawer rm-config-edit-drawer" tabindex="-1" id="rm-config-drawer-campos" aria-labelledby="rm-config-drawer-campos-title">
        <div class="offcanvas-header">
            <div class="integration-drawer-title">
                <span class="integration-icon"><i class="bi bi-ui-checks-grid"></i></span>
                <div>
                    <small>Mapeo Redmine</small>
                    <h2 class="offcanvas-title" id="rm-config-drawer-campos-title">Editar campos</h2>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <div class="offcanvas-body">
            <form id="rm-config-form-campos" class="rm-config-drawer-form" method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'campos']) }}" data-restore-on-close>
                @csrf
                <div class="rm-config-card-grid">
                    @foreach ($customFieldCards as $field => $fieldCard)
                        <label class="rm-config-field-card">
                            <span class="rm-config-field-icon"><i class="bi {{ $fieldCard['icon'] }}"></i></span>
                            <span class="rm-config-field-copy">
                                <strong>{{ $fieldCard['label'] }}</strong>
                                <small>{{ $fieldCard['hint'] }}</small>
                            </span>
                            <input class="form-control" name="{{ $field }}" value="{{ data_get($config, $field, '') }}" placeholder="ID del campo">
                        </label>
                    @endforeach
                </div>
            </form>
        </div>
        <div class="offcanvas-footer nova-drawer-actions rm-config-drawer-actions">
            <button class="btn-nova btn-nova-secondary" type="button" data-bs-dismiss="offcanvas">
                <span class="btn-nova-icon"><i class="bi bi-x-lg"></i></span>
                <span>Cerrar</span>
            </button>
            <button class="btn-nova btn-nova-primary" type="submit" form="rm-config-form-campos">
                <span class="btn-nova-icon"><i class="bi bi-save"></i></span>
                <span>Guardar</span>
            </button>
        </div>
    </div>
@endif

@if ($activePanel === 'retencion')
    @php
        $retentionHours = (int) data_get($config, 'retencion_horas', 24);
    @endphp
    <section class="card nova-card rm-panel rm-config-feature-form">
        <div class="rm-feature-head">
            <span class="rm-feature-head-icon is-cyan"><i class="bi bi-stopwatch"></i></span>
            <div>
                <small>Archivado automatico</small>
                <h2>Retencion</h2>
                <p>Controla cuando los reportes procesados salen de la vista operativa.</p>
            </div>
            <div class="rm-feature-meter">
                <strong>{{ $retentionHours }}h</strong>
                <span>ventana activa</span>
            </div>
        </div>
        <div class="rm-retention-layout">
            <article class="rm-config-field-card rm-retention-control rm-config-readonly-card">
                <span class="rm-config-field-icon"><i class="bi bi-hourglass-split"></i></span>
                <span class="rm-config-field-copy">
                    <strong>Horas antes de archivar procesados</strong>
                    <small>La sesion la administra NOVA; este valor solo afecta reportes ya procesados.</small>
                </span>
                <span class="rm-config-value">{{ $retentionHours }} horas</span>
            </article>
            <aside class="rm-config-side-card">
                <i class="bi bi-archive"></i>
                <strong>Historico limpio</strong>
                <span>Los reportes procesados se archivan despues de la ventana definida, manteniendo el dashboard liviano.</span>
            </aside>
        </div>
        <div class="rm-feature-actions">
            <button class="btn-nova btn-nova-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#rm-config-drawer-retencion" aria-controls="rm-config-drawer-retencion">
                <i class="bi bi-pencil-square"></i>Editar retencion
            </button>
        </div>
    </section>
    <div class="offcanvas offcanvas-end integration-drawer rm-config-edit-drawer" tabindex="-1" id="rm-config-drawer-retencion" aria-labelledby="rm-config-drawer-retencion-title">
        <div class="offcanvas-header">
            <div class="integration-drawer-title">
                <span class="integration-icon"><i class="bi bi-stopwatch"></i></span>
                <div>
                    <small>Archivado automatico</small>
                    <h2 class="offcanvas-title" id="rm-config-drawer-retencion-title">Editar retencion</h2>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <div class="offcanvas-body">
            <form id="rm-config-form-retencion" class="rm-config-drawer-form" method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'retencion']) }}" data-restore-on-close>
                @csrf
                <label class="rm-config-field-card rm-retention-control">
                    <span class="rm-config-field-icon"><i class="bi bi-hourglass-split"></i></span>
                    <span class="rm-config-field-copy">
                        <strong>Horas antes de archivar procesados</strong>
                        <small>La sesion la administra NOVA; este valor solo afecta reportes ya procesados.</small>
                    </span>
                    <div class="rm-number-field">
                        <input class="form-control" type="number" min="1" name="retencion_horas" value="{{ $retentionHours }}">
                        <span>horas</span>
                    </div>
                </label>
            </form>
        </div>
        <div class="offcanvas-footer nova-drawer-actions rm-config-drawer-actions">
            <button class="btn-nova btn-nova-danger" type="button" data-clear-drawer-form="#rm-config-form-retencion" data-clear-confirm="Eliminar el valor de retencion?">
                <span class="btn-nova-icon"><i class="bi bi-trash"></i></span>
                <span>Eliminar</span>
            </button>
            <button class="btn-nova btn-nova-secondary" type="button" data-bs-dismiss="offcanvas">
                <span class="btn-nova-icon"><i class="bi bi-x-lg"></i></span>
                <span>Cerrar</span>
            </button>
            <button class="btn-nova btn-nova-primary" type="submit" form="rm-config-form-retencion">
                <span class="btn-nova-icon"><i class="bi bi-save"></i></span>
                <span>Guardar</span>
            </button>
        </div>
    </div>
@endif

@if ($activePanel === 'mantencion')
    @php
        $maintenanceEnabled = !empty($config['maintenance_mode']);
        $maintenanceUntil = data_get($config, 'maintenance_until', '');
    @endphp
    <section class="card nova-card rm-panel rm-config-feature-form">
        <div class="rm-feature-head">
            <span class="rm-feature-head-icon {{ $maintenanceEnabled ? 'is-orange' : 'is-green' }}"><i class="bi {{ $maintenanceEnabled ? 'bi-tools' : 'bi-check2-circle' }}"></i></span>
            <div>
                <small>Disponibilidad del modulo</small>
                <h2>Mantencion</h2>
                <p>Activa una pausa temporal para proteger datos mientras se realizan ajustes.</p>
            </div>
            <div class="rm-feature-meter {{ $maintenanceEnabled ? 'is-warning' : 'is-ok' }}">
                <strong>{{ $maintenanceEnabled ? 'Activa' : 'Normal' }}</strong>
                <span>estado actual</span>
            </div>
        </div>
        <div class="rm-maintenance-layout">
            <div class="rm-maintenance-control-card">
                <article class="rm-maintenance-switch rm-config-readonly-card">
                    <span>
                        <i class="bi bi-power"></i>
                        <strong>Modo mantenimiento</strong>
                        <small>{{ !empty($config['maintenance_mode']) ? 'Activo: la edicion esta bloqueada.' : 'Inactivo: el modulo opera normalmente.' }}</small>
                    </span>
                    <span class="nova-status-badge {{ $maintenanceEnabled ? 'is-warning' : 'is-success' }}">{{ $maintenanceEnabled ? 'Activa' : 'Inactiva' }}</span>
                </article>
                <article class="rm-config-field-card rm-maintenance-date rm-config-readonly-card">
                    <span class="rm-config-field-icon"><i class="bi bi-calendar2-check"></i></span>
                    <span class="rm-config-field-copy">
                        <strong>Mantencion hasta</strong>
                        <small>Define la fecha de termino visible para usuarios del modulo.</small>
                    </span>
                    <span class="rm-config-value">{{ $maintenanceUntil ?: 'Sin fecha definida' }}</span>
                </article>
            </div>
            <aside class="rm-config-side-card {{ $maintenanceEnabled ? 'is-warning' : 'is-ok' }}">
                <i class="bi {{ $maintenanceEnabled ? 'bi-shield-lock' : 'bi-shield-check' }}"></i>
                <strong>{{ $maintenanceEnabled ? 'Edicion bloqueada' : 'Modulo disponible' }}</strong>
                <span>{{ $maintenanceEnabled ? 'Las acciones de escritura quedan protegidas hasta desactivar la mantencion.' : 'Los usuarios pueden operar reportes, catalogos y configuracion segun permisos.' }}</span>
            </aside>
        </div>
        <div class="rm-feature-actions">
            <button class="btn-nova btn-nova-primary" type="button" data-bs-toggle="offcanvas" data-bs-target="#rm-config-drawer-mantencion" aria-controls="rm-config-drawer-mantencion">
                <i class="bi bi-pencil-square"></i>Editar mantencion
            </button>
        </div>
    </section>
    <div class="offcanvas offcanvas-end integration-drawer rm-config-edit-drawer" tabindex="-1" id="rm-config-drawer-mantencion" aria-labelledby="rm-config-drawer-mantencion-title">
        <div class="offcanvas-header">
            <div class="integration-drawer-title">
                <span class="integration-icon"><i class="bi bi-tools"></i></span>
                <div>
                    <small>Disponibilidad del modulo</small>
                    <h2 class="offcanvas-title" id="rm-config-drawer-mantencion-title">Editar mantencion</h2>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <div class="offcanvas-body">
            <form id="rm-config-form-mantencion" class="rm-config-drawer-form" method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'mantencion']) }}" data-maintenance-allowed="1" data-restore-on-close>
                @csrf
                <div class="rm-maintenance-control-card">
                    <input type="hidden" name="maintenance_mode" value="0">
                    <label class="rm-maintenance-switch">
                        <span>
                            <i class="bi bi-power"></i>
                            <strong>Modo mantenimiento</strong>
                            <small>{{ !empty($config['maintenance_mode']) ? 'Activo: la edicion esta bloqueada.' : 'Inactivo: el modulo opera normalmente.' }}</small>
                        </span>
                        <span class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="maintenance_mode" value="1" @checked(!empty($config['maintenance_mode'])) aria-label="Activar modo mantenimiento" data-maintenance-mode-switch>
                        </span>
                    </label>
                    <label class="rm-config-field-card rm-maintenance-date">
                        <span class="rm-config-field-icon"><i class="bi bi-calendar2-check"></i></span>
                        <span class="rm-config-field-copy">
                            <strong>Mantencion hasta</strong>
                            <small>Define la fecha de termino visible para usuarios del modulo.</small>
                        </span>
                        <input class="form-control" type="datetime-local" name="maintenance_until" min="{{ now('America/Santiago')->format('Y-m-d\TH:i') }}" value="{{ old('maintenance_until', $maintenanceUntil) }}" data-maintenance-until>
                    </label>
                </div>
            </form>
        </div>
        <div class="offcanvas-footer nova-drawer-actions rm-config-drawer-actions">
            <button class="btn-nova btn-nova-danger" type="button" data-clear-drawer-form="#rm-config-form-mantencion" data-clear-confirm="Eliminar los valores de mantencion?">
                <span class="btn-nova-icon"><i class="bi bi-trash"></i></span>
                <span>Eliminar</span>
            </button>
            <button class="btn-nova btn-nova-secondary" type="button" data-bs-dismiss="offcanvas">
                <span class="btn-nova-icon"><i class="bi bi-x-lg"></i></span>
                <span>Cerrar</span>
            </button>
            <button class="btn-nova btn-nova-primary" type="submit" form="rm-config-form-mantencion">
                <span class="btn-nova-icon"><i class="bi bi-save"></i></span>
                <span>Guardar</span>
            </button>
        </div>
    </div>
@endif

@if ($activePanel === 'roles')
    @php
        $roleNames = array_keys($roles);
        $selectedRole = request('role', session('redmine_selected_role', $roleNames[0] ?? 'usuario'));
        if (!array_key_exists($selectedRole, $roles) && $roleNames !== []) {
            $selectedRole = $roleNames[0];
        }
        $selectedPermissions = is_array($roles[$selectedRole] ?? null) ? $roles[$selectedRole] : [];
        $roleFormAction = $redmineRoute('redmine.native.config.action', ['panel' => 'roles', 'role' => $selectedRole]);
        $selectedRoleActiveCount = collect($selectedPermissions)->filter(fn ($value, $key) => is_string($key) && !in_array($key, ['mensajes', 'horas_extra', 'historico_scope'], true) && $value === true)->count();
    @endphp
    <section class="card nova-card rm-panel rm-config-feature-form rm-permissions-page">
        <div class="rm-feature-head">
            <span class="rm-feature-head-icon is-green"><i class="bi bi-shield-check"></i></span>
            <div>
                <small>Matriz de acceso</small>
                <h2>Roles y Permisos</h2>
                <p>Define que vistas, acciones y alcances quedan disponibles para cada rol.</p>
            </div>
            <div class="rm-feature-meter">
                <strong>{{ count($roleNames) }}</strong>
                <span>roles</span>
            </div>
        </div>

        <div class="rm-permissions-layout">
            <aside class="rm-permissions-list-panel">
                <div class="rm-permissions-list-head">
                    <div>
                        <h3>Roles</h3>
                        <p>{{ count($roleNames) }} perfil(es) configurado(s)</p>
                    </div>
                    <span>{{ $selectedRoleActiveCount }} activos</span>
                </div>
                <form method="get" action="{{ $redmineRoute('redmine.native.section', 'configuracion') }}" class="rm-role-dropdown-card">
                    <input type="hidden" name="panel" value="roles">
                    <span class="rm-permission-selector-icon"><i class="bi bi-shield-check"></i></span>
                    <span class="rm-permission-selector-copy">
                        <strong>Rol seleccionado</strong>
                        <small>{{ $selectedRoleActiveCount }} permiso(s) activo(s)</small>
                    </span>
                    <select class="form-select" name="role" aria-label="Seleccionar rol" onchange="this.form.submit()">
                        @foreach ($roleNames as $roleName)
                            <option value="{{ $roleName }}" @selected($selectedRole === $roleName)>{{ $roleName }}</option>
                        @endforeach
                    </select>
                </form>
                <form class="rm-create-role-form rm-create-role-card" method="post" action="{{ $redmineRoute('redmine.native.config.action', ['panel' => 'roles']) }}">
                    @csrf
                    <input type="hidden" name="config_action" value="save_role_permissions">
                    <span class="rm-permission-selector-icon"><i class="bi bi-plus-lg"></i></span>
                    <span class="rm-permission-selector-copy">
                        <strong>Crear rol</strong>
                        <small>Agrega un nuevo perfil a la lista.</small>
                    </span>
                    <div class="rm-create-role-row">
                        <input class="form-control" id="rm-new-role" name="role_name" placeholder="Nombre del rol" aria-label="Nombre del rol" autocomplete="off" required>
                        <button class="btn-nova btn-nova-success" type="submit" title="Crear rol" aria-label="Crear rol"><i class="bi bi-plus-lg"></i></button>
                    </div>
                </form>
            </aside>

            <div class="rm-permissions-editor-panel">
                <div class="rm-permissions-editor-head">
                    <div>
                        <small>Matriz de acceso</small>
                        <h3>{{ $selectedRole ?: 'Sin rol seleccionado' }}</h3>
                        <p>Activa vistas y define las acciones disponibles para este rol.</p>
                    </div>
                    <div class="rm-role-summary">
                        <strong>{{ $selectedRoleActiveCount }}</strong>
                        <span>activos</span>
                    </div>
                </div>

                <form id="rm-config-form-roles" class="rm-permissions-inline-form" method="post" action="{{ $roleFormAction }}" data-inline-restore-form>
                    @csrf
                    <input type="hidden" name="config_action" value="save_role_permissions">
                    <input type="hidden" name="role_name" value="{{ $selectedRole }}">

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
                                    <strong><i class="bi {{ $permissionRow['icon'] }}"></i>{{ $permissionRow['label'] }}</strong>
                                    <label class="rm-toggle-line">
                                        <span>Ver</span>
                                        <input class="rm-switch" type="checkbox" name="perm_{{ $permissionRow['access'] }}" value="1" data-role-access-toggle @if ($permissionRow['access'] === 'configuracion') data-config-access-toggle @endif @checked(!empty($selectedPermissions[$permissionRow['access']] ))>
                                    </label>
                                </div>

                                @if ($permissionRow['edit'] || $permissionRow['delete'])
                                    <div class="rm-role-permission-children" data-role-dependent-actions>
                                        @if ($permissionRow['edit'])
                                            <label class="rm-toggle-line rm-role-permission-child">
                                                <span>{{ $permissionRow['edit_label'] ?? 'Editar' }}</span>
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
                                <label class="rm-toggle-line rm-config-permission-switch">
                                    <span>{{ $permissionLabel }}</span>
                                    <input class="rm-switch" type="checkbox" name="perm_{{ $permissionKey }}" value="1" @checked(!empty($selectedPermissions[$permissionKey]))>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </form>

                <div class="rm-inline-actions">
                    <button class="btn-nova btn-nova-secondary" type="button" data-role-cancel hidden>
                        <span class="btn-nova-icon"><i class="bi bi-x-lg"></i></span>
                        <span>Cancelar</span>
                    </button>
                    <button class="btn-nova btn-nova-primary" type="submit" form="rm-config-form-roles">
                        <span class="btn-nova-icon"><i class="bi bi-save"></i></span>
                        <span>Guardar</span>
                    </button>
                </div>
            </div>
        </div>
    </section>
@endif

@if ($activePanel === 'usuarios-permisos')
    @php
        $userOptions = collect($users ?? [])
            ->filter(function ($user) {
                $ticStatus = strtolower(trim((string) ($user['estado_usuario'] ?? 'activo')));
                $novaStatus = strtolower(trim((string) ($user['estado_nova'] ?? $user['estado'] ?? 'activo')));

                return $ticStatus === 'activo' && $novaStatus === 'activo';
            })
            ->sortBy(fn ($user) => trim(($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')) ?: ($user['id'] ?? ''))
            ->values();
        $firstUser = $userOptions->first();
        $selectedUserId = (string) request('user_id', session('redmine_selected_user_permissions', (string) ($firstUser['id'] ?? '')));
        $selectedUser = $userOptions->first(fn ($user) => (string) ($user['id'] ?? '') === $selectedUserId);
        if (!$selectedUser && $firstUser) {
            $selectedUser = $firstUser;
            $selectedUserId = (string) ($firstUser['id'] ?? '');
        }
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
    <section class="card nova-card rm-panel rm-config-feature-form rm-permissions-page">
        <div class="rm-feature-head">
            <span class="rm-feature-head-icon is-orange"><i class="bi bi-person-lock"></i></span>
            <div>
                <small>Permisos por usuario</small>
                <h2>Usuarios y permisos</h2>
                <p>Ajusta permisos individuales sin perder la referencia del rol asignado.</p>
            </div>
            <div class="rm-feature-meter">
                <strong>{{ $userOptions->count() }}</strong>
                <span>usuarios activos</span>
            </div>
        </div>
        @if ($selectedUser)
            <div class="rm-permissions-layout">
                <aside class="rm-permissions-list-panel">
                    <div class="rm-permissions-list-head">
                        <div>
                            <h3>Usuarios activos</h3>
                            <p>{{ $userOptions->count() }} usuario(s) disponible(s)</p>
                        </div>
                        <span>{{ $selectedUserActiveCount }} activos</span>
                    </div>
                    <form method="get" action="{{ $redmineRoute('redmine.native.section', 'configuracion') }}" class="rm-role-dropdown-card" data-user-permission-picker>
                        <input type="hidden" name="panel" value="usuarios-permisos">
                        <input type="hidden" name="user_id" value="{{ $selectedUserId }}" data-user-permission-id>
                        <span class="rm-permission-selector-icon"><i class="bi bi-person"></i></span>
                        <span class="rm-permission-selector-copy">
                            <strong>Usuario seleccionado</strong>
                            <small>{{ $selectedUserName }} · {{ $selectedUserRole }}</small>
                        </span>
                        <div class="rm-user-search-row">
                            <input
                                class="form-control"
                                type="search"
                                list="rm-user-permission-options"
                                value="{{ $selectedUserName }} · {{ $selectedUserRole }} · ID {{ $selectedUserId }}"
                                placeholder="Buscar usuario por nombre, rol o ID"
                                aria-label="Buscar usuario"
                                autocomplete="off"
                                data-user-permission-search
                            >
                            <button class="btn-nova btn-nova-primary" type="submit" title="Cargar usuario" aria-label="Cargar usuario"><i class="bi bi-search"></i></button>
                        </div>
                        <datalist id="rm-user-permission-options">
                            @foreach ($userOptions as $userOption)
                                @php
                                    $userOptionId = (string) ($userOption['id'] ?? '');
                                    $userOptionName = trim(($userOption['nombre'] ?? '') . ' ' . ($userOption['apellido'] ?? '')) ?: $userOptionId;
                                    $userOptionRole = (string) ($userOption['rol'] ?? 'usuario');
                                @endphp
                                <option value="{{ $userOptionName }} · {{ $userOptionRole }} · ID {{ $userOptionId }}" data-user-id="{{ $userOptionId }}"></option>
                            @endforeach
                        </datalist>
                    </form>
                </aside>

                <div class="rm-permissions-editor-panel">
                    <div class="rm-permissions-editor-head">
                        <div>
                            <small>Permisos por usuario</small>
                            <h3>{{ $selectedUserName }}</h3>
                            <p>ID {{ $selectedUser['id'] ?? '-' }} · Rol actual: {{ $selectedUserRole }}</p>
                        </div>
                        <div class="rm-role-summary">
                            <strong data-user-active-count>{{ $selectedUserActiveCount }}</strong>
                            <span>activos</span>
                        </div>
                    </div>

                    <form id="rm-config-form-usuarios-permisos" class="rm-permissions-inline-form" method="post" action="{{ $userPermissionFormAction }}" data-user-permission-form data-inline-restore-form>
                        @csrf
                        <input type="hidden" name="config_action" value="save_user_permissions">
                        <input type="hidden" name="user_id" value="{{ $selectedUserId }}">
                        <input type="hidden" name="apply_role_permissions" value="0" data-apply-role-permissions>

                        <div class="rm-user-permission-actions">
                            <label class="rm-inline-field">
                                <span>Rol asignado</span>
                                <select class="form-select" name="user_role" aria-label="Rol asignado" data-user-role-select data-current-user-role="{{ $selectedUserRole }}">
                                    @foreach (array_unique(array_merge(array_keys($roles), [$selectedUserRole])) as $roleOption)
                                        <option value="{{ $roleOption }}" @selected($selectedUserRole === $roleOption)>{{ $roleOption }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <button class="btn-nova btn-nova-info" type="button" data-load-role-permissions>
                                <i class="bi bi-arrow-repeat"></i>Cargar permisos del rol
                            </button>
                            <button class="btn-nova btn-nova-secondary" type="button" data-reset-user-permissions>
                                <i class="bi bi-eraser"></i>Restaurar
                            </button>
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
                                        <strong><i class="bi {{ $permissionRow['icon'] }}"></i>{{ $permissionRow['label'] }}</strong>
                                        <label class="rm-toggle-line">
                                            <span>Ver</span>
                                            <input class="rm-switch" type="checkbox" name="perm_{{ $permissionRow['access'] }}" value="1" data-role-access-toggle @if ($permissionRow['access'] === 'configuracion') data-config-access-toggle @endif @checked(!empty($selectedUserPermissions[$permissionRow['access']] ))>
                                        </label>
                                    </div>

                                    @if ($permissionRow['edit'] || $permissionRow['delete'])
                                        <div class="rm-role-permission-children" data-role-dependent-actions>
                                            @if ($permissionRow['edit'])
                                                <label class="rm-toggle-line rm-role-permission-child">
                                                    <span>{{ $permissionRow['edit_label'] ?? 'Editar' }}</span>
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
                                    <label class="rm-toggle-line rm-config-permission-switch">
                                        <span>{{ $permissionLabel }}</span>
                                        <input class="rm-switch" type="checkbox" name="perm_{{ $permissionKey }}" value="1" @checked(!empty($selectedUserPermissions[$permissionKey]))>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    </form>

                    <div class="rm-inline-actions is-two">
                        <button class="btn-nova btn-nova-secondary" type="button" data-user-permission-cancel hidden>
                            <span class="btn-nova-icon"><i class="bi bi-x-lg"></i></span>
                            <span>Cancelar</span>
                        </button>
                        <button class="btn-nova btn-nova-primary" type="submit" form="rm-config-form-usuarios-permisos">
                            <span class="btn-nova-icon"><i class="bi bi-save"></i></span>
                            <span>Guardar</span>
                        </button>
                    </div>
                </div>
            </div>
        @else
            <div class="nova-empty-state">Sin usuarios activos registrados.</div>
        @endif
    </section>
@endif

@if ($activePanel === 'categorias')
    <section class="card nova-card rm-panel rm-config-feature-form">
        <div class="rm-feature-head">
            <span class="rm-feature-head-icon is-cyan"><i class="bi bi-tags"></i></span>
            <div>
                <small>Catalogo compartido</small>
                <h2>Categorias</h2>
                <p>Catalogo sincronizado desde Redmine para clasificar reportes TIC.</p>
            </div>
            <div class="rm-feature-meter">
                <strong>{{ count($categories ?? []) }}</strong>
                <span>registros</span>
            </div>
        </div>
        @include('redmine_tic::native-sections.categories', ['categories' => $categories ?? []])
    </section>
@endif

@if ($activePanel === 'unidades')
    <section class="card nova-card rm-panel rm-config-feature-form">
        <div class="rm-feature-head">
            <span class="rm-feature-head-icon is-green"><i class="bi bi-building"></i></span>
            <div>
                <small>Catalogo organizacional</small>
                <h2>Unidades</h2>
                <p>Unidades sincronizadas desde Redmine para completar reportes y estadisticas.</p>
            </div>
            <div class="rm-feature-meter">
                <strong>{{ count($units ?? []) }}</strong>
                <span>registros</span>
            </div>
        </div>
        @include('redmine_tic::native-sections.units', ['units' => $units ?? []])
    </section>
@endif

    </main>
</div>

<script>
    const closeConfigDrawer = (drawer) => {
        if (!drawer) return;

        const instance = window.bootstrap?.Offcanvas?.getInstance(drawer);
        if (instance) {
            try { instance.hide(); } catch (error) {}
        }

        window.setTimeout(() => {
            drawer.classList.remove('show', 'showing', 'hiding');
            drawer.setAttribute('aria-hidden', 'true');
            drawer.removeAttribute('aria-modal');
            drawer.removeAttribute('role');
            drawer.style.visibility = '';
            drawer.style.transform = '';
            drawer.style.zIndex = drawer.dataset.previousZIndex || '';
            delete drawer.dataset.previousZIndex;

            document.querySelectorAll('.offcanvas-backdrop').forEach((backdrop) => backdrop.remove());
            if (!document.querySelector('.modal.show')) {
                document.querySelectorAll('.modal-backdrop').forEach((backdrop) => backdrop.remove());
                document.body.classList.remove('modal-open', 'rm-confirm-open');
            }
            if (!document.querySelector('.offcanvas.show')) {
                document.body.classList.remove('offcanvas-backdrop');
            }
            document.body.style.removeProperty('overflow');
            document.body.style.removeProperty('padding-right');

            drawer.dispatchEvent(new Event('hidden.bs.offcanvas'));
        }, 0);
    };

    document.addEventListener('click', (event) => {
        const closeButton = event.target.closest('.rm-config-edit-drawer .btn-close');
        if (!closeButton) return;

        const drawer = closeButton.closest('.rm-config-edit-drawer');
        if (!drawer) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        closeConfigDrawer(drawer);
    }, true);

    document.querySelectorAll('.rm-config-edit-drawer').forEach((drawer) => {
        const forms = Array.from(drawer.querySelectorAll('[data-restore-on-close]'));
        const fieldsFor = (form) => Array.from(form.querySelectorAll('input:not([type="hidden"]), textarea, select'));
        const snapshot = () => {
            forms.forEach((form) => {
                fieldsFor(form).forEach((field) => {
                    if (field.type === 'checkbox' || field.type === 'radio') {
                        field.dataset.originalChecked = field.checked ? '1' : '0';
                    } else {
                        field.dataset.originalValue = field.value;
                    }
                });
            });
        };
        const restore = () => {
            forms.forEach((form) => {
                fieldsFor(form).forEach((field) => {
                    if (field.type === 'checkbox' || field.type === 'radio') {
                        field.checked = field.dataset.originalChecked === '1';
                    } else {
                        field.value = Object.prototype.hasOwnProperty.call(field.dataset, 'originalValue') ? field.dataset.originalValue : '';
                    }
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });
        };

        drawer.addEventListener('show.bs.offcanvas', snapshot);
        drawer.addEventListener('hidden.bs.offcanvas', restore);
    });

    document.querySelectorAll('[data-clear-drawer-form]').forEach((button) => {
        button.addEventListener('click', () => {
            const form = document.querySelector(button.dataset.clearDrawerForm || '');
            if (!form) return;

            const clearForm = () => {
                const preserveNames = (button.dataset.clearPreserve || '').split(',').map((name) => name.trim()).filter(Boolean);
                const preservedValues = {};
                preserveNames.forEach((name) => {
                    const field = form.elements[name];
                    if (field) preservedValues[name] = field.value;
                });

                form.querySelectorAll('input:not([type="hidden"]), textarea, select').forEach((field) => {
                    if (preserveNames.includes(field.name)) {
                        return;
                    }
                    if (field.type === 'checkbox' || field.type === 'radio') {
                        field.checked = false;
                    } else if (field.tagName === 'SELECT') {
                        field.selectedIndex = 0;
                    } else {
                        field.value = '';
                    }
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                });
                Object.entries(preservedValues).forEach(([name, value]) => {
                    const field = form.elements[name];
                    if (!field) return;
                    field.value = value;
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                });
            };

            const message = button.dataset.clearConfirm || 'Eliminar los valores de este formulario?';
            if (window.appUi?.confirmAction) {
                window.appUi.confirmAction(message, clearForm, { title: 'Confirmar eliminacion' });
                return;
            }

            if (!window.confirm(message)) return;
            clearForm();
        });
    });
</script>

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
        const userPermissionForm = document.querySelector('[data-user-permission-form]');
        const applyRolePermissionsInput = document.querySelector('[data-apply-role-permissions]');
        const userPermissionCancel = document.querySelector('[data-user-permission-cancel]');
        const rolePermissionScopes = [
            { input: 'perm_mensajes_scope', key: 'mensajes' },
            { input: 'perm_horas_scope', key: 'horas_extra' },
            { input: 'perm_historico_scope', key: 'historico_scope' },
        ];
        const ignoredActivePermissionKeys = ['mensajes', 'horas_extra', 'historico_scope'];
        const userPermissionState = () => {
            if (!userPermissionForm) return '';
            const data = new FormData(userPermissionForm);
            data.delete('_token');
            data.delete('config_action');
            return new URLSearchParams(data).toString();
        };
        const initialUserPermissionState = userPermissionState();
        const syncUserPermissionDirty = () => {
            if (userPermissionCancel) {
                userPermissionCancel.hidden = userPermissionState() === initialUserPermissionState;
            }
        };

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
            document.querySelectorAll('[data-user-active-count]').forEach((countTarget) => {
                countTarget.textContent = String(count);
            });

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
                if (applyRolePermissionsInput) applyRolePermissionsInput.value = '1';
                syncUserPermissionDirty();
            }
        });

        document.querySelector('[data-user-role-select]')?.addEventListener('change', (event) => {
            const roleName = event.target?.value || '';
            if (rolePermissionPayloads[roleName]) {
                applyPermissionPayload(rolePermissionPayloads[roleName]);
                if (applyRolePermissionsInput) applyRolePermissionsInput.value = '1';
                syncUserPermissionDirty();
            }
        });

        document.querySelector('[data-reset-user-permissions]')?.addEventListener('click', () => {
            const roleSelect = document.querySelector('[data-user-role-select]');
            if (roleSelect) {
                roleSelect.value = roleSelect.dataset.currentUserRole || roleSelect.value;
            }
            applyPermissionPayload(currentUserPermissionPayload);
            if (applyRolePermissionsInput) applyRolePermissionsInput.value = '0';
            syncUserPermissionDirty();
        });

        userPermissionForm?.querySelectorAll('input, select').forEach((field) => {
            field.addEventListener('change', () => {
                if (field.name.startsWith('perm_') && applyRolePermissionsInput) applyRolePermissionsInput.value = '0';
                syncUserPermissionDirty();
            });
        });
        userPermissionForm?.addEventListener('input', syncUserPermissionDirty);
        userPermissionCancel?.addEventListener('click', () => {
            const roleSelect = document.querySelector('[data-user-role-select]');
            if (roleSelect) roleSelect.value = roleSelect.dataset.currentUserRole || roleSelect.value;
            applyPermissionPayload(currentUserPermissionPayload);
            if (applyRolePermissionsInput) applyRolePermissionsInput.value = '0';
            syncUserPermissionDirty();
        });

        const userPicker = document.querySelector('[data-user-permission-picker]');
        const userSearch = userPicker?.querySelector('[data-user-permission-search]');
        const userIdInput = userPicker?.querySelector('[data-user-permission-id]');
        const userOptions = Array.from(document.querySelectorAll('#rm-user-permission-options option'));
        const selectedUserId = @json($selectedUserId);
        const findUserOption = () => userOptions.find((option) => option.value === userSearch?.value);
        const syncUserPicker = () => {
            if (!userSearch || !userIdInput) return null;
            const option = findUserOption();
            userSearch.setCustomValidity('');
            if (option) {
                userIdInput.value = option.dataset.userId || '';
            }
            return option;
        };

        userSearch?.addEventListener('input', syncUserPicker);
        userSearch?.addEventListener('change', () => {
            const option = syncUserPicker();
            const nextUserId = option?.dataset.userId || '';
            if (nextUserId !== '' && nextUserId !== selectedUserId) {
                userPicker?.requestSubmit();
            }
        });
        userPicker?.addEventListener('submit', (event) => {
            const option = syncUserPicker();
            if (!option || !userSearch || !userIdInput) {
                event.preventDefault();
                userSearch?.setCustomValidity('Selecciona un usuario de la lista.');
                userSearch?.reportValidity();
                return;
            }
            userIdInput.value = option.dataset.userId || '';
        });

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
                control.disabled = control.hasAttribute('data-permission-locked') || !access.checked;
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
            panel.classList.toggle('is-disabled', !access.checked);
            panel.setAttribute('aria-disabled', access.checked ? 'false' : 'true');
            panel.querySelectorAll('input, select, button').forEach((control) => {
                if (!access.checked && control instanceof HTMLInputElement && control.type === 'checkbox') {
                    control.checked = false;
                }
                control.disabled = !access.checked;
            });
        };
        syncConfigDependentPanel();
        const roleForm = document.getElementById('rm-config-form-roles');
        const roleCancel = document.querySelector('[data-role-cancel]');
        if (roleForm && roleCancel) {
            const roleState = () => {
                const data = new FormData(roleForm);
                data.delete('_token');
                data.delete('config_action');
                return new URLSearchParams(data).toString();
            };
            const initialRoleState = roleState();
            const syncRoleDirty = () => {
                roleCancel.hidden = roleState() === initialRoleState;
            };
            roleForm.addEventListener('input', syncRoleDirty);
            roleForm.addEventListener('change', syncRoleDirty);
            roleCancel.addEventListener('click', () => {
                roleForm.reset();
                document.querySelectorAll('.rm-role-permission-item').forEach(syncRoleDependentActions);
                syncConfigDependentPanel();
                roleCancel.hidden = true;
            });
        }
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

@php $redmineTicConfigCssVersion = @filemtime(public_path('assets/redmine-tic-config.css')) ?: '1'; @endphp
<link href="{{ asset('assets/redmine-tic-config.css') }}?v={{ $redmineTicConfigCssVersion }}" rel="stylesheet">
