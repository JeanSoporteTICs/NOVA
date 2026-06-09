<?php

namespace App\Support\Nova;

final class NovaSettingsRepository
{
    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $settings = $this->read();

        return array_merge([
            'session_timeout' => max(60, (int) config('nova.session_timeout', 3600)),
            'notification_enabled' => false,
            'health_warning_threshold' => 1,
        ], $settings);
    }

    public function sessionTimeout(): int
    {
        return max(60, (int) ($this->all()['session_timeout'] ?? 3600));
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function save(array $payload): void
    {
        $settings = $this->all();
        $settings['session_timeout'] = max(60, (int) ($payload['session_timeout'] ?? $settings['session_timeout'] ?? 3600));
        $settings['notification_enabled'] = !empty($payload['notification_enabled']);
        $settings['health_warning_threshold'] = max(1, (int) ($payload['health_warning_threshold'] ?? $settings['health_warning_threshold'] ?? 1));

        $this->write($settings);
    }

    /**
     * @return array<string,mixed>
     */
    private function read(): array
    {
        $raw = (string) @file_get_contents($this->path());
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function write(array $settings): void
    {
        $directory = dirname($this->path());
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->path(), json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function path(): string
    {
        return storage_path('app/nova/settings.json');
    }
}
