<?php

namespace App\Modulos\Nova\Services\HoursExtra;

use App\Modulos\Nova\Services\HoursExtra\Concerns\FormatsHoursExtraRows;
use App\Modulos\RedmineMantencion\Repositories\MantencionHoursExtraRepository;

/**
 * Lee y actualiza únicamente los datos de horas extra de RedmineMantencion
 * a través de su propio repositorio. No accede a ninguna tabla de RedmineTic.
 */
final class MantencionHoursExtraAdapter
{
    use FormatsHoursExtraRows;

    private const ORIGEN = 'mantencion';
    private const MODULO_LABEL = 'Mantención';

    public function __construct(private readonly MantencionHoursExtraRepository $repository)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function rows(string $assignedUserId): array
    {
        if ($assignedUserId === '') {
            return [];
        }

        $rows = [];
        $urlOrigen = route('redmine.mantencion.section', 'horas-extra');

        foreach ($this->repository->groups() as $group) {
            $reports = array_values(array_filter(
                (array) ($group['reports'] ?? []),
                static fn ($report): bool => is_array($report) && (string) ($report['asignado_a'] ?? '') === $assignedUserId
            ));
            if ($reports === []) {
                continue;
            }

            $fecha = (string) ($group['fecha'] ?? '');
            $rows[] = [
                'origen' => self::ORIGEN,
                'modulo' => self::ORIGEN,
                'modulo_label' => self::MODULO_LABEL,
                'grupo_id' => self::ORIGEN . ':' . $fecha,
                'fecha' => $fecha,
                'hora_inicio' => (string) ($group['hora_inicio'] ?? ''),
                'hora_fin' => (string) ($group['hora_fin'] ?? ''),
                'total_horas' => $this->durationLabel($group['hora_inicio'] ?? null, $group['hora_fin'] ?? null),
                'usuario' => $this->representativeUser($reports),
                'reportes' => array_map(static fn (array $r): array => [
                    'id_redmine' => trim((string) ($r['redmine_id'] ?? $r['numero_ticket_redmine'] ?? '')) ?: '—',
                    'detalle' => trim((string) ($r['asunto'] ?? '')) ?: '—',
                    'proyecto' => trim((string) ($r['proyecto'] ?? '')) ?: '—',
                    'url_origen' => $urlOrigen,
                    'modulo' => self::ORIGEN,
                    'modulo_label' => self::MODULO_LABEL,
                ], $reports),
            ];
        }

        return $rows;
    }

    /**
     * Corrige hora_inicio/hora_fin de un grupo ya existente (identificado por
     * fecha, única por módulo). No crea grupos nuevos ni toca reportes.
     */
    public function updateGroupTime(string $fecha, string $horaInicio, string $horaFin): bool
    {
        return $this->repository->updateGroupHours($fecha, $horaInicio, $horaFin);
    }
}
