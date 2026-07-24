<?php

namespace App\Modulos\Nova\Services;

use App\Modulos\Telegram\Services\TelegramService;
use App\Modulos\Nova\Repositories\NovaSettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $sent = false;
        foreach ($this->adminChatIds() as $chatId) {
            $sent = $this->telegram->sendToChat($chatId, '[NOVA] ' . $message) || $sent;
        }

        return $sent;
    }

    /**
     * @return array<int,string>
     */
    private function adminChatIds(): array
    {
        if (!Schema::hasTable('usuarios_nova') || !Schema::hasColumn('usuarios_nova', 'telegram_id_chat')) {
            return [];
        }

        $roles = array_map('strval', config('nova.module_admin_roles', ['admin', 'root', 'gestor', 'administrador']));

        try {
            return DB::table('usuarios_nova')
                ->whereIn('rol', $roles)
                ->where(function ($query): void {
                    $query->whereNull('estado')
                        ->orWhereNotIn('estado', ['baneado', 'inactivo', 'bloqueado']);
                })
                ->whereNotNull('telegram_id_chat')
                ->where('telegram_id_chat', '<>', '')
                ->pluck('telegram_id_chat')
                ->map(static fn($chatId): string => trim((string) $chatId))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
