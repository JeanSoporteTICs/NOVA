<?php

namespace RedmineTic\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manages relational permission tables for roles and user permission keys.
 * Tables: redmine_tic_permisos_rol, redmine_tic_permisos_usuario
 *
 * Note: RedmineDataRepository::allPermissionsFromRelational() and
 * ::savePermissionsToRelational() still exist as private bridges to this
 * class's public methods of the same name — kept only because
 * tests/Feature/Phase3aPermissionsTest.php reaches them via reflection
 * (ETAPA B / Lote B2). Do not remove those bridges without first migrating
 * that test to call this class's public API directly.
 */
class RedminePermissionRepository
{
    private const SCOPE_KEYS = ['mensajes', 'historico_scope', 'horas_extra'];

    private ?bool $userPermissionsTableAvailableCache = null;

    private ?bool $rolePermissionsTableAvailableCache = null;

    public function __construct(
        private string $projectKey,
        private string $projectName,
    ) {}

    /**
     * Returns roles from the relational table (with its own JSON-blob
     * fallback), then falls back to hard-coded defaults if still empty.
     *
     * @return array<string,array<string,mixed>>
     */
    public function roles(): array
    {
        $databaseRoles = $this->rolesFromDatabase();

        return $databaseRoles !== [] ? $databaseRoles : $this->defaultRoles();
    }

    /**
     * Returns roles from the relational table, then falls back to the JSON blob
     * in configuraciones_modulo, then to hard-coded defaults.
     *
     * @return array<string,array<string,mixed>>
     */
    public function rolesFromDatabase(): array
    {
        $relational = $this->rolesFromRelational();
        if ($relational !== []) {
            return $relational;
        }

        // Fallback: JSON blob stored in configuraciones_modulo
        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return [];
        }

        try {
            $row = DB::table('configuraciones_modulo')
                ->where('modulo_id', $moduleId)
                ->where('clave', 'roles')
                ->first(['valor', 'tipo']);

            if ($row && ($row->tipo ?? '') === 'json') {
                $decoded = json_decode((string) ($row->valor ?? ''), true);

                return is_array($decoded) ? $decoded : [];
            }
        } catch (\Throwable) {
        }

