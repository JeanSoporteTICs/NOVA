<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('emach_horarios_usuario')) {
            return;
        }

        Schema::create('emach_horarios_usuario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios_nova')->cascadeOnDelete();
            $table->unsignedTinyInteger('dia_semana');
            $table->boolean('activo')->default(false);
            $table->time('hora_entrada')->nullable();
            $table->time('hora_salida')->nullable();
            $table->timestamp('creado_at')->useCurrent();
            $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['usuario_id', 'dia_semana'], 'uq_emach_horario_usuario_dia');
            $table->index(['usuario_id', 'activo'], 'idx_emach_horario_usuario_activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emach_horarios_usuario');
    }
};
