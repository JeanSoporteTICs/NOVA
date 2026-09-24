<?php

use Illuminate\Support\Facades\DB;

// Frozen pre-P06 reader, bound to the repository to reuse the original mappers.
return function (): array {
    if (! $this->tableReady()) {
        return [];
    }

    $grupos = $this->shared->groupsForOrigen('mantencion');
    if ($grupos === []) {
        return [];
    }

    $todosLosReporteIds = array_values(array_unique(array_merge(...array_map(
        static fn (array $g): array => $g['reporte_ids'],
        $grupos
    ))));

    if ($todosLosReporteIds === []) {
        return [];
    }

    try {
        $rows = DB::table('redmine_mantencion_reportes as r')
            ->leftJoin('categorias as c', 'c.id', '=', 'r.categoria_id')
            ->whereIn('r.id', $todosLosReporteIds)
            ->where('r.estado', 'archivado')
            ->orderByDesc('r.fecha_reporte')
            ->orderByDesc('r.id')
            ->get(['r.*', 'c.nombre as categoria_nombre']);
    } catch (Throwable) {
        return [];
    }

    $result = [];
    foreach ($grupos as $grupo) {
        $reporteIdsDelGrupo = array_flip($grupo['reporte_ids']);
        $reportesDelGrupo = [];
        // Se itera $rows (ya ordenado por fecha_reporte/id desc) en vez de
        // reporte_ids (sin orden) para conservar el orden de visualizacion previo.
        foreach ($rows as $row) {
            if (! isset($reporteIdsDelGrupo[$row->id])) {
                continue;
            }
            $message = $this->reports->rowToMessage($row);
            $message['hora_extra'] = '1';
            $message['_fuente'] = 'horas_extra';
            $reportesDelGrupo[] = $message;
        }

        if ($reportesDelGrupo === []) {
            continue;
        }

        $result[] = [
            'fecha' => $grupo['fecha'],
            'hora_inicio' => $this->formatTime($grupo['hora_inicio']),
            'hora_fin' => $this->formatTime($grupo['hora_fin']),
            'reports' => $reportesDelGrupo,
        ];
    }

    return $result;
};
