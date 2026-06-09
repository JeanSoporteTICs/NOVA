<?php

namespace App\Support\Modules;

final class ModuleRegistry
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        $modules = config('modules', []);
        $state = $this->state();

        foreach ($modules as $key => &$module) {
            $moduleState = $state[$key] ?? [];
            $module['enabled'] = (bool) ($moduleState['enabled'] ?? true);
            $module['label'] = trim((string) ($moduleState['label'] ?? ''));
            $module['order'] = (int) ($moduleState['order'] ?? 100);
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
        $novaUsers = json_decode((string) @file_get_contents(storage_path('app/nova/users.json')), true);
        if (is_array($novaUsers)) {
            foreach ($novaUsers as $record) {
                if (!is_array($record) || !is_array($record['projects'] ?? null)) {
                    continue;
                }

                $identity = $this->userIdentity([
                    'rut_sin_dv' => $record['rut_sin_dv'] ?? $record['username'] ?? '',
                    'rut' => $record['rut'] ?? '',
                    'id' => $record['redmine_id'] ?? $record['id'] ?? '',
                ]);
                if ($identity === '') {
                    continue;
                }

                if (!isset($users[$identity])) {
                    $users[$identity] = [
                        'identity' => $identity,
                        'name' => trim((string) (($record['name'] ?? '') . ' ' . ($record['apellido'] ?? ''))) ?: 'Usuario sin nombre',
                        'rut' => trim((string) ($record['rut'] ?? '')),
                        'status' => trim((string) ($record['status'] ?? '')),
                        'projects' => [],
                    ];
                }

                foreach ($record['projects'] as $projectKey => $project) {
                    if (!is_array($project)) {
                        continue;
                    }
                    $module = $this->all()[$projectKey] ?? ['name' => $projectKey];
                    $users[$identity]['projects'][$projectKey] = [
                        'name' => (string) ($module['name'] ?? $projectKey),
                        'role' => trim((string) ($project['rol'] ?? 'sin rol')),
                        'status' => trim((string) ($project['estado_usuario'] ?? '')),
                    ];
                }
            }
        }

        foreach ($this->all() as $projectKey => $module) {
            $path = rtrim((string) ($module['path'] ?? ''), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'usuarios.json';
            if (!is_file($path)) {
                continue;
            }

            $records = json_decode((string) file_get_contents($path), true);
            if (!is_array($records)) {
                continue;
            }

            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $identity = $this->userIdentity($record);
                if ($identity === '') {
                    continue;
                }

                if (!isset($users[$identity])) {
                    $users[$identity] = [
                        'identity' => $identity,
                        'name' => $this->userDisplayName($record),
                        'rut' => trim((string) ($record['rut'] ?? '')),
                        'status' => trim((string) ($record['estado'] ?? $record['estado_usuario'] ?? '')),
                        'projects' => [],
                    ];
                }

                $users[$identity]['projects'][$projectKey] = [
                    'name' => (string) ($module['name'] ?? $projectKey),
                    'role' => trim((string) ($record['rol'] ?? 'sin rol')),
                    'status' => trim((string) ($record['estado'] ?? $record['estado_usuario'] ?? '')),
                ];
            }
        }

        uasort($users, static function (array $left, array $right): int {
            return [$left['name'], $left['identity']] <=> [$right['name'], $right['identity']];
        });

        return array_values($users);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function state(): array
    {
        $path = $this->statePath();
        if (!is_file($path)) {
            return [];
        }

        $state = json_decode((string) file_get_contents($path), true);

        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string,array<string,mixed>> $state
     */
    public function saveState(array $state): void
    {
        $path = $this->statePath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function statePath(): string
    {
        return storage_path('app/modules/state.json');
    }

    /**
     * @param array<string,mixed> $module
     * @return array{enabled:bool,until:string,until_text:string}
     */
    private function maintenanceState(array $module): array
    {
        $path = rtrim((string) ($module['path'] ?? ''), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'configuracion.json';

        if (!is_file($path)) {
            return ['enabled' => false, 'until' => '', 'until_text' => ''];
        }

        $config = json_decode((string) file_get_contents($path), true);
        if (!is_array($config)) {
            return ['enabled' => false, 'until' => '', 'until_text' => ''];
        }

        $until = trim((string) ($config['maintenance_until'] ?? ''));

        return [
            'enabled' => !empty($config['maintenance_mode']),
            'until' => $until,
            'until_text' => $this->formatMaintenanceUntil($until),
        ];
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
        $name = trim((string) ($record['nombre'] ?? ''));
        $lastName = trim((string) ($record['apellido'] ?? ''));
        $displayName = trim($name . ' ' . $lastName);

        return $displayName !== '' ? $displayName : 'Usuario sin nombre';
    }
}
