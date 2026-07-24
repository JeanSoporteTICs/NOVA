<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mantencion_permisos_usuario')) {
            Schema::create('mantencion_permisos_usuario', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('usuario_id');
                $table->string('permiso', 80);
                $table->string('valor', 255)->default('');
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
                $table->unique(['usuario_id', 'permiso'], 'uq_mpu_usuario_permiso');
                $table->foreign('usuario_id')->references('id')->on('usuarios_nova')->cascadeOnDelete();
            });
        }
        if (Schema::hasTable('redmine_tic_permisos_catalogo')) {
            DB::table('redmine_tic_permisos_catalogo')->updateOrInsert(
                ['clave' => 'actividad_eliminar'],
                ['tipo' => 'bool', 'descripcion' => 'Puede eliminar la bitácora de actividad', 'orden' => 39]
            );
        }

        if (Schema::hasTable('redmine_tic_permisos_rol')) {
            foreach (DB::table('redmine_tic_permisos_rol')->where('clave', 'actividad')->get(['modulo_id', 'rol', 'valor']) as $row) {
                DB::table('redmine_tic_permisos_rol')->updateOrInsert(
                    ['modulo_id' => $row->modulo_id, 'rol' => $row->rol, 'clave' => 'actividad_eliminar'],
                    ['valor' => $row->valor, 'actualizado_at' => now()]
                );
            }
        }

        if (Schema::hasTable('redmine_tic_permisos_usuario')) {
            foreach (DB::table('redmine_tic_permisos_usuario')->where('clave', 'actividad')->get(['perfil_id', 'valor']) as $row) {
                DB::table('redmine_tic_permisos_usuario')->updateOrInsert(
                    ['perfil_id' => $row->perfil_id, 'clave' => 'actividad_eliminar'],
                    ['valor' => $row->valor, 'actualizado_at' => now()]
                );
            }
        }

        if (Schema::hasTable('mantencion_permisos_rol')) {
            foreach (DB::table('mantencion_permisos_rol')->where('permiso', 'actividad')->get(['rol', 'valor']) as $row) {
                DB::table('mantencion_permisos_rol')->updateOrInsert(
                    ['rol' => $row->rol, 'permiso' => 'actividad_eliminar'],
                    ['valor' => $row->valor]
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('redmine_tic_permisos_usuario')) DB::table('redmine_tic_permisos_usuario')->where('clave', 'actividad_eliminar')->delete();
        if (Schema::hasTable('redmine_tic_permisos_rol')) DB::table('redmine_tic_permisos_rol')->where('clave', 'actividad_eliminar')->delete();
        if (Schema::hasTable('redmine_tic_permisos_catalogo')) DB::table('redmine_tic_permisos_catalogo')->where('clave', 'actividad_eliminar')->delete();
        if (Schema::hasTable('mantencion_permisos_rol')) DB::table('mantencion_permisos_rol')->where('permiso', 'actividad_eliminar')->delete();
        Schema::dropIfExists('mantencion_permisos_usuario');
    }
};
