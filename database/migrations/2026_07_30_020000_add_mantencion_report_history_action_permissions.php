<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const REPORT_KEYS = [
        'reportes_editar',
        'reportes_eliminar',
        'reportes_importar_core',
    ];

    private const HISTORY_KEYS = [
        'historico_estado',
        'historico_eliminar',
    ];

    private const LEGACY_HISTORY_KEY = 'historico_acciones';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->migrateRolePermissions();
            $this->migrateUserPermissions();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->restoreLegacyRoleHistoryPermission();
            $this->restoreLegacyUserHistoryPermission();

            if (Schema::hasTable('mantencion_permisos_usuario')) {
                DB::table('mantencion_permisos_usuario')
                    ->whereIn('permiso', array_merge(self::REPORT_KEYS, self::HISTORY_KEYS))
                    ->delete();
            }
            if (Schema::hasTable('mantencion_permisos_rol')) {
                DB::table('mantencion_permisos_rol')
                    ->whereIn('permiso', array_merge(self::REPORT_KEYS, self::HISTORY_KEYS))
                    ->delete();
            }
        });
    }

    private function migrateRolePermissions(): void
    {
        if (!Schema::hasTable('mantencion_permisos_rol')) {
            return;
        }

        foreach (DB::table('mantencion_permisos_rol')->where('permiso', 'mensajes_acceso')->get(['rol', 'valor']) as $permission) {
            foreach (self::REPORT_KEYS as $key) {
                DB::table('mantencion_permisos_rol')->updateOrInsert(
                    ['rol' => $permission->rol, 'permiso' => $key],
                    ['valor' => $this->booleanValue($permission->valor)]
                );
            }
        }

        foreach (DB::table('mantencion_permisos_rol')->where('permiso', self::LEGACY_HISTORY_KEY)->get(['rol', 'valor']) as $permission) {
            foreach (self::HISTORY_KEYS as $key) {
                DB::table('mantencion_permisos_rol')->updateOrInsert(
                    ['rol' => $permission->rol, 'permiso' => $key],
                    ['valor' => $this->booleanValue($permission->valor)]
                );
            }
        }

        DB::table('mantencion_permisos_rol')->where('permiso', self::LEGACY_HISTORY_KEY)->delete();
    }

    private function migrateUserPermissions(): void
    {
        if (!Schema::hasTable('mantencion_permisos_usuario')) {
            return;
        }

        foreach (DB::table('mantencion_permisos_usuario')->where('permiso', 'mensajes_acceso')->get(['usuario_id', 'valor']) as $permission) {
            foreach (self::REPORT_KEYS as $key) {
                DB::table('mantencion_permisos_usuario')->updateOrInsert(
                    ['usuario_id' => $permission->usuario_id, 'permiso' => $key],
                    [
                        'valor' => $this->booleanValue($permission->valor),
                        'actualizado_at' => now(),
                    ]
                );
            }
        }

        foreach (DB::table('mantencion_permisos_usuario')->where('permiso', self::LEGACY_HISTORY_KEY)->get(['usuario_id', 'valor']) as $permission) {
            foreach (self::HISTORY_KEYS as $key) {
                DB::table('mantencion_permisos_usuario')->updateOrInsert(
                    ['usuario_id' => $permission->usuario_id, 'permiso' => $key],
                    [
                        'valor' => $this->booleanValue($permission->valor),
                        'actualizado_at' => now(),
                    ]
                );
            }
        }

        DB::table('mantencion_permisos_usuario')->where('permiso', self::LEGACY_HISTORY_KEY)->delete();
    }

    private function restoreLegacyRoleHistoryPermission(): void
    {
        if (!Schema::hasTable('mantencion_permisos_rol')) {
            return;
        }

        foreach (DB::table('mantencion_permisos_rol')->whereIn('permiso', self::HISTORY_KEYS)->distinct()->pluck('rol') as $role) {
            $enabled = DB::table('mantencion_permisos_rol')
                ->where('rol', $role)
                ->whereIn('permiso', self::HISTORY_KEYS)
                ->where('valor', '<>', '')
                ->exists();
            DB::table('mantencion_permisos_rol')->updateOrInsert(
                ['rol' => $role, 'permiso' => self::LEGACY_HISTORY_KEY],
                ['valor' => $enabled ? '1' : '']
            );
        }
    }

    private function restoreLegacyUserHistoryPermission(): void
    {
        if (!Schema::hasTable('mantencion_permisos_usuario')) {
            return;
        }

        foreach (DB::table('mantencion_permisos_usuario')->whereIn('permiso', self::HISTORY_KEYS)->distinct()->pluck('usuario_id') as $userId) {
            $enabled = DB::table('mantencion_permisos_usuario')
                ->where('usuario_id', $userId)
                ->whereIn('permiso', self::HISTORY_KEYS)
                ->where('valor', '<>', '')
                ->exists();
            DB::table('mantencion_permisos_usuario')->updateOrInsert(
                ['usuario_id' => $userId, 'permiso' => self::LEGACY_HISTORY_KEY],
                [
                    'valor' => $enabled ? '1' : '',
                    'actualizado_at' => now(),
                ]
            );
        }
    }

    private function booleanValue(mixed $value): string
    {
        return trim((string) $value) !== '' ? '1' : '';
    }
};
