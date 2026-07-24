<?php

namespace App\Modulos\Nova\Services\HoursExtra;

use App\Modulos\Nova\Services\HoursExtra\Concerns\FormatsHoursExtraRows;
use RedmineTic\Repositories\RedmineDataRepository;

/**
 * Lee y actualiza únicamente los datos de horas extra de RedmineTic a
 * través del método público RedmineDataRepository::hoursExtra()/
 * saveHoursGroup(). No accede a ninguna tabla de RedmineMantencion.
 */
final class TicHoursExtraAdapter
{
    use FormatsHoursExtraRows;

    private const ORIGEN = 'tic';
    private const MODULO_LABEL = 'TIC';

    public function __construct(private readonly RedmineDataRepository $repository)
    {
    }

    /** @return array<int,array<string,mixed>> */
    public function rows(string $assignedUserId): array
    {
        if ($assignedUserId === '') {
            return [];
        }

        $rows = [];
        $urlOrigen = route('redmine.native.section', 'horas-extra');
        // TIC no guarda un "proyecto" por reporte (a diferencia de Mantención):
        // todos sus tickets pertenecen al único proyecto Redmine configurado para este módulo.
        $config = $this->repository->configuration();
        $proyecto = trim((string) ($config['project_name'] ?? '')) ?: 'Backlog Soporte TI';

        foreach ($this->repository->hoursExtra() as $group) {
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
                    'id_redmine' => trim((string) ($r['redmine_id'] ?? '')) ?: '—',
                    'detalle' => trim((string) ($r['asunto'] ?? '')) ?: '—',
                    'proyecto' => $proyecto,
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
     * El primer parámetro de saveHoursGroup() es un vestigio del antiguo
     * almacenamiento en archivo; ya no se usa dentro del repositorio.
     */
    public function updateGroupTime(string $fecha, string $horaInicio, string $horaFin): bool
    {
        return $this->repository->saveHoursGroup('', [
            'fecha' => $fecha,
            'hora_inicio' => $horaInicio,
            'hora_fin' => $horaFin,
        ]);
    }
}
