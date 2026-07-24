<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODULE_KEY = 'redmine_tic';
    private const OLD_NAME = 'Redmine TICS';
    private const NEW_NAME = 'Backlog Soporte TI';

    public function up(): void
    {
        if (!Schema::hasTable('modulos_nova')) {
            return;
        }

        DB::table('modulos_nova')
            ->where('clave_modulo', self::MODULE_KEY)
            ->update([
                'nombre' => self::NEW_NAME,
                'descripcion' => 'Captura, procesa y envia reportes del proyecto ' . self::NEW_NAME . '.',
                'actualizado_at' => now(),
            ]);

        $moduleId = DB::table('modulos_nova')
            ->where('clave_modulo', self::MODULE_KEY)
            ->value('id');

        if ($moduleId !== null && Schema::hasTable('configuraciones_modulo')) {
            $current = DB::table('configuraciones_modulo')
                ->where('modulo_id', $moduleId)
                ->where('clave', 'project_name')
                ->value('valor');

            if ($current === null || trim((string) $current) === '' || in_array(trim((string) $current), [self::OLD_NAME, 'Redmine TIC'], true)) {
                DB::table('configuraciones_modulo')->updateOrInsert(
                    ['modulo_id' => (int) $moduleId, 'clave' => 'project_name'],
                    [
                        'valor' => self::NEW_NAME,
                        'tipo' => 'string',
                        'actualizado_at' => now(),
                    ],
                );
            }
        }

        Cache::forget('nova.modules.state');
    }

    public function down(): void
    {
        if (!Schema::hasTable('modulos_nova')) {
            return;
        }

        DB::table('modulos_nova')
            ->where('clave_modulo', self::MODULE_KEY)
            ->where('nombre', self::NEW_NAME)
            ->update([
                'nombre' => self::OLD_NAME,
                'descripcion' => 'Captura, procesa y envia reportes del proyecto ' . self::OLD_NAME . '.',
                'actualizado_at' => now(),
            ]);

        $moduleId = DB::table('modulos_nova')
            ->where('clave_modulo', self::MODULE_KEY)
            ->value('id');

        if ($moduleId !== null && Schema::hasTable('configuraciones_modulo')) {
            DB::table('configuraciones_modulo')
                ->where('modulo_id', $moduleId)
                ->where('clave', 'project_name')
                ->where('valor', self::NEW_NAME)
                ->update([
                    'valor' => self::OLD_NAME,
                    'actualizado_at' => now(),
                ]);
        }

        Cache::forget('nova.modules.state');
    }
};
