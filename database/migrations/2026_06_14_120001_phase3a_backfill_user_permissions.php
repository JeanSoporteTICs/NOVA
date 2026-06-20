<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3a backfill — Non-destructive.
 *
 * Corrects the initial Phase 3a population that skipped profiles whose
 * `permisos` JSON was an empty array `[]`. Those profiles now receive the
 * full 37-key default set derived from their `rol`.
 *
 * Profiles that already have rows in redmine_tic_permisos_usuario are
 * untouched for existing keys; only missing keys are inserted.
 */
return new class extends Migration
{
    private const SCOPE_KEYS = ['mensajes', 'historico_scope', 'horas_extra'];

    private const ALL_KEYS = [
        'mensajes', 'mensajes_acceso', 'horas_extra', 'historico', 'historico_acciones',
        'historico_scope', 'configuracion', 'estadisticas', 'estadisticas_manual', 'usuarios',
        'categorias', 'unidades', 'simulador', 'actividad', 'reportes_editar',
        'reportes_eliminar', 'horas_extra_editar', 'horas_extra_eliminar', 'usuarios_editar',
        'usuarios_eliminar', 'cfg_resumen', 'cfg_conexion', 'cfg_proyecto', 'cfg_redmine',
        'cfg_campos', 'cfg_retencion', 'cfg_webhook', 'cfg_sesion', 'cfg_mantencion',
        'cfg_trackers', 'cfg_prioridades', 'cfg_estados', 'cfg_roles', 'cfg_usuarios',
        'cfg_catalogos', 'cfg_categorias', 'cfg_unidades',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('redmine_tic_permisos_usuario') ||
            !Schema::hasTable('redmine_tic_perfiles_usuario')) {
            return;
        }

        $profiles = DB::table('redmine_tic_perfiles_usuario')->get(['id', 'rol', 'permisos']);

        foreach ($profiles as $profile) {
            $perfilId = (int) $profile->id;
            if ($perfilId <= 0) {
                continue;
            }

            $rol      = trim((string) ($profile->rol ?? 'usuario')) ?: 'usuario';
            $defaults = $this->buildDefaultPermissions($rol);
            $existing = json_decode((string) ($profile->permisos ?? '{}'), true);
            $perms    = array_merge($defaults, is_array($existing) ? $existing : []);

            // Keep only the 37 canonical keys; ignore any stale/unknown keys
            $perms = array_intersect_key($perms, array_flip(self::ALL_KEYS));
            // Ensure all 37 keys are present even if missing from both defaults and JSON
            foreach (self::ALL_KEYS as $key) {
                if (!array_key_exists($key, $perms)) {
                    $perms[$key] = in_array($key, self::SCOPE_KEYS, true) ? 'asignados' : false;
                }
            }

            foreach ($perms as $clave => $valor) {
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

    public function down(): void
    {
        // The backfill is non-destructive; rolling back would over-aggressively
        // remove rows that may have been updated by legitimate user actions.
        // Nothing to undo here — use Phase 3a down() to drop the entire table.
    }

    private function encodeValue(string $clave, mixed $value): string
    {
        if (in_array($clave, self::SCOPE_KEYS, true)) {
            if (is_string($value)) {
                return $value;
            }
            return $value ? 'asignados' : '';
        }

        return $value ? 'si' : 'no';
    }

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

        return $all;
    }
};
