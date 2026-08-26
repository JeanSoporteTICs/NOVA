<?php

namespace RedmineTic\Services;

use RedmineTic\Support\CatalogMatchSupport;

final class QuickReportService
{
    /**
     * @param  array<int,array<string,mixed>>  $categories
     * @param  array<int,array<string,mixed>>  $units
     * @return array{ok:bool,error:string,input:string,draft:array<string,mixed>}
     */
    public function createDraft(string $input, array $categories, array $units, string $assigneeId): array
    {
        $parts = array_map('trim', explode(',', trim($input)));
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            return [
                'ok' => false,
                'error' => 'Escribe problema, unidad y solicitante separados por comas.',
                'input' => '',
                'draft' => [],
            ];
        }

        [$problem, $unitText, $requester] = $parts;
        $categoryNames = $this->catalogNames($categories);
        $unitNames = $this->catalogNames($units);
        $category = CatalogMatchSupport::inferCatalogMatch($problem, $categoryNames)
            ?: CatalogMatchSupport::inferCatalogMatch($problem.' '.$unitText, $categoryNames)
            ?: $this->preferredCatalogValue($categoryNames, 'Equipos');
        $requestUnit = CatalogMatchSupport::inferCatalogMatch($unitText, $unitNames)
            ?: $this->exactCatalogValue($unitNames, 'HBV');
        $now = now('America/Santiago');
        $normalizedInput = implode(', ', $parts);

        return [
            'ok' => true,
            'error' => '',
            'input' => $normalizedInput,
            'draft' => [
                'tipo' => 'Soporte',
                'prioridad' => 'NORMAL',
                'asunto' => $problem.' / '.$unitText,
                'descripcion' => '',
                'solicitante' => $requester,
                'unidad' => $unitText,
                'unidad_solicitante' => $requestUnit,
                'categoria' => $category,
                'asignado_a' => trim($assigneeId),
                'fecha_inicio' => $now->format('Y-m-d'),
                'fecha_fin' => $now->format('Y-m-d'),
                'fecha' => $now->format('Y-m-d'),
                'hora' => $now->format('H:i'),
                'chat_id_telegram' => '',
                'mensaje' => $normalizedInput,
                'hora_extra' => 'NO',
                'tiempo_estimado' => '',
                'origen' => 'manual_rapido',
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $users
     * @return array{id:string,name:string,chat_id:string}|null
     */
    public function assignedRecipient(array $users, string $assigneeId): ?array
    {
        $assigneeId = trim($assigneeId);
        if ($assigneeId === '') {
            return null;
        }

        foreach ($users as $user) {
            $projectState = strtolower(trim((string) ($user['estado_usuario'] ?? $user['estado'] ?? 'activo')));
            $novaState = strtolower(trim((string) ($user['estado_nova'] ?? 'activo')));
            if ($projectState !== 'activo' || $novaState !== 'activo') {
                continue;
            }
            $redmineId = trim((string) ($user['redmine_id'] ?? ''));
            if (! ctype_digit($redmineId) || $assigneeId !== $redmineId) {
                continue;
            }

            $name = trim((string) (($user['nombre'] ?? '').' '.($user['apellido'] ?? '')));
            if ($name === '') {
                $name = trim((string) ($user['nombre_completo'] ?? $user['usuario'] ?? $user['username'] ?? $assigneeId));
            }

            return [
                'id' => $assigneeId,
                'name' => $name,
                'chat_id' => trim((string) ($user['telegram_chat_id'] ?? data_get($user, 'telegram_settings.chat_id', ''))),
            ];
        }

        return null;
    }

    /** @param array<string,mixed> $report */
    public function notificationMessage(array $report, string $redmineId, string $issueUrl): string
    {
        $unit = trim((string) ($report['unidad_solicitante'] ?? ''));
        if ($unit === '') {
            $unit = trim((string) ($report['unidad'] ?? ''));
        }
        $lines = [
            'Nuevo reporte TIC asignado',
            'Redmine #'.$redmineId,
            'Problema: '.trim((string) ($report['asunto'] ?? '')),
            'Solicitante: '.trim((string) ($report['solicitante'] ?? '')),
            'Unidad: '.$unit,
            'Categoría: '.trim((string) ($report['categoria'] ?? '')),
            'Prioridad: '.trim((string) ($report['prioridad'] ?? 'NORMAL')),
        ];
        if ($issueUrl !== '') {
            $lines[] = 'Ver reporte: '.$issueUrl;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return string[]
     */
    public function catalogNames(array $rows): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['nombre'] ?? $row['id'] ?? '')),
            $rows
        ))));
    }

    /** @param string[] $values */
    private function preferredCatalogValue(array $values, string $preferred): string
    {
        foreach ($values as $value) {
            if (strcasecmp($value, $preferred) === 0) {
                return $value;
            }
        }

        return $values[0] ?? '';
    }

    /** @param string[] $values */
    private function exactCatalogValue(array $values, string $expected): string
    {
        foreach ($values as $value) {
            if (strcasecmp($value, $expected) === 0) {
                return $value;
            }
        }

        return '';
    }
}
