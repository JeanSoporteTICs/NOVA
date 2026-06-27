<?php

namespace App\Modulos\Nova\Services;

use App\Modulos\Telegram\Services\TelegramService;
use App\Modulos\Nova\Repositories\NovaSettingsRepository;

final class NovaNotificationService
{
    public function __construct(
        private NovaSettingsRepository $settings,
        private TelegramService $telegram,
    ) {
    }

    public function notify(string $message): bool
    {
        if (empty($this->settings->all()['notification_enabled'])) {
            return false;
        }

        return $this->telegram->notify('[NOVA] ' . $message);
    }
}
