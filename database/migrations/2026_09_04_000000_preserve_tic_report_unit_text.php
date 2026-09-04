<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('redmine_tic_reportes')) {
            return;
        }

        if (! Schema::hasColumn('redmine_tic_reportes', 'unidad_texto')) {
            Schema::table('redmine_tic_reportes', function (Blueprint $table): void {
                $table->string('unidad_texto', 180)->nullable()->after('unidad_catalogo_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('redmine_tic_reportes') && Schema::hasColumn('redmine_tic_reportes', 'unidad_texto')) {
            Schema::table('redmine_tic_reportes', function (Blueprint $table): void {
                $table->dropColumn('unidad_texto');
            });
        }
    }
};
