@php
    $catChunks = array_chunk($categories, max(1, (int) ceil(count($categories) / 2)));
    $catLeft   = $catChunks[0] ?? [];
    $catRight  = $catChunks[1] ?? [];
    $catOffset = count($catLeft);
@endphp

<div class="rm-catalog-action-head">
    <div>
        <strong><i class="bi bi-cloud-download"></i> Sincronizacion Redmine</strong>
        <span>Actualiza este catalogo usando la URL de categorias configurada.</span>
    </div>
    <form method="post" action="{{ $redmineRoute('redmine.native.categories.action') }}">
        @csrf
        <input type="hidden" name="action" value="sync_remote">
        <button class="btn-nova btn-nova-info" type="submit"><i class="bi bi-cloud-download"></i>Sincronizar Redmine</button>
    </form>
</div>
<div class="rm-catalog-panel">
    <div class="rm-catalog-panel-head">
        <strong>{{ count($categories) }} registros</strong>
        <label class="rm-catalog-search">
            <i class="bi bi-search"></i>
            <input type="search" class="form-control" placeholder="Buscar categoria" data-catalog-search>
        </label>
    </div>
    @if (count($categories))
        <div class="rm-catalog-cols">
            @foreach ([[$catLeft, 0], [$catRight, $catOffset]] as [$chunk, $offset])
                @if (count($chunk))
                    <table class="rm-catalog-table">
                        <thead>
                            <tr>
                                <th class="rm-ct-num">#</th>
                                <th class="rm-ct-id">ID</th>
                                <th>Nombre</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($chunk as $row)
                                <tr data-catalog-item data-catalog-text="{{ Str::lower(($row['id'] ?? '') . ' ' . ($row['nombre'] ?? $row['name'] ?? '')) }}">
                                    <td class="rm-ct-num">{{ $offset + $loop->iteration }}</td>
                                    <td class="rm-ct-id"><span title="{{ $row['id'] ?? '-' }}">{{ $row['id'] ?? '-' }}</span></td>
                                    <td title="{{ $row['nombre'] ?? $row['name'] ?? '-' }}">{{ $row['nombre'] ?? $row['name'] ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            @endforeach
        </div>
        <div class="rm-empty-state mt-3" data-catalog-empty hidden>No hay resultados para la busqueda.</div>
    @else
        <div class="rm-empty-state">No hay categorias registradas.</div>
    @endif
</div>
