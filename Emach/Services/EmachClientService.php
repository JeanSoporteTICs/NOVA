<?php

namespace App\Modulos\Emach\Services;

/**
 * Bridge service for the EMACH HTTP client.
 *
 * Full HTTP/cURL logic lives in emach/lib/client.php (legacy).
 * This service is the migration target: once migrated, it will replace
 * the procedural functions with proper OO HTTP calls via Guzzle.
 *
 * Legacy constants extracted here for reference during migration:
 *   EMACH_CLIENT_BASE_URL = 'http://10.6.206.19/index.php'
 *   Columns: codigo_enrolamiento, run, nombre, fecha, marcas, tipo,
 *            reloj, longitud, latitud, precision
 */
final class EmachClientService
{
    public function __construct(
        private readonly string $baseUrl = 'http://10.6.206.19/index.php'
    ) {
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
