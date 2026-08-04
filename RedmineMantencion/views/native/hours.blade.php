@extends('redmine_mantencion::native.layout')

@section('content')
@php
    $canEdit = !empty($permissions['horas_extra_editar']) || !empty($permissions['all']);
    $months = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    $currentYear = (int) now('America/Santiago')->format('Y');
    $currentMonth = (int) now('America/Santiago')->format('n');
    $years = collect($groups)->pluck('fecha')->filter()->map(fn ($date) => (int) substr((string) $date, 0, 4))->push($currentYear)->filter()->unique()->sort()->values();
    $formatDate = static function ($date): string {
        try { return \Illuminate\Support\Carbon::parse($date)->format('d-m-Y'); }
        catch (\Throwable) { return (string) $date ?: '—'; }
    };
    $durationLabel = static function ($start, $end): string {
        if (!$start || !$end) return '—';
        try {
            $from = \Illuminate\Support\Carbon::createFromFormat('H:i', substr((string) $start, 0, 5));
            $to = \Illuminate\Support\Carbon::createFromFormat('H:i', substr((string) $end, 0, 5));
            if ($to->lessThan($from)) $to->addDay();
            $minutes = (int) $from->diffInMinutes($to);
            $hours = intdiv($minutes, 60);
            $remainder = $minutes % 60;
            return $remainder > 0 ? $hours.'h '.$remainder.'m' : $hours.'h';
        } catch (\Throwable) { return '—'; }
    };
