@extends('redmine_mantencion::native.layout')

@push('styles')
    @php
        $historyCss = base_path('RedmineMantencion/assets/css/historico.css');
    @endphp
    <link rel="stylesheet" href="{{ url('/redmine-mantencion/assets/css/historico.css') }}?v={{ @filemtime($historyCss) ?: 1 }}">
@endpush

@section('content')
@php
    $canEdit = !empty($permissions['reportes_editar']) || !empty($permissions['all']);
    $canDelete = !empty($permissions['reportes_eliminar']) || !empty($permissions['all']);
    $showActions = $canEdit || $canDelete;
    $categories = collect($messages)->pluck('categoria')->filter()->unique()->sort()->values();
    $formatDate = static function ($date): string {
        try { return \Illuminate\Support\Carbon::parse($date)->format('d-m-Y'); }
        catch (\Throwable) { return (string) $date ?: '—'; }
    };
    $issueBase = $platformUrl ? rtrim((string) preg_replace('#/issues(?:\.json)?$#', '', $platformUrl), '/') : '';
@endphp
<div class="container-fluid py-4">
    @include('redmine_mantencion::native.partials.hero', [
        'icon' => 'bi-archive',
        'title' => 'Histórico',
        'subtitle' => 'Registros procesados archivados y horas extra.',
    ])

    @if(session('mantencion_status'))<div data-nova-flash="{{ session('mantencion_status_type', 'success') }}" data-nova-flash-message="{{ session('mantencion_status') }}" hidden></div>@endif

    <form id="history-filter-form" class="card card-body shadow-sm mb-3 historico-filter-card">
        <div class="row g-3 align-items-end">
            <div class="col-md-2"><label class="form-label" for="history-from">Desde</label><input class="form-control" type="date" id="history-from"></div>
            <div class="col-md-2"><label class="form-label" for="history-to">Hasta</label><input class="form-control" type="date" id="history-to"></div>
            <div class="col-md-2"><label class="form-label" for="history-source">Fuente</label><select class="form-select" id="history-source"><option value="">Todas</option><option value="core">CORE</option><option value="manual">Manual</option></select></div>
            <div class="col-md-3"><label class="form-label" for="history-category">Categoría</label><select class="form-select" id="history-category"><option value="">Todas</option>@foreach($categories as $category)<option value="{{ mb_strtolower($category, 'UTF-8') }}">{{ $category }}</option>@endforeach</select></div>
            <div class="col-md-3"></div>
            <div class="col-md-4"><label class="form-label" for="history-search">Buscar solicitante / nombre / rut</label><input class="form-control" id="history-search" type="search"></div>
            <div class="col-md-4"><label class="form-label" for="history-description">Buscar en descripción</label><input class="form-control" id="history-description" type="search"></div>
            <div class="col-md-2"><button class="btn-nova btn-nova-primary w-100" type="submit"><i class="bi bi-funnel"></i> Filtrar</button></div>
            <div class="col-md-2"><button class="btn-nova btn-nova-secondary w-100" type="reset"><i class="bi bi-x-circle"></i> Limpiar</button></div>
        </div>
    </form>

    <section class="card shadow-sm historico-table-card" id="historico-table-card">
        <div class="historico-summary">
            <div><span class="historico-count"><i class="bi bi-clock-history text-primary"></i> <strong id="history-total-count">{{ count($messages) }}</strong> registros</span><span class="text-muted ms-2">Mostrando <strong id="history-visible-count">{{ min(25, count($messages)) }}</strong> de <strong id="history-filtered-count">{{ count($messages) }}</strong> registros</span></div>
            <div class="historico-summary__tools">
                @if($canEdit)
                    <div class="dropdown historico-bulk-status">
                        <button class="btn-nova btn-nova-primary historico-bulk-status__button dropdown-toggle" id="history-status-button" type="button" data-bs-toggle="dropdown" disabled><i class="bi bi-kanban"></i> Cambiar estado <span class="historico-selection-count" id="historico-selection-count">0</span></button>
                        <ul class="dropdown-menu dropdown-menu-end historico-status-menu" aria-labelledby="history-status-button"><li class="dropdown-header">Aplicar a seleccionados</li>@foreach($statusOptions as $id => $name)<li><button class="dropdown-item" type="button" data-history-status="{{ $id }}"><span class="historico-status-dot is-status-{{ $id }}"></span>{{ $name }}</button></li>@endforeach</ul>
                    </div>
                    <form method="POST" action="{{ route('redmine.mantencion.history.action') }}" id="history-status-form" hidden>@csrf<input type="hidden" name="action" value="update_redmine_status"><input type="hidden" name="ids" id="history-status-ids"><input type="hidden" name="status_id" id="history-status-id"></form>
                @endif
                <label class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" id="historico-compact-toggle"><span class="form-check-label fw-semibold">Modo compacto</span></label>
                <span class="text-muted small" id="history-page-top">Página 1 de 1</span>
            </div>
        </div>

        <div id="redmine-sync-panel" class="historico-redmine-sync d-none" role="status" aria-live="polite">
            <div class="historico-redmine-sync__header"><span><i class="bi bi-arrow-repeat"></i> Sincronizando estados con Redmine</span><strong id="redmine-sync-count">0/0</strong></div>
            <div class="progress" aria-hidden="true"><div id="redmine-sync-bar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div></div>
        </div>

        <div class="table-responsive rm-table-wrap">
            <table class="table table-hover historico-table align-middle mb-0" id="history-table">
                <colgroup>@if($canEdit)<col class="historico-col-select">@endif<col class="historico-col-date"><col class="historico-col-id"><col class="historico-col-redmine-status"><col class="historico-col-requester"><col class="historico-col-category"><col class="historico-col-department"><col class="historico-col-subject"><col class="historico-col-source"><col class="historico-col-detail">@if($showActions)<col class="historico-col-actions">@endif</colgroup>
                <thead><tr>@if($canEdit)<th class="historico-select-cell"><input type="checkbox" class="form-check-input" id="history-all" aria-label="Seleccionar visibles" disabled></th>@endif<th>Fecha</th><th>Redmine ID</th><th>Estado Redmine</th><th>Solicitante</th><th>Categoría</th><th>Departamento</th><th>Asunto</th><th>Fuente</th><th>Detalle</th>@if($showActions)<th class="historico-actions-cell">Acciones</th>@endif</tr></thead>
                <tbody>
                @forelse($messages as $message)
                    @php
                        $date = ($message['fecha'] ?? '') ?: ($message['fecha_inicio'] ?? '');
                        $sourceText = mb_strtolower(trim((string) ($message['fuente'] ?? '')), 'UTF-8');
                        $source = str_contains($sourceText, 'manual') || ($sourceText === '' && empty($message['id_core'])) ? 'manual' : 'core';
                        $department = ($message['core_departamento'] ?? '') ?: ($message['unidad'] ?? '') ?: '—';
                        $redmineId = trim((string) ($message['redmine_id'] ?? ''));
                        $searchValue = mb_strtolower(implode(' ', [$message['solicitante'] ?? '', $message['nombre'] ?? '', $message['rut'] ?? '', $message['asunto'] ?? '', $redmineId]), 'UTF-8');
                        $descriptionValue = mb_strtolower((string) ($message['descripcion'] ?? ''), 'UTF-8');
                    @endphp
                    <tr data-history-row data-date="{{ substr($date, 0, 10) }}" data-source="{{ $source }}" data-category="{{ mb_strtolower((string) ($message['categoria'] ?? ''), 'UTF-8') }}" data-search="{{ $searchValue }}" data-description="{{ $descriptionValue }}">
                        @if($canEdit)<td class="historico-select-cell">@if($redmineId !== '')<input type="checkbox" class="form-check-input history-check" value="{{ $message['id'] }}" data-redmine-id="{{ $redmineId }}" aria-label="Seleccionar {{ $message['id'] }}" disabled>@else<span class="text-muted">—</span>@endif</td>@endif
                        <td><span class="historico-date"><i class="bi bi-calendar3"></i>{{ $formatDate($date) }}</span></td>
                        <td>@if($redmineId !== '')<a class="historico-redmine-link" href="{{ $issueBase ? $issueBase.'/issues/'.$redmineId : '#' }}" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i>{{ $redmineId }}</a>@else<span class="text-muted">—</span>@endif</td>
                        <td>@if($redmineId !== '')<span class="historico-redmine-status historico-redmine-status--syncing js-redmine-status" data-redmine-id="{{ $redmineId }}" title="Sincronizando con Redmine"><i class="bi bi-arrow-repeat"></i><span>Sincronizando</span></span>@else<span class="text-muted">—</span>@endif</td>
                        <td title="{{ $message['solicitante'] ?? '' }}">{{ ($message['solicitante'] ?? '') ?: '—' }}</td>
                        <td title="{{ $message['categoria'] ?? '' }}">{{ ($message['categoria'] ?? '') ?: 'Sin categoría' }}</td>
                        <td title="{{ $department }}">{{ $department }}</td>
                        <td title="{{ $message['asunto'] ?? '' }}">{{ ($message['asunto'] ?? '') ?: 'Sin asunto' }}</td>
                        <td><span class="historico-source-badge {{ $source === 'manual' ? 'is-manual' : 'is-core' }}"><i class="bi {{ $source === 'manual' ? 'bi-pencil-square' : 'bi-cloud-arrow-down' }}"></i>{{ $source === 'manual' ? 'Manual' : 'CORE' }}@if(!empty($message['hora_extra']))<i class="bi bi-clock-fill" title="Hora extra"></i>@endif</span></td>
                        <td><button class="btn-action btn-action-view historico-detail-btn" type="button" data-bs-toggle="modal" data-bs-target="#history-detail" data-detail='@json($message)' title="Ver detalle"><i class="bi bi-eye"></i></button></td>
                        @if($showActions)
                            <td class="historico-actions-cell"><div class="historico-row-actions">
                                @if($canEdit && $redmineId !== '')
                                    <div class="dropdown">
                                        <button class="btn-action btn-action-sync dropdown-toggle no-caret js-redmine-status-menu d-none" type="button" data-redmine-id="{{ $redmineId }}" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-expanded="false" title="Cambiar estado en Redmine" aria-label="Cambiar estado del ticket {{ $redmineId }}"><i class="bi bi-arrow-repeat"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end historico-status-menu"><li class="dropdown-header">Cambiar estado #{{ $redmineId }}</li>@foreach($statusOptions as $id => $name)<li><form method="POST" action="{{ route('redmine.mantencion.history.action') }}" class="m-0" onsubmit="return confirm('¿Cambiar el ticket #{{ $redmineId }} a {{ $name }}?')">@csrf<input type="hidden" name="action" value="update_redmine_status"><input type="hidden" name="ids" value="{{ $message['id'] }}"><input type="hidden" name="status_id" value="{{ $id }}"><button class="dropdown-item" type="submit"><span class="historico-status-dot is-status-{{ $id }}"></span>{{ $name }}</button></form></li>@endforeach</ul>
                                    </div>
                                @endif
                                @if($canDelete)<form method="POST" action="{{ route('redmine.mantencion.history.action') }}" onsubmit="return confirm('¿Eliminar este registro del histórico?')">@csrf<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="{{ $message['id'] }}"><button class="btn-action btn-action-delete" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash"></i></button></form>@endif
                            </div></td>
                        @endif
                    </tr>
                @empty<tr><td colspan="{{ 9 + ($canEdit ? 1 : 0) + ($showActions ? 1 : 0) }}" class="nova-empty"><i class="bi bi-archive fs-3 d-block mb-2"></i>No hay reportes archivados.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
        <div class="historico-pagination" id="history-pagination">
            <div class="historico-pagination__left"><span id="history-page-meta">Página 1 de 1</span></div>
            <nav class="d-flex gap-2" aria-label="Paginación histórico"><button class="btn btn-sm btn-outline-secondary" id="history-prev" type="button"><i class="bi bi-chevron-left"></i> Anterior</button><button class="btn btn-sm btn-outline-secondary" id="history-next" type="button">Siguiente <i class="bi bi-chevron-right"></i></button></nav>
        </div>
    </section>
