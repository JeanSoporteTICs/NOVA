<?php

namespace App\Modulos\RedmineMantencion\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MantencionCatalogRepository
{
    private const MODULE_KEY = 'redmine-mantencion';

    private ?int $moduleId = null;
    private bool $moduleIdResolved = false;

    public function tableReady(): bool
    {
        try {
            return Schema::hasTable('modulos_nova')
                && Schema::hasTable('categorias')
                && Schema::hasTable('unidades');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<int,array{id:string,nombre:string}> */
    public function categorias(): array
    {
        return $this->catalogRows('categorias');
    }

    /** @return array<int,array{id:string,nombre:string}> */
    public function unidades(): array
    {
        return $this->catalogRows('unidades');
    }

    public function categoriaNombre(?int $id): ?string
    {
        return $this->nameById('categorias', $id);
    }

    public function unidadNombre(?int $id): ?string
    {
        return $this->nameById('unidades', $id);
    }

    public function categoriaIdPorNombre(string $name): ?int
    {
        return $this->idByName('categorias', $name);
    }

    public function unidadIdPorNombre(string $name): ?int
    {
        return $this->idByName('unidades', $name);
    }

    /** @param array<int,array<string,mixed>> $rows */
    public function upsertCategorias(array $rows): void
    {
        $this->upsertRows('categorias', $rows);
    }

    /** @param array<int,array<string,mixed>> $rows */
    public function upsertUnidades(array $rows): void
    {
        $this->upsertRows('unidades', $rows);
    }

    public function deactivateUnidad(string $id): void
    {
        $this->deactivateByDisplayId('unidades', $id);
    }

    /** @return array<string,string> */
    public function categoriaNameMap(): array
    {
        return $this->nameMap('categorias');
    }

    /** @return array<string,string> */
    public function unidadNameMap(): array
    {
        return $this->nameMap('unidades');
    }

    /** @return array<int,string> */
    public function categoriaNames(): array
    {
        return array_values(array_map(static fn (array $row): string => $row['nombre'], $this->categorias()));
    }

    /** @return array<int,string> */
    public function unidadNames(): array
    {
        return array_values(array_map(static fn (array $row): string => $row['nombre'], $this->unidades()));
    }

    /** @return array<int,array{id:string,nombre:string}> */
    private function catalogRows(string $table): array
    {
        $moduleId = $this->resolveModuleId();
        if ($moduleId === null || ! $this->tableReady()) {
            return [];
        }

        try {
            $columns = ['id', 'nombre'];
            if (Schema::hasColumn($table, 'clave_externa')) {
                $columns[] = 'clave_externa';
            }
            if (Schema::hasColumn($table, 'origen')) {
                $columns[] = 'origen';
            }

            $query = DB::table($table)
                ->where('modulo_id', $moduleId);

            if (Schema::hasColumn($table, 'activo')) {
                $query->where('activo', 1);
            }

            $rows = $query
                ->orderByRaw($this->originOrderSql($table))
                ->orderByRaw(Schema::hasColumn($table, 'clave_externa') ? "CASE WHEN clave_externa IS NULL OR clave_externa = '' THEN 1 ELSE 0 END" : 'id asc')
                ->orderBy('nombre')
                ->orderBy('id')
                ->get($columns);
        } catch (\Throwable) {
            return [];
        }

        $byName = [];
        foreach ($rows as $row) {
            $name = trim((string) ($row->nombre ?? ''));
            if ($name === '') {
                continue;
            }

            $key = mb_strtoupper($name, 'UTF-8');
            $externalId = trim((string) ($row->clave_externa ?? ''));
            $value = [
                'id' => $externalId !== '' ? $externalId : (string) $row->id,
                'nombre' => $name,
            ];

            if (! isset($byName[$key])) {
                $byName[$key] = $value;
            }
        }

        return array_values($byName);
    }

    private function nameById(string $table, ?int $id): ?string
    {
        if ($id === null || $id <= 0 || ! $this->tableReady()) {
            return null;
        }

        try {
            $name = DB::table($table)->where('id', $id)->value('nombre');
        } catch (\Throwable) {
            return null;
        }

        $name = trim((string) $name);
        return $name !== '' ? $name : null;
    }

    private function idByName(string $table, string $name): ?int
    {
        $moduleId = $this->resolveModuleId();
        $name = trim($name);
        if ($moduleId === null || $name === '' || ! $this->tableReady()) {
            return null;
        }

        try {
            $query = DB::table($table)
                ->where('modulo_id', $moduleId)
                ->where('nombre', $name);

            if (Schema::hasColumn($table, 'activo')) {
                $query->where('activo', 1);
            }

            $id = $query
                ->orderByRaw($this->originOrderSql($table))
                ->orderByRaw(Schema::hasColumn($table, 'clave_externa') ? "CASE WHEN clave_externa IS NULL OR clave_externa = '' THEN 1 ELSE 0 END" : 'id asc')
                ->orderBy('id')
                ->value('id');
        } catch (\Throwable) {
            return null;
        }

        return $id !== null ? (int) $id : null;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function upsertRows(string $table, array $rows): void
    {
        $moduleId = $this->resolveModuleId();
        if ($moduleId === null || ! $this->tableReady()) {
            return;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $this->rowName($row);
            if ($name === '') {
                continue;
            }

            $externalId = $this->rowExternalId($row);
            $values = [
                'modulo_id' => $moduleId,
                'nombre' => $name,
                'actualizado_at' => now(),
            ];

            if (Schema::hasColumn($table, 'clave_externa')) {
                $values['clave_externa'] = $externalId !== '' ? $externalId : null;
            }
            if (Schema::hasColumn($table, 'activo')) {
                $values['activo'] = true;
            }
            if (Schema::hasColumn($table, 'predeterminado')) {
                $values['predeterminado'] = false;
            }
            if (Schema::hasColumn($table, 'origen')) {
                $values['origen'] = 'redmine_mantencion_storage';
            }

            try {
                $id = $this->findExistingRowId($table, $moduleId, $name, $externalId);
                if ($id !== null) {
                    DB::table($table)->where('id', $id)->update($values);
                    continue;
                }

                $values['creado_at'] = now();
                DB::table($table)->insert($values);
            } catch (\Throwable) {
                continue;
            }
        }
    }

    private function findExistingRowId(string $table, int $moduleId, string $name, string $externalId): ?int
    {
        $query = DB::table($table)->where('modulo_id', $moduleId);

        if ($externalId !== '' && Schema::hasColumn($table, 'clave_externa')) {
            $byExternal = (clone $query)->where('clave_externa', $externalId)->value('id');
            if ($byExternal !== null) {
                return (int) $byExternal;
            }
        }

        $byName = (clone $query)
            ->where('nombre', $name)
            ->orderByRaw($this->originOrderSql($table))
            ->orderBy('id')
            ->value('id');

        return $byName !== null ? (int) $byName : null;
    }

    private function deactivateByDisplayId(string $table, string $id): void
    {
        $moduleId = $this->resolveModuleId();
        $id = trim($id);
        if ($moduleId === null || $id === '' || ! $this->tableReady() || ! Schema::hasColumn($table, 'activo')) {
            return;
        }

        try {
            $query = DB::table($table)->where('modulo_id', $moduleId);
            if (ctype_digit($id)) {
                $query->where(function ($inner) use ($id, $table): void {
                    $inner->where('id', (int) $id);
                    if (Schema::hasColumn($table, 'clave_externa')) {
                        $inner->orWhere('clave_externa', $id);
                    }
                });
            } elseif (Schema::hasColumn($table, 'clave_externa')) {
                $query->where('clave_externa', $id);
            } else {
                return;
            }

            $query->update(['activo' => false, 'actualizado_at' => now()]);
        } catch (\Throwable) {
        }
    }

    /** @return array<string,string> */
    private function nameMap(string $table): array
    {
        $map = [];
        foreach ($this->catalogRows($table) as $row) {
            $map[mb_strtoupper(trim($row['nombre']), 'UTF-8')] = $row['id'] !== '' ? $row['id'] : $row['nombre'];
        }

        return $map;
    }

    private function rowName(array $row): string
    {
        foreach (['nombre', 'name', 'label', 'text', 'value'] as $key) {
            if (array_key_exists($key, $row) && ! is_array($row[$key])) {
                $value = trim((string) $row[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function rowExternalId(array $row): string
    {
        foreach (['id', 'clave_externa', 'value', 'clave'] as $key) {
            if (array_key_exists($key, $row) && ! is_array($row[$key])) {
                $value = trim((string) $row[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function originOrderSql(string $table): string
    {
        if (! Schema::hasColumn($table, 'origen')) {
            return 'id asc';
        }

        return "FIELD(origen, 'redmine_mantencion_storage', 'catalogos_modulo', 'reportes_mantencion', 'normalizado') DESC";
    }

    private function resolveModuleId(): ?int
    {
        if ($this->moduleIdResolved) {
            return $this->moduleId;
        }

        $this->moduleIdResolved = true;

        try {
            if (! Schema::hasTable('modulos_nova')) {
                return null;
            }

            $id = DB::table('modulos_nova')->where('clave_modulo', self::MODULE_KEY)->value('id');
            $this->moduleId = $id !== null ? (int) $id : null;
        } catch (\Throwable) {
            $this->moduleId = null;
        }

        return $this->moduleId;
    }
}
