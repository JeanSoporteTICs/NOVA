<?php

namespace RedmineTic\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Manages catalog rows (categories, units, generic catalogs) and lookup caches.
 * Table: catalogos_modulo
 */
class RedmineCatalogRepository
{
    private ?array $idsByTypeValue = null;
    private ?array $namesById      = null;
    private ?array $externalValuesByTypeValue = null;
    private ?array $activeExternalValuesById = null;
    private ?bool $tableAvailableCache = null;

    public function __construct(
        private string $projectKey,
        private string $projectName,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function categories(): array
    {
        return $this->fromDatabase('categoria');
    }

    /** @return array<int,array<string,mixed>> */
    public function saveCategory(array $payload): array
    {
        return $this->upsertRow('categoria', $payload);
    }

    public function deleteCategory(string $id): int
    {
        return $this->deleteRow('categoria', $id);
    }

    /** @return array<int,array<string,mixed>> */
    public function units(): array
    {
        return $this->fromDatabase('unidad');
    }

    /** @return array<int,array<string,mixed>> */
    public function saveUnit(array $payload): array
    {
        return $this->upsertRow('unidad', $payload);
    }

    public function deleteUnit(string $id): int
    {
        return $this->deleteRow('unidad', $id);
    }

    // ---- lookup helpers (used by report persistence in RedmineDataRepository) ----

    public function idForValue(string $type, mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || !$this->tableAvailable()) {
            return null;
        }

        $this->loadLookup();
        $key = $type . ':' . $this->normalizeLookupValue($value);

        if (isset($this->idsByTypeValue[$key])) {
            return $this->idsByTypeValue[$key];
        }

        // Los valores de reportes son datos operacionales, no nuevas opciones
        // de catalogo. Las altas deben pasar explicitamente por saveUnit(),
        // saveCategory() o por la sincronizacion de Redmine.
        return null;
    }

    public function nameById(mixed $id): string
    {
        $id = (int) $id;
        if ($id <= 0 || !$this->tableAvailable()) {
            return '';
        }

        $this->loadLookup();

        return (string) ($this->namesById[$id] ?? '');
    }

    /**
     * Returns the exact external key expected by Redmine for an active row.
     */
    public function activeExternalValue(string $type, mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || !$this->tableAvailable()) {
            return null;
        }

        $this->loadLookup();
        $key = $type . ':' . $this->normalizeLookupValue($value);
        $externalValue = $this->externalValuesByTypeValue[$key] ?? null;

