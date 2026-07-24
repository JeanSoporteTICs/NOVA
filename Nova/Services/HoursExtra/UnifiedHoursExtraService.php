<?php

namespace App\Modulos\Nova\Services\HoursExtra;

/**
 * Agregador de solo lectura para la vista global de Horas Extra en NOVA.
 *
 * No fusiona tablas ni reglas de negocio: cada adapter lee exclusivamente las
 * tablas de su propio módulo y esta clase solo combina/filtra los arreglos ya
 * normalizados (agrupados por fecha) que ambos devuelven. Esta clase no
 * conoce permisos ni sesión: quién puede ver Mantención/TIC lo decide el
 * controlador antes de llamar a getMantencion()/getTic()/getAll().
 */
final class UnifiedHoursExtraService
{
    public function __construct(
        private readonly MantencionHoursExtraAdapter $mantencion,
        private readonly TicHoursExtraAdapter $tic,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function getAll(string $assignedUserId): array
    {
        $rows = array_merge($this->getMantencion($assignedUserId), $this->getTic($assignedUserId));
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['fecha'] ?? ''), (string) ($a['fecha'] ?? '')));

        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function getMantencion(string $assignedUserId): array
    {
        return $this->mantencion->rows($assignedUserId);
    }

    /** @return array<int,array<string,mixed>> */
    public function getTic(string $assignedUserId): array
    {
        return $this->tic->rows($assignedUserId);
    }

    /**
     * Corrige hora_inicio/hora_fin de un grupo ya existente, delegando
     * exclusivamente al adapter del módulo dueño del grupo ($origen). Nunca
     * escribe en las tablas del otro módulo.
     */
    public function updateGroupTime(string $origen, string $fecha, string $horaInicio, string $horaFin): bool
    {
        return match ($origen) {
            'mantencion' => $this->mantencion->updateGroupTime($fecha, $horaInicio, $horaFin),
            'tic' => $this->tic->updateGroupTime($fecha, $horaInicio, $horaFin),
            default => false,
        };
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public function filterByDate(array $rows, ?string $from, ?string $to): array
    {
        $from = trim((string) $from);
        $to = trim((string) $to);
        if ($from === '' && $to === '') {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($from, $to): bool {
            $fecha = (string) ($row['fecha'] ?? '');
            if ($fecha === '') {
                return false;
            }
            if ($from !== '' && $fecha < $from) {
                return false;
            }
            if ($to !== '' && $fecha > $to) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Agrupa filas ya combinadas (getAll()/getMantencion()/getTic()) solo por
     * fecha. La hora_inicio/hora_fin/total_horas pertenecen al bloque diario
     * compartido (usuario_id + fecha), no a cada módulo visualmente.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{fecha:string,origen:string,hora_inicio:string,hora_fin:string,total_horas:string,reportes:array<int,array<string,mixed>>}>
     */
    public function groupByDate(array $rows): array
    {
        $byDate = [];
        foreach ($rows as $row) {
            $fecha = (string) ($row['fecha'] ?? '');
            if (!isset($byDate[$fecha])) {
                $byDate[$fecha] = [
                    'fecha' => $fecha,
                    'origen' => (string) ($row['origen'] ?? $row['modulo'] ?? ''),
                    'hora_inicio' => (string) ($row['hora_inicio'] ?? ''),
                    'hora_fin' => (string) ($row['hora_fin'] ?? ''),
                    'total_horas' => (string) ($row['total_horas'] ?? '—'),
                    'reportes' => [],
                ];
            }

            if ((string) $byDate[$fecha]['hora_inicio'] === '' && (string) ($row['hora_inicio'] ?? '') !== '') {
                $byDate[$fecha]['hora_inicio'] = (string) $row['hora_inicio'];
            }
            if ((string) $byDate[$fecha]['hora_fin'] === '' && (string) ($row['hora_fin'] ?? '') !== '') {
                $byDate[$fecha]['hora_fin'] = (string) $row['hora_fin'];
            }
            if ((string) $byDate[$fecha]['total_horas'] === '—' && (string) ($row['total_horas'] ?? '') !== '') {
                $byDate[$fecha]['total_horas'] = (string) $row['total_horas'];
            }

            foreach ((array) ($row['reportes'] ?? []) as $reporte) {
                if (is_array($reporte)) {
                    $byDate[$fecha]['reportes'][] = $reporte;
                }
            }
        }

        krsort($byDate);

        return array_values($byDate);
    }

    /**
     * Busca por ID Redmine, detalle o proyecto entre los reportes de cada
     * grupo. Un grupo se mantiene completo (con todos sus reportes) si al
     * menos uno de sus reportes coincide con el término.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public function filterBySearch(array $rows, string $term): array
    {
        $term = trim(mb_strtolower($term));
        if ($term === '') {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($term): bool {
            foreach ((array) ($row['reportes'] ?? []) as $reporte) {
                if (!is_array($reporte)) {
                    continue;
                }
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($reporte['id_redmine'] ?? ''),
                    (string) ($reporte['detalle'] ?? ''),
                    (string) ($reporte['proyecto'] ?? ''),
                ]));
                if (str_contains($haystack, $term)) {
                    return true;
                }
            }

            return false;
        }));
    }
}
