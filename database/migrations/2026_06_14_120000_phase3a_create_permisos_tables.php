<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3a — Non-destructive.
 *
 * Creates three relational tables to normalize the permissions system:
 *   - redmine_tic_permisos_catalogo  : catalog of 37 valid permission keys + types
 *   - redmine_tic_permisos_rol       : role→key→value triplets (replaces JSON in configuraciones_modulo)
 *   - redmine_tic_permisos_usuario   : profile→key→value triplets (replaces JSON in perfiles_usuario.permisos)
 *
 * The original JSON columns and configuraciones_modulo rows are NOT modified here.
 * RedmineDataRepository is updated to dual-write and read from these tables first,
 * with fallback to the original JSON if the tables are empty.
 */
return new class extends Migration
{
    /** The 3 keys that carry a string scope value instead of a boolean. */
    private const SCOPE_KEYS = ['mensajes', 'historico_scope', 'horas_extra'];

    /** Full 37-key catalog — single source of truth for key names and types. */
    private const CATALOG = [
        ['clave' => 'mensajes',             'tipo' => 'scope',          'descripcion' => 'Alcance de reportes: todos/asignados',      'orden' => 1],
        ['clave' => 'mensajes_acceso',      'tipo' => 'bool',           'descripcion' => 'Acceso a la seccion Reportes',               'orden' => 2],
        ['clave' => 'horas_extra',          'tipo' => 'scope_or_empty', 'descripcion' => 'Alcance horas extra: todos/asignados o vacio','orden' => 3],
        ['clave' => 'historico',            'tipo' => 'bool',           'descripcion' => 'Acceso a la seccion Historico',               'orden' => 4],
        ['clave' => 'historico_acciones',   'tipo' => 'bool',           'descripcion' => 'Puede ejecutar acciones en Historico',        'orden' => 5],
        ['clave' => 'historico_scope',      'tipo' => 'scope',          'descripcion' => 'Alcance historico: todos/asignados',          'orden' => 6],
        ['clave' => 'configuracion',        'tipo' => 'bool',           'descripcion' => 'Acceso a la seccion Configuracion',           'orden' => 7],
        ['clave' => 'estadisticas',         'tipo' => 'bool',           'descripcion' => 'Acceso a la seccion Estadisticas',            'orden' => 8],
        ['clave' => 'estadisticas_manual',  'tipo' => 'bool',           'descripcion' => 'Acceso a Redmine API',                        'orden' => 9],
        ['clave' => 'usuarios',             'tipo' => 'bool',           'descripcion' => 'Acceso a la seccion Usuarios',                'orden' => 10],
        ['clave' => 'categorias',           'tipo' => 'bool',           'descripcion' => 'Acceso a la seccion Categorias',              'orden' => 11],
        ['clave' => 'unidades',             'tipo' => 'bool',           'descripcion' => 'Acceso a la seccion Unidades',                'orden' => 12],
        ['clave' => 'simulador',            'tipo' => 'bool',           'descripcion' => 'Acceso a la seccion Webhook/Simulador',       'orden' => 13],
        ['clave' => 'actividad',            'tipo' => 'bool',           'descripcion' => 'Acceso a la seccion Actividad',               'orden' => 14],
        ['clave' => 'reportes_editar',      'tipo' => 'bool',           'descripcion' => 'Puede editar reportes',                       'orden' => 15],
        ['clave' => 'reportes_eliminar',    'tipo' => 'bool',           'descripcion' => 'Puede eliminar reportes',                     'orden' => 16],
        ['clave' => 'horas_extra_editar',   'tipo' => 'bool',           'descripcion' => 'Puede editar horas extra',                    'orden' => 17],
        ['clave' => 'horas_extra_eliminar', 'tipo' => 'bool',           'descripcion' => 'Puede eliminar horas extra',                  'orden' => 18],
        ['clave' => 'usuarios_editar',      'tipo' => 'bool',           'descripcion' => 'Puede editar usuarios',                       'orden' => 19],
        ['clave' => 'usuarios_eliminar',    'tipo' => 'bool',           'descripcion' => 'Puede eliminar usuarios',                     'orden' => 20],
        ['clave' => 'cfg_resumen',          'tipo' => 'bool',           'descripcion' => 'Panel Resumen en Configuracion',              'orden' => 21],
        ['clave' => 'cfg_conexion',         'tipo' => 'bool',           'descripcion' => 'Panel Conexion',                              'orden' => 22],
        ['clave' => 'cfg_proyecto',         'tipo' => 'bool',           'descripcion' => 'Panel Proyecto',                              'orden' => 23],
        ['clave' => 'cfg_redmine',          'tipo' => 'bool',           'descripcion' => 'Panel Redmine',                               'orden' => 24],
        ['clave' => 'cfg_campos',           'tipo' => 'bool',           'descripcion' => 'Panel Campos personalizados',                 'orden' => 25],
        ['clave' => 'cfg_retencion',        'tipo' => 'bool',           'descripcion' => 'Panel Retencion',                             'orden' => 26],
        ['clave' => 'cfg_webhook',          'tipo' => 'bool',           'descripcion' => 'Panel Webhook',                               'orden' => 27],
        ['clave' => 'cfg_sesion',           'tipo' => 'bool',           'descripcion' => 'Panel Sesion',                                'orden' => 28],
        ['clave' => 'cfg_mantencion',       'tipo' => 'bool',           'descripcion' => 'Panel Mantencion',                            'orden' => 29],
        ['clave' => 'cfg_trackers',         'tipo' => 'bool',           'descripcion' => 'Gestionar Trackers',                          'orden' => 30],
        ['clave' => 'cfg_prioridades',      'tipo' => 'bool',           'descripcion' => 'Gestionar Prioridades',                       'orden' => 31],
        ['clave' => 'cfg_estados',          'tipo' => 'bool',           'descripcion' => 'Gestionar Estados',                           'orden' => 32],
        ['clave' => 'cfg_roles',            'tipo' => 'bool',           'descripcion' => 'Gestionar Roles y Permisos',                  'orden' => 33],
        ['clave' => 'cfg_usuarios',         'tipo' => 'bool',           'descripcion' => 'Gestionar Usuarios y Permisos',               'orden' => 34],
        ['clave' => 'cfg_catalogos',        'tipo' => 'bool',           'descripcion' => 'Reservado (legacy)',                          'orden' => 35],
        ['clave' => 'cfg_categorias',       'tipo' => 'bool',           'descripcion' => 'Gestionar Categorias',                        'orden' => 36],
        ['clave' => 'cfg_unidades',         'tipo' => 'bool',           'descripcion' => 'Gestionar Unidades',                          'orden' => 37],
    ];

    // -------------------------------------------------------------------------
    // Table creation
    // -------------------------------------------------------------------------

    public function up(): void
    {
        $this->createCatalogTable();
        $this->createRolTable();
        $this->createUsuarioTable();

        $this->populateCatalog();
        $this->populateRoles();
        $this->populateUserPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('redmine_tic_permisos_usuario');
        Schema::dropIfExists('redmine_tic_permisos_rol');
        Schema::dropIfExists('redmine_tic_permisos_catalogo');
    }

    private function createCatalogTable(): void
    {
        if (Schema::hasTable('redmine_tic_permisos_catalogo')) {
            return;
        }
        Schema::create('redmine_tic_permisos_catalogo', function (Blueprint $table): void {
            $table->id();
            $table->string('clave', 60)->unique();
            $table->enum('tipo', ['bool', 'scope', 'scope_or_empty'])->default('bool');
            $table->string('descripcion', 200)->default('');
            $table->unsignedTinyInteger('orden')->default(100);
        });
    }

    private function createRolTable(): void
    {
        if (Schema::hasTable('redmine_tic_permisos_rol')) {
            return;
        }
        Schema::create('redmine_tic_permisos_rol', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('modulo_id')->constrained('modulos_nova')->cascadeOnDelete();
            $table->string('rol', 40);
            $table->string('clave', 60);
            $table->string('valor', 20)->default('no');
            $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['modulo_id', 'rol', 'clave'], 'uq_permiso_rol');
            $table->index(['modulo_id', 'rol'], 'idx_pr_rol');
        });
    }

    private function createUsuarioTable(): void
    {
        if (Schema::hasTable('redmine_tic_permisos_usuario')) {
            return;
        }
        Schema::create('redmine_tic_permisos_usuario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('perfil_id')
                ->constrained('redmine_tic_perfiles_usuario')
                ->cascadeOnDelete();
            $table->string('clave', 60);
            $table->string('valor', 20)->default('no');
            $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['perfil_id', 'clave'], 'uq_permiso_usuario');
            $table->index('clave', 'idx_pu_clave');
        });
    }

    // -------------------------------------------------------------------------
    // Data population
    // -------------------------------------------------------------------------

    private function populateCatalog(): void
    {
        foreach (self::CATALOG as $entry) {
            try {
                DB::table('redmine_tic_permisos_catalogo')->updateOrInsert(
                    ['clave' => $entry['clave']],
                    ['tipo' => $entry['tipo'], 'descripcion' => $entry['descripcion'], 'orden' => $entry['orden']]
                );
            } catch (\Throwable) {
                continue;
            }
        }
    }

    private function populateRoles(): void
    {
        if (!Schema::hasTable('configuraciones_modulo') || !Schema::hasTable('modulos_nova')) {
            return;
        }

        $moduleId = (int) DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');
        if ($moduleId <= 0) {
            return;
        }

        // Read existing roles from configuraciones_modulo
        $existingRolesJson = DB::table('configuraciones_modulo')
            ->where('modulo_id', $moduleId)
            ->where('clave', 'roles')
            ->value('valor');

        $existingRoles = [];
        if ($existingRolesJson !== null) {
            $decoded = json_decode((string) $existingRolesJson, true);
            if (is_array($decoded)) {
                $existingRoles = $decoded;
            }
        }

        // Merge known roles: defaults + existing values on top
        $baseRoles = ['root', 'administrador', 'gestor', 'usuario'];
        $allRoleNames = array_unique(array_merge($baseRoles, array_keys($existingRoles)));

        foreach ($allRoleNames as $roleName) {
            $defaults = $this->buildDefaultPermissions($roleName);
            $existing = is_array($existingRoles[$roleName] ?? null) ? $existingRoles[$roleName] : [];
            // Existing values override defaults (DB values are authoritative)
            $merged = array_merge($defaults, $existing);

            foreach ($merged as $clave => $valor) {
                $clave = trim((string) $clave);
                if ($clave === '') {
                    continue;
                }
                try {
                    DB::table('redmine_tic_permisos_rol')->updateOrInsert(
                        ['modulo_id' => $moduleId, 'rol' => $roleName, 'clave' => $clave],
                        ['valor' => $this->encodeValue($clave, $valor), 'actualizado_at' => now()]
                    );
                } catch (\Throwable) {
                    continue;
                }
            }
        }
    }

    private function populateUserPermissions(): void
    {
        if (!Schema::hasTable('redmine_tic_perfiles_usuario')) {
            return;
        }

        $profiles = DB::table('redmine_tic_perfiles_usuario')->get(['id', 'rol', 'permisos']);

        foreach ($profiles as $profile) {
            $perfilId = (int) $profile->id;
            if ($perfilId <= 0) {
                continue;
            }

            // Build full 37-key set: defaults for the user's role + any existing JSON values on top
            $rol      = trim((string) ($profile->rol ?? 'usuario')) ?: 'usuario';
            $defaults = $this->buildDefaultPermissions($rol);
            $existing = json_decode((string) ($profile->permisos ?? '{}'), true);
            $perms    = array_merge($defaults, is_array($existing) ? $existing : []);

            foreach ($perms as $clave => $valor) {
                $clave = trim((string) $clave);
                if ($clave === '') {
                    continue;
                }
                try {
                    DB::table('redmine_tic_permisos_usuario')->updateOrInsert(
                        ['perfil_id' => $perfilId, 'clave' => $clave],
                        ['valor' => $this->encodeValue($clave, $valor), 'actualizado_at' => now()]
                    );
                } catch (\Throwable) {
                    continue;
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function encodeValue(string $clave, mixed $value): string
    {
        if (in_array($clave, self::SCOPE_KEYS, true)) {
            if (is_string($value)) {
                return $value; // 'todos', 'asignados', ''
            }
            // Legacy bool value for a scope key: convert to scope string
            return $value ? 'asignados' : '';
        }

        return $value ? 'si' : 'no';
    }

    /**
     * Returns the full 37-key default permission set for a given role.
     * Custom roles not in the known list receive root-level permissions.
     */
    private function buildDefaultPermissions(string $role): array
    {
        $all = [
            'mensajes'            => 'todos',
            'mensajes_acceso'     => true,
            'horas_extra'         => 'todos',
            'historico'           => true,
            'historico_acciones'  => true,
            'historico_scope'     => 'todos',
            'configuracion'       => true,
            'estadisticas'        => true,
            'estadisticas_manual' => true,
            'usuarios'            => true,
            'categorias'          => true,
            'unidades'            => true,
            'simulador'           => true,
            'actividad'           => true,
            'reportes_editar'     => true,
            'reportes_eliminar'   => true,
            'horas_extra_editar'  => true,
            'horas_extra_eliminar'=> true,
            'usuarios_editar'     => true,
            'usuarios_eliminar'   => true,
            'cfg_resumen'         => true,
            'cfg_conexion'        => true,
            'cfg_proyecto'        => true,
            'cfg_redmine'         => true,
            'cfg_campos'          => true,
            'cfg_retencion'       => true,
            'cfg_webhook'         => true,
            'cfg_sesion'          => true,
            'cfg_mantencion'      => true,
            'cfg_trackers'        => true,
            'cfg_prioridades'     => true,
            'cfg_estados'         => true,
            'cfg_roles'           => true,
            'cfg_usuarios'        => true,
            'cfg_catalogos'       => true,
            'cfg_categorias'      => true,
            'cfg_unidades'        => true,
        ];

        if ($role === 'gestor') {
            return array_merge($all, [
                'usuarios'          => false,
                'usuarios_editar'   => false,
                'usuarios_eliminar' => false,
                'configuracion'     => false,
                'cfg_resumen'       => false,
                'cfg_conexion'      => false,
                'cfg_proyecto'      => false,
                'cfg_redmine'       => false,
                'cfg_campos'        => false,
                'cfg_retencion'     => false,
                'cfg_webhook'       => false,
                'cfg_sesion'        => false,
                'cfg_mantencion'    => false,
                'cfg_trackers'      => false,
                'cfg_prioridades'   => false,
                'cfg_estados'       => false,
                'cfg_roles'         => false,
                'cfg_usuarios'      => false,
                'cfg_catalogos'     => false,
                'cfg_categorias'    => false,
                'cfg_unidades'      => false,
            ]);
        }

        if ($role === 'usuario') {
            return [
                'mensajes'            => 'asignados',
                'mensajes_acceso'     => true,
                'horas_extra'         => 'asignados',
                'historico'           => true,
                'historico_acciones'  => false,
                'historico_scope'     => 'asignados',
                'configuracion'       => false,
                'estadisticas'        => true,
                'estadisticas_manual' => false,
                'usuarios'            => false,
                'categorias'          => false,
                'unidades'            => false,
                'simulador'           => true,
                'actividad'           => false,
                'reportes_editar'     => false,
                'reportes_eliminar'   => false,
                'horas_extra_editar'  => false,
                'horas_extra_eliminar'=> false,
                'usuarios_editar'     => false,
                'usuarios_eliminar'   => false,
                'cfg_resumen'         => false,
                'cfg_conexion'        => false,
                'cfg_proyecto'        => false,
                'cfg_redmine'         => false,
                'cfg_campos'          => false,
                'cfg_retencion'       => false,
                'cfg_webhook'         => false,
                'cfg_sesion'          => false,
                'cfg_mantencion'      => false,
                'cfg_trackers'        => false,
                'cfg_prioridades'     => false,
                'cfg_estados'         => false,
                'cfg_roles'           => false,
                'cfg_usuarios'        => false,
                'cfg_catalogos'       => false,
                'cfg_categorias'      => false,
                'cfg_unidades'        => false,
            ];
        }

        // root, administrador, and any unknown custom roles → full access
        return $all;
    }
};
