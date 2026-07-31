<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('monitoreo_servidores')) {
            Schema::create('monitoreo_servidores', function (Blueprint $table): void {
                $table->id();
                $table->string('nombre', 160);
                $table->string('host', 255);
                $table->string('tipo', 20)->default('tcp')->index();
                $table->unsignedSmallInteger('puerto')->nullable();
                $table->string('ruta', 500)->nullable();
                $table->boolean('verificar_ssl')->default(false);
                $table->unsignedInteger('intervalo_segundos')->default(60);
                $table->unsignedSmallInteger('timeout_segundos')->default(5);
                $table->unsignedSmallInteger('fallos_para_alertar')->default(3);
                $table->boolean('activo')->default(true)->index();
                $table->string('estado', 20)->default('pendiente')->index();
                $table->unsignedSmallInteger('fallos_consecutivos')->default(0);
                $table->unsignedInteger('latencia_ms')->nullable();
                $table->text('ultimo_error')->nullable();
                $table->dateTime('ultimo_chequeo_at')->nullable()->index();
                $table->dateTime('ultima_respuesta_at')->nullable();
                $table->dateTime('caido_desde')->nullable();
                $table->dateTime('alertado_caida_at')->nullable();
                $table->foreignId('creado_por')->nullable()->constrained('usuarios_nova')->nullOnDelete();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (!Schema::hasTable('monitoreo_servidor_eventos')) {
            Schema::create('monitoreo_servidor_eventos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('servidor_id')->constrained('monitoreo_servidores')->cascadeOnDelete();
                $table->string('tipo', 30)->index();
                $table->string('estado_anterior', 20)->nullable();
                $table->string('estado_nuevo', 20);
                $table->text('detalle')->nullable();
                $table->unsignedInteger('latencia_ms')->nullable();
                $table->dateTime('ocurrido_at')->index();
                $table->dateTime('notificado_at')->nullable();
                $table->unsignedSmallInteger('destinatarios_notificados')->default(0);
                $table->unsignedSmallInteger('fallos_notificacion')->default(0);
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
                $table->index(['servidor_id', 'ocurrido_at'], 'idx_monitor_evento_servidor_fecha');
            });
        }

        if (!Schema::hasTable('monitoreo_alerta_usuarios')) {
            Schema::create('monitoreo_alerta_usuarios', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('usuario_id')->constrained('usuarios_nova')->cascadeOnDelete();
                $table->boolean('activo')->default(true);
                $table->boolean('recibir_caidas')->default(true);
                $table->boolean('recibir_recuperaciones')->default(true);
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
                $table->unique('usuario_id', 'uq_monitor_alerta_usuario');
            });
        }

        if (!Schema::hasTable('monitoreo_workers')) {
            Schema::create('monitoreo_workers', function (Blueprint $table): void {
                $table->id();
                $table->string('instancia', 160)->unique();
                $table->dateTime('ultimo_ciclo_at')->nullable()->index();
                $table->unsignedInteger('servidores_comprobados')->default(0);
                $table->text('ultimo_error')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (Schema::hasTable('modulos_nova')) {
            DB::table('modulos_nova')->updateOrInsert(
                ['clave_modulo' => 'monitoreo-servidores'],
                [
                    'nombre' => 'Monitor de Servidores',
                    'descripcion' => 'Supervisa la disponibilidad de servidores y alerta por Telegram.',
                    'icono' => 'bi-hdd-network',
                    'tipo' => 'native',
                    'ruta' => '/monitoreo-servidores',
                    'entrada' => 'laravel:monitor.dashboard',
                    'habilitado' => 1,
                    'en_mantencion' => 0,
                    'orden' => 70,
                    'actualizado_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('modulos_nova')) {
            $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'monitoreo-servidores')->value('id');
            if ($moduleId !== null && Schema::hasTable('permisos_usuario_modulo')) {
                DB::table('permisos_usuario_modulo')->where('modulo_id', $moduleId)->delete();
            }
            DB::table('modulos_nova')->where('clave_modulo', 'monitoreo-servidores')->delete();
        }

        Schema::dropIfExists('monitoreo_workers');
        Schema::dropIfExists('monitoreo_alerta_usuarios');
        Schema::dropIfExists('monitoreo_servidor_eventos');
        Schema::dropIfExists('monitoreo_servidores');
    }
};
