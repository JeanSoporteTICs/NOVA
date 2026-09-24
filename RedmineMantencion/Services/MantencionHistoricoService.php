<?php

namespace App\Modulos\RedmineMantencion\Services;

use DateTimeImmutable;

class MantencionHistoricoService
{
    public function __construct(private readonly RedmineIssueStatusService $redmineStatus)
    {
    }

    /** Preserve legacy normalization and scope on a narrow projection before paging. */
    public function filterRows(array $items, array $filters, string $userId, array $userNames, array $redmineStatusOptions): array
    {
        $f_desde = $filters['desde'] ?? '';
        $f_hasta = $filters['hasta'] ?? '';
        $f_fuente = $filters['fuente'] ?? '';
        $f_estado_redmine = $filters['estado_redmine'] ?? '';
        $f_usuario = $filters['usuario'] ?? '';
        $f_scope = $filters['scope'] ?? 'asignados';
        $f_categoria = $filters['categoria'] ?? '';
        $f_busqueda = $filters['buscar'] ?? '';
        $f_descripcion = $filters['descripcion'] ?? '';
        $filtered = [];
        foreach ($items as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (! in_array(strtolower(trim((string) ($row['estado'] ?? ''))), ['procesado', 'archivado'], true)) {
                continue;
            }
            $fecha = $this->normDate($row['fecha'] ?? ($row['fecha_inicio'] ?? ''));
            if ($fecha === '') {
                continue;
            }
            if ($f_desde && $fecha < $f_desde) {
                continue;
            }
            if ($f_hasta && $fecha > $f_hasta) {
                continue;
            }
            if ($f_fuente && ($row['_fuente'] ?? '') !== $f_fuente) {
                continue;
            }
            if ($f_estado_redmine !== '') {
                $rowRedmineStatus = trim((string) ($row['estado_redmine'] ?? $row['redmine_estado'] ?? $row['status_name'] ?? ''));
                if ($rowRedmineStatus === '') {
                    $rowStatusId = (int) ($row['status_id'] ?? $row['estado_id'] ?? 0);
                    $rowRedmineStatus = trim((string) ($redmineStatusOptions[$rowStatusId] ?? ''));
                }
                if (dashboard_normalize_text($rowRedmineStatus) !== dashboard_normalize_text($f_estado_redmine)) {
                    continue;
                }
            }
            if ($f_usuario !== '' && (string) ($row['asignado_a'] ?? '') !== (string) $f_usuario) {
                continue;
            }
            if ($f_scope === 'asignados' && ! $this->recordMatchesCurrentUser($row, $userId, $userNames)) {
                continue;
            }
            $cat = strtolower($row['categoria'] ?? '');
            if ($f_categoria !== '' && $cat !== $f_categoria) {
                continue;
            }
            if (! $this->matchesSearch($row, $f_busqueda)) {
                continue;
            }
            if ($f_descripcion !== '') {
                $descriptionNeedle = dashboard_normalize_text($f_descripcion);
                $descriptionText = dashboard_normalize_text((string) ($row['descripcion'] ?? ''));
                if ($descriptionNeedle !== '' && ! str_contains($descriptionText, $descriptionNeedle)) {
                    continue;
                }
            }
            $row['_fecha_norm'] = $fecha;
            $filtered[] = $row;
        }

        if ($f_fuente === '') $filtered = $this->dedupeRows($filtered);
        usort($filtered, static fn (array $a, array $b): int => strcmp($b['_fecha_norm'] ?? '', $a['_fecha_norm'] ?? ''));
        return $filtered;
    }

    public function deleteReporte(string $id): bool
    {
        $repo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
        if ($repo !== null && $repo->tableReady()) {
            return $repo->deleteByFuenteIds([$id]) > 0;
        }

        return false;
    }

    public function deleteHorasExtra(string $id): bool
    {
        $repo = function_exists('mantencion_hours_extra_repository') ? mantencion_hours_extra_repository() : null;

        return $repo !== null && $repo->detachMessageId($id);
    }

