<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('redmine_mantencion_nextcloud_historial_usuarios')
            && Schema::hasColumn('redmine_mantencion_nextcloud_historial_usuarios', 'password')) {
            Schema::table('redmine_mantencion_nextcloud_historial_usuarios', function (Blueprint $table): void {
                $table->dropColumn('password');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('redmine_mantencion_nextcloud_historial_usuarios')
            && !Schema::hasColumn('redmine_mantencion_nextcloud_historial_usuarios', 'password')) {
            Schema::table('redmine_mantencion_nextcloud_historial_usuarios', function (Blueprint $table): void {
                $table->string('password')->nullable();
            });
        }
    }
};
