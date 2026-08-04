@extends('redmine_mantencion::native.layout')

@push('styles')
    @php
        $manualCss = base_path('RedmineMantencion/assets/css/pendiente-manual.css');
    @endphp
    <link rel="stylesheet" href="{{ url('/redmine-mantencion/assets/css/pendiente-manual.css') }}?v={{ @filemtime($manualCss) ?: 1 }}">
@endpush

@section('content')
@php
    $value = static fn (string $key, mixed $fallback = ''): mixed => old($key, $defaults[$key] ?? $fallback);
    $categoryNames = collect($categories)->pluck('nombre')->filter()->values();
@endphp
<div class="container-fluid py-4">
    @include('redmine_mantencion::native.partials.hero', [
        'icon' => 'bi-pencil-square',
        'title' => 'Pendiente manual',
        'subtitle' => 'Formulario manual con la misma estructura operativa del ticket en Redmine.',
    ])

    @if (session('mantencion_status'))
        <div data-nova-flash="{{ session('mantencion_status_type', 'success') }}" data-nova-flash-message="{{ session('mantencion_status') }}" hidden></div>
    @endif
    @if ($errors->any())
        <div data-nova-flash="danger" data-nova-flash-message="{{ $errors->first() }}" hidden></div>
    @endif

    <div class="card manual-card">
        <div class="card-body manual-grid">
            <form method="POST" action="{{ route('redmine.mantencion.manual.store') }}" class="d-flex flex-column gap-3">
                @csrf
                <input type="hidden" name="project_id" value="{{ $config['project_id'] }}">

                <div class="field-row single">
                    <div class="field-label">Proyecto *</div>
                    <div>
                        <select class="form-select" disabled>
                            <option selected>&raquo; {{ $config['project_name'] }}</option>
                        </select>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field-label">Tipo *</div>
                    <div>
                        <select name="tracker_id" class="form-select" required>
                            @foreach ($config['trackers'] as $tracker)
                                <option value="{{ $tracker['id'] ?? '' }}" @selected((string) $value('tracker_id') === (string) ($tracker['id'] ?? ''))>{{ $tracker['nombre'] ?? '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div></div><div></div>
                </div>

                <div class="field-row single">
                    <div class="field-label">Asunto *</div>
                    <div><input name="asunto" class="form-control" value="{{ old('asunto') }}" maxlength="255" required></div>
                </div>

                <div class="field-row single">
                    <div class="field-label">Descripción</div>
                    <div>
                        <div class="toolbar manual-description-tabs" role="tablist" aria-label="Vista de descripción">
                            <button type="button" class="manual-description-tab is-active" id="description-edit-tab" role="tab" aria-selected="true">Modificar</button>
                            <button type="button" class="manual-description-tab" id="description-preview-tab" role="tab" aria-selected="false">Previsualizar</button>
                        </div>
                        <textarea name="descripcion" id="manual-descripcion" class="form-control editor" aria-labelledby="description-edit-tab">{{ old('descripcion') }}</textarea>
                        <div class="manual-description-preview" id="manual-description-preview" role="tabpanel" aria-labelledby="description-preview-tab" hidden></div>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field-label">Estado *</div>
                    <div>
                        <select name="status_id" class="form-select">
                            @foreach ($config['estados'] as $status)
                                <option value="{{ $status['id'] ?? '' }}" @selected((string) $value('status_id') === (string) ($status['id'] ?? ''))>{{ $status['nombre'] ?? '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field-label">Fecha de inicio</div>
                    <div><input type="date" name="fecha_inicio" id="manual-fecha-inicio" class="form-control" value="{{ $value('fecha_inicio') }}"></div>
                </div>

                <div class="field-row">
                    <div class="field-label">Prioridad *</div>
                    <div>
                        <select name="priority_id" class="form-select">
                            @foreach ($config['prioridades'] as $priority)
                                <option value="{{ $priority['id'] ?? '' }}" @selected((string) $value('priority_id') === (string) ($priority['id'] ?? ''))>{{ $priority['nombre'] ?? '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="field-label">Fecha fin</div>
                    <div><input type="date" name="fecha_fin" id="manual-fecha-fin" class="form-control" value="{{ $value('fecha_fin') }}"></div>
                </div>

                <div class="field-row">
                    <div class="field-label">Asignado a</div>
                    <div>
                        <div class="d-flex align-items-center gap-2">
                            @if ($can_assign_others)
                                <select name="asignado_a" id="asignado_a" class="form-select">
                                    @foreach ($users as $user)
                                        <option value="{{ $user['id'] }}" @selected((string) $value('asignado_a') === (string) $user['id'])>{{ $user['nombre'] }}</option>
                                    @endforeach
                                </select>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="assign-me" data-assign-me-id="{{ $current_user_id }}" @disabled($current_user_id === '')>Asignarme</button>
                            @else
                                <input type="text" class="form-control" value="{{ $current_user_name }}" readonly>
                                <input type="hidden" name="asignado_a" value="{{ $current_user_id }}">
                            @endif
                        </div>
                    </div>
                    <div class="field-label">Tiempo estimado</div>
                    <div>
                        <div class="input-group">
                            <input name="tiempo_estimado" class="form-control" inputmode="decimal" value="{{ old('tiempo_estimado') }}">
                            <span class="input-group-text">Horas</span>
                        </div>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field-label">Categoría</div>
                    <div>
                        <div class="nova-search-select" data-search-select data-options='@json($categoryNames)'>
                            <input name="categoria" id="manual-categoria" class="form-control" autocomplete="off" placeholder="Buscar categoría" value="{{ old('categoria') }}" data-search-select-input>
                            <div class="nova-search-select__menu" role="listbox" data-search-select-menu hidden></div>
                        </div>
                    </div>
                    <div></div><div></div>
                </div>

                <div class="field-row single">
                    <div class="field-label">Solicitante *</div>
                    <div><input name="solicitante" class="form-control" placeholder="Persona que solicita la actividad" value="{{ old('solicitante') }}" maxlength="255" required></div>
                </div>

                <div class="field-row">
                    <div class="field-label">Anexo</div>
                    <div><input name="anexo" class="form-control" placeholder="Número telefónico de contacto" value="{{ old('anexo') }}"></div>
                    <div class="field-label">Correo</div>
                    <div><input name="core_email" type="email" class="form-control" placeholder="Correo electrónico" value="{{ old('core_email') }}"></div>
                </div>

                <div class="field-row">
                    <div class="field-label">Unidad</div>
                    <div><input name="unidad" class="form-control" placeholder="Lugar donde realizar la actividad" value="{{ old('unidad') }}"></div>
                    <div class="field-label">Hora Extra *</div>
                    <div>
                        <select name="hora_extra" class="form-select">
                            <option value="0" @selected((string) $value('hora_extra', '0') === '0')>No</option>
                            <option value="1" @selected((string) $value('hora_extra', '0') === '1')>Sí</option>
                        </select>
                    </div>
                </div>

                <div class="rm-manual-actions">
                    <button class="btn-nova btn-nova-success" type="submit" @disabled(!empty($context['maintenance']['enabled']))><i class="bi bi-plus-circle"></i> Crear pendiente</button>
                    <a class="btn btn-outline-secondary" href="{{ route('redmine.mantencion.dashboard') }}"><i class="bi bi-inboxes"></i> Ver pendientes</a>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    window.NovaSearchSelect?.init(document);
    const start = document.getElementById('manual-fecha-inicio');
    const end = document.getElementById('manual-fecha-fin');
    const syncDates = () => { if (start && end) end.value = start.value; };
    start?.addEventListener('change', syncDates);

    const description = document.getElementById('manual-descripcion');
    const preview = document.getElementById('manual-description-preview');
    const editTab = document.getElementById('description-edit-tab');
    const previewTab = document.getElementById('description-preview-tab');
    const showPreview = enabled => {
        if (!description || !preview) return;
        if (enabled) {
            preview.textContent = description.value.trim() || 'Sin descripción.';
        }
        description.hidden = enabled;
        preview.hidden = !enabled;
        editTab?.classList.toggle('is-active', !enabled);
        previewTab?.classList.toggle('is-active', enabled);
        editTab?.setAttribute('aria-selected', enabled ? 'false' : 'true');
        previewTab?.setAttribute('aria-selected', enabled ? 'true' : 'false');
    };
    editTab?.addEventListener('click', () => showPreview(false));
    previewTab?.addEventListener('click', () => showPreview(true));

    document.getElementById('assign-me')?.addEventListener('click', event => {
        const select = document.getElementById('asignado_a');
        const userId = event.currentTarget.dataset.assignMeId || '';
        if (select && userId) select.value = userId;
    });
})();
</script>
@endpush
