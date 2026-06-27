<?php

namespace App\Modulos\Nova\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NovaSettingsRepository
{
    private const DEFAULTS = [
        'session_timeout'          => 3600,
        'notification_enabled'     => false,
        'health_warning_threshold' => 1,
    ];

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return array_merge(self::DEFAULTS, $this->read());
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
        $current = $this->all();
        $current['session_timeout']          = max(60, (int) ($payload['session_timeout'] ?? $current['session_timeout']));
        $current['notification_enabled']     = ! empty($payload['notification_enabled']);
        $current['health_warning_threshold'] = max(1, (int) ($payload['health_warning_threshold'] ?? $current['health_warning_threshold']));
        $this->write($current);
    }

    /**
     * @return array<string,mixed>
     */
    private function read(): array
    {
        if (! $this->tableReady()) {
            return [];
        }
        try {
            $rows = DB::table('nova_settings')->get(['clave', 'valor', 'tipo']);
            $out  = [];
            foreach ($rows as $row) {
                $out[(string) $row->clave] = $this->cast((string) ($row->valor ?? ''), (string) ($row->tipo ?? 'string'));
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function write(array $settings): void
    {
        if (! $this->tableReady()) {
            return;
        }
        $types = [
            'session_timeout'          => 'int',
            'notification_enabled'     => 'bool',
            'health_warning_threshold' => 'int',
        ];
        foreach ($settings as $key => $value) {
            $tipo   = $types[$key] ?? 'string';
            $stored = match ($tipo) {
                'bool'  => $value ? '1' : '0',
                'int'   => (string) (int) $value,
                default => (string) $value,
            };
            try {
                DB::table('nova_settings')->updateOrInsert(
                    ['clave' => $key],
                    ['valor' => $stored, 'tipo' => $tipo]
                );
            } catch (\Throwable) {
            }
        }
    }

    private function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'bool'  => in_array(strtolower($value), ['1', 'true'], true),
            'int'   => (int) $value,
            default => $value,
        };
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('nova_settings');
        } catch (\Throwable) {
            return false;
        }
    }
}
