@extends('redmine_mantencion::native.layout', ['pageTitle' => 'Cuentas conectadas | Redmine Mantención', 'activeSection' => 'integraciones'])

@section('content')
@php
    $configuredCount = collect($integrations)->filter(fn ($item) => !empty($item['stored']))->count();
    $formatDate = static function ($value): string {
        try { return trim((string) $value) === '' ? '—' : \Illuminate\Support\Carbon::parse($value)->timezone('America/Santiago')->format('d/m/Y H:i'); }
        catch (\Throwable) { return (string) $value; }
    };
@endphp
<div class="container-fluid py-4">
    @include('redmine_mantencion::native.partials.hero', [
        'icon' => 'bi-person-lock',
        'title' => 'Cuentas conectadas',
        'subtitle' => 'Configure las cuentas personales utilizadas por Redmine Mantención para conectarse con Redmine y CORE.',
        'badges' => [['icon' => 'bi-shield-check', 'label' => $configuredCount.' configurada(s)']],
    ])

    @if(session('integration_status'))<div data-nova-flash="success" data-nova-flash-message="{{ session('integration_status') }}" hidden></div>@endif
    @if(session('integration_error'))<div data-nova-flash="warning" data-nova-flash-message="{{ session('integration_error') }}" hidden></div>@endif

    <section class="integration-grid mb-3">
        @foreach($integrationDefinitions as $type => $definition)
            @php
                $state = $integrations[$type] ?? [];
                $stored = !empty($state['stored']);
                $needsUser = !empty($definition['external_required']);
                $complete = $stored && !empty($state['has_secret']) && (!$needsUser || !empty($state['has_external_user']));
                $drawerId = 'integration-drawer-'.$type;
            @endphp
            <article class="integration-card nova-card integration-card-summary" role="button" tabindex="0" data-integration-card data-drawer-target="{{ $drawerId }}" aria-controls="{{ $drawerId }}">
                <div class="integration-card-head">
                    <div class="integration-title"><span class="integration-icon"><i class="bi {{ $definition['icon'] }}"></i></span><div><h2>{{ $definition['label'] }}</h2><p>{{ $definition['description'] }}</p></div></div>
                    <span class="integration-card-open" aria-hidden="true"><i class="bi bi-sliders"></i></span>
                </div>
                <div class="integration-card-status"><span class="integration-status {{ $complete ? 'is-ready' : 'is-empty' }}"><i class="bi {{ $complete ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill' }}"></i>{{ $complete ? 'Configurada' : 'Pendiente' }}</span></div>
                <dl class="integration-meta">
                    @if($needsUser)<div><dt>Usuario</dt><dd>{{ ($state['masked_external_user'] ?? '') ?: 'No configurado' }}</dd></div>@endif
                    <div><dt>Actualización</dt><dd>{{ $formatDate($state['updated_at'] ?? '') }}</dd></div>
                </dl>
                <div class="integration-card-actions"><button type="button" class="btn-nova btn-nova-primary" data-open-drawer="{{ $drawerId }}"><i class="bi bi-sliders"></i> Configurar</button></div>
            </article>

            <div class="offcanvas offcanvas-end integration-drawer" tabindex="-1" id="{{ $drawerId }}" aria-labelledby="{{ $drawerId }}-title">
                <div class="offcanvas-header integration-drawer-title">
                    <span class="integration-icon"><i class="bi {{ $definition['icon'] }}"></i></span>
                    <div><h2 class="offcanvas-title h5 mb-1" id="{{ $drawerId }}-title">{{ $definition['label'] }}</h2><span class="integration-status {{ $complete ? 'is-ready' : 'is-empty' }}">{{ $complete ? 'Configurada' : 'Pendiente' }}</span></div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
                </div>
                <div class="offcanvas-body">
                    <p class="text-muted">{{ $definition['description'] }}</p>
                    <dl class="integration-meta integration-drawer-meta">@if($needsUser)<div><dt>Usuario actual</dt><dd>{{ ($state['masked_external_user'] ?? '') ?: 'No configurado' }}</dd></div>@endif<div><dt>Última actualización</dt><dd>{{ $formatDate($state['updated_at'] ?? '') }}</dd></div></dl>
                    <form method="POST" action="{{ $postUrl }}" class="integration-form js-integration-form">@csrf<input type="hidden" name="action" value="save"><input type="hidden" name="type" value="{{ $type }}">
                        @if($needsUser)<div><label class="form-label" for="integration-user-{{ $type }}">{{ $definition['external_label'] }}</label><input class="form-control" id="integration-user-{{ $type }}" name="external_user" autocomplete="username" placeholder="{{ ($state['masked_external_user'] ?? '') ?: $definition['external_label'] }}">@if($stored)<div class="form-text">Dejar vacío para conservar el usuario actual.</div>@endif</div>@else<input type="hidden" name="external_user" value="">@endif
                        <div><label class="form-label" for="integration-secret-{{ $type }}">{{ $definition['secret_label'] }}</label><div class="input-group integration-secret-group" data-secret-wrapper><input class="form-control" id="integration-secret-{{ $type }}" name="secret" type="password" autocomplete="new-password" placeholder="{{ $stored ? '**************' : $definition['secret_label'] }}"><button class="btn btn-outline-secondary" type="button" data-toggle-secret aria-controls="integration-secret-{{ $type }}"><i class="bi bi-eye"></i></button></div><div class="form-text">{{ $stored ? 'Dejar vacío para conservar el secreto actual.' : 'El secreto se almacena cifrado y nunca vuelve a mostrarse.' }}</div></div>
                        <button class="btn-nova btn-nova-primary w-100" type="submit"><i class="bi bi-check2"></i> Guardar {{ $definition['label'] }}</button>
                    </form>
                    @if($stored)<form method="POST" action="{{ $postUrl }}" class="integration-delete-form js-integration-delete" data-app-confirm="¿Eliminar la cuenta {{ $definition['label'] }}?">@csrf<input type="hidden" name="action" value="delete"><input type="hidden" name="type" value="{{ $type }}"><button class="btn btn-outline-danger w-100" type="submit"><i class="bi bi-trash"></i> Eliminar credencial</button></form>@endif
                </div>
            </div>
        @endforeach
    </section>

    <section class="integration-summary nova-card">
        <div class="rm-section-head"><div><span class="rm-section-kicker">Resumen personal</span><h2 class="h5 mb-1">Estado de conexiones</h2><p class="text-muted mb-0">Cada credencial pertenece al usuario conectado.</p></div><span class="badge text-bg-primary rounded-pill">{{ $configuredCount }} de {{ count($integrationDefinitions) }}</span></div>
        <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Integración</th><th>Estado</th><th>Usuario externo</th><th>Actualización</th></tr></thead><tbody>@foreach($integrationDefinitions as $type => $definition)@php $state=$integrations[$type]??[];$stored=!empty($state['stored']);$needsUser=!empty($definition['external_required']);$complete=$stored&&!empty($state['has_secret'])&&(!$needsUser||!empty($state['has_external_user'])); @endphp<tr><td><i class="bi {{ $definition['icon'] }} me-2"></i>{{ $definition['label'] }}</td><td><span class="integration-status {{ $complete ? 'is-ready' : 'is-empty' }}">{{ $complete ? 'Configurada' : 'Pendiente' }}</span></td><td>{{ ($state['masked_external_user'] ?? '') ?: '—' }}</td><td>{{ $formatDate($state['updated_at'] ?? '') }}</td></tr>@endforeach</tbody></table></div>
    </section>
</div>
@endsection

@push('scripts')
<script>(()=>{const open=id=>{const node=document.getElementById(id);if(node&&window.bootstrap)bootstrap.Offcanvas.getOrCreateInstance(node).show();};document.addEventListener('click',event=>{const toggle=event.target.closest('[data-toggle-secret]');if(toggle){const input=document.getElementById(toggle.getAttribute('aria-controls'));if(input){input.type=input.type==='password'?'text':'password';toggle.querySelector('i').className=input.type==='password'?'bi bi-eye':'bi bi-eye-slash';}return;}const button=event.target.closest('[data-open-drawer]');if(button){event.stopPropagation();open(button.dataset.openDrawer);return;}const card=event.target.closest('[data-integration-card]');if(card)open(card.dataset.drawerTarget);});document.querySelectorAll('[data-integration-card]').forEach(card=>card.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();open(card.dataset.drawerTarget);}}));})();</script>
@endpush
