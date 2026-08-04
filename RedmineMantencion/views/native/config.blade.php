@extends('redmine_mantencion::native.layout')

@push('styles')
    @php
        $configCss = base_path('RedmineMantencion/assets/css/configuracion.css');
    @endphp
    <link rel="stylesheet" href="{{ url('/redmine-mantencion/assets/css/configuracion.css') }}?v={{ @filemtime($configCss) ?: 1 }}">
@endpush

@section('content')
@php
    $allowed = static fn (string $key): bool => !empty($permissions[$key]) || !empty($permissions['all']);
    $canSettings = $allowed('configuracion');
    $canRoles = $allowed('cfg_roles');
    $optionGroups = [
        'tracker' => ['label' => 'Trackers', 'key' => 'trackers', 'permission' => 'cfg_trackers'],
        'prioridad' => ['label' => 'Prioridades', 'key' => 'prioridades', 'permission' => 'cfg_prioridades'],
        'estado' => ['label' => 'Estados', 'key' => 'estados', 'permission' => 'cfg_estados'],
    ];
@endphp

<div class="container-fluid py-4">
    @include('redmine_mantencion::native.partials.hero', [
        'icon' => 'bi-gear-wide-connected',
        'title' => 'Configuración de Redmine',
        'subtitle' => 'Administra conexión, proyecto, tiempos y listas maestras.',
    ])

    @if (session('mantencion_status'))
        <div data-nova-flash="{{ session('mantencion_status_type', 'success') }}" data-nova-flash-message="{{ session('mantencion_status') }}" hidden></div>
    @endif

    <div class="rm-config-shell rm-maint-config-shell">
        <aside class="rm-config-rail">
            <div class="rm-config-rail-head"><span><i class="bi bi-gear-wide-connected"></i></span><div><small>Redmine Mantención</small><strong>Configuración</strong></div></div>
            <nav class="rm-config-nav" aria-label="Opciones de configuración">
                <a class="rm-config-nav-link active" href="#config-summary"><i class="bi bi-speedometer2"></i><span>Resumen</span><i class="bi bi-chevron-right rm-config-nav-chevron"></i></a>
                @if($canSettings)<a class="rm-config-nav-link" href="#config-connection"><i class="bi bi-plug"></i><span>Conexión y proyecto</span><i class="bi bi-chevron-right rm-config-nav-chevron"></i></a>@endif
                @foreach($optionGroups as $group)<a class="rm-config-nav-link" href="#config-{{ $group['key'] }}"><i class="bi bi-list-check"></i><span>{{ $group['label'] }}</span><i class="bi bi-chevron-right rm-config-nav-chevron"></i></a>@endforeach
                <a class="rm-config-nav-link" href="#config-catalogs"><i class="bi bi-tags"></i><span>Catálogos</span><i class="bi bi-chevron-right rm-config-nav-chevron"></i></a>
                @if($canRoles)<a class="rm-config-nav-link" href="#config-roles"><i class="bi bi-shield-check"></i><span>Roles y permisos</span><i class="bi bi-chevron-right rm-config-nav-chevron"></i></a>@endif
                @if($allowed('cfg_conexion'))<a class="rm-config-nav-link" href="#config-nextcloud"><i class="bi bi-cloud"></i><span>Nextcloud</span><i class="bi bi-chevron-right rm-config-nav-chevron"></i></a>@endif
            </nav>
        </aside>
        <main class="rm-config-content">
            <section class="rm-config-summary" id="config-summary">
                <div class="rm-config-summary-kpis">
                    <article class="rm-summary-kpi"><span class="is-blue"><i class="bi bi-kanban"></i></span><div><small>Proyecto</small><strong>{{ $config['project_name'] ?? 'Sin definir' }}</strong></div></article>
                    <article class="rm-summary-kpi"><span class="is-cyan"><i class="bi bi-diagram-3"></i></span><div><small>Trackers</small><strong>{{ count($config['trackers'] ?? []) }}</strong></div></article>
                    <article class="rm-summary-kpi"><span class="is-green"><i class="bi bi-tags"></i></span><div><small>Categorías</small><strong>{{ count($categories) }}</strong></div></article>
                    <article class="rm-summary-kpi"><span class="is-orange"><i class="bi bi-stopwatch"></i></span><div><small>Retención</small><strong>{{ $config['retencion_horas'] ?? 24 }} h</strong></div></article>
                </div>
            </section>

    <div class="row g-3" id="config-connection">
        @if ($canSettings)
            <div class="col-xl-7">
                <section class="nova-system-card">
                    <h2 class="h5 mb-3">Conexiones y proyecto</h2>
                    <form method="POST" action="{{ route('redmine.mantencion.config.action') }}" class="row g-3">
                        @csrf
                        <input type="hidden" name="action" value="save_settings">
                        <div class="col-12"><label class="form-label">URL API Redmine</label><input class="form-control" name="platform_url" value="{{ $config['platform_url'] ?? '' }}" placeholder="https://redmine.example/issues.json"></div>
                        <div class="col-md-4"><label class="form-label">ID proyecto</label><input class="form-control" name="project_id" value="{{ $config['project_id'] ?? '' }}"></div>
                        <div class="col-md-8"><label class="form-label">Nombre proyecto</label><input class="form-control" name="project_name" value="{{ $config['project_name'] ?? '' }}"></div>
                        <div class="col-12"><label class="form-label">URL miembros Redmine</label><input class="form-control" name="users_members_url" value="{{ $config['users_members_url'] ?? '' }}"></div>
                        <div class="col-md-6"><label class="form-label">Retención (horas)</label><input type="number" min="1" class="form-control" name="retencion_horas" value="{{ $config['retencion_horas'] ?? 24 }}"></div>
                        <div class="col-md-6"><label class="form-label">Tiempo hora extra</label><input class="form-control" name="hora_extra_tiempo_estimado" value="{{ $config['hora_extra_tiempo_estimado'] ?? 1 }}"></div>
                        <div class="col-12"><hr class="my-1"></div>
                        <div class="col-12 form-check ms-2"><input type="hidden" name="core_enabled" value="0"><input class="form-check-input" type="checkbox" name="core_enabled" value="1" id="core-enabled" @checked(!empty($config['core_enabled']))><label class="form-check-label" for="core-enabled">Integración CORE habilitada</label></div>
                        <div class="col-12"><label class="form-label">URL administración CORE</label><input class="form-control" name="core_admin_url" value="{{ $config['core_admin_url'] ?? '' }}"></div>
                        <div class="col-12"><label class="form-label">URL histórico CORE</label><input class="form-control" name="core_historico_url" value="{{ $config['core_historico_url'] ?? '' }}"></div>
                        <div class="col-md-8"><label class="form-label">URL Nextcloud</label><input class="form-control" name="nextcloud_url" value="{{ $config['nextcloud_url'] ?? '' }}"></div>
                        <div class="col-md-4"><label class="form-label">Usuario administrador</label><input class="form-control" name="nextcloud_admin_user" value="{{ $config['nextcloud_admin_user'] ?? '' }}"></div>
                        <div class="col-12"><button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Guardar configuración</button></div>
                    </form>
                </section>
            </div>
            <div class="col-xl-5">
                <section class="nova-system-card">
                    <h2 class="h5 mb-3">Modo mantención</h2>
                    <form method="POST" action="{{ route('redmine.mantencion.config.action') }}" class="row g-3">
                        @csrf
                        <input type="hidden" name="action" value="maintenance_settings">
                        <input type="hidden" name="maintenance_mode" value="0">
                        <div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" name="maintenance_mode" value="1" id="maintenance-mode" @checked(!empty($config['maintenance_mode']))><label class="form-check-label" for="maintenance-mode">Bloquear operaciones</label></div>
                        <div class="col-12"><label class="form-label">Hasta</label><input type="datetime-local" class="form-control" name="maintenance_until" value="{{ $config['maintenance_until'] ?? '' }}"></div>
                        <div><button class="btn btn-warning" type="submit"><i class="bi bi-tools"></i> Actualizar</button></div>
                    </form>
                </section>
            </div>
        @endif
    </div>

    <div class="row g-3 mt-0">
        @foreach ($optionGroups as $type => $group)
            @if ($allowed($group['permission']))
                <div class="col-xl-4">
                    <section class="nova-system-card h-100" id="config-{{ $group['key'] }}">
                        <h2 class="h5 mb-3">{{ $group['label'] }}</h2>
                        <div class="vstack gap-2 mb-3">
                            @forelse ($config[$group['key']] ?? [] as $option)
                                <div class="border rounded-3 p-2">
                                    <form method="POST" action="{{ route('redmine.mantencion.config.action') }}" class="row g-2 align-items-center">
                                        @csrf
                                        <input type="hidden" name="action" value="option_update">
                                        <input type="hidden" name="type" value="{{ $type }}">
                                        <input type="hidden" name="original_id" value="{{ $option['id'] ?? '' }}">
                                        <div class="col-3"><input class="form-control form-control-sm" name="external_id" value="{{ $option['id'] ?? '' }}" required aria-label="ID"></div>
                                        <div class="col"><input class="form-control form-control-sm" name="name" value="{{ $option['nombre'] ?? '' }}" required aria-label="Nombre"></div>
                                        <div class="col-auto"><label class="form-check mb-0" title="Predeterminado"><input type="hidden" name="default" value="0"><input class="form-check-input" type="checkbox" name="default" value="1" @checked(!empty($option['default']))></label></div>
                                        <div class="col-auto"><button class="btn btn-sm btn-outline-primary" title="Guardar"><i class="bi bi-check2"></i></button></div>
                                    </form>
                                    <form method="POST" action="{{ route('redmine.mantencion.config.action') }}" class="mt-1" data-app-confirm="¿Eliminar esta opción?">
                                        @csrf
                                        <input type="hidden" name="action" value="option_delete"><input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="external_id" value="{{ $option['id'] ?? '' }}">
                                        <button class="btn btn-sm btn-link text-danger p-0" type="submit"><i class="bi bi-trash"></i> Eliminar</button>
                                    </form>
                                </div>
                            @empty
                                <p class="text-muted small">No hay opciones configuradas.</p>
                            @endforelse
                        </div>
                        <form method="POST" action="{{ route('redmine.mantencion.config.action') }}" class="row g-2">
                            @csrf
                            <input type="hidden" name="action" value="option_create"><input type="hidden" name="type" value="{{ $type }}">
                            <div class="col-3"><input class="form-control" name="external_id" placeholder="ID" required></div>
                            <div class="col"><input class="form-control" name="name" placeholder="Nombre" required></div>
                            <div class="col-auto d-flex align-items-center"><label class="form-check" title="Predeterminado"><input class="form-check-input" type="checkbox" name="default" value="1"></label></div>
                            <div class="col-auto"><button class="btn btn-outline-primary" type="submit" title="Agregar"><i class="bi bi-plus"></i></button></div>
                        </form>
                    </section>
                </div>
            @endif
        @endforeach
    </div>

    <div class="row g-3 mt-0" id="config-catalogs">
        @foreach ([
            ['type' => 'categoria', 'label' => 'Categorías', 'items' => $categories, 'permission' => 'cfg_categorias'],
            ['type' => 'unidad', 'label' => 'Unidades', 'items' => $units, 'permission' => 'cfg_unidades'],
        ] as $catalog)
            @if ($allowed($catalog['permission']))
                <div class="col-xl-6">
                    <section class="nova-system-card h-100">
                        <h2 class="h5 mb-3">{{ $catalog['label'] }}</h2>
                        <div class="vstack gap-2 mb-3">
                            @forelse ($catalog['items'] as $item)
                                <div class="d-flex gap-2 align-items-center">
                                    <form method="POST" action="{{ route('redmine.mantencion.config.action') }}" class="row g-2 flex-grow-1">
                                        @csrf
                                        <input type="hidden" name="action" value="catalog_save"><input type="hidden" name="catalog" value="{{ $catalog['type'] }}"><input type="hidden" name="catalog_id" value="{{ $item['id'] }}">
                                        <div class="col-3"><input class="form-control form-control-sm" value="{{ $item['id'] }}" disabled aria-label="ID"></div>
                                        <div class="col"><input class="form-control form-control-sm" name="name" value="{{ $item['nombre'] }}" required></div>
                                        <div class="col-auto"><button class="btn btn-sm btn-outline-primary" title="Guardar"><i class="bi bi-check2"></i></button></div>
                                    </form>
                                    <form method="POST" action="{{ route('redmine.mantencion.config.action') }}" data-app-confirm="¿Desactivar {{ mb_strtolower($catalog['label']) }}?">
                                        @csrf
                                        <input type="hidden" name="action" value="catalog_delete"><input type="hidden" name="catalog" value="{{ $catalog['type'] }}"><input type="hidden" name="catalog_id" value="{{ $item['id'] }}">
                                        <button class="btn btn-sm btn-outline-danger" title="Desactivar"><i class="bi bi-trash"></i></button>
                                    </form>
                                </div>
                            @empty
                                <p class="text-muted small">No hay registros activos.</p>
                            @endforelse
                        </div>
                        <form method="POST" action="{{ route('redmine.mantencion.config.action') }}" class="row g-2">
                            @csrf
                            <input type="hidden" name="action" value="catalog_save"><input type="hidden" name="catalog" value="{{ $catalog['type'] }}">
                            <div class="col-3"><input class="form-control" name="catalog_id" placeholder="ID" required></div>
                            <div class="col"><input class="form-control" name="name" placeholder="Nombre" required></div>
                            <div class="col-auto"><button class="btn btn-outline-primary" title="Agregar"><i class="bi bi-plus"></i></button></div>
                        </form>
                    </section>
                </div>
            @endif
        @endforeach
    </div>

    @if ($canRoles)
        <section class="nova-system-card mt-3" id="config-roles">
            <h2 class="h5 mb-3">Permisos por rol</h2>
            <ul class="nav nav-tabs mb-3" role="tablist">
                @foreach ($roles as $role)<li class="nav-item"><button class="nav-link @if($loop->first) active @endif" data-bs-toggle="tab" data-bs-target="#role-{{ $loop->index }}" type="button">{{ ucfirst($role) }}</button></li>@endforeach
            </ul>
            <div class="tab-content">
                @foreach ($roles as $role)
                    <div class="tab-pane fade @if($loop->first) show active @endif" id="role-{{ $loop->index }}">
                        <form method="POST" action="{{ route('redmine.mantencion.config.action') }}">
                            @csrf
                            <input type="hidden" name="action" value="save_role_permissions"><input type="hidden" name="role" value="{{ $role }}">
                            <div class="row g-2">
                                @foreach ($permissionKeys as $key)
                                    @php
                                        $value = $rolePermissions[$role][$key] ?? '';
                                    @endphp
                                    <div class="col-md-4 col-lg-3">
                                        @if (in_array($key, ['mensajes', 'horas_extra', 'historico_scope'], true))
                                            <label class="form-label small mb-1">{{ str_replace('_', ' ', $key) }}</label>
                                            <select class="form-select form-select-sm" name="permissions[{{ $key }}]" @disabled($role === 'root')><option value="asignados" @selected($value === 'asignados')>Asignados</option><option value="todos" @selected($value === 'todos')>Todos</option></select>
                                        @else
                                            <label class="form-check"><input type="checkbox" class="form-check-input" name="permissions[{{ $key }}]" value="1" @checked($value === '1') @disabled($role === 'root')><span class="form-check-label">{{ str_replace('_', ' ', $key) }}</span></label>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            @if ($role !== 'root')<button class="btn btn-primary mt-3" type="submit">Guardar permisos</button>@endif
                        </form>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($allowed('cfg_conexion'))
        <section class="nova-system-card mt-3" id="config-nextcloud">
            <h2 class="h5 mb-3">Credencial administrativa Nextcloud</h2>
            <form method="POST" action="{{ route('redmine.mantencion.config.action') }}" class="row g-3 align-items-end">
                @csrf
                <input type="hidden" name="action" value="save_nextcloud_secret">
                <div class="col-md-9"><label class="form-label">Contraseña</label><input type="password" class="form-control" name="nextcloud_admin_pass" autocomplete="new-password" placeholder="La credencial actual nunca se muestra" required><div class="form-text">Se almacena cifrada con la clave de la aplicación.</div></div>
                <div class="col-md-3"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-key"></i> Guardar</button></div>
            </form>
        </section>
    @endif
        </main>
    </div>
</div>
@endsection

@push('scripts')
<script>(()=>{document.querySelectorAll('.rm-config-nav-link').forEach(link=>link.addEventListener('click',()=>document.querySelectorAll('.rm-config-nav-link').forEach(item=>item.classList.toggle('active',item===link))));})();</script>
@endpush
