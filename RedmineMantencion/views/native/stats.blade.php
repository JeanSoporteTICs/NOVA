@extends('redmine_mantencion::native.layout')

@push('styles')
    @php
        $statsCss = base_path('RedmineMantencion/assets/css/estadisticas.css');
    @endphp
    <link rel="stylesheet" href="{{ url('/redmine-mantencion/assets/css/estadisticas.css') }}?v={{ @filemtime($statsCss) ?: 1 }}">
@endpush

@section('content')
@php
    $statusPalette = ['Pendiente' => ['#f59e0b', 'bi-hourglass-split'], 'Procesado' => ['#1cc88a', 'bi-check2-circle'], 'Error' => ['#ef4444', 'bi-exclamation-octagon']];
    $byCategoryChart = array_slice($byCategory, 0, 8, true);
@endphp
<div class="container-fluid py-4">
    @include('redmine_mantencion::native.partials.hero', [
        'icon' => 'bi-graph-up-arrow',
        'title' => 'Estadísticas',
        'subtitle' => 'Resumen y detalle de reportes',
        'badges' => [
            ['icon' => 'bi-collection', 'label' => 'Total: '.count($messages)],
            ['icon' => 'bi-clock', 'label' => 'Actualizado: '.now('America/Santiago')->format('d-m-Y H:i')],
        ],
    ])

    <div class="row g-3 mb-4">
        <div class="col-lg-8 col-md-6">
            <section class="card p-3 chart-card">
                <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-graph-up text-primary"></i><span class="fw-semibold">Reportes por mes</span></div>
                @if($byMonth === [])<div class="nova-empty h-100 d-grid place-items-center">Sin datos disponibles.</div>@else<canvas id="chart-months" height="140"></canvas>@endif
            </section>
        </div>
        <div class="col-lg-4 col-md-6">
            <section class="card p-3 chart-card">
                <div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-pie-chart text-success"></i><span class="fw-semibold">Reportes por categoría</span></div>
                @if($byCategory === [])<div class="nova-empty h-100 d-grid place-items-center">Sin datos disponibles.</div>@else<canvas id="chart-categories" height="220"></canvas>@endif
            </section>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-3 col-md-6">
            <section class="card p-3 h-100 stat-card">
                <div class="d-flex align-items-center justify-content-between mb-2"><div class="stat-icon"><i class="bi bi-collection"></i></div><span class="fw-semibold text-muted">Total reportes</span></div>
                <div class="display-6">{{ count($messages) }}</div>
            </section>
        </div>
        @foreach($byStatus as $status => $count)
            @php
                [$color, $icon] = $statusPalette[$status] ?? ['#4e73df', 'bi-inboxes'];
            @endphp
            <div class="col-lg-3 col-md-6">
                <section class="card p-3 h-100 stat-card" style="border-left-color:{{ $color }}">
                    <div class="d-flex align-items-center justify-content-between mb-2"><div class="stat-icon" style="background:color-mix(in srgb, {{ $color }} 12%, white);color:{{ $color }}"><i class="bi {{ $icon }}"></i></div><span class="fw-semibold text-muted">{{ $status }}</span></div>
                    <div class="display-6">{{ $count }}</div>
                </section>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-lg-6"><section class="card p-3 h-100"><div class="d-flex align-items-center gap-2 mb-3"><i class="bi bi-tags text-primary"></i><h2 class="h6 mb-0">Detalle por categoría</h2></div><div class="list-group list-group-flush">@forelse(array_slice($byCategory,0,12,true) as $label => $count)<div class="list-group-item d-flex justify-content-between"><span>{{ $label }}</span><span class="badge bg-primary rounded-pill">{{ $count }}</span></div>@empty<div class="list-group-item text-muted">Sin datos</div>@endforelse</div></section></div>
        <div class="col-lg-6"><section class="card p-3 h-100"><div class="d-flex align-items-center gap-2 mb-3"><i class="bi bi-calendar3 text-success"></i><h2 class="h6 mb-0">Detalle por mes</h2></div><div class="list-group list-group-flush">@forelse($byMonth as $label => $count)<div class="list-group-item d-flex justify-content-between"><span>{{ $label }}</span><span class="badge bg-success rounded-pill">{{ $count }}</span></div>@empty<div class="list-group-item text-muted">Sin datos</div>@endforelse</div></section></div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>(() => {
    if (!window.Chart) return;
    const months = @json($byMonth);
    const categories = @json($byCategoryChart);
    const monthCanvas = document.getElementById('chart-months');
    const categoryCanvas = document.getElementById('chart-categories');
    if (monthCanvas) new Chart(monthCanvas, {type:'line',data:{labels:Object.keys(months),datasets:[{label:'Reportes',data:Object.values(months),borderColor:'#4e73df',backgroundColor:'rgba(78,115,223,.13)',fill:true,tension:.3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
    if (categoryCanvas) new Chart(categoryCanvas, {type:'doughnut',data:{labels:Object.keys(categories),datasets:[{data:Object.values(categories),backgroundColor:['#4e73df','#1cc88a','#36b9cc','#f6c23e','#e74a3b','#6f42c1','#fd7e14','#20c997']}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});
})();</script>
@endpush
