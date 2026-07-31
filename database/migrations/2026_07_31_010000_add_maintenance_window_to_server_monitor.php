<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('monitoreo_servidores')) {
            return;
        }

        Schema::table('monitoreo_servidores', function (Blueprint $table): void {
            if (! Schema::hasColumn('monitoreo_servidores', 'mantenimiento_desde')) {
                $table->dateTime('mantenimiento_desde')->nullable()->after('activo');
            }
            if (! Schema::hasColumn('monitoreo_servidores', 'mantenimiento_hasta')) {
                $table->dateTime('mantenimiento_hasta')->nullable()->after('mantenimiento_desde')->index();
            }
            if (! Schema::hasColumn('monitoreo_servidores', 'mantenimiento_motivo')) {
                $table->string('mantenimiento_motivo', 255)->nullable()->after('mantenimiento_hasta');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('monitoreo_servidores')) {
            return;
        }

        Schema::table('monitoreo_servidores', function (Blueprint $table): void {
            $columns = array_values(array_filter(
                ['mantenimiento_desde', 'mantenimiento_hasta', 'mantenimiento_motivo'],
                static fn (string $column): bool => Schema::hasColumn('monitoreo_servidores', $column)
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
