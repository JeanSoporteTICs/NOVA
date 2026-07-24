<?php

namespace App\Modulos\Nova\Services;

use App\Modulos\Nova\Repositories\NovaHealthRepository;
use App\Modulos\Nova\Repositories\NovaSettingsRepository;
use Illuminate\Support\Facades\Cache;

final class NovaHealthAlertService
{
    private const CACHE_KEY = 'nova.health_alerts.last_status';
    public const ALERT_CHECKS = ['CORE', 'EMACH', 'Nextcloud', 'OnlyOffice', 'Telegram', 'Docker Telegram'];

    public function __construct(
        private NovaHealthRepository $health,
        private NovaSettingsRepository $settings,
        private NovaNotificationService $notifications,
    ) {
    }

    /**
     * @return array{alerts:int,recoveries:int,checks:int}
     */
    public function run(): array
    {
        $checks = $this->alertChecks($this->health->checks());
        if (empty($this->settings->all()['notification_enabled'])) {
            Cache::forget(self::CACHE_KEY);
            return [
                'alerts' => 0,
                'recoveries' => 0,
                'checks' => count($checks),
            ];
        }

        $previous = Cache::get(self::CACHE_KEY, []);
        $previous = is_array($previous) ? $previous : [];
        $current = [];
        $alerts = 0;
        $recoveries = 0;

        foreach ($checks as $check) {
            $name = trim((string) ($check['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $status = strtolower(trim((string) ($check['status'] ?? 'warn'))) ?: 'warn';
            $detail = trim((string) ($check['detail'] ?? ''));
            $current[$name] = ['status' => $status, 'detail' => $detail];
            $oldStatus = strtolower((string) data_get($previous, $name . '.status', ''));

            if ($status !== 'ok' && $oldStatus !== $status) {
                if ($this->notifications->notify($this->formatAlertMessage($name, $status, $detail))) {
                    $alerts++;
                }
                continue;
            }

            if ($status === 'ok' && $oldStatus !== '' && $oldStatus !== 'ok') {
                if ($this->notifications->notify('✅ Servicio recuperado: ' . $name . ' OK')) {
                    $recoveries++;
                }
            }
        }

        Cache::forever(self::CACHE_KEY, $current);

        return [
            'alerts' => $alerts,
            'recoveries' => $recoveries,
            'checks' => count($checks),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @return array<int,array<string,mixed>>
     */
    private function alertChecks(array $checks): array
    {
        return array_values(array_filter($checks, static function (array $check): bool {
            return in_array((string) ($check['name'] ?? ''), self::ALERT_CHECKS, true);
        }));
    }

    private function formatAlertMessage(string $name, string $status, string $detail): string
    {
        $icon = $status === 'error' ? '❌' : '⚠️';
        $label = $status === 'error' ? 'ERROR' : 'WARNING';

        return $icon . ' Alerta de servicio: ' . $name . ' ' . $label . ($detail !== '' ? "\n" . $detail : '');
    }
}
