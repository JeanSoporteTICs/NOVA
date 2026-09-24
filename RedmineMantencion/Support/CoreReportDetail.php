<?php

namespace App\Modulos\RedmineMantencion\Support;

final class CoreReportDetail
{
    private const FIELDS = [
        'core_tipo_solicitud',
        'core_detalle_tipo_solicitud',
        'core_detalle_run',
        'core_detalle_nombre',
        'core_detalle_motivo',
        'core_detalle_establecimientos',
        'core_detalle_otros_permisos',
        'core_detalle_fecha_nacimiento',
        'core_detalle_email',
        'core_detalle_departamento',
        'core_detalle_cargo',
        'core_detalle_rol',
        'core_detalle_estado',
    ];

    public static function encode(array $message): ?string
    {
        $detail = [];
        foreach (self::FIELDS as $field) {
            $value = trim((string) ($message[$field] ?? ''));
            if ($value !== '') {
                $detail[$field] = $value;
            }
        }
        $items = array_values(array_filter((array) ($message['core_detalle_items'] ?? []), 'is_array'));
        if ($items !== []) {
            $detail['core_detalle_items'] = $items;
        }

        if ($detail === []) {
            return null;
        }
        $encoded = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }

    public static function fromDescription(string $description): array
    {
        $labels = [
            'Tipo de solicitud' => 'core_tipo_solicitud',
            'Detalle tipo solicitud' => 'core_detalle_tipo_solicitud',
            'RUN' => 'core_detalle_run',
            'Nombre' => 'core_detalle_nombre',
            'Motivo' => 'core_detalle_motivo',
            'Establecimientos' => 'core_detalle_establecimientos',
            'Otros permisos' => 'core_detalle_otros_permisos',
        ];
        $detail = [];
        foreach (preg_split('/\R/u', $description) ?: [] as $line) {
            foreach ($labels as $label => $field) {
                if (preg_match('/^' . preg_quote($label, '/') . ':\s*(.*)$/u', trim($line), $match)
                    && trim($match[1]) !== '') {
                    $detail[$field] = trim($match[1]);
                    break;
                }
            }
        }
        $row = [];
        foreach ($detail as $field => $value) {
            if (str_starts_with($field, 'core_detalle_')) {
                $row['detalle_' . substr($field, strlen('core_detalle_'))] = $value;
            }
        }
        if ($row !== []) {
            $row['detalle_tipo_solicitud'] = $row['detalle_tipo_solicitud']
                ?? ($detail['core_tipo_solicitud'] ?? '');
            $detail['core_detalle_items'] = [$row];
        }

        return $detail;
    }

    public static function decode(mixed $value): array
    {
        $detail = is_array($value) ? $value : json_decode((string) $value, true);
        if (!is_array($detail)) {
            return [];
        }
        $result = [];
        foreach (self::FIELDS as $field) {
            if (isset($detail[$field]) && is_scalar($detail[$field])) {
                $result[$field] = trim((string) $detail[$field]);
            }
        }
        if (isset($detail['core_detalle_items']) && is_array($detail['core_detalle_items'])) {
            $result['core_detalle_items'] = array_values(array_filter($detail['core_detalle_items'], 'is_array'));
        }

        return $result;
    }
}
