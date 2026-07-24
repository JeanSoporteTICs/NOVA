<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4: elimina definitivamente las tablas legacy de Horas Extra, ya
 * reemplazadas por horas_extra_grupos / horas_extra_grupo_reportes.
 *
 * Un respaldo (CREATE TABLE backup_xxx AS SELECT * FROM xxx) de las 4 tablas
 * se generó por separado antes de ejecutar esta migración; up() solo elimina.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Hijas primero (FK hacia las tablas de grupo).
        Schema::dropIfExists('redmine_mantencion_horas_extra_reportes');
        Schema::dropIfExists('redmine_tic_horas_extra_grupo_reportes');

        Schema::dropIfExists('redmine_mantencion_horas_extra_grupos');
        Schema::dropIfExists('redmine_tic_horas_extra_grupos');
    }

    public function down(): void
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
                $table->foreignId('grupo_id')->constrained('redmine_mantencion_horas_extra_grupos')->cascadeOnDelete();
                $table->foreignId('reporte_id')->constrained('redmine_mantencion_reportes')->cascadeOnDelete();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();

                $table->unique(['grupo_id', 'reporte_id'], 'uq_rm_mant_horas_grupo_reporte');
                $table->index('reporte_id', 'idx_rm_mant_horas_reporte');
            });
        }

        if (! Schema::hasTable('redmine_tic_horas_extra_grupos')) {
            Schema::create('redmine_tic_horas_extra_grupos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')->constrained('modulos_nova')->cascadeOnDelete();
                $table->date('fecha');
                $table->time('hora_inicio')->nullable();
                $table->time('hora_fin')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();

                $table->unique(['modulo_id', 'fecha'], 'uq_redmine_tic_horas_fecha');
            });
        }

        if (! Schema::hasTable('redmine_tic_horas_extra_grupo_reportes')) {
            Schema::create('redmine_tic_horas_extra_grupo_reportes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('grupo_id')->constrained('redmine_tic_horas_extra_grupos')->cascadeOnDelete();
                $table->unsignedBigInteger('reporte_id');
                $table->timestamp('creado_at')->useCurrent();

                $table->unique(['grupo_id', 'reporte_id'], 'uq_hegr_grupo_reporte');
                $table->index('reporte_id', 'idx_hegr_reporte');

                if (Schema::hasTable('redmine_tic_reportes')) {
                    $table->foreign('reporte_id', 'fk_hegr_reporte')
                        ->references('id')->on('redmine_tic_reportes')->cascadeOnDelete();
                }
            });
        }

        // No se restauran datos: solo estructura. Los datos siguen disponibles
        // en las tablas backup_* generadas antes de esta migración.
    }
};
