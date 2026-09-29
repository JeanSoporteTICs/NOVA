<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redmine_mantencion_plantillas_manuales', function (Blueprint $table): void {
            $table->id();
            $table->string('usuario_nova_id', 64);
            $table->string('nombre', 120);
            $table->string('asunto', 255);
            $table->string('categoria', 255);
            $table->timestamps();
            $table->unique(['usuario_nova_id', 'nombre'], 'rm_plantillas_usuario_nombre_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redmine_mantencion_plantillas_manuales');
    }
};
