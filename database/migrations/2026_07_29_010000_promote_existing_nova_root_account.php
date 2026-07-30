<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('usuarios_nova')) {
            return;
        }

        DB::table('usuarios_nova')
            ->whereRaw('LOWER(TRIM(nombre)) = ?', ['root'])
            ->whereRaw('LOWER(TRIM(apellido)) = ?', ['root'])
            ->where('rol', 'admin')
            ->update([
                'rol' => 'root',
                'actualizado_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('usuarios_nova')) {
            return;
        }

        DB::table('usuarios_nova')
            ->whereRaw('LOWER(TRIM(nombre)) = ?', ['root'])
            ->whereRaw('LOWER(TRIM(apellido)) = ?', ['root'])
            ->where('rol', 'root')
            ->update([
                'rol' => 'admin',
                'actualizado_at' => now(),
            ]);
    }
};