        return is_string($externalValue) && $externalValue !== '' ? $externalValue : null;
    }

    public function activeExternalValueById(string $type, mixed $id): ?string
    {
        $id = (int) $id;
        if ($id <= 0 || !$this->tableAvailable()) {
            return null;
        }

        $this->loadLookup();
        $externalValue = $this->activeExternalValuesById[$type . ':' . $id] ?? null;

        return is_string($externalValue) && $externalValue !== '' ? $externalValue : null;
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,array{id:string,nombre:string}>
     */
    public function rowsFromRedminePossibleValues(array $values): array
    {
        $rows = [];
        foreach ($values as $value) {
            $externalValue = is_array($value)
                ? trim((string) ($value['value'] ?? ''))
                : trim((string) $value);
            $label = is_array($value)
                ? trim((string) ($value['label'] ?? $externalValue))
                : $externalValue;
            if ($externalValue === '' || $label === '') {
                continue;
            }
            $rows[] = ['id' => $externalValue, 'nombre' => $label];
        }

        return $rows;
    }

    // ---- DB operations (also used by syncCategoriesFromRedmine etc. in RDR) ----

    public function tableAvailable(): bool
    {
        if ($this->tableAvailableCache !== null) {
            return $this->tableAvailableCache;
        }
        try {
            return $this->tableAvailableCache = Schema::hasTable('modulos_nova') && Schema::hasTable('catalogos_modulo');
        } catch (\Throwable) {
            return $this->tableAvailableCache = false;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $current
     * @param array<int,array<string,string>> $incoming
     */
    public function rowsChanged(array $current, array $incoming): bool
    {
        $normalize = static function (array $rows): array {
            return array_values(array_map(static fn (array $row): array => [
                'id'     => trim((string) ($row['id'] ?? $row['clave_externa'] ?? '')),
                'nombre' => trim((string) ($row['nombre'] ?? $row['name'] ?? '')),
            ], $rows));
        };

        return $normalize($current) !== $normalize($incoming);
    }

    /** @param array<int,array<string,mixed>> $rows */
    public function saveCatalogRowsToDatabase(string $type, array $rows, bool $deactivateMissing = true): void
    {
        if (!$this->tableAvailable()) {
            return;
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return;
        }

        $keys = [];
        foreach ($rows as $row) {
            $key  = trim((string) ($row['id'] ?? $row['clave_externa'] ?? $row['nombre'] ?? ''));
            $name = trim((string) ($row['nombre'] ?? $row['name'] ?? $key));
            if ($key === '' || $name === '') {
                continue;
            }
            $keys[] = $key;

            try {
                DB::table('catalogos_modulo')->updateOrInsert(
                    ['modulo_id' => $moduleId, 'tipo' => $type, 'clave_externa' => $key],
                    [
                        'clave_externa' => $key,
                        'nombre'         => $name,
                        'predeterminado' => !empty($row['predeterminado']) ? 1 : 0,
                        'activo'         => !array_key_exists('activo', $row) || !empty($row['activo']) ? 1 : 0,
                        'actualizado_at' => now(),
                    ]
                );
            } catch (\Throwable) {
                continue;
            }
        }

        if ($deactivateMissing && $keys !== []) {
            try {
                DB::table('catalogos_modulo')
                    ->where('modulo_id', $moduleId)
                    ->where('tipo', $type)
                    ->whereNotIn('clave_externa', $keys)
                    ->update(['activo' => 0, 'actualizado_at' => now()]);
            } catch (\Throwable) {
            }
        }

        // Invalidate lookup cache
        $this->idsByTypeValue = null;
        $this->namesById      = null;
        $this->externalValuesByTypeValue = null;
        $this->activeExternalValuesById = null;
    }

    // ---- private helpers ----

    /** @return array<int,array<string,mixed>> */
    private function fromDatabase(string $type): array
    {
        if (!$this->tableAvailable()) {
            return [];
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return [];
        }

        try {
            return DB::table('catalogos_modulo')
                ->where('modulo_id', $moduleId)
                ->where('tipo', $type)
                ->where('activo', 1)
                ->orderBy('nombre')
                ->get()
                ->map(static fn ($row): array => [
                    'id'     => (string) ($row->clave_externa ?? $row->id ?? ''),
                    'nombre' => (string) ($row->nombre ?? ''),
                ])
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function upsertRow(string $type, array $payload): array
    {
        $id   = trim((string) ($payload['id'] ?? $payload['clave_externa'] ?? '')) ?: (string) Str::uuid();
        $name = trim((string) ($payload['nombre'] ?? $payload['name'] ?? ''));
        if ($name === '') {
            return $this->fromDatabase($type);
        }

        $this->saveCatalogRowsToDatabase($type, [['id' => $id, 'nombre' => $name, 'activo' => 1]], false);

        return $this->fromDatabase($type);
    }

    private function deleteRow(string $type, string $id): int
    {
        if (!$this->tableAvailable()) {
            return 0;
        }

        $moduleId = $this->moduleId();
        $id       = trim($id);
        if ($moduleId === null || $id === '') {
            return 0;
        }

        $updated = DB::table('catalogos_modulo')
            ->where('modulo_id', $moduleId)
            ->where('tipo', $type)
            ->where('clave_externa', $id)
            ->update(['activo' => 0, 'actualizado_at' => now()]);

        if ($updated > 0) {
            $this->idsByTypeValue = null;
            $this->namesById = null;
            $this->externalValuesByTypeValue = null;
            $this->activeExternalValuesById = null;
        }

        return $updated;
    }

    private function loadLookup(): void
    {
        if ($this->idsByTypeValue !== null && $this->namesById !== null && $this->externalValuesByTypeValue !== null && $this->activeExternalValuesById !== null) {
            return;
        }

        $this->idsByTypeValue = [];
        $this->namesById      = [];
        $this->externalValuesByTypeValue = [];
        $this->activeExternalValuesById = [];
        $moduleId             = $this->moduleId();
        if ($moduleId === null) {
            return;
        }

        try {
            $rows = DB::table('catalogos_modulo')
                ->where('modulo_id', $moduleId)
                ->orderByDesc('activo')
                ->orderBy('id')
                ->get(['id', 'tipo', 'clave_externa', 'nombre', 'activo']);
        } catch (\Throwable) {
            return;
        }

        foreach ($rows as $row) {
            $id   = (int) ($row->id ?? 0);
            $type = trim((string) ($row->tipo ?? ''));
            $name = trim((string) ($row->nombre ?? ''));
            $externalValue = trim((string) ($row->clave_externa ?? ''));
            if ($id <= 0 || $type === '') {
                continue;
            }

            $this->namesById[$id] = $name;
            if (empty($row->activo)) {
                continue;
            }
            $this->activeExternalValuesById[$type . ':' . $id] = $externalValue;
            foreach ([$row->clave_externa ?? '', $name] as $candidate) {
                $candidate = $this->normalizeLookupValue((string) $candidate);
                if ($candidate !== '') {
                    $this->idsByTypeValue[$type . ':' . $candidate] = $id;
                    $this->externalValuesByTypeValue[$type . ':' . $candidate] = $externalValue;
                }
            }
        }
    }

    private function normalizeLookupValue(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }

    private function moduleId(): ?int
    {
        try {
            $id = DB::table('modulos_nova')->where('clave_modulo', $this->projectKey)->value('id');
            if ($id !== null) {
                return (int) $id;
            }

            DB::table('modulos_nova')->insert([
                'clave_modulo'   => $this->projectKey,
                'nombre'         => $this->projectName,
                'descripcion'    => '',
                'icono'          => '',
                'tipo'           => 'native',
                'ruta'           => $this->projectKey,
                'entrada'        => 'laravel:redmine.native.dashboard',
                'habilitado'     => 1,
                'orden'          => 100,
                'creado_at'      => now(),
                'actualizado_at' => now(),
            ]);

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }
}
