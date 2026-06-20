<?php

namespace App\Support\RedmineMantencion;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MantencionConfigRepository
{
    private const MODULE_KEY    = 'redmine-mantencion';
    private const CONFIG_TABLE  = 'configuraciones_modulo';
    private const MODULES_TABLE = 'modulos_nova';

    private ?int $moduleId = null;
    private bool $moduleIdResolved = false;

    public function tableReady(): bool
    {
        try {
            return Schema::hasTable(self::CONFIG_TABLE) && Schema::hasTable(self::MODULES_TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Returns all config key-value pairs, or null when no rows exist yet (triggers migration).
     *
     * @return array<string,mixed>|null
     */
    public function loadAll(): ?array
    {
        $moduleId = $this->resolveModuleId();
        if ($moduleId === null) {
            return null;
        }

        try {
            $rows = DB::table(self::CONFIG_TABLE)
                ->where('modulo_id', $moduleId)
                ->get(['clave', 'valor', 'tipo']);

            if ($rows->isEmpty()) {
                return null;
            }

            $out = [];
            foreach ($rows as $row) {
                $valor = $row->valor;
                $out[(string) $row->clave] = $valor === null
                    ? null
                    : $this->cast((string) $valor, (string) ($row->tipo ?? 'string'));
            }

            return $out;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $config */
    public function saveAll(array $config): void
    {
        $moduleId = $this->resolveModuleId();
        if ($moduleId === null) {
            return;
        }

        foreach ($config as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            $type   = $this->typeOf($value);
            $stored = $this->encode($value, $type);

            try {
                DB::table(self::CONFIG_TABLE)->updateOrInsert(
                    ['modulo_id' => $moduleId, 'clave' => $key],
                    ['valor' => $stored, 'tipo' => $type, 'actualizado_at' => now()]
                );
            } catch (\Throwable) {
                continue;
            }
        }
    }

    private function resolveModuleId(): ?int
    {
        if ($this->moduleIdResolved) {
            return $this->moduleId;
        }

        $this->moduleIdResolved = true;

        if (!$this->tableReady()) {
            return null;
        }

        try {
            $row = DB::table(self::MODULES_TABLE)
                ->where('clave_modulo', self::MODULE_KEY)
                ->first(['id']);

            $this->moduleId = $row ? (int) $row->id : null;
        } catch (\Throwable) {
            $this->moduleId = null;
        }

        return $this->moduleId;
    }

    private function cast(string $value, string $type): mixed
    {
        return match ($type) {
            'json' => json_decode($value, true) ?? [],
            'bool' => in_array(strtolower($value), ['1', 'true', 'si', 'sí', 'yes'], true),
            'int'  => (int) $value,
            default => $value,
        };
    }

    private function typeOf(mixed $value): string
    {
        if ($value === null)  return 'string';
        if (is_bool($value))  return 'bool';
        if (is_int($value))   return 'int';
        if (is_array($value)) return 'json';
        return 'string';
    }

    private function encode(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'json'  => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
            'bool'  => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
