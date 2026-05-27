<div class="rm-section-head">
    <div>
        <h2>Unidades</h2>
        <p>Catalogo sincronizado desde Redmine usando el campo personalizado de unidades.</p>
    </div>
    <form method="post" action="{{ $redmineRoute('redmine.native.units.action') }}">
        @csrf
        <input type="hidden" name="action" value="sync_remote">
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-cloud-download"></i>Sincronizar Redmine</button>
    </form>
</div>
<div class="card nova-card rm-catalog-panel">
    <div class="rm-catalog-panel-head">
        <strong>{{ count($units) }} registros</strong>
        <label class="rm-catalog-search">
            <i class="bi bi-search"></i>
            <input type="search" class="form-control" placeholder="Buscar unidad" data-catalog-search>
        </label>
    </div>
    @if (count($units))
        <div class="rm-catalog-grid" data-catalog-grid>
            @foreach ($units as $row)
                <article class="rm-catalog-item" data-catalog-item data-catalog-text="{{ Str::lower(($row['id'] ?? '') . ' ' . ($row['nombre'] ?? $row['name'] ?? '')) }}">
                    <span>{{ $row['id'] ?? '-' }}</span>
                    <strong>{{ $row['nombre'] ?? $row['name'] ?? '-' }}</strong>
                </article>
            @endforeach
        </div>
        <div class="rm-empty-state mt-3" data-catalog-empty hidden>No hay resultados para la busqueda.</div>
    @else
        <div class="rm-empty-state">No hay unidades registradas.</div>
    @endif
</div>

