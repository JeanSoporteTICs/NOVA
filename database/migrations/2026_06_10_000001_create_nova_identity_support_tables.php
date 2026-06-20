<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

        if (!Schema::hasTable('integraciones_usuario')) {
            Schema::create('integraciones_usuario', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('usuario_id')->constrained('usuarios_nova')->cascadeOnDelete();
                $table->string('tipo', 40);
                $table->string('usuario_externo', 180)->nullable();
                $table->text('valor_secreto')->nullable();
                $table->string('chat_id', 120)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
                $table->unique(['usuario_id', 'tipo'], 'uq_integracion_usuario_tipo');
                $table->index('tipo', 'idx_integraciones_usuario_tipo');
                $table->index('usuario_externo', 'idx_integraciones_usuario_externo');
            });
        }

        if (!Schema::hasTable('permisos_usuario_modulo')) {
            Schema::create('permisos_usuario_modulo', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('usuario_id')->constrained('usuarios_nova')->cascadeOnDelete();
                $table->foreignId('modulo_id')->constrained('modulos_nova')->cascadeOnDelete();
                $table->boolean('permitido')->default(false);
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
                $table->unique(['usuario_id', 'modulo_id'], 'uq_permiso_usuario_modulo');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permisos_usuario_modulo');
        Schema::dropIfExists('integraciones_usuario');
    }
};
