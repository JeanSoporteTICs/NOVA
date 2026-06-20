<?php

namespace App\Support\Mantencion;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MantencionReportRepository
{
    private const MODULE_KEY = 'redmine-mantencion';

    private ?int $moduleId = null;
    private bool $moduleIdResolved = false;

    public function __construct(private readonly MantencionCatalogRepository $catalogs)
    {
    }

    public function tableReady(): bool
    {
        try {
            return Schema::hasTable('modulos_nova')
                && Schema::hasTable('redmine_mantencion_reportes');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Return all fuente_id values that already exist in this module's table.
     *
     * Scoped to a specific fuente when provided (e.g. 'core').
     * Used to build the DB-based duplicate guard in the CORE import loop.
     *
     * @return array<string, true>
     */
    public function getExistingFuenteIds(string $fuente = ''): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        $moduleId = $this->resolveModuleId();
        if ($moduleId === null) {
            return [];
        }

        try {
            $query = DB::table('redmine_mantencion_reportes')
                ->where('modulo_id', $moduleId)
                ->whereNotNull('fuente_id');

            if ($fuente !== '') {
                $query->where('fuente', $fuente);
            }

            return $query->pluck('fuente_id')
                ->mapWithKeys(fn (string $id): array => [$id => true])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Physically delete report rows by their fuente_id values.
     *
     * @param array<int,string> $fuenteIds
     */
    public function deleteByFuenteIds(array $fuenteIds): void
    {
        if (! $this->tableReady() || empty($fuenteIds)) {
            return;
        }

        $moduleId = $this->resolveModuleId();
        if ($moduleId === null) {
            return;
        }

        try {
            DB::table('redmine_mantencion_reportes')
                ->where('modulo_id', $moduleId)
                ->whereIn('fuente_id', $fuenteIds)
                ->delete();
        } catch (\Throwable) {
        }
    }

    /** @param array<int,array<string,mixed>> $messages */
    public function syncMessages(array $messages, array $config = []): void
    {
        if (! $this->tableReady()) {
            return;
        }

        foreach ($messages as $message) {
            if (is_array($message)) {
                $this->upsertMessage($message, $config);
            }
        }
    }

    /** @param array<string,mixed> $message */
    public function upsertMessage(array $message, array $config = []): void
    {
        $moduleId = $this->resolveModuleId();
        if ($moduleId === null || ! $this->tableReady()) {
            return;
        }

        $fuente = trim((string) ($message['fuente'] ?? ''));
        $fuenteId = trim((string) ($message['fuente_id'] ?? ''));
        if ($fuenteId === '') {
            $fuenteId = trim((string) ($message['id'] ?? ''));
        }
        if ($fuenteId === '') {
            return;
        }

        $values = $this->filterColumns($this->payload($moduleId, $message, $config));

        try {
            $existing = DB::table('redmine_mantencion_reportes')
                ->where('modulo_id', $moduleId)
                ->where('fuente', $fuente !== '' ? $fuente : null)
                ->where('fuente_id', $fuenteId)
                ->value('id');

            if ($existing !== null) {
                DB::table('redmine_mantencion_reportes')->where('id', $existing)->update($values);
                return;
            }

            $values['creado_at'] = now();
            DB::table('redmine_mantencion_reportes')->insert($values);
        } catch (\Throwable) {
        }
    }

    /** @return array<string,mixed> */
    private function payload(int $moduleId, array $message, array $config): array
    {
        $fuente = trim((string) ($message['fuente'] ?? ''));
        $fuenteId = trim((string) ($message['fuente_id'] ?? $message['id'] ?? ''));
        $projectId = trim((string) ($message['project_id'] ?? $config['project_id'] ?? ''));
        $trackerId = trim((string) ($message['tipo_id'] ?? $message['tracker_id'] ?? $config['tracker_id'] ?? ''));
        $statusId = trim((string) ($message['status_id'] ?? $config['status_id'] ?? ''));
        $priorityId = trim((string) ($message['priority_id'] ?? $config['priority_id'] ?? ''));
        $categoriaNombre = trim((string) ($message['categoria'] ?? $message['core_tipo_solicitud'] ?? ''));
        $unidadTexto = $this->unidadTexto($message);
        $redmineId = $this->integerOrNull((string) ($message['redmine_id'] ?? $message['numero_ticket_redmine'] ?? ''));

        return [
            'modulo_id' => $moduleId,
            'fuente' => $fuente !== '' ? $fuente : null,
            'fuente_id' => $fuenteId !== '' ? $fuenteId : null,
            'id_core' => $this->idCore($message),
            'proyecto' => trim((string) ($message['proyecto'] ?? $message['project_name'] ?? $config['project_name'] ?? '')) ?: null,
            'project_id' => $projectId !== '' ? $projectId : null,
            'tipo' => trim((string) ($message['tipo'] ?? $message['core_tipo_solicitud'] ?? '')) ?: null,
            'tipo_id' => $trackerId !== '' ? $trackerId : null,
            'asunto' => trim((string) ($message['asunto'] ?? $message['mensaje'] ?? '')) ?: null,
            'descripcion' => trim((string) ($message['descripcion'] ?? '')) ?: null,
            'estado' => trim((string) ($message['estado'] ?? 'pendiente')) ?: 'pendiente',
            'estado_redmine' => trim((string) ($message['estado_redmine'] ?? $message['core_estado'] ?? '')) ?: null,
            'estado_id' => $statusId !== '' ? $statusId : null,
            'prioridad' => trim((string) ($message['prioridad'] ?? '')) ?: null,
            'priority_id' => $priorityId !== '' ? $priorityId : null,
            'id_redmine_asignado' => trim((string) ($message['asignado_a'] ?? $message['id_redmine_asignado'] ?? '')) ?: null,
            'asignado_nombre' => trim((string) ($message['asignado_nombre'] ?? $message['core_usuario_asignado'] ?? '')) ?: null,
            'categoria_id' => $categoriaNombre !== '' ? $this->catalogs->categoriaIdPorNombre($categoriaNombre) : null,
            'solicitante' => trim((string) ($message['solicitante'] ?? '')) ?: null,
            'anexo' => trim((string) ($message['anexo'] ?? $message['core_telefono'] ?? $message['core_celular'] ?? $message['numero'] ?? '')) ?: null,
            'unidad_texto' => $unidadTexto !== '' ? $unidadTexto : null,
            'fecha_inicio' => $this->parseDate((string) ($message['fecha_inicio'] ?? $message['fecha'] ?? '')),
            'fecha_fin' => $this->parseDate((string) ($message['fecha_fin'] ?? $message['fecha_inicio'] ?? $message['fecha'] ?? '')),
            'fecha_reporte' => $this->parseDate((string) ($message['fecha'] ?? $message['core_fecha_creacion'] ?? '')),
            'hora_reporte' => $this->parseTime((string) ($message['hora'] ?? $message['core_fecha_creacion'] ?? '')),
            'tiempo_estimado' => $this->decimalOrNull((string) ($message['tiempo_estimado'] ?? '')),
            'correo' => trim((string) ($message['core_email'] ?? $message['correo'] ?? '')) ?: null,
            'hora_extra' => $this->truthy((string) ($message['hora_extra'] ?? '')),
            'numero_ticket_redmine' => $redmineId,
            'actualizado_at' => now(),
        ];
    }

    /** @return array<string,mixed> */
    private function filterColumns(array $values): array
    {
        foreach (array_keys($values) as $column) {
            if (! Schema::hasColumn('redmine_mantencion_reportes', $column)) {
                unset($values[$column]);
            }
        }

        return $values;
    }

    private function unidadTexto(array $message): string
    {
        foreach (['unidad', 'unidad_texto', 'core_departamento', 'unidad_solicitante', 'core_establecimiento'] as $key) {
            $value = trim((string) ($message[$key] ?? ''));
            if ($value !== '' && strtoupper($value) !== 'N/A') {
                return $value;
            }
        }

        return '';
    }

    private function idCore(array $message): ?string
    {
        foreach (['id_core', 'core_id'] as $key) {
            $value = trim((string) ($message[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        if (($message['fuente'] ?? '') === 'core') {
            $value = trim((string) ($message['core_solicitud_id'] ?? $message['fuente_id'] ?? ''));
            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i'] as $format) {
            try {
                return Carbon::createFromFormat($format, substr($value, 0, strlen($format)))->toDateString();
            } catch (\Throwable) {
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['H:i:s', 'H:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd/m/Y H:i:s', 'd/m/Y H:i'] as $format) {
            try {
                return Carbon::createFromFormat($format, substr($value, 0, strlen($format)))->format('H:i:s');
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function decimalOrNull(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));
        return is_numeric($value) ? (float) $value : null;
    }

    private function integerOrNull(string $value): ?int
    {
        $value = trim($value);
        return ctype_digit($value) ? (int) $value : null;
    }

    private function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'si', 's', 'true', 'yes'], true);
    }

    private function resolveModuleId(): ?int
    {
        if ($this->moduleIdResolved) {
            return $this->moduleId;
        }

        $this->moduleIdResolved = true;

        try {
            $id = DB::table('modulos_nova')->where('clave_modulo', self::MODULE_KEY)->value('id');
            $this->moduleId = $id !== null ? (int) $id : null;
        } catch (\Throwable) {
            $this->moduleId = null;
        }

        return $this->moduleId;
    }
}
