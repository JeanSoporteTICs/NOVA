<?php

namespace App\Modulos\RedmineMantencion\Services;

use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;

/**
 * Bridge service for OnlyOffice integration in Mantencion.
 *
 * Full logic (callback handling, JWT verification, document URL generation)
 * lives in redmine-mantencion/controllers/onlyoffice.php (legacy).
 * This service is the migration target for that logic.
 */
final class MantencionOnlyOfficeService
{
    public function __construct(private readonly MantencionConfigRepository $config)
    {
    }

    /**
     * Returns OnlyOffice server configuration from DB.
     *
     * @return array<string,mixed>
     */
    public function serverConfig(): array
    {
        $all = $this->config->loadAll() ?? [];
        return [
            'server_url' => $all['onlyoffice_url'] ?? '',
            'secret'     => $all['onlyoffice_secret'] ?? '',
        ];
    }

    /**
     * Returns whether OnlyOffice is configured.
     */
    public function isConfigured(): bool
    {
        $cfg = $this->serverConfig();
        return !empty($cfg['server_url']);
    }
}