@endphp
<div class="container-fluid py-4">
    @include('redmine_mantencion::native.partials.hero', [
        'icon' => 'bi-alarm',
        'title' => 'Horas extra',
        'subtitle' => 'Reportes con hora extra agrupados por fecha',
    ])

    @if(session('mantencion_status'))
        <div data-nova-flash="{{ session('mantencion_status_type', 'success') }}" data-nova-flash-message="{{ session('mantencion_status') }}" hidden></div>
    @endif

    <div class="nova-alert-card is-info mb-3 he-guidance">
        <span class="he-guidance-icon"><i class="bi bi-lightbulb"></i></span>
        <div><strong>Registro de Mantención</strong><span>Las horas se agrupan por fecha. Puedes buscar, filtrar, copiar la tabla o editar el horario de cada jornada.</span></div>
    </div>

    <section class="nova-table-card he-workspace">
        <div class="nova-table-toolbar he-toolbar">
            <div class="he-toolbar-heading"><span>Registro mensual</span><strong>Mis horas extra</strong></div>
            <div class="he-filter he-filter-search">
                <label for="he-search">Buscar</label>
                <div class="nova-table-search"><i class="bi bi-search"></i><input id="he-search" type="search" placeholder="ID o detalle" data-he-search></div>
            </div>
            <div class="he-filter">
                <label for="he-month">Periodo</label>
                <div class="he-period-controls">
                    <select id="he-month" class="form-select form-select-sm" data-he-month aria-label="Mes">@foreach($months as $number => $label)<option value="{{ $number }}" @selected($number === $currentMonth)>{{ $label }}</option>@endforeach</select>
                    <select class="form-select form-select-sm" data-he-year aria-label="Año">@foreach($years as $year)<option value="{{ $year }}" @selected($year === $currentYear)>{{ $year }}</option>@endforeach</select>
                    <button class="btn-nova btn-nova-secondary btn-nova-icon-only" type="button" data-he-filter-clear title="Volver al mes actual" aria-label="Limpiar filtros"><i class="bi bi-arrow-counterclockwise"></i></button>
                </div>
            </div>
            <div class="he-toolbar-summary">
                <span class="he-visible-count"><i class="bi bi-calendar3"></i><strong data-he-count>{{ count($groups) }}</strong> fechas</span>
                <span class="he-month-total" title="Total de horas del mes visible"><span class="he-month-total-icon"><i class="bi bi-stopwatch"></i></span><span><small>Total del mes</small><strong data-he-month-total>0h</strong></span></span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="nova-user-table" data-he-table>
                <thead><tr><th>ID Redmine</th><th>Detalle</th><th class="nova-col-actions text-end"><span class="me-2">Acciones</span><button type="button" class="he-action-button he-action-copy" data-he-copy title="Copiar tabla completa" aria-label="Copiar tabla completa"><span class="he-action-icon"><i class="bi bi-clipboard"></i></span><span>Copiar</span></button></th></tr></thead>
                @forelse($groups as $group)
                    @php
                        $date = (string) ($group['fecha'] ?? '');
                        $dateDisplay = $formatDate($date);
                        $start = (string) ($group['hora_inicio'] ?? '');
                        $end = (string) ($group['hora_fin'] ?? '');
                        $total = $durationLabel($start, $end);
                    @endphp
                    <tbody data-he-date data-he-fecha="{{ $date }}" data-he-fecha-display="{{ $dateDisplay }}">
                        <tr class="rm-hours-group rm-hours-date" data-he-hora-inicio="{{ $start ?: '—' }}" data-he-hora-fin="{{ $end ?: '—' }}" data-he-total-horas="{{ $total }}">
                            <td colspan="3"><div class="he-date-bar">
                                <div class="he-date-heading"><span class="he-date-icon"><i class="bi bi-calendar-check"></i></span><span><small>Fecha registrada</small><strong>{{ $dateDisplay }}</strong></span></div>
                                <div class="he-time-line"><span><small>Inicio</small><strong>{{ $start ?: '—' }}</strong></span><i class="bi bi-arrow-right"></i><span><small>Término</small><strong>{{ $end ?: '—' }}</strong></span><span class="he-day-total"><small>Total</small><strong>{{ $total }}</strong></span></div>
                                @if($canEdit)<button type="button" class="he-action-button he-action-edit" title="Editar horas" aria-label="Editar horas" data-he-edit-open data-fecha="{{ $date }}" data-fecha-display="{{ $dateDisplay }}" data-hora-inicio="{{ $start }}" data-hora-fin="{{ $end }}"><span class="he-action-icon"><i class="bi bi-pencil-square"></i></span><span>Editar horas</span></button>@endif
                            </div></td>
                        </tr>
                        @foreach($group['reports'] as $message)
                            <tr data-search="{{ mb_strtolower(implode(' ', [$message['redmine_id'] ?? '', $message['asunto'] ?? '']), 'UTF-8') }}">
                                <td><span class="he-ticket-id"><i class="bi bi-box-arrow-up-right"></i>{{ $message['redmine_id'] ?: '—' }}</span></td>
                                <td><span class="he-report-detail">{{ $message['asunto'] ?: 'Sin asunto' }}</span></td>
                                <td class="text-end">@if($canEdit)<form method="POST" action="{{ route('redmine.mantencion.hours.action') }}" class="d-inline" onsubmit="return confirm('¿Retirar este reporte de horas extra?')">@csrf<input type="hidden" name="action" value="detach"><input type="hidden" name="id" value="{{ $message['id'] }}"><button class="he-action-button he-action-remove he-action-icon-only" type="submit" title="Retirar de horas extra" aria-label="Retirar de horas extra"><span class="he-action-icon"><i class="bi bi-x-circle"></i></span></button></form>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                @empty
                    <tbody><tr><td colspan="3" class="admin-empty-row">No se encontraron grupos de horas extra.</td></tr></tbody>
                @endforelse
            </table>
        </div>
    </section>
</div>

@if($canEdit)
<div class="modal fade" id="editar-horas-mantencion" tabindex="-1" aria-labelledby="editar-horas-mantencion-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <form class="modal-content" method="POST" action="{{ route('redmine.mantencion.hours.action') }}">@csrf<input type="hidden" name="action" value="update_extra"><input type="hidden" name="fecha" data-he-form-fecha>
            <div class="modal-header"><div><p class="detail-drawer-kicker">Horas extra</p><h2 class="modal-title fs-5" id="editar-horas-mantencion-title">Editar horas por fecha</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body"><div class="row g-3">
                <div class="col-12"><label class="form-label">Fecha</label><input class="form-control" data-he-form-fecha-display readonly></div>
                <div class="col-12 col-md-6"><label class="form-label">Hora de inicio</label><input class="form-control" type="time" name="hora_inicio" data-he-form-hora-inicio></div>
                <div class="col-12 col-md-6"><label class="form-label">Hora de término</label><input class="form-control" type="time" name="hora_fin" data-he-form-hora-fin></div>
                <div class="col-12"><label class="form-label">Total horas</label><input class="form-control" type="text" data-he-form-total readonly></div>
                <div class="col-12"><button class="btn btn-outline-primary" type="button" data-he-emach-calculate><i class="bi bi-calculator"></i> Calcular con EMACH</button><div class="form-text fw-semibold" data-he-emach-status></div></div>
            </div></div>
            <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i> Cancelar</button><button class="btn-nova btn-nova-primary" type="submit"><i class="bi bi-save"></i> Guardar cambios</button></div>
        </form>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script>
