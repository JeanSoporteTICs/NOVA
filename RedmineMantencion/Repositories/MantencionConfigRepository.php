<?php

namespace App\Modulos\RedmineMantencion\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MantencionConfigRepository
{
    private const MODULE_KEY = 'redmine-mantencion';

    private const CONFIG_TABLE = 'configuraciones_modulo';

    private const MODULES_TABLE = 'modulos_nova';

    private const OPTIONS_TABLE = 'modulo_opciones';

    private const OPTION_KEYS = [
        'trackers' => 'tracker',
        'prioridades' => 'prioridad',
        'estados' => 'estado',
    ];

    private ?int $moduleId = null;

    private bool $moduleIdResolved = false;

    private ?bool $tableReadyCache = null;

    private ?bool $optionsTableReadyCache = null;

    public function tableReady(): bool
    {
        if ($this->tableReadyCache !== null) {
            return $this->tableReadyCache;
        }
        try {
            return $this->tableReadyCache = Schema::hasTable(self::CONFIG_TABLE) && Schema::hasTable(self::MODULES_TABLE);
        } catch (\Throwable) {
            return $this->tableReadyCache = false;
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

            foreach (self::OPTION_KEYS as $key => $type) {
                $options = $this->optionsFromDatabase($type);
                if ($options === [] && isset($out[$key]) && is_array($out[$key])) {
                    $this->saveOptionsToDatabase($type, $out[$key]);
                    $options = $this->optionsFromDatabase($type);
                }
                $out[$key] = $options;
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

        foreach (self::OPTION_KEYS as $key => $type) {
            if (isset($config[$key]) && is_array($config[$key])) {
                $this->saveOptionsToDatabase($type, $config[$key]);
            }
            unset($config[$key]);
        }

        foreach ($config as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            $type = $this->typeOf($value);
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

    /**
     * @param  'tracker'|'prioridad'|'estado'  $type
     * @return array<int,array<string,mixed>>
     */
    public function options(string $type): array
    {
        return $this->optionsFromDatabase($type);
    }

    /**
     * @param  'tracker'|'prioridad'|'estado'  $type
     * @param  array<int,array<string,mixed>>  $items
     */
    public function saveOptions(string $type, array $items): void
    {
        $this->saveOptionsToDatabase($type, $items);
    }

    public function defaultOptionId(string $type): string
    {
        foreach ($this->optionsFromDatabase($type) as $option) {
            if (! empty($option['default'])) {
                return (string) ($option['id'] ?? '');
            }
        }

        return '';
    }

    public function createOption(string $type, string $externalId, string $name, bool $default = false): bool
    {
        $moduleId = $this->resolveModuleId();
        if (! $this->optionsTableReady() || $moduleId === null || trim($externalId) === '' || trim($name) === '') {
            return false;
        }

        try {
            $exists = DB::table(self::OPTIONS_TABLE)
                ->where('modulo_id', $moduleId)
                ->where('tipo', $type)
                ->where('id_externo', $externalId)
                ->exists();
            if ($exists) {
                return false;
            }

            $order = (int) DB::table(self::OPTIONS_TABLE)
                ->where('modulo_id', $moduleId)
                ->where('tipo', $type)
                ->max('orden') + 1;
            DB::table(self::OPTIONS_TABLE)->insert([
                'modulo_id' => $moduleId,
                'tipo' => $type,
                'id_externo' => $externalId,
                'nombre' => trim($name),
                'predeterminado' => 0,
                'activo' => 1,
                'orden' => max(1, $order),
                'actualizado_at' => now(),
            ]);

            return ! $default || $this->setDefaultOption($type, $externalId);
        } catch (\Throwable) {
            return false;
        }
    }

    public function updateOption(string $type, string $originalId, string $externalId, string $name, bool $default = false): bool
    {
        $moduleId = $this->resolveModuleId();
        if (! $this->optionsTableReady() || $moduleId === null || trim($originalId) === '' || trim($externalId) === '' || trim($name) === '') {
            return false;
        }

        try {
            $row = DB::table(self::OPTIONS_TABLE)
                ->where('modulo_id', $moduleId)
                ->where('tipo', $type)
                ->where('id_externo', $originalId)
                ->first(['predeterminado']);
            if ($row === null) {
                return false;
            }

            if ($externalId !== $originalId) {
                $conflict = DB::table(self::OPTIONS_TABLE)
                    ->where('modulo_id', $moduleId)
                    ->where('tipo', $type)
                    ->where('id_externo', $externalId)
                    ->exists();
                if ($conflict) {
                    return false;
                }
            }

            DB::table(self::OPTIONS_TABLE)
                ->where('modulo_id', $moduleId)
                ->where('tipo', $type)
                ->where('id_externo', $originalId)
                ->update([
                    'id_externo' => $externalId,
                    'nombre' => trim($name),
                    'predeterminado' => $default ? 1 : 0,
                    'activo' => 1,
                    'actualizado_at' => now(),
                ]);

            if ($default) {
                return $this->setDefaultOption($type, $externalId);
            }
            if (! empty($row->predeterminado)) {
                $this->saveDefaultOptionId($type, $this->defaultOptionId($type));
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function deleteOption(string $type, string $externalId): bool
    {
        $moduleId = $this->resolveModuleId();
        if (! $this->optionsTableReady() || $moduleId === null || trim($externalId) === '') {
            return false;
        }

        try {
            $row = DB::table(self::OPTIONS_TABLE)
                ->where('modulo_id', $moduleId)
                ->where('tipo', $type)
                ->where('id_externo', $externalId)
                ->first(['predeterminado']);
            if ($row === null) {
                return false;
            }

            DB::table(self::OPTIONS_TABLE)
                ->where('modulo_id', $moduleId)
                ->where('tipo', $type)
                ->where('id_externo', $externalId)
                ->delete();

            if (! empty($row->predeterminado)) {
                $replacement = DB::table(self::OPTIONS_TABLE)
                    ->where('modulo_id', $moduleId)
                    ->where('tipo', $type)
                    ->where('activo', 1)
                    ->orderBy('orden')
                    ->value('id_externo');
                if ($replacement !== null && trim((string) $replacement) !== '') {
                    return $this->setDefaultOption($type, (string) $replacement);
                }
                $this->saveDefaultOptionId($type, '');
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function setDefaultOption(string $type, string $externalId): bool
    {
        $moduleId = $this->resolveModuleId();
        if (! $this->optionsTableReady() || $moduleId === null || trim($externalId) === '') {
            return false;
        }

        try {
            $exists = DB::table(self::OPTIONS_TABLE)
                ->where('modulo_id', $moduleId)
                ->where('tipo', $type)
                ->where('id_externo', $externalId)
                ->exists();
            if (! $exists) {
                return false;
            }

            DB::table(self::OPTIONS_TABLE)
                ->where('modulo_id', $moduleId)
                ->where('tipo', $type)
                ->where('predeterminado', 1)
                ->update(['predeterminado' => 0, 'actualizado_at' => now()]);
            DB::table(self::OPTIONS_TABLE)
                ->where('modulo_id', $moduleId)
                ->where('tipo', $type)
                ->where('id_externo', $externalId)
                ->update(['predeterminado' => 1, 'actualizado_at' => now()]);
            $this->saveDefaultOptionId($type, $externalId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveModuleId(): ?int
    {
        if ($this->moduleIdResolved) {
            return $this->moduleId;
        }

        $this->moduleIdResolved = true;

        if (! $this->tableReady()) {
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
            'int' => (int) $value,
            default => $value,
        };
    }

    private function typeOf(mixed $value): string
    {
        if ($value === null) {
            return 'string';
        }
        if (is_bool($value)) {
            return 'bool';
        }
        if (is_int($value)) {
            return 'int';
        }
        if (is_array($value)) {
            return 'json';
        }

        return 'string';
    }

    private function encode(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
            'bool' => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    private function saveDefaultOptionId(string $type, string $externalId): void
    {
        $configKey = [
            'tracker' => 'tracker_id',
            'prioridad' => 'priority_id',
            'estado' => 'status_id',
        ][$type] ?? null;
        $moduleId = $this->resolveModuleId();
        if ($configKey === null || $moduleId === null) {
            return;
        }

        $value = $externalId === '' ? null : $externalId;
        DB::table(self::CONFIG_TABLE)->updateOrInsert(
            ['modulo_id' => $moduleId, 'clave' => $configKey],
            [
                'valor' => $value,
                'tipo' => $value !== null && is_numeric($value) ? 'int' : 'string',
                'actualizado_at' => now(),
            ]
        );
    }

    private function optionsTableReady(): bool
    {
        if ($this->optionsTableReadyCache !== null) {
            return $this->optionsTableReadyCache;
        }
        try {
            return $this->optionsTableReadyCache = Schema::hasTable(self::OPTIONS_TABLE);
        } catch (\Throwable) {
            return $this->optionsTableReadyCache = false;
        }
    }

    /**
     * @param  'tracker'|'prioridad'|'estado'  $type
     * @return array<int,array<string,mixed>>
     */
    private function optionsFromDatabase(string $type): array
    {
        if (! $this->optionsTableReady()) {
            return [];
        }

        $moduleId = $this->resolveModuleId();
        if ($moduleId === null) {
            return [];
        }

        try {
            return DB::table(self::OPTIONS_TABLE)
                ->where('modulo_id', $moduleId)
                ->where('tipo', $type)
                ->where('activo', 1)
                ->orderBy('orden')
                ->get(['id_externo', 'nombre', 'predeterminado'])
                ->map(static function (object $row): array {
                    $id = (string) ($row->id_externo ?? '');

                    return [
                        'id' => is_numeric($id) ? (int) $id : $id,
                        'nombre' => (string) ($row->nombre ?? ''),
                        'default' => (bool) ($row->predeterminado ?? false),
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  'tracker'|'prioridad'|'estado'  $type
     * @param  array<int,array<string,mixed>>  $items
     */
    private function saveOptionsToDatabase(string $type, array $items): void
    {
        if (! $this->optionsTableReady()) {
            return;
        }

        $moduleId = $this->resolveModuleId();
        if ($moduleId === null) {
            return;
        }

        $savedExternalIds = [];

        foreach (array_values($items) as $order => $item) {
            if (! is_array($item)) {
                continue;
            }

            $externalId = isset($item['id']) ? (string) $item['id'] : null;
            $name = trim((string) ($item['nombre'] ?? $item['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            try {
                DB::table(self::OPTIONS_TABLE)->updateOrInsert(
                    ['modulo_id' => $moduleId, 'tipo' => $type, 'id_externo' => $externalId],
                    [
                        'nombre' => $name,
                        'predeterminado' => ! empty($item['default']) ? 1 : 0,
                        'activo' => 1,
                        'orden' => $order + 1,
                        'actualizado_at' => now(),
                    ]
                );
                if ($externalId !== null) {
                    $savedExternalIds[] = $externalId;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            $deleteQuery = DB::table(self::OPTIONS_TABLE)
                ->where('modulo_id', $moduleId)
                ->where('tipo', $type);
            if ($savedExternalIds !== []) {
                $deleteQuery->whereNotIn('id_externo', $savedExternalIds);
            }
            $deleteQuery->delete();
        } catch (\Throwable) {
        }
    }
}
