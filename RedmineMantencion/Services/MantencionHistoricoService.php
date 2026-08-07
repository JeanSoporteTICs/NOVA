<?php

namespace App\Modulos\RedmineMantencion\Services;

use DateTimeImmutable;

class MantencionHistoricoService
{
    public function __construct(private readonly RedmineIssueStatusService $redmineStatus)
    {
    }

    public function deleteReporte(string $id): bool
    {
        $repo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
        if ($repo !== null && $repo->tableReady()) {
            $repo->deleteByFuenteIds([$id]);

            return true;
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
