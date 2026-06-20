<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3c — DROP the permisos JSON column now that Phase 3a relational
 * tables are validated and dual-write is confirmed working.
 *
 * Also removes the roles JSON row from configuraciones_modulo since
 * redmine_tic_permisos_rol is the authoritative source for role permissions.
 *
 * Prerequisites:
 *   - Phase 3a migration must have run (permisos_usuario + permisos_rol populated)
 *   - nova:validate-phase3a command passed 17/17 checks
 *   - 48/48 PHPUnit tests passed
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('redmine_tic_perfiles_usuario') &&
            Schema::hasColumn('redmine_tic_perfiles_usuario', 'permisos')) {
            Schema::table('redmine_tic_perfiles_usuario', function (Blueprint $table): void {
                $table->dropColumn('permisos');
            });
        }

        if (Schema::hasTable('configuraciones_modulo')) {
            DB::table('configuraciones_modulo')
                ->where('clave', 'roles')
                ->where('modulo_id', 1)
                ->delete();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('redmine_tic_perfiles_usuario') &&
            !Schema::hasColumn('redmine_tic_perfiles_usuario', 'permisos')) {
            Schema::table('redmine_tic_perfiles_usuario', function (Blueprint $table): void {
                $table->longText('permisos')->nullable()->after('rol');
            });
        }
    }
};
