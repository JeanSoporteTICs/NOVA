<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * These tables belonged to earlier or default Laravel paths that are not
     * used by the current NOVA runtime. Current sources are JSON-backed
     * repositories, usuarios_nova, integraciones_usuario, and module tables.
     */
    public function up(): void
    {
        Schema::dropIfExists('alias_comando_telegram');
        Schema::dropIfExists('comandos_telegram');
        Schema::dropIfExists('mensajes_telegram');
        Schema::dropIfExists('auditoria_nova');
        Schema::dropIfExists('configuraciones_nova');
        Schema::dropIfExists('mantenciones_modulo');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('password_resets');
        Schema::dropIfExists('failed_jobs');
    }

    public function down(): void
    {
        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table): void {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        if (!Schema::hasTable('password_resets')) {
            Schema::create('password_resets', function (Blueprint $table): void {
                $table->string('email')->index();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table): void {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('auditoria_nova')) {
            Schema::create('auditoria_nova', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('usuario_id')->nullable()->constrained('usuarios_nova')->nullOnDelete();
                $table->string('evento', 120)->index();
                $table->text('mensaje')->nullable();
                $table->json('contexto')->nullable();
                $table->string('ip', 80)->nullable();
                $table->timestamp('creado_at')->useCurrent();
            });
        }

        if (!Schema::hasTable('configuraciones_nova')) {
            Schema::create('configuraciones_nova', function (Blueprint $table): void {
                $table->id();
                $table->string('clave', 120)->unique();
                $table->text('valor')->nullable();
                $table->string('tipo', 30)->default('string');
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (!Schema::hasTable('mantenciones_modulo')) {
            Schema::create('mantenciones_modulo', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')->constrained('modulos_nova')->cascadeOnDelete();
                $table->boolean('activa')->default(false);
                $table->dateTime('hasta')->nullable();
                $table->text('motivo')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
                $table->unique('modulo_id', 'uq_mantencion_modulo');
            });
        }

        if (!Schema::hasTable('mensajes_telegram')) {
            Schema::create('mensajes_telegram', function (Blueprint $table): void {
                $table->id();
                $table->string('clave_mensaje', 100)->unique();
                $table->string('etiqueta', 160);
                $table->text('cuerpo');
                $table->text('descripcion')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (!Schema::hasTable('comandos_telegram')) {
            Schema::create('comandos_telegram', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')->nullable()->constrained('modulos_nova')->nullOnDelete();
                $table->string('clave', 80)->unique();
                $table->string('comando', 80);
                $table->string('descripcion', 255)->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        if (!Schema::hasTable('alias_comando_telegram')) {
            Schema::create('alias_comando_telegram', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('comando_id')->constrained('comandos_telegram')->cascadeOnDelete();
                $table->string('alias', 80)->unique();
                $table->timestamp('creado_at')->useCurrent();
            });
        }
    }
};
