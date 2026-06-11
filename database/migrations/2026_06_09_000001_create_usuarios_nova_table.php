<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('usuarios_nova')) {
            return;
        }

        Schema::create('usuarios_nova', function (Blueprint $table): void {
            $table->id();
            $table->char('uuid', 36)->unique();
            $table->string('usuario', 80)->unique();
            $table->string('rut', 20)->nullable()->unique();
            $table->unsignedInteger('redmine_id')->nullable()->unique();
            $table->string('nombre', 120)->index();
            $table->string('apellido', 160);
            $table->string('email', 180)->nullable();
            $table->string('rol', 40)->default('usuario')->index();
            $table->string('estado', 40)->default('activo')->index();
            $table->string('password');
            $table->string('usuario_core', 120)->nullable()->index();
            $table->dateTime('ultimo_login_at')->nullable();
            $table->timestamp('creado_at')->useCurrent();
            $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios_nova');
    }
};