(() => {
    const dateGroups = [...document.querySelectorAll('[data-he-date]')];
    const search = document.querySelector('[data-he-search]');
    const month = document.querySelector('[data-he-month]');
    const year = document.querySelector('[data-he-year]');
    const count = document.querySelector('[data-he-count]');
    const monthTotal = document.querySelector('[data-he-month-total]');
    const currentYear = String(new Date().getFullYear());
    const currentMonth = String(new Date().getMonth() + 1);
    const parseMinutes = value => {
        const text = String(value || '').trim().toLowerCase();
        const clock = text.match(/^(\d{1,3}):([0-5]\d)$/);
        if (clock) return Number(clock[1]) * 60 + Number(clock[2]);
        const hours = text.match(/(\d+(?:[.,]\d+)?)\s*h/), minutes = text.match(/(\d+)\s*m/);
        return Math.round((hours ? Number(hours[1].replace(',', '.')) * 60 : 0) + (minutes ? Number(minutes[1]) : 0));
    };
    const formatMinutes = minutes => `${Math.floor(minutes / 60)}h${minutes % 60 ? ` ${minutes % 60}m` : ''}`;
    const applyFilters = () => {
        const query = String(search?.value || '').toLocaleLowerCase('es').trim();
        let visible = 0, minutes = 0;
        dateGroups.forEach(group => {
            const date = group.dataset.heFecha || '';
            const matchesPeriod = (!month?.value || Number(date.slice(5, 7)) === Number(month.value)) && (!year?.value || date.slice(0, 4) === year.value);
            const matchesSearch = !query || [...group.querySelectorAll('tr[data-search]')].some(row => (row.dataset.search || '').includes(query));
            group.style.display = matchesPeriod && matchesSearch ? '' : 'none';
            if (matchesPeriod && matchesSearch) { visible++; minutes += parseMinutes(group.querySelector('.rm-hours-date')?.dataset.heTotalHoras || ''); }
        });
        if (count) count.textContent = String(visible);
        if (monthTotal) monthTotal.textContent = formatMinutes(minutes);
    };
    search?.addEventListener('input', applyFilters);
    month?.addEventListener('change', applyFilters);
    year?.addEventListener('change', applyFilters);
    document.querySelector('[data-he-filter-clear]')?.addEventListener('click', () => { if (search) search.value=''; if(month) month.value=currentMonth; if(year) year.value=currentYear; applyFilters(); });
    applyFilters();

    const modalElement = document.getElementById('editar-horas-mantencion');
    if (modalElement) {
        const modal = window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalElement) : null;
        const fieldDate = modalElement.querySelector('[data-he-form-fecha]'), fieldDateDisplay = modalElement.querySelector('[data-he-form-fecha-display]'), fieldStart = modalElement.querySelector('[data-he-form-hora-inicio]'), fieldEnd = modalElement.querySelector('[data-he-form-hora-fin]'), fieldTotal = modalElement.querySelector('[data-he-form-total]');
        const computeTotal = () => { if(!fieldStart.value||!fieldEnd.value){fieldTotal.value='—';return;}const [sh,sm]=fieldStart.value.split(':').map(Number),[eh,em]=fieldEnd.value.split(':').map(Number);let minutes=(eh*60+em)-(sh*60+sm);if(minutes<0)minutes+=1440;fieldTotal.value=formatMinutes(minutes); };
        document.querySelectorAll('[data-he-edit-open]').forEach(button => button.addEventListener('click', () => { fieldDate.value=button.dataset.fecha||'';fieldDateDisplay.value=button.dataset.fechaDisplay||'';fieldStart.value=button.dataset.horaInicio||'';fieldEnd.value=button.dataset.horaFin||'';computeTotal();modal?.show(); }));
        fieldStart.addEventListener('input', computeTotal); fieldEnd.addEventListener('input', computeTotal);
        const emachButton=modalElement.querySelector('[data-he-emach-calculate]'),emachStatus=modalElement.querySelector('[data-he-emach-status]'),emachEndpoint=@json(route('emach.overtime-suggestion')),csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
        emachButton?.addEventListener('click',async()=>{emachButton.disabled=true;emachStatus.className='form-text fw-semibold';emachStatus.textContent='Consultando EMACH...';try{const response=await fetch(emachEndpoint,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify({fecha:fieldDate.value})}),data=await response.json().catch(()=>({}));if(!response.ok||!data.ok)throw new Error(data.message||'Sin datos EMACH para esta fecha.');fieldStart.value=data.hora_inicio||'';fieldEnd.value=data.hora_fin||'';computeTotal();emachStatus.classList.add('text-success');emachStatus.textContent=data.message||'Horario calculado desde EMACH.';}catch(error){emachStatus.classList.add('text-danger');emachStatus.textContent=error.message||'No se pudo consultar EMACH.';}finally{emachButton.disabled=false;}});
    }

    const copyButton = document.querySelector('[data-he-copy]'), table = document.querySelector('[data-he-table]');
    if (copyButton && table) copyButton.addEventListener('click', async () => {
        const escape = value => String(value).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
        const cellText = cell => (cell?.innerText || '').replace(/\s+/g,' ').trim();
        const headers=['ID Redmine','Detalle','Acciones'], rows=[headers], htmlRows=['<tr>'+headers.map(label=>`<th style="border:1px solid #000;background:#d9d9d9;font-weight:700;text-align:left;padding:6px;">${escape(label)}</th>`).join('')+'</tr>'];
        const total=monthTotal?.textContent||'0h';rows.push([`Total horas del mes: ${total}`,'','']);htmlRows.push(`<tr><td colspan="3" style="border:1px solid #000;background:#dbeafe;color:#1d4ed8;font-weight:700;padding:6px;">Total horas del mes: ${escape(total)}</td></tr>`);
        dateGroups.forEach(group=>{if(group.style.display==='none')return;[...group.children].forEach(row=>{if(row.classList.contains('rm-hours-date')){const line=[`Fecha: ${group.dataset.heFechaDisplay||group.dataset.heFecha||''}`,`Hora inicio: ${row.dataset.heHoraInicio||'—'}`,`Hora término: ${row.dataset.heHoraFin||'—'}`,`Total horas: ${row.dataset.heTotalHoras||'—'}`].join('   |   ');rows.push([line,'','']);htmlRows.push(`<tr><td colspan="3" style="border:1px solid #000;background:#cfe0f7;font-weight:700;padding:6px;">${escape(line)}</td></tr>`);return;}if(row.cells.length<3)return;const values=[cellText(row.cells[0]),cellText(row.cells[1]),cellText(row.cells[2])];rows.push(values);htmlRows.push('<tr>'+values.map(value=>`<td style="border:1px solid #000;padding:6px;">${escape(value)}</td>`).join('')+'</tr>');});});
        const text=rows.map(row=>row.join('\t')).join('\n'),html=`<table style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:12px;color:#000;">${htmlRows.join('')}</table>`,original=copyButton.innerHTML;
        const success=()=>{copyButton.innerHTML='<span class="he-action-icon"><i class="bi bi-check2"></i></span><span>Copiado</span>';setTimeout(()=>copyButton.innerHTML=original,1600);};
        try{if(navigator.clipboard&&window.ClipboardItem){await navigator.clipboard.write([new ClipboardItem({'text/html':new Blob([html],{type:'text/html'}),'text/plain':new Blob([text],{type:'text/plain'})})]);}else if(navigator.clipboard){await navigator.clipboard.writeText(text);}else throw new Error('Clipboard API no disponible');success();}
        catch(error){const container=document.createElement('div');container.contentEditable='true';container.innerHTML=html;container.style.position='fixed';container.style.left='-9999px';document.body.appendChild(container);const range=document.createRange();range.selectNodeContents(container);const selection=window.getSelection();selection.removeAllRanges();selection.addRange(range);const copied=document.execCommand('copy');selection.removeAllRanges();container.remove();if(copied)success();else window.appUi?.toast?.('El navegador bloqueó el portapapeles.','danger');}
    });
})();
</script>
@endpush
