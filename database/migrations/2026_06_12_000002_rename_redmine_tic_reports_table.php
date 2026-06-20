<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reportes_redmine') && !Schema::hasTable('redmine_tic_reportes')) {
            Schema::rename('reportes_redmine', 'redmine_tic_reportes');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('redmine_tic_reportes') && !Schema::hasTable('reportes_redmine')) {
            Schema::rename('redmine_tic_reportes', 'reportes_redmine');
        }
    }
};
