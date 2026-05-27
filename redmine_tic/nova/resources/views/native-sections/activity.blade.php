<div class="rm-section-head">
    <h2>Actividad reciente</h2>
    <form method="post" action="{{ $redmineRoute('redmine.native.activity.action') }}">
        @csrf
        <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-trash"></i>Limpiar actividad</button>
    </form>
</div>
<span class="nova-muted">{{ count($lines) }} eventos</span>
<pre class="rm-log">@forelse ($lines as $line){{ $line }}
@empty
Sin actividad registrada.
@endforelse</pre>

