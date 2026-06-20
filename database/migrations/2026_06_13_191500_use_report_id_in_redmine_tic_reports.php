<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('redmine_tic_horas_extra_grupos') && !Schema::hasColumn('redmine_tic_horas_extra_grupos', 'report_ids')) {
            Schema::table('redmine_tic_horas_extra_grupos', function (Blueprint $table): void {
                $table->json('report_ids')->nullable()->after('hora_fin');
            });
        }

        if (
            Schema::hasTable('redmine_tic_reportes')
            && Schema::hasColumn('redmine_tic_reportes', 'local_id')
            && Schema::hasTable('redmine_tic_horas_extra_grupos')
            && Schema::hasColumn('redmine_tic_horas_extra_grupos', 'report_local_ids')
            && Schema::hasColumn('redmine_tic_horas_extra_grupos', 'report_ids')
        ) {
            $localToId = DB::table('redmine_tic_reportes')
                ->whereNotNull('local_id')
                ->pluck('id', 'local_id')
                ->mapWithKeys(static fn ($id, $localId): array => [(string) $localId => (string) $id])
                ->all();

            foreach (DB::table('redmine_tic_horas_extra_grupos')->get(['id', 'report_local_ids']) as $row) {
                $rawIds = json_decode((string) ($row->report_local_ids ?? '[]'), true);
                $rawIds = is_array($rawIds) ? $rawIds : [];
                $reportIds = [];

                foreach ($rawIds as $rawId) {
                    $key = trim((string) $rawId);
                    if ($key === '') {
                        continue;
                    }

                    $mappedId = $localToId[$key] ?? (ctype_digit($key) ? $key : '');
                    if ($mappedId !== '') {
                        $reportIds[$mappedId] = $mappedId;
                    }
                }

                DB::table('redmine_tic_horas_extra_grupos')
                    ->where('id', $row->id)
                    ->update([
                        'report_ids' => json_encode(array_values($reportIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'actualizado_at' => now(),
                    ]);
            }
        }

        if (Schema::hasTable('redmine_tic_horas_extra_grupos') && Schema::hasColumn('redmine_tic_horas_extra_grupos', 'report_local_ids')) {
            Schema::table('redmine_tic_horas_extra_grupos', function (Blueprint $table): void {
                $table->dropColumn('report_local_ids');
            });
        }

        if (Schema::hasTable('redmine_tic_reportes') && Schema::hasColumn('redmine_tic_reportes', 'local_id')) {
            if ($this->indexExists('redmine_tic_reportes', 'uq_reporte_modulo_local')) {
                Schema::table('redmine_tic_reportes', function (Blueprint $table): void {
                    $table->dropUnique('uq_reporte_modulo_local');
                });
            }

            Schema::table('redmine_tic_reportes', function (Blueprint $table): void {
                $table->dropColumn('local_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('redmine_tic_reportes') && !Schema::hasColumn('redmine_tic_reportes', 'local_id')) {
            Schema::table('redmine_tic_reportes', function (Blueprint $table): void {
                $table->char('local_id', 36)->nullable()->after('modulo_id');
            });

            DB::table('redmine_tic_reportes')
                ->whereNull('local_id')
                ->orderBy('id')
                ->update(['local_id' => DB::raw('CAST(id AS CHAR)')]);

            if (!$this->indexExists('redmine_tic_reportes', 'uq_reporte_modulo_local')) {
                Schema::table('redmine_tic_reportes', function (Blueprint $table): void {
                    $table->unique(['modulo_id', 'local_id'], 'uq_reporte_modulo_local');
                });
            }
        }

        if (Schema::hasTable('redmine_tic_horas_extra_grupos') && !Schema::hasColumn('redmine_tic_horas_extra_grupos', 'report_local_ids')) {
            Schema::table('redmine_tic_horas_extra_grupos', function (Blueprint $table): void {
                $table->json('report_local_ids')->nullable()->after('hora_fin');
            });
        }

        if (
            Schema::hasTable('redmine_tic_horas_extra_grupos')
            && Schema::hasColumn('redmine_tic_horas_extra_grupos', 'report_ids')
            && Schema::hasColumn('redmine_tic_horas_extra_grupos', 'report_local_ids')
        ) {
            foreach (DB::table('redmine_tic_horas_extra_grupos')->get(['id', 'report_ids']) as $row) {
                DB::table('redmine_tic_horas_extra_grupos')
                    ->where('id', $row->id)
                    ->update([
                        'report_local_ids' => $row->report_ids,
                        'actualizado_at' => now(),
                    ]);
            }

            Schema::table('redmine_tic_horas_extra_grupos', function (Blueprint $table): void {
                $table->dropColumn('report_ids');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }
};
