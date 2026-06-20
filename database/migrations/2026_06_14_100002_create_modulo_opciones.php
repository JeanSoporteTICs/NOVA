<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1c — Non-destructive.
 *
 * Creates `modulo_opciones` to hold structured list items (trackers, prioridades,
 * estados) that are currently stored as JSON arrays in `configuraciones_modulo`.
 *
 * Populates from existing configuraciones_modulo rows where tipo = 'json'
 * and clave IN ('trackers', 'prioridades', 'estados').
 *
 * The `roles` key is intentionally skipped — it has a different structure
 * (dict of role → permission object) and will be handled in Phase 3.
 *
 * The original configuraciones_modulo rows are NOT modified here.
 */
return new class extends Migration
{
    private const TIPO_MAP = [
        'trackers'   => 'tracker',
        'prioridades' => 'prioridad',
        'estados'    => 'estado',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('modulo_opciones')) {
            Schema::create('modulo_opciones', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')
                    ->constrained('modulos_nova')
                    ->cascadeOnDelete();
                $table->string('tipo', 40);
                $table->string('id_externo', 100)->nullable();
                $table->string('nombre', 255);
                $table->boolean('predeterminado')->default(false);
                $table->boolean('activo')->default(true);
                $table->unsignedInteger('orden')->default(100);
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();

                $table->unique(['modulo_id', 'tipo', 'id_externo'], 'uq_modulo_opcion_tipo_ext');
                $table->index('tipo', 'idx_modulo_opciones_tipo');
            });
        }

        $this->populateFromConfiguraciones();
    }

    public function down(): void
    {
        Schema::dropIfExists('modulo_opciones');
    }

    private function populateFromConfiguraciones(): void
    {
        if (!Schema::hasTable('configuraciones_modulo')) {
            return;
        }

        $rows = DB::table('configuraciones_modulo')
            ->where('tipo', 'json')
            ->whereIn('clave', array_keys(self::TIPO_MAP))
            ->get(['modulo_id', 'clave', 'valor']);

        foreach ($rows as $row) {
            $tipo = self::TIPO_MAP[$row->clave] ?? null;
            if ($tipo === null) {
                continue;
            }

            $items = json_decode((string) ($row->valor ?? '[]'), true);
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $orden => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $idExterno = isset($item['id']) ? (string) $item['id'] : null;
                $nombre = trim((string) ($item['nombre'] ?? $item['name'] ?? ''));
                if ($nombre === '') {
                    continue;
                }
                $predeterminado = !empty($item['default']) ? 1 : 0;

                try {
                    DB::table('modulo_opciones')->updateOrInsert(
                        [
                            'modulo_id'  => (int) $row->modulo_id,
                            'tipo'       => $tipo,
                            'id_externo' => $idExterno,
                        ],
                        [
                            'nombre'        => $nombre,
                            'predeterminado' => $predeterminado,
                            'activo'        => 1,
                            'orden'         => (int) $orden + 1,
                            'actualizado_at' => now(),
                            'creado_at'     => now(),
                        ]
                    );
                } catch (\Throwable) {
                    continue;
                }
            }
        }
    }
};
