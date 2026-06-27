@php
    $unitChunks = array_chunk($units, max(1, (int) ceil(count($units) / 2)));
    $unitLeft   = $unitChunks[0] ?? [];
    $unitRight  = $unitChunks[1] ?? [];
    $unitOffset = count($unitLeft);
@endphp

<div class="rm-catalog-action-head">
    <div>
        <strong><i class="bi bi-cloud-download"></i> Sincronizacion Redmine</strong>
        <span>Actualiza este catalogo usando el campo personalizado de unidades.</span>
    </div>
    <form method="post" action="{{ $redmineRoute('redmine.native.units.action') }}">
        @csrf
        <input type="hidden" name="action" value="sync_remote">
        <button class="btn btn-outline-primary" type="submit"><i class="bi bi-cloud-download"></i>Sincronizar Redmine</button>
    </form>
</div>
<div class="rm-catalog-panel">
    <div class="rm-catalog-panel-head">
        <strong>{{ count($units) }} registros</strong>
        <label class="rm-catalog-search">
            <i class="bi bi-search"></i>
            <input type="search" class="form-control" placeholder="Buscar unidad" data-catalog-search>
        </label>
    </div>
    @if (count($units))
        <div class="rm-catalog-cols">
            @foreach ([[$unitLeft, 0], [$unitRight, $unitOffset]] as [$chunk, $offset])
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
        <div class="rm-empty-state">No hay unidades registradas.</div>
    @endif
</div>
