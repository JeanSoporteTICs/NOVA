<?php

namespace RedmineTic\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manages per-module configuration and option lists.
 * Tables: configuraciones_modulo, modulo_opciones
 */
class RedmineConfigRepository
{
    private ?array $cache = null;

    public function __construct(
        private string $projectKey,
        private string $projectName,
    ) {}

    /** @return array<string,mixed> */
    public function configuration(): array
    {
        if ($this->cache === null) {
            $databaseConfig = $this->fromDatabase();
            unset($databaseConfig['roles'], $databaseConfig['trackers'], $databaseConfig['prioridades'], $databaseConfig['estados']);

            if ($this->optionsTableAvailable()) {
                $databaseConfig['trackers']    = $this->optionsFromDatabase('tracker');
                $databaseConfig['prioridades'] = $this->optionsFromDatabase('prioridad');
                $databaseConfig['estados']     = $this->optionsFromDatabase('estado');
            }

            $this->cache = array_merge($this->defaultConfiguration(), $databaseConfig);
        }

        return $this->cache;
    }

    /** @param array<string,mixed> $config */
    public function saveConfiguration(array $config): void
    {
        $this->cache = null;
        $databaseConfig = $config;
        unset($databaseConfig['roles']);

        foreach (['trackers' => 'tracker', 'prioridades' => 'prioridad', 'estados' => 'estado'] as $key => $tipo) {
            if (array_key_exists($key, $databaseConfig)) {
                $this->saveOptionsToDatabase($tipo, (array) $databaseConfig[$key]);
                unset($databaseConfig[$key]);
            }
        }

        $this->saveToDatabase($databaseConfig);
    }

    public function maintenanceModeEnabled(): bool
    {
        return !empty($this->configuration()['maintenance_mode']);
    }

    /**
     * Saves arbitrary key→value pairs to configuraciones_modulo.
     * Public so permission repo can dual-write roles here.
     *
     * @param array<string,mixed> $config
     * @param array<string,string> $types
     */
    public function saveToDatabase(array $config, array $types = []): void
    {
        if (!$this->configTableAvailable()) {
            return;
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return;
        }

        foreach ($config as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }

            $type   = $types[$key] ?? $this->configType($value);
            $stored = $type === 'json'
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) (is_bool($value) ? (int) $value : $value);

            try {
                DB::table('configuraciones_modulo')->updateOrInsert(
                    ['modulo_id' => $moduleId, 'clave' => $key],
                    ['valor' => $stored, 'tipo' => $type, 'actualizado_at' => now()]
                );
            } catch (\Throwable) {
                continue;
            }
        }
    }

    /** @return array<string,mixed> */
    public function fromDatabase(): array
    {
        if (!$this->configTableAvailable()) {
            return [];
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return [];
        }

        try {
            return DB::table('configuraciones_modulo')
                ->where('modulo_id', $moduleId)
                ->get()
                ->mapWithKeys(function ($row): array {
                    $value = (string) ($row->valor ?? '');
                    if (($row->tipo ?? '') === 'json') {
                        $decoded = json_decode($value, true);
                        $value   = is_array($decoded) ? $decoded : [];
                    } elseif (($row->tipo ?? '') === 'bool') {
                        $value = in_array(strtolower($value), ['1', 'true', 'si', 'sí', 'yes'], true);
                    } elseif (($row->tipo ?? '') === 'int') {
                        $value = (int) $value;
                    }

                    return [(string) $row->clave => $value];
                })
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function configTableAvailable(): bool
    {
        try {
            return Schema::hasTable('modulos_nova') && Schema::hasTable('configuraciones_modulo');
        } catch (\Throwable) {
            return false;
        }
    }

    public function optionsTableAvailable(): bool
    {
        try {
            return Schema::hasTable('modulo_opciones');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function optionsFromDatabase(string $tipo): array
    {
        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return [];
        }

        try {
            return DB::table('modulo_opciones')
                ->where('modulo_id', $moduleId)
                ->where('tipo', $tipo)
                ->orderBy('orden')
                ->get(['id_externo', 'nombre', 'predeterminado'])
                ->map(static function ($row): array {
                    $id = (string) ($row->id_externo ?? '');

                    return [
                        'id'      => is_numeric($id) ? (int) $id : $id,
                        'nombre'  => (string) ($row->nombre ?? ''),
                        'default' => (bool) $row->predeterminado,
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<int,array<string,mixed>> $items */
    public function saveOptionsToDatabase(string $tipo, array $items): void
    {
        if (!$this->optionsTableAvailable()) {
            return;
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return;
        }

        $savedExternalIds = [];

        foreach ($items as $orden => $item) {
            if (!is_array($item)) {
                continue;
            }
            $idExterno = isset($item['id']) ? (string) $item['id'] : null;
            $nombre    = trim((string) ($item['nombre'] ?? $item['name'] ?? ''));
            if ($nombre === '') {
                continue;
            }

            try {
                DB::table('modulo_opciones')->updateOrInsert(
                    ['modulo_id' => $moduleId, 'tipo' => $tipo, 'id_externo' => $idExterno],
                    [
                        'nombre'         => $nombre,
                        'predeterminado' => !empty($item['default']) ? 1 : 0,
                        'activo'         => 1,
                        'orden'          => (int) $orden + 1,
                        'actualizado_at' => now(),
                    ]
                );
                if ($idExterno !== null) {
                    $savedExternalIds[] = $idExterno;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        if ($savedExternalIds !== []) {
            try {
                DB::table('modulo_opciones')
                    ->where('modulo_id', $moduleId)
                    ->where('tipo', $tipo)
                    ->whereNotIn('id_externo', $savedExternalIds)
                    ->delete();
            } catch (\Throwable) {
            }
        }
    }

    private function configType(mixed $value): string
    {
        if (is_array($value)) {
            return 'json';
        }
        if (is_bool($value)) {
            return 'bool';
        }
        if (is_int($value)) {
            return 'int';
        }

        return 'string';
    }

    /** @return array<string,mixed> */
    private function defaultConfiguration(): array
    {
        return [
            'platform_url'       => '',
            'platform_token'     => '',
            'categories_url'     => '',
            'unidades_url'       => '',
            'webhook_url'        => '',
            'project_id'         => '',
            'project_name'       => 'Redmine TICS',
            'tracker_id'         => '',
            'priority_id'        => '',
            'status_id'          => '',
            'cf_solicitante'     => '',
            'cf_unidad'          => '',
            'cf_unidad_solicitante' => '',
            'cf_hora_extra'      => '',
            'retencion_horas'    => 24,
            'maintenance_mode'   => false,
            'maintenance_until'  => '',
            'trackers'           => [],
            'prioridades'        => [],
            'estados'            => [],
        ];
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
