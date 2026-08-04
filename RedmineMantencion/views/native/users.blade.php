@extends('redmine_mantencion::native.layout')

@push('styles')
    @php
        $usersCss = base_path('RedmineMantencion/assets/css/usuarios.css');
    @endphp
    <link rel="stylesheet" href="{{ url('/redmine-mantencion/assets/css/usuarios.css') }}?v={{ @filemtime($usersCss) ?: 1 }}">
@endpush

@section('content')
@php
    $activeUsers = collect($users)->filter(fn ($user) => ($user['estado'] ?? 'activo') !== 'baneado')->count();
    $bannedUsers = count($users) - $activeUsers;
@endphp
<div class="container-fluid py-4">
    @include('redmine_mantencion::native.partials.hero', [
        'icon' => 'bi-people',
        'title' => 'Usuarios',
        'subtitle' => 'Gestión de usuarios e integraciones personales',
    ])
    @if(session('mantencion_status'))<div data-nova-flash="{{ session('mantencion_status_type','success') }}" data-nova-flash-message="{{ session('mantencion_status') }}" hidden></div>@endif

    <div class="nova-user-summary-grid mb-3" id="user-status-filters">
        <section class="nova-user-summary-card is-enabled is-active" data-user-filter="activo" role="button" tabindex="0"><div class="nova-user-summary-icon"><i class="bi bi-person-check"></i></div><div><span>Usuarios activos</span><strong>{{ $activeUsers }}</strong></div></section>
        <section class="nova-user-summary-card is-banned" data-user-filter="baneado" role="button" tabindex="0"><div class="nova-user-summary-icon"><i class="bi bi-person-x"></i></div><div><span>Usuarios baneados</span><strong>{{ $bannedUsers }}</strong></div></section>
    </div>

    <section class="nova-table-card">
        <div class="nova-table-toolbar">
            <span class="nova-table-toolbar-title">Usuarios del proyecto</span>
            <div class="nova-table-search"><i class="bi bi-search"></i><input id="users-search" type="search" placeholder="Buscar nombre, RUT, ID o rol" aria-label="Buscar usuario"></div>
            <span class="nova-user-meta ms-auto">Total: {{ count($users) }}</span>
            <form method="POST" action="{{ route('redmine.mantencion.users.action') }}" class="m-0">@csrf<input type="hidden" name="action" value="sync_remote"><button class="btn-nova btn-nova-info"><i class="bi bi-cloud-download"></i> Importar Redmine</button></form>
        </div>
        <div class="table-responsive">
            <table class="nova-user-table" id="user-table"><thead><tr><th>Usuario</th><th>RUT / acceso</th><th>Redmine</th><th>Rol módulo</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
            @forelse($users as $user)
                @php
                    $fullName = trim(($user['nombre'] ?? '').' '.($user['apellido'] ?? '')) ?: ($user['usuario'] ?? 'Usuario');
                    $initials = collect(preg_split('/\s+/u', $fullName))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1), 'UTF-8'))->implode('');
                    $userStatus = ($user['estado'] ?? 'activo') === 'baneado' ? 'baneado' : 'activo';
                @endphp
                <tr data-user-row data-user-status="{{ $userStatus }}" data-search="{{ mb_strtolower(implode(' ',[$fullName,$user['rut'] ?? '',$user['redmine_id'] ?? '',$user['rol_modulo'] ?? '']),'UTF-8') }}">
                    <td><div class="nova-user-cell"><div class="nova-user-avatar">{{ $initials }}</div><div class="nova-user-name">{{ $fullName }}</div></div></td>
                    <td>{{ $user['rut'] ?: '—' }}<div class="nova-user-meta">{{ $user['usuario'] }}</div></td>
                    <td>{{ $user['redmine_id'] ?: 'Pendiente' }}<div class="nova-user-meta">CORE: {{ $user['usuario_core'] ?: '—' }}</div></td>
                    <td><span class="badge text-bg-primary">{{ ucfirst($user['rol_modulo'] ?: 'usuario') }}</span></td>
                    <td><span class="nova-status-badge {{ $userStatus === 'activo' ? 'is-success' : 'is-danger' }}">{{ ucfirst($userStatus) }}</span></td>
                    <td><div class="nova-table-actions"><button class="btn-action btn-action-edit" data-bs-toggle="modal" data-bs-target="#edit-user" data-user='@json($user)' title="Editar"><i class="bi bi-pencil-square"></i></button><form method="POST" action="{{ route('redmine.mantencion.users.action') }}" onsubmit="return confirm('¿Retirar el acceso de este usuario a Mantención?')">@csrf<input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="{{ $user['id'] }}"><button class="btn-action btn-action-delete" title="Retirar acceso"><i class="bi bi-person-x"></i></button></form></div></td>
                </tr>
            @empty<tr><td colspan="6" class="nova-empty">No hay usuarios habilitados para Mantención.</td></tr>@endforelse
            </tbody></table>
        </div>
    </section>
</div>

<div class="modal fade" id="edit-user" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content"><form method="POST" action="{{ route('redmine.mantencion.users.action') }}" data-user-form>@csrf<input type="hidden" name="action" value="update"><input type="hidden" name="user_id"><div class="modal-header"><h2 class="modal-title fs-5">Editar usuario</h2><button class="btn-close" type="button" data-bs-dismiss="modal"></button></div><div class="modal-body row g-3"><div class="col-md-6"><label class="form-label">Nombre</label><input class="form-control" name="nombre" required></div><div class="col-md-6"><label class="form-label">Apellido</label><input class="form-control" name="apellido" required></div><div class="col-md-6"><label class="form-label">RUT</label><input class="form-control" name="rut"></div><div class="col-md-6"><label class="form-label">Usuario CORE</label><input class="form-control" name="usuario_core"></div><div class="col-md-3"><label class="form-label">Rol Mantención</label><select class="form-select" name="rol_modulo">@foreach($roles as $role)<option value="{{ $role }}">{{ ucfirst($role) }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label">Estado NOVA</label><select class="form-select" name="estado"><option value="activo">Activo</option><option value="baneado">Baneado</option></select></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Guardar</button></div></form></div></div></div>
@endsection

@push('scripts')<script>(()=>{let status='activo';const apply=()=>{const q=(document.getElementById('users-search')?.value||'').toLocaleLowerCase('es').trim();document.querySelectorAll('[data-user-row]').forEach(r=>r.hidden=r.dataset.userStatus!==status||(q&&!(r.dataset.search||'').includes(q)));};document.getElementById('users-search')?.addEventListener('input',apply);document.querySelectorAll('[data-user-filter]').forEach(card=>{const activate=()=>{status=card.dataset.userFilter;document.querySelectorAll('[data-user-filter]').forEach(item=>item.classList.toggle('is-active',item===card));apply();};card.addEventListener('click',activate);card.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();activate();}});});document.querySelectorAll('[data-user]').forEach(b=>b.addEventListener('click',()=>{const u=JSON.parse(b.dataset.user||'{}'),f=document.querySelector('#edit-user [data-user-form]');['id','nombre','apellido','rut','usuario_core','rol_modulo','estado'].forEach(k=>{const n=k==='id'?'user_id':k;if(f?.elements[n])f.elements[n].value=u[k]||'';});}));apply();})();</script>@endpush
