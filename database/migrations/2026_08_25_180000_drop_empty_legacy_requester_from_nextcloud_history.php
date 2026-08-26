<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = 'redmine_mantencion_nextcloud_historial_lotes';
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'solicitante')) {
            return;
        }

        Schema::table($tableName, static function (Blueprint $table): void {
            $table->dropColumn('solicitante');
        });
    }

    public function down(): void
    {
        $tableName = 'redmine_mantencion_nextcloud_historial_lotes';
        if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'solicitante')) {
            return;
        }

        Schema::table($tableName, static function (Blueprint $table): void {
            $table->string('solicitante', 150)->nullable()->after('numero_lote');
        });
    }
};
