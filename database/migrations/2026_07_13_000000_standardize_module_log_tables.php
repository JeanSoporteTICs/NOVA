<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('redmine_tic_activity_logs') && ! Schema::hasTable('tic_log')) {
            Schema::rename('redmine_tic_activity_logs', 'tic_log');
        }

        if (Schema::hasTable('redmine_mantencion_eventos') && ! Schema::hasTable('mantencion_log')) {
            Schema::rename('redmine_mantencion_eventos', 'mantencion_log');
        }

        $this->createModuleLog('telegram_log');
        $this->createModuleLog('emach_log');
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_log');
        Schema::dropIfExists('emach_log');

        if (Schema::hasTable('tic_log') && ! Schema::hasTable('redmine_tic_activity_logs')) {
            Schema::rename('tic_log', 'redmine_tic_activity_logs');
        }

        if (Schema::hasTable('mantencion_log') && ! Schema::hasTable('redmine_mantencion_eventos')) {
            Schema::rename('mantencion_log', 'redmine_mantencion_eventos');
        }
    }

    private function createModuleLog(string $table): void
    {
        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->id();
            $blueprint->string('evento', 120)->index();
            $blueprint->string('usuario_id', 160)->nullable()->index();
            $blueprint->text('detalle')->nullable();
            $blueprint->json('contexto')->nullable();
            $blueprint->timestamp('registrado_at')->useCurrent()->index();
        });
    }
};
