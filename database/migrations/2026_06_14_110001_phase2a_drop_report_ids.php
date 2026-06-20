<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2a — Destructive, irreversible.
 *
 * Drops the `report_ids` JSON column from `redmine_tic_horas_extra_grupos`
 * after RedmineDataRepository has been updated to use the pivot table
 * `redmine_tic_horas_extra_grupo_reportes` (created in Phase 1a).
 *
 * Prerequisites:
 *   - Phase 1a migration must have run (pivot table exists and is populated)
 *   - RedmineDataRepository no longer reads/writes report_ids at runtime
 *
 * A row-level backup is written to _nova_column_backups before the drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('redmine_tic_horas_extra_grupos')) {
            return;
        }
        if (!Schema::hasColumn('redmine_tic_horas_extra_grupos', 'report_ids')) {
            return;
        }

        // Verify pivot table exists before dropping the source column
        if (!Schema::hasTable('redmine_tic_horas_extra_grupo_reportes')) {
            throw new \RuntimeException(
                'Phase 1a migration (create_horas_extra_grupo_reportes_pivot) must run before Phase 2a. ' .
                'Pivot table redmine_tic_horas_extra_grupo_reportes not found.'
            );
        }

        $this->backupColumn();

        Schema::table('redmine_tic_horas_extra_grupos', function (Blueprint $table): void {
            $table->dropColumn('report_ids');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('redmine_tic_horas_extra_grupos')) {
            return;
        }
        if (Schema::hasColumn('redmine_tic_horas_extra_grupos', 'report_ids')) {
            return;
        }

        Schema::table('redmine_tic_horas_extra_grupos', function (Blueprint $table): void {
            $table->longText('report_ids')->nullable()->after('hora_fin');
        });

        // Restore data from pivot table
        $grupos = DB::table('redmine_tic_horas_extra_grupos')->get(['id']);
        foreach ($grupos as $grupo) {
            $ids = DB::table('redmine_tic_horas_extra_grupo_reportes')
                ->where('grupo_id', $grupo->id)
                ->pluck('reporte_id')
                ->map(static fn ($v) => (string) $v)
                ->values()
                ->all();
            DB::table('redmine_tic_horas_extra_grupos')
                ->where('id', $grupo->id)
                ->update(['report_ids' => json_encode($ids, JSON_UNESCAPED_UNICODE)]);
        }
    }

    private function backupColumn(): void
    {
        try {
            if (!Schema::hasTable('_nova_column_backups')) {
                Schema::create('_nova_column_backups', function (Blueprint $bp): void {
                    $bp->id();
                    $bp->string('source_table', 100);
                    $bp->string('source_column', 100);
                    $bp->unsignedBigInteger('source_row_id');
                    $bp->longText('valor')->nullable();
                    $bp->timestamp('backed_up_at')->useCurrent();
                });
            }

            $rows = DB::table('redmine_tic_horas_extra_grupos')
                ->whereNotNull('report_ids')
                ->get(['id', 'report_ids']);

            foreach ($rows as $row) {
                DB::table('_nova_column_backups')->insert([
                    'source_table'  => 'redmine_tic_horas_extra_grupos',
                    'source_column' => 'report_ids',
                    'source_row_id' => (int) $row->id,
                    'valor'         => (string) ($row->report_ids ?? ''),
                    'backed_up_at'  => now(),
                ]);
            }
        } catch (\Throwable) {
        }
    }
};
