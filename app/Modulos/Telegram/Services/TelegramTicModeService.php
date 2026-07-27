<?php

namespace App\Modulos\Telegram\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Cache\CacheManager;

/**
 * Keeps the per-chat daily TIC intake mode and validates plain report text.
 */
final class TelegramTicModeService
{
    private const TIMEZONE = 'America/Santiago';

    public function __construct(private CacheManager $cache) {}

    /**
     * @return array{active:bool,until:string,date:string}
     */
    public function activate(string $chatId, ?DateTimeImmutable $now = null): array
    {
        $chatId = trim($chatId);
        if ($chatId === '') {
            return ['active' => false, 'until' => '', 'date' => ''];
        }

        $now = $this->localTime($now);
        $until = $now->setTime(23, 59, 59);
        $state = [
            'active' => true,
            'date' => $now->format('Y-m-d'),
            'until' => $until->format(DATE_ATOM),
        ];

        $this->cache->put($this->cacheKey($chatId), $state, $until);

        return $state;
    }

    public function deactivate(string $chatId): void
    {
        $chatId = trim($chatId);
        if ($chatId !== '') {
            $this->cache->forget($this->cacheKey($chatId));
        }
    }

    /**
     * @return array{active:bool,until:string,date:string}
     */
    public function status(string $chatId, ?DateTimeImmutable $now = null): array
    {
        $chatId = trim($chatId);
        $now = $this->localTime($now);
        $inactive = ['active' => false, 'until' => '', 'date' => $now->format('Y-m-d')];
        if ($chatId === '') {
            return $inactive;
        }

        $state = $this->cache->get($this->cacheKey($chatId));
        if (!is_array($state) || !($state['active'] ?? false)) {
            return $inactive;
        }

        if ((string) ($state['date'] ?? '') !== $now->format('Y-m-d')) {
            $this->deactivate($chatId);

            return $inactive;
        }

        return [
            'active' => true,
            'until' => (string) ($state['until'] ?? ''),
            'date' => (string) ($state['date'] ?? ''),
        ];
    }

    public function isActive(string $chatId, ?DateTimeImmutable $now = null): bool
    {
        return $this->status($chatId, $now)['active'];
    }

    /**
     * @return array{valid:bool,text:string,error:string}
     */
    public function validateReportText(string $text): array
    {
        $parts = array_map('trim', explode(',', trim($text)));
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            return [
                'valid' => false,
                'text' => '',
                'error' => 'El mensaje debe contener problema, unidad y solicitante separados por comas.',
            ];
        }

        return [
            'valid' => true,
            'text' => implode(', ', $parts),
            'error' => '',
        ];
    }

    public function formattedUntil(array $state): string
    {
        $until = trim((string) ($state['until'] ?? ''));
        if ($until === '') {
            return '23:59';
        }

        try {
            return (new DateTimeImmutable($until))
                ->setTimezone(new DateTimeZone(self::TIMEZONE))
                ->format('d/m/Y H:i');
        } catch (\Throwable) {
            return '23:59';
        }
    }

    private function localTime(?DateTimeImmutable $now): DateTimeImmutable
    {
        $timezone = new DateTimeZone(self::TIMEZONE);

        return ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone);
    }

    private function cacheKey(string $chatId): string
    {
        return 'nova.telegram.tic_mode.'.hash('sha256', $chatId);
    }
}
