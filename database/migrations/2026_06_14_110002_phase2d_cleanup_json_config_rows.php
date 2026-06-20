<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2d — Destructive, irreversible.
 *
 * Removes the JSON rows for trackers, prioridades, and estados from
 * `configuraciones_modulo` now that those options live in `modulo_opciones`
 * (migrated in Phase 1c) and RedmineDataRepository reads/writes them there.
 *
 * The `roles` key is NOT touched here — it stays in configuraciones_modulo
 * and will be addressed in Phase 3 (permissions normalisation).
 *
 * Prerequisites:
 *   - Phase 1c migration must have run (modulo_opciones exists and is populated)
 *   - RedmineDataRepository::configuration() reads from modulo_opciones
 *   - RedmineDataRepository::saveConfiguration() writes to modulo_opciones
 */
return new class extends Migration
{
    private const CLAVES = ['trackers', 'prioridades', 'estados'];

    public function up(): void
    {
        if (!Schema::hasTable('configuraciones_modulo')) {
            return;
        }
        if (!Schema::hasTable('modulo_opciones')) {
            throw new \RuntimeException(
                'Phase 1c migration (create_modulo_opciones) must run before Phase 2d. ' .
                'Table modulo_opciones not found.'
            );
        }

        $this->backupRows();

        DB::table('configuraciones_modulo')
            ->where('tipo', 'json')
            ->whereIn('clave', self::CLAVES)
            ->delete();
    }

    public function down(): void
    {
        // Restore from modulo_opciones back into configuraciones_modulo
        if (!Schema::hasTable('configuraciones_modulo') || !Schema::hasTable('modulo_opciones')) {
            return;
        }

        $tipoMap = ['tracker' => 'trackers', 'prioridad' => 'prioridades', 'estado' => 'estados'];

        $groups = DB::table('modulo_opciones')
            ->whereIn('tipo', array_keys($tipoMap))
            ->orderBy('modulo_id')
            ->orderBy('tipo')
            ->orderBy('orden')
            ->get(['modulo_id', 'tipo', 'id_externo', 'nombre', 'predeterminado'])
            ->groupBy(fn ($row) => $row->modulo_id . '|' . $row->tipo);

        foreach ($groups as $key => $rows) {
            [$moduleId, $tipo] = explode('|', $key);
            $clave = $tipoMap[$tipo] ?? null;
            if ($clave === null) {
                continue;
            }
            $items = $rows->map(static fn ($row) => [
                'id'      => $row->id_externo,
                'nombre'  => $row->nombre,
                'default' => (bool) $row->predeterminado,
            ])->values()->all();

            try {
                DB::table('configuraciones_modulo')->updateOrInsert(
                    ['modulo_id' => (int) $moduleId, 'clave' => $clave],
                    [
                        'valor'          => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'tipo'           => 'json',
                        'actualizado_at' => now(),
                    ]
                );
            } catch (\Throwable) {
                continue;
            }
        }
    }

    private function backupRows(): void
    {
        try {
            if (!Schema::hasTable('_nova_column_backups')) {
                Schema::create('_nova_column_backups', static function (\Illuminate\Database\Schema\Blueprint $bp): void {
                    $bp->id();
                    $bp->string('source_table', 100);
                    $bp->string('source_column', 100);
                    $bp->unsignedBigInteger('source_row_id');
                    $bp->longText('valor')->nullable();
                    $bp->timestamp('backed_up_at')->useCurrent();
                });
            }

            $rows = DB::table('configuraciones_modulo')
                ->where('tipo', 'json')
                ->whereIn('clave', self::CLAVES)
                ->get(['id', 'valor']);

            foreach ($rows as $row) {
                DB::table('_nova_column_backups')->insert([
                    'source_table'  => 'configuraciones_modulo',
                    'source_column' => 'valor[' . ($row->clave ?? '') . ']',
                    'source_row_id' => (int) $row->id,
                    'valor'         => (string) ($row->valor ?? ''),
                    'backed_up_at'  => now(),
                ]);
            }
        } catch (\Throwable) {
        }
    }
};
