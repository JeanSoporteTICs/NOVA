<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S30 — Replaces storage/app/modules/state.json with columns on modulos_nova.
 *
 * Adds:
 *   modulos_nova.habilitado        tinyint(1) default 1
 *   modulos_nova.en_mantencion     tinyint(1) default 0
 *
 * Imports existing state.json data if present, then removes the file.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modulos_nova')) {
            return;
        }

        Schema::table('modulos_nova', function (Blueprint $table): void {
            if (! Schema::hasColumn('modulos_nova', 'habilitado')) {
                $table->boolean('habilitado')->default(true)->after('activo');
            }
            if (! Schema::hasColumn('modulos_nova', 'en_mantencion')) {
                $table->boolean('en_mantencion')->default(false)->after('habilitado');
            }
        });

        // Import existing state.json
        $statePath = storage_path('app/modules/state.json');
        if (is_file($statePath)) {
            $raw   = (string) @file_get_contents($statePath);
            $state = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($state)) {
                foreach ($state as $key => $moduleState) {
                    if (! is_array($moduleState)) {
                        continue;
                    }
                    $updates = [];
                    if (array_key_exists('enabled', $moduleState)) {
                        $updates['habilitado'] = (bool) $moduleState['enabled'] ? 1 : 0;
                    }
                    if (array_key_exists('maintenance', $moduleState)) {
                        $updates['en_mantencion'] = (bool) $moduleState['maintenance'] ? 1 : 0;
                    }
                    if ($updates !== []) {
                        try {
                            DB::table('modulos_nova')
                                ->where('clave_modulo', $key)
                                ->update($updates);
                        } catch (\Throwable) {
                        }
                    }
                }
            }
            @unlink($statePath);

            // Remove empty directory if applicable
            $dir = dirname($statePath);
            if (is_dir($dir) && count(scandir($dir)) === 2) {
                @rmdir($dir);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('modulos_nova')) {
            return;
        }
        Schema::table('modulos_nova', function (Blueprint $table): void {
            if (Schema::hasColumn('modulos_nova', 'en_mantencion')) {
                $table->dropColumn('en_mantencion');
            }
            if (Schema::hasColumn('modulos_nova', 'habilitado')) {
                $table->dropColumn('habilitado');
            }
        });
    }
};
