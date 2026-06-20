<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the orphaned `horas_extras` table (Mantención module bridge).
 *
 * WHY:
 *   - Created in S12 as a migration bridge to normalize horasExtras/*.json files.
 *   - S27 emptied the table and deleted the source JSON files from storage.
 *   - No runtime code reads or writes this table:
 *       · redmine-mantencion/views/Historico/historico.php reads from the
 *         filesystem path data/horasExtras, NOT from the DB table.
 *       · The string 'horas_extras' in controllers is an array key for a
 *         maintenance UI section, not a DB::table() call.
 *   - `reporte_local_id` references redmine_tic_reportes.local_id, a column
 *     that was eliminated in S22 (use_report_id_in_redmine_tic_reports).
 *     That FK was never formalized, making it a dead orphan column.
 *   - `datos_extra` was dropped in S27 Phase 2; no promoted columns replaced it.
 *
 * SAFE:
 *   - Table is empty (0 rows since S27 cleanup).
 *   - No FK constraints from other tables point INTO horas_extras.
 *   - The active TIC overtime model (redmine_tic_horas_extra_grupos + pivot) is
 *     untouched — it serves a completely different module and concept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('horas_extras');
    }

    public function down(): void
    {
        // Recreates the original structure exactly as it existed before the drop.
        // reporte_local_id is included even though it was orphaned — a down()
        // should restore the prior state, not a corrected version of it.
        if (Schema::hasTable('horas_extras')) {
            return;
        }

        Schema::create('horas_extras', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('modulo_id')->nullable();
            $table->string('proyecto', 180)->nullable();
            $table->string('project_id', 80)->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('id_redmine_asignado', 80)->nullable();
            $table->unsignedInteger('numero_ticket_redmine')->nullable();
            $table->string('reporte_local_id', 120)->nullable();
            $table->date('fecha')->nullable();
            $table->time('hora_inicio')->nullable();
            $table->time('hora_termino')->nullable();
            $table->decimal('cantidad', 10, 2)->nullable();
            $table->string('source_path')->nullable();
            $table->char('origen_hash', 64)->unique();
            $table->timestamp('creado_at')->useCurrent();
            $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('modulo_id')
                ->references('id')->on('modulos_nova')
                ->nullOnDelete();

            $table->foreign('usuario_id')
                ->references('id')->on('usuarios_nova')
                ->nullOnDelete();

            $table->index('modulo_id');
            $table->index('project_id');
            $table->index('id_redmine_asignado');
            $table->index('numero_ticket_redmine');
            $table->index('reporte_local_id');
            $table->index('fecha');
            $table->index('source_path');
        });
    }
};
