<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('redmine_tic_perfiles_usuario') || !Schema::hasColumn('redmine_tic_perfiles_usuario', 'redmine_roles')) {
            return;
        }

        Schema::table('redmine_tic_perfiles_usuario', function (Blueprint $table): void {
            $table->dropColumn('redmine_roles');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('redmine_tic_perfiles_usuario') || Schema::hasColumn('redmine_tic_perfiles_usuario', 'redmine_roles')) {
            return;
        }

        Schema::table('redmine_tic_perfiles_usuario', function (Blueprint $table): void {
            $table->json('redmine_roles')->nullable()->after('redmine_membership_id');
        });
    }
};
