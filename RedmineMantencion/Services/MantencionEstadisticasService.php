<?php

namespace App\Modulos\RedmineMantencion\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Throwable;

class MantencionEstadisticasService
{
    public function normalizeDate($str)
    {
        $str = trim((string) $str);
        if ($str === '') {
            return '';
        }
        // dd-mm-yyyy
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $str, $m)) {
            return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
        }
        // dd-mm-yyyy con hora
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})\s+\d{1,2}:\d{2}(?::\d{2})?$/', $str, $m)) {
            return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
        }
        // yyyy-mm-dd
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $str)) {
            return $str;
        }
        // yyyy-mm-dd con hora o timestamp ISO
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[T\s]/', $str, $m)) {
            return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        }
        try {
            $dt = new DateTimeImmutable($str);

            return $dt->setTimezone(new DateTimeZone('America/Santiago'))->format('Y-m-d');
        } catch (Throwable $_) {
        }

        return '';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function normalizeMessage(array $row): array
    {
        if (($row['fuente'] ?? '') === 'core') {
            $row = dashboard_expand_message($row);
        }
        $row['asunto'] = trim((string) ($row['asunto'] ?? $row['mensaje'] ?? ''));
        $row['categoria'] = trim((string) ($row['categoria'] ?? ''));
        $row['unidad'] = trim((string) ($row['unidad'] ?? ''));
        if ($row['unidad'] === '' && ($row['fuente'] ?? '') === 'core') {
            $row['unidad'] = dashboard_resolve_unit_value($row);
        }
        $row['unidad_solicitante'] = trim((string) ($row['unidad_solicitante'] ?? ($row['core_establecimiento'] ?? '')));
        $row['usuario_stats'] = trim((string) ($row['core_usuario_asignado'] ?? $row['asignado_nombre'] ?? $row['asignado_a'] ?? ''));
        $row['fecha_stats'] = trim((string) ($row['fecha'] ?? $row['fecha_inicio'] ?? $row['core_fecha_creacion'] ?? $row['procesado_ts'] ?? ''));

        return $row;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function loadReportMessages($baseDir)
    {
        $repo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
        if ($repo === null || !$repo->tableReady()) {
            return [];
        }

        return array_map(function (array $row): array {
            $row['_fuente'] = 'reportes';

            return $this->normalizeMessage($row);
        }, $repo->archivedMessages());
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function loadLiveMessages($file)
    {
        $data = load_messages();
        if (!is_array($data)) {
            return [];
        }
        foreach ($data as &$row) {
            if (is_array($row)) {
                $row['_fuente'] = 'mensajes';
                $row = $this->normalizeMessage($row);
            }
        }

        return $data;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function loadExtraMessages($baseDir)
    {
        $repo = function_exists('mantencion_hours_extra_repository') ? mantencion_hours_extra_repository() : null;
        if ($repo === null) {
            return [];
        }

        return array_map(function (array $row): array {
            $row['_fuente'] = 'horas_extra';

            return $this->normalizeMessage($row);
        }, $repo->messages());
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function filterMessages($messages, $filters)
    {
        $result = [];
        $desde = $filters['desde'] ?? '';
        $hasta = $filters['hasta'] ?? '';
        $cat = strtolower(trim($filters['categoria'] ?? ''));
        $unidad = strtolower(trim($filters['unidad'] ?? ''));
        $usuario = trim($filters['usuario'] ?? '');

        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $fechaRaw = $msg['fecha_stats'] ?? $msg['fecha'] ?? ($msg['fecha_inicio'] ?? '');
            $fechaNorm = $this->normalizeDate($fechaRaw);
            if ($fechaNorm === '') {
                continue;
            }

            if ($desde && $fechaNorm < $desde) {
                continue;
            }
            if ($hasta && $fechaNorm > $hasta) {
                continue;
            }

            if ($cat !== '' && strtolower($msg['categoria'] ?? '') !== $cat) {
                continue;
            }
            $unidadMsg = strtolower($msg['unidad'] ?? ($msg['unidad_solicitante'] ?? ''));
            if ($unidad !== '' && $unidadMsg !== $unidad) {
                continue;
            }
            if ($usuario !== '') {
                $usuarioMsg = (string) ($msg['asignado_a'] ?? '');
                $usuarioNombre = (string) ($msg['usuario_stats'] ?? '');
                if ($usuarioMsg !== (string) $usuario && $usuarioNombre !== (string) $usuario) {
                    continue;
                }
            }

            $msg['_fecha_norm'] = $fechaNorm;
            $msg['_fecha_mes'] = substr($fechaNorm, 0, 7);
            $msg['_fuente'] = $msg['_fuente'] ?? 'reportes';
            $result[] = $msg;
        }

        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array<string,mixed>
     */
    public function computeStats($messages)
    {
        $stats = [
            'total' => 0,
            'por_usuario' => [],
            'por_categoria' => [],
            'por_unidad' => [],
            'por_estado' => [],
            'por_fecha' => [],
            'por_fecha_mes' => [],
            'msgs_por_usuario' => [],
            'msgs_por_categoria' => [],
            'msgs_por_unidad' => [],
            'actualizado' => date('Y-m-d H:i:s'),
        ];

        foreach ($messages as $msg) {
            $stats['total']++;
            $usuario = (string) ($msg['usuario_stats'] ?? $msg['asignado_a'] ?? '');
            $cat = (string) ($msg['categoria'] ?? '');
            $unidad = (string) ($msg['unidad'] ?? ($msg['unidad_solicitante'] ?? ''));
            $estado = (string) ($msg['estado'] ?? '');
            $fecha = $msg['_fecha_norm'];
            $mes = $msg['_fecha_mes'];

            $stats['por_usuario'][$usuario] = ($stats['por_usuario'][$usuario] ?? 0) + 1;
            $stats['por_categoria'][$cat] = ($stats['por_categoria'][$cat] ?? 0) + 1;
            $stats['por_unidad'][$unidad] = ($stats['por_unidad'][$unidad] ?? 0) + 1;
            $stats['por_estado'][$estado] = ($stats['por_estado'][$estado] ?? 0) + 1;
            $stats['por_fecha'][$fecha] = ($stats['por_fecha'][$fecha] ?? 0) + 1;
            $stats['por_fecha_mes'][$mes] = ($stats['por_fecha_mes'][$mes] ?? 0) + 1;

            $stats['msgs_por_usuario'][$usuario][] = $msg;
            $stats['msgs_por_categoria'][$cat][] = $msg;
            $stats['msgs_por_unidad'][$unidad][] = $msg;
        }

        ksort($stats['por_fecha']);
        ksort($stats['por_fecha_mes']);
        arsort($stats['por_usuario']);
        arsort($stats['por_categoria']);
        arsort($stats['por_unidad']);
        arsort($stats['por_estado']);

        return $stats;
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveFilters(array $post, array $get, ?DateTimeImmutable $now = null): array
    {
        $filters = [
            'desde' => '',
            'hasta' => '',
            'categoria' => $post['categoria'] ?? $get['categoria'] ?? '',
            'unidad' => $post['unidad'] ?? $get['unidad'] ?? '',
            'usuario' => $post['usuario'] ?? $get['usuario'] ?? '',
        ];

        $periodo = $post['periodo'] ?? $get['periodo'] ?? '';
        if ($periodo) {
            $periodo = trim($periodo);
            if (preg_match('/^(\d{4})-(\d{2})$/', $periodo, $m)) {
                $filters['desde'] = sprintf('%s-%s-01', $m[1], $m[2]);
                try {
                    $filters['hasta'] = (new DateTimeImmutable($filters['desde']))->modify('last day of this month')->format('Y-m-d');
                } catch (Throwable) {
                    $filters['hasta'] = '';
                }
            } elseif (preg_match('/^(\d{4})$/', $periodo, $m)) {
                $filters['desde'] = $m[1] . '-01-01';
                $filters['hasta'] = $m[1] . '-12-31';
            }
        }

        $desde = $this->normalizeDate($post['desde'] ?? $get['desde'] ?? '');
        $hasta = $this->normalizeDate($post['hasta'] ?? $get['hasta'] ?? '');
        if ($desde) {
            $filters['desde'] = $desde;
        }
        if ($hasta) {
            $filters['hasta'] = $hasta;
        }
        if ($filters['desde'] !== '' && $filters['hasta'] !== '' && $filters['desde'] > $filters['hasta']) {
            [$filters['desde'], $filters['hasta']] = [$filters['hasta'], $filters['desde']];
        }

        $hasExplicitDate = array_key_exists('desde', $post)
            || array_key_exists('hasta', $post)
            || array_key_exists('desde', $get)
            || array_key_exists('hasta', $get);
        if (!$hasExplicitDate && trim((string) $periodo) === '') {
            $today = ($now ?? new DateTimeImmutable('now', new DateTimeZone('America/Santiago')))->format('Y-m-d');
            $filters['desde'] = $today;
            $filters['hasta'] = $today;
        }

        return $filters;
    }

    /**
     * @return array<string,mixed>
     */
    public function handle()
    {
        if (!empty($_POST)) {
            csrf_validate();
        }

        $filters = $this->resolveFilters($_POST, $_GET);

        return $this->statisticsForFilters($filters);
    }

    public function statisticsForFilters(array $filters): array
    {
        $filtered = $this->filteredDatabaseMessages($filters);
        $stats = $this->computeStats($filtered);
        $stats['filtros_aplicados'] = $filters;

        return $stats;
    }

    private function fullFilteredDatabaseMessages(array $filters): array
    {
        return $this->filterMessages(array_merge(
            $this->loadReportMessages(''),
            $this->loadLiveMessages(''),
            $this->loadExtraMessages('')
        ), $filters);
    }

    private function filteredDatabaseMessages(array $filters): array
    {
        // With no selective filters the complete response needs all details;
        // retain the original reader instead of adding a metadata round trip.
        if (! ($filters['desde'] ?? '') && ! ($filters['hasta'] ?? '')
            && trim((string) ($filters['categoria'] ?? '')) === ''
            && trim((string) ($filters['unidad'] ?? '')) === ''
            && trim((string) ($filters['usuario'] ?? '')) === '') {
            return $this->fullFilteredDatabaseMessages($filters);
        }

        $reports = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
        $hours = function_exists('mantencion_hours_extra_repository') ? mantencion_hours_extra_repository() : null;
        if ($reports === null) {
            return [];
        }

        try {
            return DB::transaction(function () use ($reports, $hours, $filters): array {
                $readers = [
                    'reportes' => fn (?array $ids) => $reports->archivedMessages($ids, $ids === null),
                    'mensajes' => fn (?array $ids) => $reports->activeMessages($ids, $ids === null),
                    'horas_extra' => fn (?array $ids) => $hours !== null ? $hours->statisticsMessages($ids) : [],
                ];
                $filtered = [];
                foreach ($readers as $source => $read) {
                    $normalize = function (array $row) use ($source): array {
                        $row['_fuente'] = $source;

                        return $this->normalizeMessage($row);
                    };
                    $candidates = $this->filterMessages(array_map($normalize, $read(null)), $filters);
                    $ids = array_values(array_unique(array_column($candidates, '_statistics_id')));
                    if ($ids === []) {
                        continue;
                    }
                    // Reuse the complete DTO and normalizer for selected rows. Keep
                    // repeated hours links and the order of all three sources.
                    $details = $this->filterMessages(array_map($normalize, $read($ids)), $filters);
                    if (count($details) !== count($candidates)) {
                        return $this->fullFilteredDatabaseMessages($filters);
                    }
                    $filtered = array_merge($filtered, $details);
                }

                return $filtered;
            });
        } catch (Throwable) {
            return $this->fullFilteredDatabaseMessages($filters);
        }
    }

}