    public function normDate(string $str): string
    {
        $str = trim($str);
        if ($str === '') {
            return '';
        }
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $str, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $str)) {
            return $str;
        }

        return '';
    }

    public function formatDate(string $str): string
    {
        $date = $this->normDate($str);
        if ($date === '') {
            return $str;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);

        return $dt ? $dt->format('d-m-Y') : $str;
    }

    public function redmineIssueUrl(string $platformUrl, string $redmineId): string
    {
        return $this->redmineStatus->issueUrl($platformUrl, $redmineId);
    }

    public function redmineIssueApiUrl(string $platformUrl, string $redmineId): string
    {
        return $this->redmineStatus->issueApiUrl($platformUrl, $redmineId);
    }

    public function redmineIsClosedStatus(string $statusName): bool
    {
        return $this->redmineStatus->isClosedStatus($statusName);
    }

    /**
     * @return array<string,mixed>
     */
    public function fetchRedmineStatus(string $platformUrl, string $redmineId, string $token): array
    {
        static $cache = [];

        $redmineId = trim($redmineId);
        $cacheKey = $platformUrl . '|' . $redmineId . '|' . ($token !== '' ? 'token' : 'public');
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        return $cache[$cacheKey] = $this->redmineStatus->fetchStatus($platformUrl, $redmineId, $token);
    }

    public function synchronizeStatuses(string $url, string $token, array $ids): array
    {
        return app(\App\Services\Redmine\HistorySyncService::class)->statuses(
            $this->redmineStatus->issuesCollectionApiUrl($url), $token, $ids,
            function (array $status): array {
                $closed = array_key_exists('is_closed', $status) ? filter_var($status['is_closed'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null;
                return ['id' => (int) ($status['id'] ?? 0), 'name' => trim((string) $status['name']),
                    'closed' => $this->redmineStatus->isClosedStatus($status['name'], $closed)];
            }
        );
    }

    public function updateRedmineStatus(string $platformUrl, string $redmineId, int $statusId, string $token): array
    {
        return $this->redmineStatus->updateStatus($platformUrl, $redmineId, $statusId, $token);
    }

    public function redmineStatusOptions(): array
    {
        return $this->redmineStatus->statusOptions();
    }

    public function redmineStatusName(int $statusId): ?string
    {
        return $this->redmineStatus->statusName($statusId);
    }

    /**
     * @param array<string,mixed> $row
     */
    public function matchesSearch(array $row, string $needle): bool
    {
        $needle = dashboard_normalize_text($needle);
        if ($needle === '') {
            return true;
        }

        $haystacks = [
            trim((string) ($row['solicitante'] ?? '')),
            trim((string) ($row['core_detalle_nombre'] ?? '')),
            trim((string) ($row['core_detalle_run'] ?? '')),
        ];

        foreach ((array) ($row['core_detalle_items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $haystacks[] = trim((string) ($item['detalle_nombre'] ?? ''));
            $haystacks[] = trim((string) ($item['detalle_run'] ?? ''));
        }

        foreach ($haystacks as $candidate) {
            $normalized = dashboard_normalize_text($candidate);
            if ($normalized !== '' && str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function loadReportes(): array
    {
        $repo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
        if ($repo !== null && $repo->tableReady()) {
            return array_map(static function (array $row): array {
                $row['_fuente'] = 'reportes';

                return $row;
            }, $repo->archivedMessages());
        }

        return [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function loadHorasExtras(): array
    {
        $repo = function_exists('mantencion_hours_extra_repository') ? mantencion_hours_extra_repository() : null;

        return $repo !== null ? $repo->messages() : [];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $userNames
     */
    public function recordMatchesCurrentUser(array $row, string $userId, array $userNames): bool
    {
        $assignedId = trim((string) ($row['asignado_a'] ?? ''));
        if ($assignedId !== '' && $assignedId === $userId) {
            return true;
        }
        $candidates = [
            trim((string) ($row['core_usuario_asignado'] ?? '')),
            trim((string) ($row['asignado_nombre'] ?? '')),
        ];
        foreach ($userNames as $expected) {
            if ($expected === '') {
                continue;
            }
            foreach ($candidates as $candidate) {
                if ($candidate !== '' && dashboard_name_tokens_match($expected, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $row
     */
    public function dedupeKey(array $row): string
    {
        $redmineId = preg_replace('/\D+/', '', trim((string) ($row['redmine_id'] ?? $row['numero_ticket_redmine'] ?? '')));
        if ($redmineId !== '') {
            return 'redmine:' . $redmineId;
        }

        $fuenteId = trim((string) ($row['fuente_id'] ?? $row['id'] ?? ''));
        if ($fuenteId !== '') {
            return 'fuente:' . $fuenteId;
        }

        return 'row:' . md5(json_encode([
            $row['fecha'] ?? '',
            $row['solicitante'] ?? '',
            $row['asunto'] ?? $row['mensaje'] ?? '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public function dedupeRows(array $rows): array
    {
        $deduped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = $this->dedupeKey($row);
            if (!isset($deduped[$key])) {
                $deduped[$key] = $row;
                continue;
            }

            $currentSource = (string) ($deduped[$key]['_fuente'] ?? '');
            $candidateSource = (string) ($row['_fuente'] ?? '');
            if ($currentSource === 'horas_extra' && $candidateSource === 'reportes') {
                $deduped[$key] = $row;
            }
        }

        return array_values($deduped);
    }
}
