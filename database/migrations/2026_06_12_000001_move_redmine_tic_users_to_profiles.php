<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('redmine_tic_perfiles_usuario')) {
            Schema::create('redmine_tic_perfiles_usuario', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('usuario_id')->constrained('usuarios_nova')->cascadeOnDelete();
                $table->string('rol', 40)->default('usuario')->index();
                $table->string('estado_usuario', 40)->default('activo')->index();
                $table->json('permisos')->nullable();
                $table->unsignedInteger('redmine_membership_id')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
                $table->unique('usuario_id', 'uq_redmine_tic_perfil_usuario');
            });
        }

        if (!Schema::hasTable('redmine_tic_usuarios') || !Schema::hasTable('usuarios_nova')) {
            return;
        }

        $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');

        foreach (DB::table('redmine_tic_usuarios')->get() as $row) {
            $redmineId = trim((string) ($row->redmine_id ?? ''));
            if ($redmineId === '') {
                continue;
            }

            $user = DB::table('usuarios_nova')->where('redmine_id', $redmineId)->first();
            if (!$user) {
                $username = trim((string) ($row->rut_sin_dv ?? '')) ?: $redmineId;
                $name = trim((string) ($row->nombre ?? '')) ?: 'Redmine';
                $lastName = trim((string) ($row->apellido ?? '')) ?: 'Usuario';

                $userId = DB::table('usuarios_nova')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'usuario' => $this->uniqueUsername($username),
                    'rut' => trim((string) ($row->rut ?? '')) ?: null,
                    'redmine_id' => (int) $redmineId,
                    'nombre' => $name,
                    'apellido' => $lastName,
                    'rol' => $this->normalizeNovaRole((string) ($row->rol ?? 'usuario')),
                    'estado' => $this->normalizeStatus((string) ($row->estado_usuario ?? 'activo')),
                    'password' => Hash::make(Str::random(40)),
                    'creado_at' => now(),
                    'actualizado_at' => now(),
                ]);
            } else {
                $userId = (int) $user->id;
            }

            DB::table('redmine_tic_perfiles_usuario')->updateOrInsert(
                ['usuario_id' => $userId],
                [
                    'rol' => trim((string) ($row->rol ?? 'usuario')) ?: 'usuario',
                    'estado_usuario' => $this->normalizeStatus((string) ($row->estado_usuario ?? 'activo')),
                    'permisos' => $row->permisos,
                    'redmine_membership_id' => $row->redmine_membership_id,
                    'actualizado_at' => now(),
                ]
            );

            $apiToken = trim((string) ($row->api_token ?? ''));
            if ($apiToken !== '') {
                DB::table('integraciones_usuario')->updateOrInsert(
                    ['usuario_id' => $userId, 'tipo' => 'redmine_tic'],
                    [
                        'usuario_externo' => $redmineId,
                        'valor_secreto' => $apiToken,
                        'actualizado_at' => now(),
                    ]
                );
            }

            $chatId = trim((string) ($row->telegram_chat_id ?? ''));
            if ($chatId !== '') {
                DB::table('integraciones_usuario')->updateOrInsert(
                    ['usuario_id' => $userId, 'tipo' => 'telegram'],
                    [
                        'chat_id' => $chatId,
                        'actualizado_at' => now(),
                    ]
                );
            }

            if ($moduleId !== null) {
                DB::table('permisos_usuario_modulo')->updateOrInsert(
                    ['usuario_id' => $userId, 'modulo_id' => (int) $moduleId],
                    ['permitido' => 1, 'actualizado_at' => now()]
                );
            }
        }

        Schema::dropIfExists('redmine_tic_usuarios');
    }

    public function down(): void
    {
        if (!Schema::hasTable('redmine_tic_usuarios')) {
            Schema::create('redmine_tic_usuarios', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('redmine_id')->unique();
                $table->string('rut_sin_dv', 40)->nullable();
                $table->string('rut', 40)->nullable();
                $table->string('nombre', 120)->nullable();
                $table->string('apellido', 160)->nullable();
                $table->string('telegram_chat_id', 80)->nullable();
                $table->string('api_token', 255)->nullable();
                $table->string('rol', 40)->default('usuario')->index();
                $table->string('estado_usuario', 40)->default('activo')->index();
                $table->json('permisos')->nullable();
                $table->unsignedInteger('redmine_membership_id')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            });
        }
    }

    private function uniqueUsername(string $username): string
    {
        $base = trim($username) !== '' ? trim($username) : (string) Str::uuid();
        $candidate = $base;
        $suffix = 2;

        while (DB::table('usuarios_nova')->where('usuario', $candidate)->exists()) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function normalizeStatus(string $status): string
    {
        return in_array(strtolower(trim($status)), ['baneado', 'bloqueado', 'inactivo'], true) ? 'baneado' : 'activo';
    }

    private function normalizeNovaRole(string $role): string
    {
        return in_array(strtolower(trim($role)), ['admin', 'administrador', 'gestor', 'root'], true) ? 'admin' : 'usuario';
    }
};
