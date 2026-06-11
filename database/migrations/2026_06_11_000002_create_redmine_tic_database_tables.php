<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('modulos_nova')) {
            Schema::create('modulos_nova', function (Blueprint $table): void {
                $table->id();
                $table->string('clave_modulo', 80)->unique();
                $table->string('nombre', 160);
                $table->text('descripcion')->nullable();
                $table->string('icono', 80)->nullable();
                $table->string('tipo', 40)->default('native')->index();
                $table->string('ruta', 500)->nullable();
                $table->string('entrada', 255)->nullable();
                $table->boolean('activo')->default(true)->index();
                $table->integer('orden')->default(100)->index();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (!Schema::hasTable('configuraciones_modulo')) {
            Schema::create('configuraciones_modulo', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')->constrained('modulos_nova')->cascadeOnDelete();
                $table->string('clave', 120);
                $table->text('valor')->nullable();
                $table->string('tipo', 30)->default('string');
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
                $table->unique(['modulo_id', 'clave'], 'uq_configuracion_modulo_clave');
                $table->index('clave', 'idx_configuraciones_modulo_clave');
            });
        }

        if (!Schema::hasTable('catalogos_modulo')) {
            Schema::create('catalogos_modulo', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')->constrained('modulos_nova')->cascadeOnDelete();
                $table->string('tipo', 40)->index();
                $table->string('clave_externa', 100)->nullable();
                $table->string('nombre', 255)->index();
                $table->boolean('predeterminado')->default(false);
                $table->boolean('activo')->default(true);
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
                $table->unique(['modulo_id', 'tipo', 'clave_externa'], 'uq_catalogo_modulo_item');
            });
        }

        if (!Schema::hasTable('reportes_redmine')) {
            Schema::create('reportes_redmine', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')->constrained('modulos_nova')->cascadeOnDelete();
                $table->char('local_id', 36)->nullable();
                $table->unsignedInteger('redmine_id')->nullable()->index();
                $table->string('estado', 20)->nullable();
                $table->string('estado_redmine', 40)->nullable();
                $table->string('tipo', 40)->nullable();
                $table->string('prioridad', 20)->nullable();
                $table->foreignId('categoria_catalogo_id')->nullable()->constrained('catalogos_modulo')->nullOnDelete();
                $table->foreignId('unidad_catalogo_id')->nullable()->constrained('catalogos_modulo')->nullOnDelete();
                $table->foreignId('unidad_solicitante_catalogo_id')->nullable()->constrained('catalogos_modulo')->nullOnDelete();
                $table->string('solicitante', 255)->nullable();
                $table->text('asunto')->nullable();
                $table->longText('descripcion')->nullable();
                $table->date('fecha')->nullable()->index();
                $table->time('hora')->nullable();
                $table->unsignedInteger('asignado_a')->nullable()->index();
                $table->boolean('hora_extra')->nullable();
                $table->decimal('tiempo_estimado', 10, 2)->nullable();
                $table->string('origen', 40)->nullable()->index();
                $table->dateTime('procesado_at')->nullable();
                $table->string('archivado_por', 255)->nullable();
                $table->dateTime('archivado_at')->nullable();
                $table->json('datos_extra')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
                $table->unique(['modulo_id', 'local_id'], 'uq_reporte_modulo_local');
                $table->index(['modulo_id', 'estado'], 'idx_reportes_modulo_estado');
            });
        }

        if (!Schema::hasTable('redmine_tic_horas_extra_grupos')) {
            Schema::create('redmine_tic_horas_extra_grupos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')->constrained('modulos_nova')->cascadeOnDelete();
                $table->date('fecha');
                $table->time('hora_inicio')->nullable();
                $table->time('hora_fin')->nullable();
                $table->json('report_local_ids')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
                $table->unique(['modulo_id', 'fecha'], 'uq_redmine_tic_horas_fecha');
            });
        }

        if (!Schema::hasTable('redmine_tic_usuarios')) {
            Schema::create('redmine_tic_usuarios', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('redmine_id')->unique();
                $table->string('rut_sin_dv', 40)->nullable();
                $table->string('rut', 40)->nullable();
                $table->string('nombre', 120)->nullable();
                $table->string('apellido', 160)->nullable();
                $table->string('telegram_chat_id', 80)->nullable();
                $table->string('api_token', 255)->nullable();
                $table->string('rol', 40)->default('usuario')->index();
                $table->string('estado_usuario', 40)->default('activo')->index();
                $table->json('permisos')->nullable();
                $table->unsignedInteger('redmine_membership_id')->nullable();
                $table->json('redmine_roles')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (!Schema::hasTable('redmine_tic_activity_logs')) {
            Schema::create('redmine_tic_activity_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')->constrained('modulos_nova')->cascadeOnDelete();
                $table->string('evento', 120)->index();
                $table->json('contexto')->nullable();
                $table->text('linea')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->index(['modulo_id', 'creado_at'], 'idx_redmine_tic_activity_modulo_fecha');
            });
        }

        DB::table('modulos_nova')->updateOrInsert(
            ['clave_modulo' => 'redmine_tic'],
            [
                'nombre' => 'Redmine TICS',
                'descripcion' => 'Captura, procesa y envia reportes del proyecto Redmine TICS.',
                'icono' => 'bi-kanban',
                'tipo' => 'native',
                'ruta' => 'redmine_tic',
                'entrada' => 'laravel:redmine.native.dashboard',
                'activo' => 1,
                'orden' => 10,
                'actualizado_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('redmine_tic_activity_logs');
        Schema::dropIfExists('redmine_tic_usuarios');
        Schema::dropIfExists('redmine_tic_horas_extra_grupos');
    }
};
