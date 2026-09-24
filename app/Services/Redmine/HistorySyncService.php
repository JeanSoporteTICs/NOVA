<?php

namespace App\Services\Redmine;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** One bounded read operation; no retry and no modifications to remote issues. */
class HistorySyncService
{
    protected function clock(): float
    {
        return hrtime(true) / 1e9;
    }

    protected function get(string $url, string $token, float $timeout): array
    {
        try {
            $response = Http::acceptJson()->withHeaders(['X-Redmine-API-Key' => $token])
                ->connectTimeout(min(3, $timeout))->timeout($timeout)->withoutRedirecting()->get($url);

            return ['code' => $response->status(), 'data' => $response->json(), 'transport' => false];
        } catch (ConnectionException) {
            return ['code' => 0, 'data' => null, 'transport' => true];
        }
    }

    private function error(array $response): string
    {
        return match ((int) $response['code']) {
            401, 403 => 'Redmine rechazó el acceso. Revisa tu API Key personal y tus permisos.',
            429 => 'Redmine está limitando las solicitudes. Intenta más tarde.',
            default => 'Redmine no está disponible o agotó el tiempo de espera. Se conserva el último estado guardado.',
        };
    }

    private function available(string $url, string $token, float $deadline): string
    {
        if ($url === '' || $token === '') {
            return 'Configura la URL y tu API Key personal de Redmine.';
        }
        $response = $this->get($url.'?limit=1', $token, max(0.001, min(5, $deadline - $this->clock())));

        return $response['code'] === 200 && is_array($response['data']['issues'] ?? null)
            ? '' : $this->error($response);
    }

    public function statuses(string $issuesUrl, string $token, array $ids, callable $normalize, float $budget = 29): array
    {
        $deadline = $this->clock() + max(0.001, min(30, $budget));
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn ($id) => preg_match('/^[1-9]\d*$/', $id))));
        $statuses = [];
        $error = $ids ? $this->available($issuesUrl, $token, $deadline) : '';
        $pending = $ids;
        if ($error === '') {
            foreach ($ids as $id) {
                $remaining = $deadline - $this->clock();
                if ($remaining <= 0.01) {
                    $error = 'Se alcanzó el límite de 30 segundos. Continúa para consultar los pendientes.';
                    break;
                }
                $url = preg_replace('#/issues\.json$#', '/issues/'.$id.'.json', $issuesUrl);
                $response = $this->get($url, $token, min(5, $remaining));
                if ($response['transport'] || $response['code'] >= 500 || in_array($response['code'], [401, 403, 429], true)) {
                    $error = $this->error($response);
                    break;
                }
                $status = $response['data']['issue']['status'] ?? null;
                if ($response['code'] === 200 && is_array($status) && trim((string) ($status['name'] ?? '')) !== '') {
                    $statuses[$id] = $normalize($status) + ['available' => true, 'message' => ''];
                } else {
                    $statuses[$id] = ['available' => false, 'message' => $response['code'] === 404 ? 'Ticket no encontrado en Redmine (404).' : 'Redmine no informó un estado válido.'];
                }
                array_shift($pending);
            }
        }

        return ['ok' => $error === '', 'complete' => $pending === [], 'statuses' => $statuses, 'pending_ids' => $pending, 'error' => $error];
    }

    public function allStatuses(string $issuesUrl, string $token, string $projectId, int $offset = 0): array
    {
        $deadline = $this->clock() + 29;
        $error = $projectId !== '' ? $this->available($issuesUrl, $token, $deadline) : 'Falta el proyecto Redmine.';
        $statuses = [];
        $complete = false;
        while ($error === '') {
            $remaining = $deadline - $this->clock();
            if ($remaining <= 0.01) {
                $error = 'Sincronización parcial: se alcanzó el límite de 30 segundos. Pulsa nuevamente para continuar.';
                break;
            }
            $response = $this->get($issuesUrl.'?'.http_build_query([
                'project_id' => $projectId, 'status_id' => '*', 'limit' => 100, 'offset' => $offset, 'sort' => 'id:asc',
            ]), $token, min(20, $remaining));
            if ($response['code'] !== 200 || ! is_array($response['data']['issues'] ?? null)) {
                $error = $this->error($response);
                break;
            }
            $rows = $response['data']['issues'];
            foreach ($rows as $issue) {
                if ((int) ($issue['id'] ?? 0) > 0 && trim((string) ($issue['status']['name'] ?? '')) !== '') {
                    $statuses[(string) $issue['id']] = (string) $issue['status']['name'];
                }
            }
            $offset += count($rows);
            if ($offset >= (int) ($response['data']['total_count'] ?? $offset)) {
                $complete = true;
                break;
            }
            if ($rows === []) {
                $error = 'Redmine devolvió una página vacía antes de completar la consulta. Intenta continuar más tarde.';
                break;
            }
        }

        return ['statuses' => $statuses, 'error' => $error, 'complete' => $complete, 'next_offset' => $complete ? 0 : $offset];
    }
}
