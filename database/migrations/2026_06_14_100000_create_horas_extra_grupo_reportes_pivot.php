<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1a — Non-destructive.
 *
 * Normalizes redmine_tic_horas_extra_grupos.report_ids (JSON array of reporte IDs)
 * into a proper pivot table. The original report_ids column is NOT dropped here;
 * that happens in Phase 2 after code is migrated to use the pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('redmine_tic_horas_extra_grupo_reportes')) {
            Schema::create('redmine_tic_horas_extra_grupo_reportes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('grupo_id')
                    ->constrained('redmine_tic_horas_extra_grupos')
                    ->cascadeOnDelete();
                $table->unsignedBigInteger('reporte_id');
                $table->timestamp('creado_at')->useCurrent();

                $table->unique(['grupo_id', 'reporte_id'], 'uq_hegr_grupo_reporte');
                $table->index('reporte_id', 'idx_hegr_reporte');

                // FK to reportes only when table exists (may be redmine_tic_reportes in prod
                // or reportes_redmine on a clean install via migration 2026_06_11_000002).
            });

            // Add FK separately so we can pick the correct table name at runtime.
            $reportesTable = Schema::hasTable('redmine_tic_reportes') ? 'redmine_tic_reportes' : 'reportes_redmine';
            if (Schema::hasTable($reportesTable)) {
                Schema::table('redmine_tic_horas_extra_grupo_reportes', function (Blueprint $table) use ($reportesTable): void {
                    $table->foreign('reporte_id', 'fk_hegr_reporte')
                        ->references('id')
                        ->on($reportesTable)
                        ->cascadeOnDelete();
                });
            }
        }

        $this->populateFromJson();
    }

    public function down(): void
    {
        Schema::dropIfExists('redmine_tic_horas_extra_grupo_reportes');
    }

    private function populateFromJson(): void
    {
        if (!Schema::hasTable('redmine_tic_horas_extra_grupos')) {
            return;
        }

        $reportesTable = Schema::hasTable('redmine_tic_reportes') ? 'redmine_tic_reportes' : 'reportes_redmine';
        $hasReportesTable = Schema::hasTable($reportesTable);

        $grupos = DB::table('redmine_tic_horas_extra_grupos')
            ->whereNotNull('report_ids')
            ->get(['id', 'report_ids']);

        foreach ($grupos as $grupo) {
            $ids = json_decode((string) ($grupo->report_ids ?? '[]'), true);
            if (!is_array($ids)) {
                continue;
            }

            foreach ($ids as $rawId) {
                $reporteId = is_numeric($rawId) ? (int) $rawId : 0;
                if ($reporteId <= 0) {
                    continue;
                }

                // Skip if the referenced reporte no longer exists
                if ($hasReportesTable && !DB::table($reportesTable)->where('id', $reporteId)->exists()) {
                    continue;
                }

                try {
                    DB::table('redmine_tic_horas_extra_grupo_reportes')->updateOrInsert(
                        ['grupo_id' => (int) $grupo->id, 'reporte_id' => $reporteId],
                        ['creado_at' => now()]
                    );
                } catch (\Throwable) {
                    continue;
                }
            }
        }
    }
};
