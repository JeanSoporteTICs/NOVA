<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('destinatarios_informes_modulo')) {
            return;
        }

        Schema::create('destinatarios_informes_modulo', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('modulo_id')->constrained('modulos_nova')->cascadeOnDelete();
            $table->foreignId('usuario_id')->constrained('usuarios_nova')->cascadeOnDelete();
            $table->boolean('recibe_informe')->default(false);
            $table->boolean('es_jefatura')->default(false);
            $table->timestamp('creado_at')->useCurrent();
            $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            $table->unique(['modulo_id', 'usuario_id'], 'uq_destinatario_informe_modulo_usuario');
            $table->index(['modulo_id', 'recibe_informe'], 'idx_destinatario_informe');
            $table->index(['modulo_id', 'es_jefatura'], 'idx_jefatura_informe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('destinatarios_informes_modulo');
    }
};
