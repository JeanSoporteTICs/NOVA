<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies Mantencion configuration from the bridge storage table to
 * configuraciones_modulo (modulo_id = 2). The storage entry is kept intact
 * as the active source until Mantencion controllers are updated to read
 * from configuraciones_modulo.
 *
 * Cache and transient keys are excluded from the migration.
 */
return new class extends Migration
{
    private const MODULO_ID = 2;

    private const SKIP_KEYS = [
        'nextcloud_cached_groups',
        'nextcloud_cached_groups_at',
        'core_last_sync',
        'core_last_error',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('configuraciones_modulo') || !Schema::hasTable('redmine_mantencion_storage')) {
            return;
        }

        $row = DB::table('redmine_mantencion_storage')
            ->where('path', 'configuracion.json')
            ->first(['payload_json']);

        if (!$row || !$row->payload_json) {
            return;
        }

        $config = json_decode($row->payload_json, true);
        if (!is_array($config)) {
            return;
        }

        foreach ($config as $clave => $valor) {
            if (in_array($clave, self::SKIP_KEYS, true)) {
                continue;
            }

            $tipo     = $this->inferTipo($valor);
            $valorStr = is_array($valor) || is_object($valor)
                ? json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) ($valor ?? '');

            DB::table('configuraciones_modulo')->updateOrInsert(
                ['modulo_id' => self::MODULO_ID, 'clave' => $clave],
                ['valor' => $valorStr, 'tipo' => $tipo, 'actualizado_at' => now()]
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('configuraciones_modulo')) {
            return;
        }
        DB::table('configuraciones_modulo')->where('modulo_id', self::MODULO_ID)->delete();
    }

    private function inferTipo(mixed $valor): string
    {
        if (is_array($valor) || is_object($valor)) {
            return 'json';
        }
        if (is_bool($valor)) {
            return 'boolean';
        }
        if (is_int($valor)) {
            return 'integer';
        }
        return 'string';
    }
};
