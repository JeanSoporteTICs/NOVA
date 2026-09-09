<?php

namespace App\Modulos\RedmineMantencion\Services;

final class MantencionHoursExtraFields
{
    public static function normalize(array $record): array
    {
        $enabled = in_array(strtolower(trim((string) ($record['hora_extra'] ?? ''))), ['1', 'si', 'sí', 'true'], true);
        $hours = trim((string) ($record['tiempo_estimado'] ?? ''));
        $record['hora_extra'] = $enabled ? '1' : '0';
        $record['tiempo_estimado'] = $enabled ? ($hours !== '' ? $hours : '1') : '';

        return $record;
    }
}
