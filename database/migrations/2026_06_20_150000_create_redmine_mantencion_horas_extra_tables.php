<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('redmine_mantencion_horas_extra_grupos')) {
            Schema::create('redmine_mantencion_horas_extra_grupos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')->nullable()->constrained('modulos_nova')->nullOnDelete();
                $table->date('fecha');
                $table->time('hora_inicio')->nullable();
                $table->time('hora_fin')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();

                $table->unique(['modulo_id', 'fecha'], 'uq_rm_mant_horas_grupo_fecha');
                $table->index('fecha', 'idx_rm_mant_horas_fecha');
            });
        }

        if (! Schema::hasTable('redmine_mantencion_horas_extra_reportes')) {
            Schema::create('redmine_mantencion_horas_extra_reportes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('grupo_id')
                    ->constrained('redmine_mantencion_horas_extra_grupos')
                    ->cascadeOnDelete();
                $table->foreignId('reporte_id')
                    ->constrained('redmine_mantencion_reportes')
                    ->cascadeOnDelete();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();

                $table->unique(['grupo_id', 'reporte_id'], 'uq_rm_mant_horas_grupo_reporte');
                $table->index('reporte_id', 'idx_rm_mant_horas_reporte');
            });
        }

        $moduleId = Schema::hasTable('modulos_nova')
            ? DB::table('modulos_nova')->where('clave_modulo', 'redmine-mantencion')->value('id')
            : null;

        if ($moduleId === null || ! Schema::hasTable('redmine_mantencion_reportes')) {
            return;
        }

        DB::table('redmine_mantencion_reportes')
            ->where('modulo_id', $moduleId)
            ->where('hora_extra', 1)
            ->whereNotNull('fecha_reporte')
            ->orderBy('id')
            ->get(['id', 'fecha_reporte', 'hora_reporte'])
            ->each(function (object $report) use ($moduleId): void {
                $fecha = (string) $report->fecha_reporte;
                $hora = $report->hora_reporte !== null ? (string) $report->hora_reporte : null;

                $grupoId = DB::table('redmine_mantencion_horas_extra_grupos')
                    ->where('modulo_id', $moduleId)
                    ->where('fecha', $fecha)
                    ->value('id');

                if ($grupoId === null) {
                    $grupoId = DB::table('redmine_mantencion_horas_extra_grupos')->insertGetId([
                        'modulo_id' => $moduleId,
                        'fecha' => $fecha,
                        'hora_inicio' => $hora,
                        'hora_fin' => $hora,
                        'creado_at' => now(),
                        'actualizado_at' => now(),
                    ]);
                }

                DB::table('redmine_mantencion_horas_extra_reportes')->updateOrInsert(
                    [
                        'grupo_id' => $grupoId,
                        'reporte_id' => (int) $report->id,
                    ],
                    [
                        'actualizado_at' => now(),
                    ],
                );
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('redmine_mantencion_horas_extra_reportes');
        Schema::dropIfExists('redmine_mantencion_horas_extra_grupos');
    }
};