</div>

<div class="modal fade detail-drawer-modal" id="history-detail" tabindex="-1" aria-labelledby="history-detail-title" aria-hidden="true">
    <div class="modal-dialog detail-drawer-dialog modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><p class="detail-drawer-kicker">Histórico</p><h2 class="modal-title fs-5" id="history-detail-title">Detalle del reporte</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body"><dl class="historico-detail-facts"><div><dt><i class="bi bi-calendar3"></i> Fecha</dt><dd data-detail-field="fecha">—</dd></div><div><dt><i class="bi bi-box-arrow-up-right"></i> Redmine ID</dt><dd data-detail-field="redmine_id">—</dd></div><div><dt><i class="bi bi-folder2-open"></i> Estado Redmine</dt><dd data-detail-state>—</dd></div><div><dt><i class="bi bi-tags"></i> Categoría</dt><dd data-detail-field="categoria">—</dd></div><div><dt><i class="bi bi-person"></i> Solicitante</dt><dd data-detail-field="solicitante">—</dd></div><div><dt><i class="bi bi-building"></i> Departamento</dt><dd data-detail-field="unidad">—</dd></div></dl><div class="detail-drawer-panel mt-3"><h3 data-detail-field="asunto">Sin asunto</h3><p data-detail-field="descripcion" class="mb-0" style="white-space:pre-wrap"></p></div></div>
    </div></div>
