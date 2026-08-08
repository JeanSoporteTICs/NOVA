<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('redmine_mantencion_nextcloud_historial_lotes')) {
            return;
        }

        $missing = [
            'solicitante' => ! Schema::hasColumn('redmine_mantencion_nextcloud_historial_lotes', 'solicitante'),
            'solicitante_nombre' => ! Schema::hasColumn('redmine_mantencion_nextcloud_historial_lotes', 'solicitante_nombre'),
            'solicitante_rut' => ! Schema::hasColumn('redmine_mantencion_nextcloud_historial_lotes', 'solicitante_rut'),
            'solicitante_correo' => ! Schema::hasColumn('redmine_mantencion_nextcloud_historial_lotes', 'solicitante_correo'),
        ];

        Schema::table('redmine_mantencion_nextcloud_historial_lotes', function (Blueprint $table) use ($missing): void {
            if ($missing['solicitante']) {
                $table->string('solicitante', 150)->nullable()->after('legacy_id');
            }
            if ($missing['solicitante_nombre']) {
                $table->string('solicitante_nombre', 200)->nullable()->after('solicitante');
            }
            if ($missing['solicitante_rut']) {
                $table->string('solicitante_rut', 20)->nullable()->after('solicitante_nombre');
            }
            if ($missing['solicitante_correo']) {
                $table->string('solicitante_correo', 190)->nullable()->after('solicitante_rut');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('redmine_mantencion_nextcloud_historial_lotes')) {
            return;
        }

        $columns = array_values(array_filter(
            ['solicitante', 'solicitante_nombre', 'solicitante_rut', 'solicitante_correo'],
            static fn (string $column): bool => Schema::hasColumn('redmine_mantencion_nextcloud_historial_lotes', $column)
        ));
        if ($columns !== []) {
            Schema::table('redmine_mantencion_nextcloud_historial_lotes', static function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
