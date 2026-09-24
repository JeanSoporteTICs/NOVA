<?php

use App\Modulos\RedmineMantencion\Services\MantencionHorasExtraService;

// Frozen controller selection, using the original full reader and pure service rules.
return function (array $raw, string $uid, string $selMes, string $selAnio, string $anioActual): array {
    $service = new MantencionHorasExtraService;
    $grupos = $service->filterGroupsForUser($service->deduplicateGroupsBySharedDate($raw), $uid);
    $aniosDisponibles = [];
    foreach ($grupos as $g) {
        $fechaBase = $g['fecha'] ?? '';
        if ($fechaBase) {
            $dt = DateTime::createFromFormat('Y-m-d', $fechaBase) ?: DateTime::createFromFormat('d-m-Y', $fechaBase);
            if ($dt instanceof DateTime) {
                $aniosDisponibles[$dt->format('Y')] = true;
            }
        }
    }
    $aniosDisponibles = array_keys($aniosDisponibles);
    $aniosDisponibles[] = $anioActual;
    if ($selAnio !== '') {
        $aniosDisponibles[] = $selAnio;
    }
    $aniosDisponibles = array_values(array_unique(array_map('strval', $aniosDisponibles)));
    $aniosDisponibles ? sort($aniosDisponibles, SORT_NUMERIC) : [];

    $grupos = array_values(array_filter($grupos, function ($g) use ($selMes, $selAnio) {
        $fechaBase = $g['fecha'] ?? '';
        if ($fechaBase) {
            $dt = DateTime::createFromFormat('Y-m-d', $fechaBase) ?: DateTime::createFromFormat('d-m-Y', $fechaBase);
            if ($dt instanceof DateTime) {
                $mesNum = (int) $dt->format('n');
                $anioNum = $dt->format('Y');
                if ($selMes !== '' && (int) $selMes !== $mesNum) {
                    return false;
                }
                if ($selAnio !== '' && $selAnio !== $anioNum) {
                    return false;
                }
            }
        }

        return true;
    }));

    usort($grupos, function ($a, $b) use ($service) {
        $fa = $service->normalizeDateKey($a['fecha'] ?? '');
        $fb = $service->normalizeDateKey($b['fecha'] ?? '');
        if ($fa === $fb) {
            return 0;
        }
        if ($fa === '') {
            return 1;
        }
        if ($fb === '') {
            return -1;
        }

        return $fa <=> $fb; // mostrar primero las fechas más antiguas
    });

    return ['grupos' => $grupos, 'aniosDisponibles' => $aniosDisponibles];
};
