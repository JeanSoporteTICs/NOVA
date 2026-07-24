<?php

namespace App\Modulos\Nova\Services\HoursExtra\Concerns;

/**
 * Formato compartido entre adapters de horas extra (Mantención/TIC).
 * Solo presentación: no lee ni escribe ninguna tabla, no conoce reglas de negocio de ningún módulo.
 */
trait FormatsHoursExtraRows
{
    /** @param array<int,array<string,mixed>> $reports */
    private function representativeUser(array $reports): string
    {
        $names = array_values(array_unique(array_filter(array_map(
            static fn (array $r): string => trim((string) ($r['asignado_nombre'] ?? '')),
            $reports
        ))));

        return $names === [] ? '—' : implode(', ', $names);
    }

    private function durationLabel(?string $horaInicio, ?string $horaFin): string
    {
        $horaInicio = trim((string) $horaInicio);
        $horaFin = trim((string) $horaFin);
        if ($horaInicio === '' || $horaFin === '') {
            return '—';
        }

        $start = strtotime('1970-01-01 ' . $horaInicio);
        $end = strtotime('1970-01-01 ' . $horaFin);
        if ($start === false || $end === false) {
            return '—';
        }
        if ($end < $start) {
            $end += 86400;
        }

        $minutes = (int) round(($end - $start) / 60);
        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        return $remainder > 0 ? "{$hours}h {$remainder}m" : "{$hours}h";
    }
}
