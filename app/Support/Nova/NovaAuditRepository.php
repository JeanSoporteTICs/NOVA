<?php

namespace App\Support\Nova;

use Illuminate\Http\Request;

final class NovaAuditRepository
{
    /**
     * @param array<string,mixed> $context
     */
    public function record(string $event, string $message, array $context = [], ?Request $request = null): void
    {
        $items = $this->recent(500);
        $sessionUser = $request?->session()->get('nova_user', []);
        $items[] = [
            'at' => now('America/Santiago')->toIso8601String(),
            'event' => $event,
            'message' => $message,
            'user_id' => is_array($sessionUser) ? (string) ($sessionUser['id'] ?? '') : '',
            'user_name' => is_array($sessionUser) ? trim((string) (($sessionUser['name'] ?? '') . ' ' . ($sessionUser['apellido'] ?? ''))) : '',
            'ip' => $request?->ip() ?? '',
            'context' => $context,
        ];

        $this->write(array_slice($items, -500));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 100): array
    {
        $raw = (string) @file_get_contents($this->path());
        $items = json_decode($raw, true);
        $items = is_array($items) ? array_values(array_filter($items, 'is_array')) : [];

        return array_reverse(array_slice($items, -max(1, $limit)));
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function write(array $items): void
    {
        $directory = dirname($this->path());
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->path(), json_encode(array_values($items), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($this->path(), 0666);
    }

    private function path(): string
    {
        return storage_path('app/nova/audit.json');
    }
}
