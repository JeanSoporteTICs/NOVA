@extends('redmine_mantencion::native.layout')

@push('styles')
    @php
        $nextcloudUsersCss = base_path('RedmineMantencion/assets/css/nextcloud-usuarios.css');
        $nextcloudHistoryCss = base_path('RedmineMantencion/assets/css/nextcloud-historial.css');
    @endphp
    <link rel="stylesheet" href="{{ url('/redmine-mantencion/assets/css/nextcloud-usuarios.css') }}?v={{ @filemtime($nextcloudUsersCss) ?: 1 }}">
    <link rel="stylesheet" href="{{ url('/redmine-mantencion/assets/css/nextcloud-historial.css') }}?v={{ @filemtime($nextcloudHistoryCss) ?: 1 }}">
@endpush

@section('content')
<div class="container-fluid py-4">
    @include('redmine_mantencion::native.partials.hero', [
        'icon' => 'bi-clock-history',
        'title' => 'Historial Nextcloud',
        'subtitle' => 'Registro permanente de los lotes de usuarios procesados en Nextcloud.',
        'badges' => [['icon' => 'bi-clock-history', 'label' => count($batches).' lote(s) registrado(s)']],
    ])
    @if(session('mantencion_status'))<div data-nova-flash="{{ session('mantencion_status_type','success') }}" data-nova-flash-message="{{ session('mantencion_status') }}" hidden></div>@endif
    @if($errors->any())<div data-nova-flash="danger" data-nova-flash-message="{{ $errors->first() }}" hidden></div>@endif

    @if($batches !== [])
        <section class="card nextcloud-panel mb-3" aria-label="Filtros del historial"><div class="card-body p-3"><label class="form-label" for="nextcloud-history-search"><i class="bi bi-search"></i> Buscar</label><input class="form-control" id="nextcloud-history-search" type="search" placeholder="Fecha, lote o resultado" autocomplete="off"><div class="text-muted small mt-2" id="nextcloud-history-count">{{ count($batches) }} lote(s)</div></div></section>
    @endif

    @forelse($batches as $batch)
        <section class="card nextcloud-panel mb-3" data-history-batch data-search="{{ mb_strtolower(implode(' ', [$batch['created_at_cl'] ?? '', $batch['legacy_id'] ?? '', $batch['id'] ?? '', $batch['total'] ?? '', $batch['creados'] ?? '', $batch['fallidos'] ?? '']), 'UTF-8') }}">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3"><div><h2 class="h5 mb-1">Lote {{ $batch['legacy_id'] ?: '#'.$batch['id'] }}</h2><div class="text-muted small">Creado: {{ $batch['created_at_cl'] }}</div></div><span class="badge text-bg-primary">{{ $batch['total'] }} usuario(s)</span></div>
                <h3 class="h6 mb-2">Resultado de importación</h3>
                <div class="table-responsive border rounded-4 overflow-hidden"><table class="table table-sm mb-0 align-middle"><thead class="table-light"><tr><th>Total</th><th>Creados</th><th>Fallidos</th></tr></thead><tbody><tr><td>{{ $batch['total'] }}</td><td><span class="badge text-bg-success">{{ $batch['creados'] }}</span></td><td><span class="badge text-bg-danger">{{ $batch['fallidos'] }}</span></td></tr></tbody></table></div>
            </div>
        </section>
    @empty
        <section class="card nextcloud-panel"><div class="nova-empty-state"><div class="nova-empty-state-icon"><i class="bi bi-clock-history"></i></div><h3>Sin historial disponible</h3><p>Los nuevos lotes procesados en Nextcloud aparecerán aquí.</p></div></section>
    @endforelse
    <div class="nova-empty-state mb-3" id="nextcloud-history-no-results" hidden><div class="nova-empty-state-icon"><i class="bi bi-search"></i></div><h3>Sin coincidencias</h3><p>No hay lotes que coincidan con la búsqueda.</p></div>
</div>
@endsection

@push('scripts')
<script>(()=>{const input=document.getElementById('nextcloud-history-search'),batches=[...document.querySelectorAll('[data-history-batch]')],count=document.getElementById('nextcloud-history-count'),empty=document.getElementById('nextcloud-history-no-results');input?.addEventListener('input',()=>{const q=input.value.toLocaleLowerCase('es').trim();let visible=0;batches.forEach(batch=>{const show=!q||(batch.dataset.search||'').includes(q);batch.hidden=!show;if(show)visible++;});if(count)count.textContent=`${visible} lote(s)`;if(empty)empty.hidden=visible>0;});})();</script>
@endpush
