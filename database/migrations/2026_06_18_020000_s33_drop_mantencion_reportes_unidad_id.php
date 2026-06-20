<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('redmine_mantencion_reportes')
            || ! Schema::hasColumn('redmine_mantencion_reportes', 'unidad_id')) {
            return;
        }

        Schema::table('redmine_mantencion_reportes', function (Blueprint $table): void {
            if ($this->foreignKeyExists('redmine_mantencion_reportes', 'redmine_mantencion_reportes_unidad_id_foreign')) {
                $table->dropForeign('redmine_mantencion_reportes_unidad_id_foreign');
            }

            if ($this->indexExists('redmine_mantencion_reportes', 'redmine_mantencion_reportes_unidad_id_foreign')) {
                $table->dropIndex('redmine_mantencion_reportes_unidad_id_foreign');
            }

            $table->dropColumn('unidad_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('redmine_mantencion_reportes')
            || Schema::hasColumn('redmine_mantencion_reportes', 'unidad_id')) {
            return;
        }

        Schema::table('redmine_mantencion_reportes', function (Blueprint $table): void {
            $table->foreignId('unidad_id')
                ->nullable()
                ->after('anexo')
                ->constrained('unidades')
                ->nullOnDelete();
        });
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        try {
            return collect(DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$table, $name]
            ))->isNotEmpty();
        } catch (Throwable) {
            return false;
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            return collect(DB::select(
                'SHOW INDEX FROM `' . str_replace('`', '``', $table) . '` WHERE Key_name = ?',
                [$index]
            ))->isNotEmpty();
        } catch (Throwable) {
            return false;
        }
    }
};
