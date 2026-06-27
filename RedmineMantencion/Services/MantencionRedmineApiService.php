<?php

namespace App\Modulos\RedmineMantencion\Services;

use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;

/**
 * Bridge service for Redmine API calls in Mantencion.
 *
 * Full logic lives in redmine-mantencion/controllers/maintenance.php (legacy).
 * This service holds the configuration facade and is the target for future
 * extraction of the cURL-based Redmine API client.
 */
final class MantencionRedmineApiService
{
    public function __construct(private readonly MantencionConfigRepository $config)
    {
    }

    /**
     * Returns Redmine connection configuration from DB.
     *
     * @return array<string,mixed>
     */
    public function connectionConfig(): array
    {
        $all = $this->config->loadAll() ?? [];
        return [
            'url'     => $all['redmine_url'] ?? '',
            'api_key' => $all['redmine_api_key'] ?? '',
        ];
    }

    /**
     * Returns whether Redmine integration is configured.
     */
    public function isConfigured(): bool
    {
        $cfg = $this->connectionConfig();
        return !empty($cfg['url']) && !empty($cfg['api_key']);
    }
}
