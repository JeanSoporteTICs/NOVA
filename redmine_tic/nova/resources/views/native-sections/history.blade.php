@php
    $fmtDate = static function ($value): string {
        $value = trim((string) $value);
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date) {
                return $date->format('d-m-Y');
            }
        }

        return $value ?: '-';
    };
@endphp

<div class="rm-section-head"><h2>Historico</h2><span class="nova-muted">{{ count($rows) }} registros recientes</span></div>
<div class="nova-card nova-table-wrap">
    <table class="table mb-0"><thead><tr><th>Redmine</th><th>Asunto</th><th>Unidad</th><th>Asignado</th><th>Fecha</th><th>Tipo</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
        @forelse ($rows as $row)
            <tr>
                <td>{{ $row['redmine_id'] ?? '-' }}</td>
                <td>{{ $row['asunto'] ?? $row['mensaje'] ?? '-' }}</td>
                <td>{{ $row['unidad_solicitante'] ?? $row['unidad'] ?? '-' }}</td>
                <td>{{ $row['asignado_nombre'] ?? $row['asignado_a'] ?? '-' }}</td>
                <td>{{ $fmtDate($row['fecha_inicio'] ?? $row['fecha'] ?? '') }}</td>
                <td><span class="nova-badge {{ !empty($row['_history_is_hours_extra']) ? 'is-success' : '' }}">{{ $row['_history_type'] ?? 'Archivado' }}</span></td>
                <td>{{ $row['estado'] ?? '-' }}</td>
                <td>
                    @if (!empty($row['_history_can_delete']))
                        <form method="post" action="{{ $redmineRoute('redmine.native.history.action') }}">
                            @csrf
                            <input type="hidden" name="id" value="{{ $row['id'] ?? '' }}">
                            <button class="btn btn-danger nova-btn-icon" type="submit"><i class="bi bi-trash"></i></button>
                        </form>
                    @else
                        <span class="nova-muted">-</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="8">No hay historico disponible.</td></tr>
        @endforelse
    </tbody></table>
</div>

