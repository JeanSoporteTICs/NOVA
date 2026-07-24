<?php

namespace App\Modulos\Emach\Services;

use App\Modulos\Emach\ExternalClients\EmachScraperClient;

/**
 * Orchestration service for EMACH — sits between Controllers and
 * EmachScraperClient (the transport-only client). Fase 8 lote 2 of the
 * 2026-07 standardization program completed the migration this class's own
 * docblock originally described as a future step for planillaUrls()/
 * columnNames(); fetchPlanillaRows() below now delegates to the client.
 * See .claude/knowledge/external-clients-architecture.md.
 *
 * Legacy constants extracted here for reference:
 *   EMACH_CLIENT_BASE_URL = 'http://10.6.206.19/index.php'
 *   Columns: codigo_enrolamiento, run, nombre, fecha, marcas, tipo,
 *            reloj, longitud, latitud, precision
 */
final class EmachClientService
{
    public function __construct(
        private readonly EmachScraperClient $client = new EmachScraperClient(),
        private readonly string $baseUrl = 'http://10.6.206.19/index.php'
    ) {
    }

    /**
     * @return array<int,array<int|string,mixed>>
     */
    public function fetchPlanillaRows(int $year, int $month, string $username, string $password): array
    {
        return $this->client->fetchPlanillaRows($year, $month, $username, $password);
    }

    /**
     * Returns the URLs for planilla queries (one per endpoint variant).
     *
     * @return array<int,string>
     */
    public function planillaUrls(int $year, int $month): array
    {
        $query = http_build_query([
            'ano' => $year,
            'mes' => $month,
            '_'   => (int) round(microtime(true) * 1000),
        ]);

        return [
            $this->baseUrl . '/reportes/getplanilla?' . $query,
            $this->baseUrl . '/autoconsulta/getplanilla?' . $query,
        ];
    }

    /**
     * @return array<int,string>
     */
    public function columnNames(): array
    {
        return [
            'codigo_enrolamiento',
            'run',
            'nombre',
            'fecha',
            'marcas',
            'tipo',
            'reloj',
            'longitud',
            'latitud',
            'precision',
        ];
    }
}
