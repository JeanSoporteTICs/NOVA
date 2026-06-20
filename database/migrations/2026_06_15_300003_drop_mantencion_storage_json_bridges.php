<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S30 — Remove JSON bridge rows from redmine_mantencion_storage.
 *
 * After this migration:
 *   - configuracion.json row deleted (config now lives in configuraciones_modulo exclusively)
 *   - roles.json row deleted (roles now live in mantencion_permisos_rol, done in 300002)
 *   - redmine_mantencion_storage becomes empty; table kept for forward compatibility
 *
 * Prerequisite: all code that read configuracion.json via storage_read_json must have been
 * updated to use MantencionConfigRepository / configuraciones_modulo directly (done in S30).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('redmine_mantencion_storage')) {
            return;
        }
        try {
            DB::table('redmine_mantencion_storage')
                ->whereIn('path', ['configuracion.json', 'roles.json'])
                ->delete();
        } catch (\Throwable) {
        }
    }

    public function down(): void
    {
        // Cannot reliably restore config from here; the configuraciones_modulo source is authoritative.
        // Restore only the roles bridge (data in mantencion_permisos_rol is the source).
        if (! Schema::hasTable('redmine_mantencion_storage')
            || ! Schema::hasTable('mantencion_permisos_rol')) {
            return;
        }
        try {
            $rows   = DB::table('mantencion_permisos_rol')->get(['rol', 'permiso', 'valor']);
            $roles  = [];
            foreach ($rows as $row) {
                $roles[$row->rol][$row->permiso] = $this->decodeValue($row->valor);
            }
            if ($roles !== []) {
                DB::table('redmine_mantencion_storage')->updateOrInsert(
                    ['path' => 'roles.json'],
                    ['payload_json' => json_encode($roles, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)]
                );
            }
        } catch (\Throwable) {
        }
    }

    private function decodeValue(string $valor): mixed
    {
        if ($valor === '1') {
            return true;
        }
        if ($valor === '') {
            return false;
        }
        return $valor;
    }
};