</div>
@endsection

@push('scripts')
<script>(() => {
    const rows=[...document.querySelectorAll('[data-history-row]')],pageSize=25;
    const statusEndpoint=@json(route('redmine.mantencion.history.statuses'));
    const statusCache=new Map();
    let filtered=[...rows],page=1;
    const checks=()=>[...document.querySelectorAll('.history-check:checked')];
    const clearSelection=()=>{checks().forEach(check=>check.checked=false);const all=document.getElementById('history-all');if(all)all.checked=false;updateSelection();};
    const updateSelection=()=>{const selected=checks(),count=selected.length,label=document.getElementById('historico-selection-count'),button=document.getElementById('history-status-button'),all=document.getElementById('history-all'),enabled=rows.filter(row=>!row.hidden).map(row=>row.querySelector('.history-check')).filter(check=>check&&!check.disabled);if(label)label.textContent=String(count);if(button)button.disabled=count===0;if(all){all.disabled=enabled.length===0;all.checked=enabled.length>0&&enabled.every(check=>check.checked);all.indeterminate=count>0&&!all.checked;}};
    const render=()=>{const pages=Math.max(1,Math.ceil(filtered.length/pageSize));page=Math.min(page,pages);const start=(page-1)*pageSize,end=start+pageSize;rows.forEach(row=>row.hidden=true);filtered.slice(start,end).forEach(row=>row.hidden=false);document.getElementById('history-visible-count').textContent=String(Math.min(pageSize,Math.max(0,filtered.length-start)));document.getElementById('history-filtered-count').textContent=String(filtered.length);document.getElementById('history-page-top').textContent=`Página ${page} de ${pages}`;document.getElementById('history-page-meta').textContent=`Página ${page} de ${pages}`;document.getElementById('history-prev').disabled=page<=1;document.getElementById('history-next').disabled=page>=pages;updateSelection();syncStatuses();};
    const apply=()=>{const query=(document.getElementById('history-search').value||'').toLocaleLowerCase('es').trim(),description=(document.getElementById('history-description').value||'').toLocaleLowerCase('es').trim(),from=document.getElementById('history-from').value||'',to=document.getElementById('history-to').value||'',source=document.getElementById('history-source').value||'',category=document.getElementById('history-category').value||'';filtered=rows.filter(row=>{const date=row.dataset.date||'';return(!from||date>=from)&&(!to||date<=to)&&(!source||row.dataset.source===source)&&(!category||row.dataset.category===category)&&(!query||(row.dataset.search||'').includes(query))&&(!description||(row.dataset.description||'').includes(description));});page=1;clearSelection();render();};
    document.getElementById('history-filter-form').addEventListener('submit',event=>{event.preventDefault();apply();});
    document.getElementById('history-filter-form').addEventListener('reset',()=>setTimeout(apply));
    document.getElementById('history-prev').addEventListener('click',()=>{if(page>1){page--;clearSelection();render();}});
    document.getElementById('history-next').addEventListener('click',()=>{if(page*pageSize<filtered.length){page++;clearSelection();render();}});
    document.getElementById('history-all')?.addEventListener('change',event=>{rows.filter(row=>!row.hidden).forEach(row=>{const check=row.querySelector('.history-check');if(check&&!check.disabled)check.checked=event.target.checked;});updateSelection();});
    document.querySelectorAll('.history-check').forEach(check=>check.addEventListener('change',updateSelection));
    document.getElementById('historico-compact-toggle').addEventListener('change',event=>document.getElementById('historico-table-card').classList.toggle('is-compact',event.target.checked));
    document.querySelectorAll('[data-history-status]').forEach(button=>button.addEventListener('click',()=>{const selected=checks().map(check=>check.value);if(!selected.length)return;document.getElementById('history-status-ids').value=selected.join(',');document.getElementById('history-status-id').value=button.dataset.historyStatus;if(confirm('¿Cambiar el estado Redmine de los registros seleccionados?'))document.getElementById('history-status-form').submit();}));

    const normalizeStatus=value=>String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('es');
    const statusTone=status=>{const name=normalizeStatus(status?.name);if(status?.closed)return'closed';if(name.includes('nuev'))return'new';if(name.includes('curso')||name.includes('progreso')||name.includes('proceso'))return'progress';if(name.includes('resuelt'))return'resolved';if(name.includes('rechaz'))return'rejected';return status?.available?'open':'unknown';};
    const setStatus=(ticket,status)=>{
        statusCache.set(ticket,status);
        const tone=statusTone(status),available=Boolean(status?.available),label=available?(status.name||'Abierto'):'No disponible',icon=!available?'bi-question-circle':(status.closed?'bi-lock-fill':'bi-folder2-open');
        document.querySelectorAll(`.js-redmine-status[data-redmine-id="${CSS.escape(ticket)}"]`).forEach(badge=>{badge.className=`historico-redmine-status historico-redmine-status--${tone} js-redmine-status`;badge.title=available?`Redmine: ${status.name}`:(status?.message||'No se pudo consultar Redmine');badge.innerHTML=`<i class="bi ${icon}"></i><span></span>`;badge.querySelector('span').textContent=label;});
        document.querySelectorAll(`.history-check[data-redmine-id="${CSS.escape(ticket)}"]`).forEach(check=>{check.disabled=!available||Boolean(status.closed);if(check.disabled)check.checked=false;});
        document.querySelectorAll(`.js-redmine-status-menu[data-redmine-id="${CSS.escape(ticket)}"]`).forEach(button=>button.classList.toggle('d-none',!available||Boolean(status.closed)));
        updateSelection();
    };
    const syncStatuses=async()=>{
        const ids=[...new Set(rows.filter(row=>!row.hidden).map(row=>row.querySelector('.js-redmine-status[data-redmine-id]')?.dataset.redmineId).filter(id=>id&&!statusCache.has(id)))];
        if(!ids.length)return;
        const panel=document.getElementById('redmine-sync-panel'),bar=document.getElementById('redmine-sync-bar'),count=document.getElementById('redmine-sync-count');
        panel?.classList.remove('d-none','historico-redmine-sync--done');
        for(let offset=0;offset<ids.length;offset+=5){
            const chunk=ids.slice(offset,offset+5);
            try{
                const url=new URL(statusEndpoint,window.location.href);url.searchParams.set('ids',chunk.join(','));
                const response=await fetch(url,{headers:{Accept:'application/json'},cache:'no-store'});if(!response.ok)throw new Error(`HTTP ${response.status}`);
                const payload=await response.json();chunk.forEach(id=>setStatus(id,payload.statuses?.[id]||{available:false,message:'Sin respuesta desde Redmine'}));
            }catch(error){chunk.forEach(id=>setStatus(id,{available:false,message:'No se pudo sincronizar con Redmine'}));}
            const done=Math.min(offset+chunk.length,ids.length),percent=Math.round(done/ids.length*100);if(count)count.textContent=`${done}/${ids.length}`;if(bar)bar.style.width=`${percent}%`;
        }
        panel?.classList.add('historico-redmine-sync--done');setTimeout(()=>panel?.classList.add('d-none'),1200);
    };
    document.querySelectorAll('[data-detail]').forEach(button=>button.addEventListener('click',()=>{const data=JSON.parse(button.dataset.detail||'{}');document.querySelectorAll('[data-detail-field]').forEach(field=>{const key=field.dataset.detailField;let value=data[key]||'—';if(key==='unidad')value=data.core_departamento||data.unidad||'—';field.textContent=value;});const ticket=String(data.redmine_id||''),remote=statusCache.get(ticket),state=document.querySelector('[data-detail-state]');if(state)state.textContent=remote?.available?(remote.name||'No disponible'):(ticket&&!remote?'Sincronizando…':'No disponible');}));
    updateSelection();render();
})();</script>
@endpush
