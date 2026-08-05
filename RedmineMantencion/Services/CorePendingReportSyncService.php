<?php

namespace App\Modulos\RedmineMantencion\Services;

/**
 * Matches repeated CORE imports by stable request ID and refreshes only
 * reports that are still pending in NOVA.
 */
final class CorePendingReportSyncService
{
    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array{source:array<string,int>,core:array<string,int>}
     */
    public function indexes(array $messages): array
    {
        $indexes = ['source' => [], 'core' => []];
        foreach ($messages as $index => $message) {
            if (!is_array($message)) {
                continue;
            }

            $sourceId = $this->sourceId($message);
            if ($sourceId !== '' && !isset($indexes['source'][$sourceId])) {
                $indexes['source'][$sourceId] = $index;
            }

            $coreId = $this->coreId($message);
            if ($coreId !== '' && !isset($indexes['core'][$coreId])) {
                $indexes['core'][$coreId] = $index;
            }
        }

        return $indexes;
    }

    /**
     * @param array{source:array<string,int>,core:array<string,int>} $indexes
     */
    public function matchIndex(array $indexes, array $incoming): ?int
    {
        $coreId = $this->coreId($incoming);
        if ($coreId !== '' && isset($indexes['core'][$coreId])) {
            return (int) $indexes['core'][$coreId];
        }

        $sourceId = $this->sourceId($incoming);

        return $sourceId !== '' && isset($indexes['source'][$sourceId])
            ? (int) $indexes['source'][$sourceId]
            : null;
    }

    /**
     * @return array{eligible:bool,changed:bool,message:array<string,mixed>}
     */
    public function mergePending(array $current, array $incoming): array
    {
        $state = strtolower(trim((string) ($current['estado'] ?? 'pendiente')));
        if ($state !== 'pendiente') {
            return ['eligible' => false, 'changed' => false, 'message' => $current];
        }

        // Local workflow fields and the original persistence key must survive
        // a refresh from CORE.
        $incoming['estado'] = 'pendiente';
        $incoming['fuente_id'] = $current['fuente_id'] ?? ($incoming['fuente_id'] ?? '');
        $incoming['redmine_id'] = $current['redmine_id'] ?? ($incoming['redmine_id'] ?? '');
        $incoming['procesado_ts'] = $current['procesado_ts'] ?? ($incoming['procesado_ts'] ?? '');
        $incoming['id'] = $current['id'] ?? ($incoming['id'] ?? '');

        $merged = array_merge($current, $incoming);
        // rowToMessage() exposes DB aliases such as estado_redmine and anexo.
        // Refresh those aliases too, otherwise the repository could prioritize
        // the previous value over the newly imported CORE field.
        $merged['estado_redmine'] = $this->first($incoming, ['core_estado', 'estado_redmine']);
        $merged['correo'] = $this->first($incoming, ['core_email', 'correo']);
        $merged['anexo'] = $this->first($incoming, ['anexo', 'core_telefono', 'core_celular', 'numero']);
        $merged['unidad_texto'] = $this->first($incoming, ['unidad', 'unidad_texto', 'core_departamento', 'unidad_solicitante', 'core_establecimiento']);

        $changed = $this->persistedSignature($current) !== $this->persistedSignature($merged);

        return [
            'eligible' => true,
            'changed' => $changed,
            'message' => $changed ? $merged : $current,
        ];
    }

    public function coreId(array $message): string
    {
        foreach (['id_core', 'core_id', 'core_solicitud_id'] as $key) {
            $value = trim((string) ($message[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function sourceId(array $message): string
    {
        return trim((string) ($message['fuente_id'] ?? ''));
    }

    /**
     * Compares the CORE-backed fields that are actually persisted in
     * redmine_mantencion_reportes. Alias selection mirrors the repository.
     *
     * @return array<string,string>
     */
    private function persistedSignature(array $message): array
    {
        return [
            'core_id' => $this->coreId($message),
            'asunto' => $this->first($message, ['asunto', 'mensaje']),
            'descripcion' => $this->first($message, ['descripcion']),
            'core_estado' => $this->first($message, ['core_estado', 'estado_redmine']),
            'categoria' => $this->first($message, ['categoria', 'core_tipo_solicitud']),
            'unidad' => $this->first($message, ['unidad', 'unidad_texto', 'core_departamento']),
            'solicitante' => $this->first($message, ['solicitante']),
            'anexo' => $this->first($message, ['anexo', 'core_telefono', 'core_celular', 'numero']),
            'correo' => $this->first($message, ['core_email', 'correo']),
            'asignado_id' => $this->first($message, ['asignado_a', 'id_redmine_asignado']),
            'asignado_nombre' => $this->first($message, ['asignado_nombre', 'core_usuario_asignado']),
            'fecha' => $this->first($message, ['fecha', 'core_fecha_creacion']),
            'hora' => $this->first($message, ['hora']),
        ];
    }

    /**
     * @param array<int,string> $keys
     */
    private function first(array $message, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($message[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
