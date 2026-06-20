<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes historical/operational data that is no longer needed.
 * All configuration data is preserved; only transient operational records
 * (reports, hours, logs, migrated storage blobs) are deleted.
 *
 * Tables emptied (structure kept):
 *   - redmine_tic_horas_extra_grupo_reportes (pivot — deleted first)
 *   - redmine_tic_horas_extra_grupos
 *   - horas_extras
 *   - redmine_tic_reportes
 *   - redmine_mantencion_reportes
 *   - redmine_tic_activity_logs
 *
 * redmine_mantencion_storage: only configuracion.json and roles.json are kept.
 * categorias.json, unidades.json, usuarios.json, horasExtras/*, reportes/*,
 * procedimientos/index.json, mensaje.json, nextcloud_created_history.json,
 * and security.log are deleted.
 */
return new class extends Migration
{
    private const STORAGE_KEEP = ['configuracion.json', 'roles.json'];

    private const TABLES_TO_EMPTY = [
        'redmine_tic_horas_extra_grupo_reportes',
        'horas_extras',
        'redmine_tic_horas_extra_grupos',
        'redmine_tic_reportes',
        'redmine_mantencion_reportes',
        'redmine_tic_activity_logs',
    ];

    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach (self::TABLES_TO_EMPTY as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            if (Schema::hasTable('redmine_mantencion_storage')) {
                DB::table('redmine_mantencion_storage')
                    ->whereNotIn('path', self::STORAGE_KEEP)
                    ->delete();
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    public function down(): void
    {
        // Operational data cannot be restored — this migration is irreversible.
    }
};
