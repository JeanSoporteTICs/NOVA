<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S30 — Replace roles.json in redmine_mantencion_storage with a relational table.
 *
 * Creates:
 *   mantencion_permisos_rol (rol, permiso, valor)
 *
 * Seeds data from the current roles.json (from redmine_mantencion_storage payload_json
 * if present, otherwise from the filesystem file as fallback).
 * After seeding, removes the roles.json entry from redmine_mantencion_storage.
 *
 * valor encoding:
 *   true  → '1'
 *   false → ''   (empty string)
 *   'todos'/'asignados' → the string as-is
 */
return new class extends Migration
{
    /** @var array<string,array<string,mixed>> Default role permissions (mirrors roles.json canonical state) */
    private array $defaultRoles = [
        'root' => [
            'all'                  => true,
            'mensajes'             => 'todos',
            'mensajes_acceso'      => true,
            'horas_extra'          => 'todos',
            'historico'            => true,
            'historico_scope'      => 'todos',
            'historico_acciones'   => true,
            'configuracion'        => true,
            'estadisticas'         => true,
            'usuarios'             => true,
            'categorias'           => true,
            'unidades'             => true,
            'simulador'            => true,
            'cfg_conexion'         => true,
            'cfg_proyecto'         => true,
            'cfg_retencion'        => true,
            'cfg_sesion'           => true,
            'cfg_trackers'         => true,
            'cfg_prioridades'      => true,
            'cfg_estados'          => true,
            'cfg_roles'            => true,
            'cfg_usuarios'         => true,
            'actividad'            => true,
            'procedimientos'       => true,
            'procedimientos_editar'=> true,
        ],
        'gestor' => [
            'mensajes'             => 'asignados',
            'mensajes_acceso'      => true,
            'horas_extra'          => 'asignados',
            'historico'            => true,
            'historico_scope'      => 'asignados',
            'historico_acciones'   => true,
            'configuracion'        => true,
            'estadisticas'         => true,
            'usuarios'             => true,
            'categorias'           => true,
            'unidades'             => true,
            'simulador'            => true,
            'cfg_conexion'         => true,
            'cfg_proyecto'         => true,
            'cfg_retencion'        => true,
            'cfg_sesion'           => true,
            'cfg_trackers'         => true,
            'cfg_prioridades'      => true,
            'cfg_estados'          => true,
            'cfg_roles'            => true,
            'cfg_usuarios'         => true,
            'actividad'            => true,
            'procedimientos'       => true,
            'procedimientos_editar'=> true,
        ],
        'administrador' => [
            'mensajes'             => 'asignados',
            'mensajes_acceso'      => true,
            'horas_extra'          => 'asignados',
            'historico'            => true,
            'historico_scope'      => 'asignados',
            'historico_acciones'   => false,
            'configuracion'        => true,
            'estadisticas'         => true,
            'usuarios'             => false,
            'categorias'           => true,
            'unidades'             => true,
            'simulador'            => true,
            'cfg_conexion'         => false,
            'cfg_proyecto'         => false,
            'cfg_retencion'        => false,
            'cfg_sesion'           => false,
            'cfg_trackers'         => false,
            'cfg_prioridades'      => false,
            'cfg_estados'          => false,
            'cfg_roles'            => false,
            'procedimientos'       => true,
            'procedimientos_editar'=> true,
        ],
        'usuario' => [
            'mensajes'             => 'asignados',
            'mensajes_acceso'      => true,
            'horas_extra'          => 'asignados',
            'historico'            => true,
            'historico_scope'      => 'asignados',
            'historico_acciones'   => false,
            'configuracion'        => false,
            'estadisticas'         => false,
            'usuarios'             => false,
            'categorias'           => false,
            'unidades'             => false,
            'simulador'            => true,
            'cfg_conexion'         => false,
            'cfg_proyecto'         => false,
            'cfg_retencion'        => false,
            'cfg_sesion'           => false,
            'cfg_trackers'         => false,
            'cfg_prioridades'      => false,
            'cfg_estados'          => false,
            'cfg_roles'            => false,
            'cfg_usuarios'         => false,
            'actividad'            => false,
            'procedimientos'       => true,
            'procedimientos_editar'=> false,
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('mantencion_permisos_rol')) {
            Schema::create('mantencion_permisos_rol', function (Blueprint $table): void {
                $table->id();
                $table->string('rol', 40)->index();
                $table->string('permiso', 80);
                $table->string('valor', 255)->default('');
                $table->unique(['rol', 'permiso'], 'uq_mpr_rol_permiso');
            });
        }

        // Load roles data: prefer live payload from redmine_mantencion_storage
        $roles = $this->loadFromStorageDb() ?? $this->loadFromFile() ?? $this->defaultRoles;

        foreach ($roles as $rol => $permissions) {
            if (! is_array($permissions)) {
                continue;
            }
            foreach ($permissions as $permiso => $value) {
                $encoded = $this->encodeValue($value);
                try {
                    DB::table('mantencion_permisos_rol')->updateOrInsert(
                        ['rol' => (string) $rol, 'permiso' => (string) $permiso],
                        ['valor' => $encoded]
                    );
                } catch (\Throwable) {
                }
            }
        }

        // Remove roles.json from redmine_mantencion_storage (blob JSON eliminated)
        if (Schema::hasTable('redmine_mantencion_storage')) {
            try {
                DB::table('redmine_mantencion_storage')->where('path', 'roles.json')->delete();
            } catch (\Throwable) {
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mantencion_permisos_rol');
        // Restore roles.json to redmine_mantencion_storage from defaultRoles
        if (Schema::hasTable('redmine_mantencion_storage')) {
            try {
                DB::table('redmine_mantencion_storage')->updateOrInsert(
                    ['path' => 'roles.json'],
                    ['payload_json' => json_encode($this->defaultRoles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)]
                );
            } catch (\Throwable) {
            }
        }
    }

    /** @return array<string,array<string,mixed>>|null */
    private function loadFromStorageDb(): ?array
    {
        if (! Schema::hasTable('redmine_mantencion_storage')) {
            return null;
        }
        try {
            $row = DB::table('redmine_mantencion_storage')->where('path', 'roles.json')->first();
            if ($row === null) {
                return null;
            }
            $decoded = json_decode((string) ($row->payload_json ?? ''), true);
            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,array<string,mixed>>|null */
    private function loadFromFile(): ?array
    {
        $paths = [
            base_path('redmine-mantencion/data/roles.json'),
        ];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $raw     = (string) @file_get_contents($path);
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return null;
    }

    private function encodeValue(mixed $value): string
    {
        if ($value === true || $value === 1) {
            return '1';
        }
        if ($value === false || $value === null || $value === 0 || $value === '') {
            return '';
        }
        return (string) $value; // 'todos', 'asignados', etc.
    }
};
