<?php

namespace App\Modulos\RedmineMantencion\Services;

use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use Illuminate\Support\Facades\DB;

/**
 * Bridge service for Nextcloud integration in Redmine Mantencion.
 *
 * Full logic lives in redmine-mantencion/controllers/nextcloud.php (legacy).
 * Methods here exist as the target API once the legacy PHP is fully migrated.
 * Called internally by some repository queries; the actual HTTP calls to
 * Nextcloud happen in the legacy layer.
 */
final class MantencionNextcloudService
{
    public function __construct(private readonly MantencionConfigRepository $config)
    {
    }

    /**
     * Returns Nextcloud connection configuration from DB.
     *
     * @return array<string,mixed>
     */
    public function connectionConfig(): array
    {
        $all = $this->config->loadAll() ?? [];
        return [
            'url'        => $all['nextcloud_url'] ?? '',
            'admin_user' => $all['nextcloud_admin_user'] ?? '',
        ];
    }

    /**
     * Returns whether Nextcloud integration is configured.
     */
    public function isConfigured(): bool
    {
        $cfg = $this->connectionConfig();
        return !empty($cfg['url']) && !empty($cfg['admin_user']);
    }
}
