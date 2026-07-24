<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('redmine_mantencion_nextcloud_historial_lotes')
            && Schema::hasColumn('redmine_mantencion_nextcloud_historial_lotes', 'expires_at')) {
            Schema::table('redmine_mantencion_nextcloud_historial_lotes', function (Blueprint $table): void {
                $table->dropColumn('expires_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('redmine_mantencion_nextcloud_historial_lotes')
            && !Schema::hasColumn('redmine_mantencion_nextcloud_historial_lotes', 'expires_at')) {
            Schema::table('redmine_mantencion_nextcloud_historial_lotes', function (Blueprint $table): void {
                $table->dateTime('expires_at')->nullable()->index();
            });
        }
    }
};
