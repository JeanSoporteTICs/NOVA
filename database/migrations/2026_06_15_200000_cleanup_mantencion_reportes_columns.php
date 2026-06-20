<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes 7 columns from redmine_mantencion_reportes that are either migration
 * artifacts or derivable from other sources.
 *
 * WHY:
 *   Table was created in S13 as a normalization bridge for horasExtras/*.json and
 *   reportes/*.json files stored in redmine_mantencion_storage. No runtime code in
 *   redmine-mantencion/ reads from this table — historico.php uses storage_json_by_prefix()
 *   through RedmineMantencionStorageRepository, and dashboard.php uses load_messages()
 *   through mensaje.json. The table is empty since S27 cleanup.
 *
 * DROPPED:
 *   local_id     — migration dedup key (analogous to reporte_local_id in horas_extras, S28)
 *   source_path  — path to JSON origin file; pure migration artifact
 *   proyecto     — project name; derivable from configuraciones_modulo (modulo_id=2, clave='project_name')
 *   project_id   — project ID;   derivable from configuraciones_modulo (modulo_id=2, clave='project_id')
 *   tipo_id      — tracker ID;   derivable from configuraciones_modulo (modulo_id=2, clave='tracker_id')
 *   priority_id  — priority ID;  derivable from configuraciones_modulo (modulo_id=2, clave='priority_id')
 *   unidad_nombre — denormalized unit name; derivable via unidad_id FK → unidades.nombre (3NF violation)
 *
 * SAFE:
 *   - Table is empty (0 rows since S27).
 *   - No runtime code reads any of these columns.
 *   - All project/tracker/priority values live in configuraciones_modulo for modulo_id=2.
 *   - unidad_nombre is redundant with the unidad_id FK already present on the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('redmine_mantencion_reportes')) {
            return;
        }

        Schema::table('redmine_mantencion_reportes', function (Blueprint $table): void {
            // Migration dedup key
            if (Schema::hasColumn('redmine_mantencion_reportes', 'local_id')) {
                $table->dropUnique(['local_id']);
                $table->dropColumn('local_id');
            }

            // JSON file origin path
            if (Schema::hasColumn('redmine_mantencion_reportes', 'source_path')) {
                $table->dropIndex('redmine_mantencion_reportes_source_path_index');
                $table->dropColumn('source_path');
            }

            // Project info — same for all reports, lives in configuraciones_modulo
            if (Schema::hasColumn('redmine_mantencion_reportes', 'proyecto')) {
                $table->dropColumn('proyecto');
            }

            if (Schema::hasColumn('redmine_mantencion_reportes', 'project_id')) {
                $table->dropIndex('redmine_mantencion_reportes_project_id_index');
                $table->dropColumn('project_id');
            }

            // Tracker/priority IDs — module-level config, not per-report
            if (Schema::hasColumn('redmine_mantencion_reportes', 'tipo_id')) {
                $table->dropColumn('tipo_id');
            }

            if (Schema::hasColumn('redmine_mantencion_reportes', 'priority_id')) {
                $table->dropColumn('priority_id');
            }

            // Denormalized unit name — derivable via unidad_id FK → unidades.nombre
            if (Schema::hasColumn('redmine_mantencion_reportes', 'unidad_nombre')) {
                $table->dropColumn('unidad_nombre');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('redmine_mantencion_reportes')) {
            return;
        }

        Schema::table('redmine_mantencion_reportes', function (Blueprint $table): void {
            if (! Schema::hasColumn('redmine_mantencion_reportes', 'local_id')) {
                $table->string('local_id', 120)->nullable()->unique()->after('modulo_id');
            }

            if (! Schema::hasColumn('redmine_mantencion_reportes', 'id_core')) {
                // id_core is not dropped, but keep after() chain safe
            }

            if (! Schema::hasColumn('redmine_mantencion_reportes', 'proyecto')) {
                $table->string('proyecto', 180)->nullable()->after('id_core');
            }

            if (! Schema::hasColumn('redmine_mantencion_reportes', 'project_id')) {
                $table->string('project_id', 80)->nullable()->index()->after('proyecto');
            }

            if (! Schema::hasColumn('redmine_mantencion_reportes', 'tipo_id')) {
                $table->string('tipo_id', 80)->nullable()->after('tipo');
            }

            if (! Schema::hasColumn('redmine_mantencion_reportes', 'priority_id')) {
                $table->string('priority_id', 80)->nullable()->after('prioridad');
            }

            if (! Schema::hasColumn('redmine_mantencion_reportes', 'unidad_nombre')) {
                $table->string('unidad_nombre', 255)->nullable()->after('unidad_id');
            }

            if (! Schema::hasColumn('redmine_mantencion_reportes', 'source_path')) {
                $table->string('source_path', 255)->nullable()->index();
            }
        });
    }
};
