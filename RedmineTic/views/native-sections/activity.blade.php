<section class="rm-module-head">
    <span class="rm-module-head-icon is-red"><i class="bi bi-activity"></i></span>
    <div>
        <small>Bitacora TIC</small>
        <h2>Actividad reciente</h2>
        <p>Revisa eventos operativos del modulo y limpia la bitacora cuando corresponda.</p>
    </div>
    <div class="rm-module-meter">
        <strong>{{ count($lines) }}</strong>
        <span>eventos</span>
    </div>
</section>

<section class="card nova-card rm-work-panel">
    <div class="card-body p-4">
        <div class="rm-section-head">
            <div>
                <h2>Registro de actividad</h2>
                <p>Eventos recientes generados por acciones del modulo TIC.</p>
            </div>
            <form method="post" action="{{ $redmineRoute('redmine.native.activity.action') }}">
                @csrf
                <button class="btn-nova btn-nova-secondary" type="submit"><i class="bi bi-trash"></i>Limpiar actividad</button>
            </form>
        </div>
<pre class="rm-log">@forelse ($lines as $line){{ $line }}
@empty
Sin actividad registrada.
@endforelse</pre>
    </div>
</section>
