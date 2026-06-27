<?php

namespace App\Repositories\Modules;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ModuleRegistry
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        $modules = config('modules', []);
        $state   = $this->state();

        foreach ($modules as $key => &$module) {
            $moduleState         = $state[$key] ?? [];
            $module['enabled']   = (bool) ($moduleState['enabled'] ?? true);
            $module['label']     = trim((string) ($moduleState['label'] ?? ''));
            $module['order']     = (int) ($moduleState['order'] ?? 100);
            $module['maintenance'] = $this->maintenanceState($module);
            if ($module['label'] !== '') {
                $module['name'] = $module['label'];
            }
        }
        unset($module);

        uasort($modules, static function (array $left, array $right): int {
            return [$left['order'] ?? 100, $left['name'] ?? ''] <=> [$right['order'] ?? 100, $right['name'] ?? ''];
        });

        return $modules;
    }

    /**
     * @return array<string,mixed>
     */
    public function get(string $key): array
    {
        $module = $this->all()[$key] ?? null;

        if (!is_array($module)) {
            abort(404);
        }

        return $module;
    }

    /**
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function enabled(): array
    {
        return array_filter(
            $this->all(),
            static fn (array $module): bool => (bool) ($module['enabled'] ?? true)
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function userMatrix(): array
    {
        $users = [];
        if (!$this->accessTablesAvailable()) {
            return [];
        }

        $modules = $this->all();

        try {
            $rows = DB::table('usuarios_nova')
                ->leftJoin('permisos_usuario_modulo', 'usuarios_nova.id', '=', 'permisos_usuario_modulo.usuario_id')
                ->leftJoin('modulos_nova', 'modulos_nova.id', '=', 'permisos_usuario_modulo.modulo_id')
                ->select([
                    'usuarios_nova.uuid',
                    'usuarios_nova.usuario',
                    'usuarios_nova.rut',
                    'usuarios_nova.redmine_id',
                    'usuarios_nova.nombre',
                    'usuarios_nova.apellido',
                    'usuarios_nova.rol',
                    'usuarios_nova.estado',
                    'modulos_nova.clave_modulo',
                    'permisos_usuario_modulo.permitido',
                ])
                ->orderBy('usuarios_nova.nombre')
                ->orderBy('usuarios_nova.apellido')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as $row) {
            $identity = $this->userIdentity([
                'rut_sin_dv' => $row->usuario ?? '',
                'rut'        => $row->rut ?? '',
                'id'         => $row->redmine_id ?? $row->uuid ?? '',
            ]);
            if ($identity === '') {
                continue;
            }

            if (!isset($users[$identity])) {
                $users[$identity] = [
                    'identity' => $identity,
                    'name'     => trim((string) (($row->nombre ?? '') . ' ' . ($row->apellido ?? ''))) ?: 'Usuario sin nombre',
                    'rut'      => trim((string) ($row->rut ?? '')),
                    'status'   => trim((string) ($row->estado ?? '')),
                    'projects' => [],
                ];
            }

            $projectKey = trim((string) ($row->clave_modulo ?? ''));
            if ($projectKey === '' || empty($row->permitido)) {
                continue;
            }

            $module                                       = $modules[$projectKey] ?? ['name' => $projectKey];
            $users[$identity]['projects'][$projectKey] = [
                'name'   => (string) ($module['name'] ?? $projectKey),
                'role'   => trim((string) ($row->rol ?? 'usuario')),
                'status' => trim((string) ($row->estado ?? '')),
            ];
        }

        uasort($users, static function (array $left, array $right): int {
            return [$left['name'], $left['identity']] <=> [$right['name'], $right['identity']];
        });

        return array_values($users);
    }

    /**
     * Returns per-module state keyed by clave_modulo.
     *
     * @return array<string,array<string,mixed>>
     */
    public function state(): array
    {
        return \Illuminate\Support\Facades\Cache::remember(
            'nova.modules.state',
            300,
            function (): array {
                if (!$this->accessTablesAvailable() || !Schema::hasColumn('modulos_nova', 'habilitado')) {
                    return [];
                }
                try {
                    $rows = DB::table('modulos_nova')
                        ->get(['clave_modulo', 'habilitado', 'en_mantencion', 'nombre', 'orden'])
                        ->keyBy('clave_modulo')
                        ->map(static fn (object $row): array => [
                            'enabled'     => (bool) ($row->habilitado ?? true),
                            'maintenance' => (bool) ($row->en_mantencion ?? false),
                        ])
                        ->all();
                    return $rows;
                } catch (\Throwable) {
                    return [];
                }
            }
        );
    }

    /**
     * @param array<string,array<string,mixed>> $state
     */
    public function saveState(array $state): void
    {
        if ($this->accessTablesAvailable() && Schema::hasColumn('modulos_nova', 'habilitado')) {
            foreach ($state as $key => $moduleState) {
                if (!is_array($moduleState)) {
                    continue;
                }
                $updates = [];
                if (array_key_exists('enabled', $moduleState)) {
                    $updates['habilitado'] = (bool) $moduleState['enabled'] ? 1 : 0;
                }
                if (array_key_exists('maintenance', $moduleState)) {
                    $updates['en_mantencion'] = (bool) $moduleState['maintenance'] ? 1 : 0;
                }
                if ($updates !== []) {
                    try {
                        DB::table('modulos_nova')->where('clave_modulo', $key)->update($updates);
                    } catch (\Throwable) {
                    }
                }
            }
        }
        \Illuminate\Support\Facades\Cache::forget('nova.modules.state');
    }

    /**
     * @param array<string,mixed> $module
     * @return array{enabled:bool,until:string,until_text:string}
     */
    private function maintenanceState(array $module): array
    {
        $modulePath = rtrim((string) ($module['path'] ?? ''), DIRECTORY_SEPARATOR);
        if (str_contains(str_replace('\\', '/', $modulePath), 'redmine-mantencion')) {
            try {
                $moduleId = DB::table('modulos_nova')
                    ->where('clave_modulo', 'redmine-mantencion')
                    ->value('id');
                if ($moduleId !== null) {
                    $rows    = DB::table('configuraciones_modulo')
                        ->where('modulo_id', $moduleId)
                        ->whereIn('clave', ['maintenance_mode', 'maintenance_until'])
                        ->get(['clave', 'valor'])
                        ->pluck('valor', 'clave');
                    $enabled = in_array(strtolower((string) ($rows['maintenance_mode'] ?? '')), ['1', 'true'], true);
                    $until   = trim((string) ($rows['maintenance_until'] ?? ''));
                    return [
                        'enabled'    => $enabled,
                        'until'      => $until,
                        'until_text' => $this->formatMaintenanceUntil($until),
                    ];
                }
            } catch (\Throwable) {
            }
        }

        return ['enabled' => false, 'until' => '', 'until_text' => ''];
    }

    private function formatMaintenanceUntil(string $until): string
    {
        if ($until === '') {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $until, new \DateTimeZone('America/Santiago'));

        return $date ? $date->format('d-m-Y H:i') : $until;
    }

    /**
     * @param array<string,mixed> $record
     */
    private function userIdentity(array $record): string
    {
        foreach (['rut_sin_dv', 'rut', 'id', 'api'] as $field) {
            $value = trim((string) ($record[$field] ?? ''));
            if ($value !== '') {
                return strtolower($field . ':' . $value);
            }
        }

        return strtolower('name:' . $this->userDisplayName($record));
    }

    /**
     * @param array<string,mixed> $record
     */
    private function userDisplayName(array $record): string
    {
        $name        = trim((string) ($record['nombre'] ?? ''));
        $lastName    = trim((string) ($record['apellido'] ?? ''));
        $displayName = trim($name . ' ' . $lastName);

        return $displayName !== '' ? $displayName : 'Usuario sin nombre';
    }

    private function accessTablesAvailable(): bool
    {
        try {
            return Schema::hasTable('usuarios_nova')
                && Schema::hasTable('modulos_nova')
                && Schema::hasTable('permisos_usuario_modulo');
        } catch (\Throwable) {
            return false;
        }
    }
}
