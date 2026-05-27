<div class="rm-section-head">
    <div>
        <h2>Categorias</h2>
        <p>Catalogo sincronizado desde Redmine usando la URL de categorias configurada.</p>
    </div>
    <form method="post" action="{{ $redmineRoute('redmine.native.categories.action') }}">
        @csrf
        <input type="hidden" name="action" value="sync_remote">
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-cloud-download"></i>Sincronizar Redmine</button>
    </form>
</div>
<div class="card nova-card rm-catalog-panel">
    <div class="rm-catalog-panel-head">
        <strong>{{ count($categories) }} registros</strong>
        <label class="rm-catalog-search">
            <i class="bi bi-search"></i>
            <input type="search" class="form-control" placeholder="Buscar categoria" data-catalog-search>
        </label>
    </div>
    @if (count($categories))
        <div class="rm-catalog-grid" data-catalog-grid>
            @foreach ($categories as $row)
                <article class="rm-catalog-item" data-catalog-item data-catalog-text="{{ Str::lower(($row['id'] ?? '') . ' ' . ($row['nombre'] ?? $row['name'] ?? '')) }}">
                    <span>{{ $row['id'] ?? '-' }}</span>
                    <strong>{{ $row['nombre'] ?? $row['name'] ?? '-' }}</strong>
                </article>
            @endforeach
        </div>
        <div class="rm-empty-state mt-3" data-catalog-empty hidden>No hay resultados para la busqueda.</div>
    @else
        <div class="rm-empty-state">No hay categorias registradas.</div>
    @endif
</div>

