<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4.1: elimina las tablas de respaldo (backup_*) generadas antes del
 * DROP de las tablas legacy de Horas Extra en la Fase 4. La información ya
 * fue traspasada y validada en horas_extra_grupos/horas_extra_grupo_reportes;
 * estos respaldos ya cumplieron su propósito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('backup_redmine_mantencion_horas_extra_grupos');
        Schema::dropIfExists('backup_redmine_mantencion_horas_extra_reportes');
        Schema::dropIfExists('backup_redmine_tic_horas_extra_grupos');
        Schema::dropIfExists('backup_redmine_tic_horas_extra_grupo_reportes');
    }

    public function down(): void
    {
        // Recrea solo la estructura basica (sin PK/FK, tal como quedaron al
        // generarse via CREATE TABLE ... AS SELECT). No restaura datos.
        if (! Schema::hasTable('backup_redmine_mantencion_horas_extra_grupos')) {
            Schema::create('backup_redmine_mantencion_horas_extra_grupos', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->default(0);
                $table->unsignedBigInteger('modulo_id')->nullable();
                $table->date('fecha');
                $table->time('hora_inicio')->nullable();
                $table->time('hora_fin')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (! Schema::hasTable('backup_redmine_mantencion_horas_extra_reportes')) {
            Schema::create('backup_redmine_mantencion_horas_extra_reportes', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->default(0);
                $table->unsignedBigInteger('grupo_id');
                $table->unsignedBigInteger('reporte_id');
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (! Schema::hasTable('backup_redmine_tic_horas_extra_grupos')) {
            Schema::create('backup_redmine_tic_horas_extra_grupos', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->default(0);
                $table->unsignedBigInteger('modulo_id');
                $table->date('fecha');
                $table->time('hora_inicio')->nullable();
                $table->time('hora_fin')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (! Schema::hasTable('backup_redmine_tic_horas_extra_grupo_reportes')) {
            Schema::create('backup_redmine_tic_horas_extra_grupo_reportes', function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->default(0);
                $table->unsignedBigInteger('grupo_id');
                $table->unsignedBigInteger('reporte_id');
                $table->timestamp('creado_at')->useCurrent();
            });
        }
    }
};
