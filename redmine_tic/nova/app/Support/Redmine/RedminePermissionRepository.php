<?php

namespace RedmineTic\Support\Redmine;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manages relational permission tables for roles and user permission keys.
 * Tables: redmine_tic_permisos_rol, redmine_tic_permisos_usuario
 *
 * Note: allPermissionsFromRelational() and savePermissionsToRelational() (user-level
 * permissions embedded in project-user management) intentionally remain in
 * RedmineDataRepository since they are called from user management code that
 * manages many concerns at once. Only role permissions are extracted here.
 */
class RedminePermissionRepository
{
    private const SCOPE_KEYS = ['mensajes', 'historico_scope', 'horas_extra'];

    public function __construct(
        private string $projectKey,
        private string $projectName,
    ) {}

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
        if (!$this->rolPermissionsTableAvailable()) {
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
                $rol   = (string) $row->rol;
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
     * @param array<string,array<string,mixed>> $roles
     */
    public function saveRolesToRelational(array $roles): void
    {
        if (!$this->rolPermissionsTableAvailable()) {
            return;
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return;
        }

        foreach ($roles as $rol => $permissions) {
            $rol = trim((string) $rol);
            if ($rol === '' || !is_array($permissions)) {
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

            if (!empty($savedClaves)) {
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
        if (!empty($roleNames)) {
            try {
                DB::table('redmine_tic_permisos_rol')
                    ->where('modulo_id', $moduleId)
                    ->whereNotIn('rol', $roleNames)
                    ->delete();
            } catch (\Throwable) {
            }
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
        if (!$this->userPermissionsTableAvailable()) {
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
                $clave    = (string) $row->clave;
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
     * @param array<string,mixed> $permissions
     */
    public function savePermissionsToRelational(int $perfilId, array $permissions): void
    {
        if (!$this->userPermissionsTableAvailable() || $perfilId <= 0) {
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

        if (!empty($savedClaves)) {
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
        try {
            return Schema::hasTable('redmine_tic_permisos_usuario');
        } catch (\Throwable) {
            return false;
        }
    }

    public function rolPermissionsTableAvailable(): bool
    {
        try {
            return Schema::hasTable('redmine_tic_permisos_rol');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,array<string,mixed>> */
    public function defaultRoles(): array
    {
        $all = [
            'mensajes'             => 'todos',
            'mensajes_acceso'      => true,
            'horas_extra'          => 'todos',
            'historico'            => true,
            'historico_acciones'   => true,
            'historico_scope'      => 'todos',
            'configuracion'        => true,
            'estadisticas'         => true,
            'estadisticas_manual'  => true,
            'usuarios'             => true,
            'categorias'           => true,
            'unidades'             => true,
            'simulador'            => true,
            'actividad'            => true,
            'reportes_editar'      => true,
            'reportes_eliminar'    => true,
            'horas_extra_editar'   => true,
            'horas_extra_eliminar' => true,
            'usuarios_editar'      => true,
            'usuarios_eliminar'    => true,
            'cfg_resumen'          => true,
            'cfg_conexion'         => true,
            'cfg_proyecto'         => true,
            'cfg_redmine'          => true,
            'cfg_campos'           => true,
            'cfg_retencion'        => true,
            'cfg_webhook'          => true,
            'cfg_sesion'           => true,
            'cfg_mantencion'       => true,
            'cfg_trackers'         => true,
            'cfg_prioridades'      => true,
            'cfg_estados'          => true,
            'cfg_roles'            => true,
            'cfg_usuarios'         => true,
            'cfg_catalogos'        => true,
            'cfg_categorias'       => true,
            'cfg_unidades'         => true,
        ];

        return [
            'root'          => $all,
            'administrador' => $all,
            'gestor'        => array_merge($all, [
                'usuarios'             => false,
                'usuarios_editar'      => false,
                'usuarios_eliminar'    => false,
                'configuracion'        => false,
                'cfg_resumen'          => false,
                'cfg_conexion'         => false,
                'cfg_proyecto'         => false,
                'cfg_redmine'          => false,
                'cfg_campos'           => false,
                'cfg_retencion'        => false,
                'cfg_webhook'          => false,
                'cfg_sesion'           => false,
                'cfg_mantencion'       => false,
                'cfg_trackers'         => false,
                'cfg_prioridades'      => false,
                'cfg_estados'          => false,
                'cfg_roles'            => false,
                'cfg_usuarios'         => false,
                'cfg_catalogos'        => false,
                'cfg_categorias'       => false,
                'cfg_unidades'         => false,
            ]),
            'usuario' => [
                'mensajes'             => 'asignados',
                'mensajes_acceso'      => true,
                'horas_extra'          => 'asignados',
                'historico'            => true,
                'historico_acciones'   => false,
                'historico_scope'      => 'asignados',
                'configuracion'        => false,
                'estadisticas'         => true,
                'estadisticas_manual'  => false,
                'usuarios'             => false,
                'categorias'           => false,
                'unidades'             => false,
                'simulador'            => true,
                'actividad'            => false,
                'reportes_editar'      => false,
                'reportes_eliminar'    => false,
                'horas_extra_editar'   => false,
                'horas_extra_eliminar' => false,
                'usuarios_editar'      => false,
                'usuarios_eliminar'    => false,
                'cfg_resumen'          => false,
                'cfg_conexion'         => false,
                'cfg_proyecto'         => false,
                'cfg_redmine'          => false,
                'cfg_campos'           => false,
                'cfg_retencion'        => false,
                'cfg_webhook'          => false,
                'cfg_sesion'           => false,
                'cfg_mantencion'       => false,
                'cfg_trackers'         => false,
                'cfg_prioridades'      => false,
                'cfg_estados'          => false,
                'cfg_roles'            => false,
                'cfg_usuarios'         => false,
                'cfg_catalogos'        => false,
                'cfg_categorias'       => false,
                'cfg_unidades'         => false,
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
