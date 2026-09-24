<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('redmine_mantencion_reportes')
            || Schema::hasColumn('redmine_mantencion_reportes', 'core_detalle')) {
            return;
        }

        Schema::table('redmine_mantencion_reportes', function (Blueprint $table): void {
            $table->json('core_detalle')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('redmine_mantencion_reportes')
            && Schema::hasColumn('redmine_mantencion_reportes', 'core_detalle')) {
            Schema::table('redmine_mantencion_reportes', function (Blueprint $table): void {
                $table->dropColumn('core_detalle');
            });
        }
    }
};