        return [];
    }

    /**
     * Reads all role→key→value triplets from the relational table.
     *
     * @return array<string,array<string,mixed>>
     */
    public function rolesFromRelational(): array
    {
        if (! $this->rolPermissionsTableAvailable()) {
            return [];
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return [];
        }

        try {
            $rows = DB::table('redmine_tic_permisos_rol')
                ->where('modulo_id', $moduleId)
                ->get(['rol', 'clave', 'valor']);

            if ($rows->isEmpty()) {
                return [];
            }

            $roles = [];
            foreach ($rows as $row) {
                $rol = (string) $row->rol;
                $clave = (string) $row->clave;
                $roles[$rol][$clave] = $this->decodeValue($clave, (string) $row->valor);
            }

            return $roles;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Writes the full roles dict to the relational table (upsert per key + prune removed roles/keys).
     *
     * @param  array<string,array<string,mixed>>  $roles
     */
    public function saveRolesToRelational(array $roles): void
    {
        if (! $this->rolPermissionsTableAvailable()) {
            return;
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return;
        }

        foreach ($roles as $rol => $permissions) {
            $rol = trim((string) $rol);
            if ($rol === '' || ! is_array($permissions)) {
                continue;
            }

            $savedClaves = [];
            foreach ($permissions as $clave => $valor) {
                $clave = trim((string) $clave);
                if ($clave === '') {
                    continue;
                }
                try {
                    DB::table('redmine_tic_permisos_rol')->updateOrInsert(
                        ['modulo_id' => $moduleId, 'rol' => $rol, 'clave' => $clave],
                        ['valor' => $this->encodeValue($clave, $valor), 'actualizado_at' => now()]
                    );
                    $savedClaves[] = $clave;
                } catch (\Throwable) {
                    continue;
                }
            }

            if (! empty($savedClaves)) {
                try {
                    DB::table('redmine_tic_permisos_rol')
                        ->where('modulo_id', $moduleId)
                        ->where('rol', $rol)
                        ->whereNotIn('clave', $savedClaves)
                        ->delete();
                } catch (\Throwable) {
                }
            }
        }

        $roleNames = array_values(array_filter(array_map('trim', array_keys($roles))));
        if (! empty($roleNames)) {
            try {
                DB::table('redmine_tic_permisos_rol')
                    ->where('modulo_id', $moduleId)
                    ->whereNotIn('rol', $roleNames)
                    ->delete();
            } catch (\Throwable) {
            }
        }
    }

    /** @param array<string,mixed> $permissions */
    public function saveRolePermissions(string $role, array $permissions): bool
    {
        $role = trim($role);
        if ($role === '' || ! $this->rolPermissionsTableAvailable()) {
            return false;
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return false;
        }

        $rows = [];
        foreach ($permissions as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $rows[] = [
                'modulo_id' => $moduleId,
                'rol' => $role,
                'clave' => $key,
                'valor' => $this->encodeValue($key, $value),
                'actualizado_at' => now(),
            ];
        }
        if ($rows === []) {
            return false;
        }

        try {
            DB::table('redmine_tic_permisos_rol')->upsert(
                $rows,
                ['modulo_id', 'rol', 'clave'],
                ['valor', 'actualizado_at']
            );

            $savedKeys = array_column($rows, 'clave');
            DB::table('redmine_tic_permisos_rol')
                ->where('modulo_id', $moduleId)
                ->where('rol', $role)
                ->whereNotIn('clave', $savedKeys)
                ->delete();

            $persisted = $this->rolesFromRelational()[$role] ?? null;
            if (! is_array($persisted)) {
                return false;
            }
            ksort($persisted);
            ksort($permissions);

            return $persisted === $permissions;
        } catch (\Throwable) {
            return false;
        }
    }

    public function encodeValue(string $clave, mixed $value): string
    {
        if (in_array($clave, self::SCOPE_KEYS, true)) {
            if (is_string($value)) {
                return $value;
            }

            return $value ? 'asignados' : '';
        }

        return $value ? 'si' : 'no';
    }

    public function decodeValue(string $clave, string $valor): mixed
    {
        if (in_array($clave, self::SCOPE_KEYS, true)) {
            return $valor;
        }

        return $valor === 'si';
    }

    /**
     * Batch-loads all user permissions in one query, keyed by perfil_id.
     * Returns null when the table does not exist or is empty (caller falls back to JSON).
     *
     * @return array<int,array<string,mixed>>|null
     */
    public function allPermissionsFromRelational(): ?array
    {
        if (! $this->userPermissionsTableAvailable()) {
            return null;
        }

        try {
            $rows = DB::table('redmine_tic_permisos_usuario')->get(['perfil_id', 'clave', 'valor']);
            if ($rows->isEmpty()) {
                return null;
            }

            $byPerfil = [];
            foreach ($rows as $row) {
                $perfilId = (int) $row->perfil_id;
                $clave = (string) $row->clave;
                $byPerfil[$perfilId][$clave] = $this->decodeValue($clave, (string) $row->valor);
            }

            return $byPerfil;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Writes one user's permissions to the relational table (upsert + prune stale keys).
     *
     * @param  array<string,mixed>  $permissions
     */
    public function savePermissionsToRelational(int $perfilId, array $permissions): void
    {
        if (! $this->userPermissionsTableAvailable() || $perfilId <= 0) {
            return;
        }

        $savedClaves = [];
        foreach ($permissions as $clave => $valor) {
            $clave = trim((string) $clave);
            if ($clave === '') {
                continue;
            }
            try {
                DB::table('redmine_tic_permisos_usuario')->updateOrInsert(
                    ['perfil_id' => $perfilId, 'clave' => $clave],
                    ['valor' => $this->encodeValue($clave, $valor), 'actualizado_at' => now()]
                );
                $savedClaves[] = $clave;
            } catch (\Throwable) {
                continue;
            }
        }

        if (! empty($savedClaves)) {
            try {
                DB::table('redmine_tic_permisos_usuario')
                    ->where('perfil_id', $perfilId)
                    ->whereNotIn('clave', $savedClaves)
                    ->delete();
            } catch (\Throwable) {
            }
        }
    }

    public function userPermissionsTableAvailable(): bool
    {
        if ($this->userPermissionsTableAvailableCache !== null) {
            return $this->userPermissionsTableAvailableCache;
        }
        try {
            return $this->userPermissionsTableAvailableCache = Schema::hasTable('redmine_tic_permisos_usuario');
        } catch (\Throwable) {
            return $this->userPermissionsTableAvailableCache = false;
        }
    }

    public function rolPermissionsTableAvailable(): bool
    {
        if ($this->rolePermissionsTableAvailableCache !== null) {
            return $this->rolePermissionsTableAvailableCache;
        }
        try {
            return $this->rolePermissionsTableAvailableCache = Schema::hasTable('redmine_tic_permisos_rol');
        } catch (\Throwable) {
            return $this->rolePermissionsTableAvailableCache = false;
        }
    }

    /** @return array<string,array<string,mixed>> */
    public function defaultRoles(): array
    {
        $all = [
            'mensajes' => 'todos',
            'mensajes_acceso' => true,
            'horas_extra' => 'todos',
            'historico' => true,
            'historico_acciones' => true,
            'historico_scope' => 'todos',
            'configuracion' => true,
            'estadisticas' => true,
            'usuarios' => true,
            'simulador' => true,
            'reporte_rapido' => true,
            'actividad' => true,
            'actividad_eliminar' => true,
            'actividad_todos' => true,
            'mis_integraciones' => true,
            'reportes_editar' => true,
            'reportes_eliminar' => true,
            'horas_extra_editar' => true,
            'usuarios_editar' => true,
            'usuarios_eliminar' => true,
            'cfg_resumen' => true,
            'cfg_conexion' => true,
            'cfg_proyecto' => true,
            'cfg_redmine' => true,
            'cfg_campos' => true,
            'cfg_retencion' => true,
            'cfg_informes' => true,
            'cfg_mantencion' => true,
            'cfg_roles' => true,
            'cfg_usuarios' => true,
            'cfg_categorias' => true,
            'cfg_unidades' => true,
        ];

        return [
            'root' => $all,
            'administrador' => $all,
            'gestor' => array_merge($all, [
                'usuarios' => false,
                'usuarios_editar' => false,
                'usuarios_eliminar' => false,
                'configuracion' => false,
                'cfg_resumen' => false,
                'cfg_conexion' => false,
                'cfg_proyecto' => false,
                'cfg_redmine' => false,
                'cfg_campos' => false,
                'cfg_retencion' => false,
                'cfg_informes' => false,
                'cfg_mantencion' => false,
                'cfg_roles' => false,
                'cfg_usuarios' => false,
                'cfg_categorias' => false,
                'cfg_unidades' => false,
            ]),
            'usuario' => [
                'mensajes' => 'asignados',
                'mensajes_acceso' => true,
                'horas_extra' => 'asignados',
                'historico' => true,
                'historico_acciones' => false,
                'historico_scope' => 'asignados',
                'configuracion' => false,
                'estadisticas' => true,
                'usuarios' => false,
                'simulador' => true,
                'reporte_rapido' => true,
                'actividad' => false,
                'actividad_eliminar' => false,
                'actividad_todos' => false,
                'mis_integraciones' => true,
                'reportes_editar' => false,
                'reportes_eliminar' => false,
                'horas_extra_editar' => false,
                'usuarios_editar' => false,
                'usuarios_eliminar' => false,
                'cfg_resumen' => false,
                'cfg_conexion' => false,
                'cfg_proyecto' => false,
                'cfg_redmine' => false,
                'cfg_campos' => false,
                'cfg_retencion' => false,
                'cfg_informes' => false,
                'cfg_mantencion' => false,
                'cfg_roles' => false,
                'cfg_usuarios' => false,
                'cfg_categorias' => false,
                'cfg_unidades' => false,
            ],
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
                'clave_modulo' => $this->projectKey,
                'nombre' => $this->projectName,
                'descripcion' => '',
                'icono' => '',
                'tipo' => 'native',
                'ruta' => $this->projectKey,
                'entrada' => 'laravel:redmine.native.dashboard',
                'habilitado' => 1,
                'orden' => 100,
                'creado_at' => now(),
                'actualizado_at' => now(),
            ]);

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }
}
