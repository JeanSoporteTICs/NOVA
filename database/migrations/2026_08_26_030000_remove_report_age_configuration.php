<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODULE_KEYS = ['redmine_tic', 'redmine-mantencion'];

    public function up(): void
    {
        if (! Schema::hasTable('modulos_nova') || ! Schema::hasTable('configuraciones_modulo')) {
            return;
        }

        $moduleIds = DB::table('modulos_nova')
            ->whereIn('clave_modulo', self::MODULE_KEYS)
            ->pluck('id');

        DB::table('configuraciones_modulo')
            ->whereIn('modulo_id', $moduleIds)
            ->where('clave', 'informes_nuevos_dias')
            ->delete();
    }

    public function down(): void
    {
        if (! Schema::hasTable('modulos_nova') || ! Schema::hasTable('configuraciones_modulo')) {
            return;
        }

        $moduleIds = DB::table('modulos_nova')
            ->whereIn('clave_modulo', self::MODULE_KEYS)
            ->pluck('id');

        foreach ($moduleIds as $moduleId) {
            DB::table('configuraciones_modulo')->updateOrInsert(
                ['modulo_id' => (int) $moduleId, 'clave' => 'informes_nuevos_dias'],
                ['valor' => '2', 'tipo' => 'int', 'actualizado_at' => now()]
            );
        }
    }
};
