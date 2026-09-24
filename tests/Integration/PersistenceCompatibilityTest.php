<?php

namespace Tests\Integration;

use App\Modulos\Nova\Repositories\NovaUserRepository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RedmineTic\Repositories\RedminePermissionRepository;
use RedmineTic\Repositories\RedmineUserRepository;

class PersistenceCompatibilityTest extends IsolatedMariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('usuarios_nova', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('usuario')->unique();
            foreach (['nombre', 'apellido', 'rut', 'redmine_id', 'email', 'usuario_core', 'rol', 'estado', 'password', 'telegram_id_chat', 'ultimo_login_at'] as $column) {
                $table->string($column)->nullable();
            }
            $table->dateTime('creado_at')->nullable();
            $table->dateTime('actualizado_at')->nullable();
        });
        Schema::create('integraciones_usuario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios_nova');
            $table->string('tipo');
            $table->string('usuario_externo')->nullable();
            $table->text('valor_secreto')->nullable();
            $table->dateTime('creado_at')->nullable();
            $table->dateTime('actualizado_at')->nullable();
            $table->unique(['usuario_id', 'tipo']);
        });
        Schema::create('modulos_nova', function (Blueprint $table): void {
            $table->id();
            $table->string('clave_modulo')->unique();
        });
        DB::table('modulos_nova')->insert(['id' => 1, 'clave_modulo' => 'redmine_tic']);
        Schema::create('permisos_usuario_modulo', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('usuario_id');
            $table->unsignedBigInteger('modulo_id');
            $table->boolean('permitido');
            $table->dateTime('actualizado_at')->nullable();
            $table->unique(['usuario_id', 'modulo_id']);
        });
        Schema::create('redmine_tic_perfiles_usuario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('usuario_id')->unique()->constrained('usuarios_nova');
            $table->string('rol');
            $table->string('estado_usuario');
            $table->unsignedBigInteger('redmine_membership_id')->nullable();
            $table->dateTime('actualizado_at')->nullable();
        });
        Schema::create('redmine_tic_permisos_usuario', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('perfil_id')->constrained('redmine_tic_perfiles_usuario');
            $table->string('clave');
            $table->string('valor');
            $table->dateTime('actualizado_at')->nullable();
            $table->unique(['perfil_id', 'clave']);
        });
        Schema::create('redmine_tic_permisos_rol', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('modulo_id');
            $table->string('rol');
            $table->string('clave');
            $table->string('valor');
            $table->dateTime('actualizado_at')->nullable();
            $table->unique(['modulo_id', 'rol', 'clave']);
        });
        foreach ([1, 2] as $id) {
            DB::table('usuarios_nova')->insert([
                'id' => $id, 'uuid' => 'user-'.$id, 'usuario' => 'user'.$id,
                'nombre' => 'Persona'.$id, 'apellido' => 'Prueba', 'redmine_id' => (string) (40 + $id),
                'rol' => 'usuario', 'estado' => 'activo', 'password' => password_hash('Original123!', PASSWORD_BCRYPT),
                'email' => 'user'.$id.'@example.invalid', 'telegram_id_chat' => 'chat-'.$id,
                'creado_at' => '2026-01-01 00:00:00', 'actualizado_at' => '2026-01-01 00:00:00',
            ]);
            DB::table('redmine_tic_perfiles_usuario')->insert([
                'id' => $id, 'usuario_id' => $id, 'rol' => 'usuario', 'estado_usuario' => 'activo',
                'redmine_membership_id' => 100 + $id, 'actualizado_at' => '2026-01-01 00:00:00',
            ]);
            DB::table('permisos_usuario_modulo')->insert(['usuario_id' => $id, 'modulo_id' => 1, 'permitido' => 1]);
            DB::table('redmine_tic_permisos_usuario')->insert([
                ['perfil_id' => $id, 'clave' => 'historico', 'valor' => 'no'],
                ['perfil_id' => $id, 'clave' => 'usuarios', 'valor' => 'no'],
                ['perfil_id' => $id, 'clave' => 'estadisticas', 'valor' => 'si'],
            ]);
        }
    }

    public function test_edit_does_not_restore_passwords_changed_after_its_read(): void
    {
        $hash = password_hash('Concurrent123!', PASSWORD_BCRYPT);
        $this->afterUsersRead(function () use ($hash): void {
            DB::connection('concurrent')->table('usuarios_nova')->update(['password' => $hash, 'actualizado_at' => '2026-02-01 00:00:00']);
        });
        $result = app(NovaUserRepository::class)->save(['id' => 'user-1', 'name' => 'Editada', 'apellido' => 'Prueba']);
        self::assertTrue($result['ok'], $result['error']);
        self::assertSame([$hash, $hash], DB::table('usuarios_nova')->orderBy('id')->pluck('password')->all());
        self::assertSame('2026-02-01 00:00:00', DB::table('usuarios_nova')->where('id', 2)->value('actualizado_at'));
        self::assertSame('Editada', DB::table('usuarios_nova')->where('id', 1)->value('nombre'));
    }

    public function test_edit_preserves_email_chat_and_integration_records(): void
    {
        DB::table('integraciones_usuario')->insert(['usuario_id' => 1, 'tipo' => 'emach', 'usuario_externo' => 'personal', 'valor_secreto' => 'synthetic-secret']);
        $integrations = $this->rows('integraciones_usuario');
        $other = $this->rows('usuarios_nova')[1];
        $result = app(NovaUserRepository::class)->save(['id' => 'user-1', 'name' => 'Editada', 'apellido' => 'Prueba']);
        self::assertTrue($result['ok'], $result['error']);
        self::assertSame($integrations, $this->rows('integraciones_usuario'));
        self::assertSame($other, $this->rows('usuarios_nova')[1]);
        self::assertSame('user1@example.invalid', DB::table('usuarios_nova')->where('id', 1)->value('email'));
        self::assertSame('chat-1', DB::table('usuarios_nova')->where('id', 1)->value('telegram_id_chat'));
    }

    public function test_conflicting_edit_returns_error_without_overwriting_the_winner(): void
    {
        $this->afterUsersRead(function (): void {
            DB::connection('concurrent')->table('usuarios_nova')->where('id', 1)->update(['nombre' => 'Concurrente']);
        });
        $result = app(NovaUserRepository::class)->save(['id' => 'user-1', 'name' => 'Obsoleta', 'apellido' => 'Prueba']);
        self::assertFalse($result['ok']);
        self::assertSame('Concurrente', DB::table('usuarios_nova')->where('id', 1)->value('nombre'));
    }

    public function test_explicit_password_edit_still_uses_the_existing_validation_and_hashing(): void
    {
        $repo = app(NovaUserRepository::class);
        self::assertFalse($repo->save(['id' => 'user-1', 'password' => 'New123!', 'password_confirmation' => 'different'])['ok']);
        self::assertTrue($repo->save(['id' => 'user-1', 'password' => 'New123!', 'password_confirmation' => 'New123!'])['ok']);
        self::assertTrue(password_verify('New123!', DB::table('usuarios_nova')->where('id', 1)->value('password')));
        self::assertTrue(password_verify('Original123!', DB::table('usuarios_nova')->where('id', 2)->value('password')));
    }

    public function test_permission_failure_rolls_back_all_keys_and_pruning(): void
    {
        $before = $this->rows('redmine_tic_permisos_usuario');
        $this->failUpdate('redmine_tic_permisos_usuario');
        $caught = false;
        try {
            $this->permissions()->savePermissionsToRelational(1, ['historico' => true, 'usuarios' => true]);
        } catch (\Throwable) {
            $caught = true;
        }
        self::assertTrue($caught, 'The void writer must propagate failure so its caller can roll back.');
        self::assertSame($before, $this->rows('redmine_tic_permisos_usuario'));
    }

    public function test_successful_permission_replacement_keeps_scopes_and_other_profiles(): void
    {
        $other = DB::table('redmine_tic_permisos_usuario')->where('perfil_id', 2)->get()->toJson();
        $expected = ['historico' => true, 'mensajes' => 'asignados'];
        $this->permissions()->savePermissionsToRelational(1, $expected);
        self::assertSame($expected, $this->permissions()->allPermissionsFromRelational()[1]);
        self::assertSame($other, DB::table('redmine_tic_permisos_usuario')->where('perfil_id', 2)->get()->toJson());
        $this->permissions()->savePermissionsToRelational(1, []);
        self::assertSame($expected, $this->permissions()->allPermissionsFromRelational()[1], 'Preserve the existing empty-input no-op.');
    }

    public function test_profile_and_permissions_roll_back_together_and_return_false(): void
    {
        $before = [];
        foreach (['usuarios_nova', 'redmine_tic_perfiles_usuario', 'redmine_tic_permisos_usuario', 'permisos_usuario_modulo'] as $table) {
            $before[$table] = $this->rows($table);
        }
        $this->failUpdate('redmine_tic_permisos_usuario');
        self::assertFalse((new RedmineUserRepository('redmine_tic', 'TIC'))->saveUserPermissions('41', 'gestor', ['historico' => true, 'usuarios' => true]));
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $this->rows($table), $table);
        }
    }

    public function test_bulk_role_failure_does_not_save_or_delete_partial_permissions(): void
    {
        $this->seedRoles();
        $before = $this->rows('redmine_tic_permisos_rol');
        $this->failUpdate('redmine_tic_permisos_rol');
        $caught = false;
        try {
            $this->permissions()->saveRolesToRelational(['usuario' => ['historico' => true, 'usuarios' => true]]);
        } catch (\Throwable) {
            $caught = true;
        }
        self::assertTrue($caught);
        self::assertSame($before, $this->rows('redmine_tic_permisos_rol'));
    }

    public function test_single_role_delete_failure_rolls_back_the_upsert_and_returns_false(): void
    {
        $this->seedRoles();
        $before = $this->rows('redmine_tic_permisos_rol');
        DB::unprepared("CREATE TRIGGER reject_prune BEFORE DELETE ON redmine_tic_permisos_rol FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic prune failure'");
        self::assertFalse($this->permissions()->saveRolePermissions('usuario', ['historico' => true]));
        self::assertSame($before, $this->rows('redmine_tic_permisos_rol'));
    }

    public function test_successful_role_save_preserves_other_roles_and_modules(): void
    {
        $this->seedRoles();
        DB::table('redmine_tic_permisos_rol')->insert(['modulo_id' => 2, 'rol' => 'usuario', 'clave' => 'historico', 'valor' => 'no']);
        $other = DB::table('redmine_tic_permisos_rol')->where('rol', 'gestor')->orWhere('modulo_id', 2)->get()->toJson();
        $expected = ['historico' => true, 'mensajes' => 'asignados'];
        self::assertTrue($this->permissions()->saveRolePermissions('usuario', $expected));
        self::assertSame($expected, $this->permissions()->rolesFromRelational()['usuario']);
        self::assertSame($other, DB::table('redmine_tic_permisos_rol')->where('rol', 'gestor')->orWhere('modulo_id', 2)->get()->toJson());
    }

    public function test_missing_permissions_table_does_not_commit_a_profile_change(): void
    {
        $before = $this->rows('redmine_tic_perfiles_usuario');
        Schema::drop('redmine_tic_permisos_usuario');
        self::assertFalse((new RedmineUserRepository('redmine_tic', 'TIC'))->saveUserPermissions('41', 'gestor', ['historico' => true]));
        self::assertSame($before, $this->rows('redmine_tic_perfiles_usuario'));
    }

    public function test_edit_does_not_recreate_a_deleted_account(): void
    {
        $this->afterUsersRead(function (): void {
            DB::connection('concurrent')->table('redmine_tic_permisos_usuario')->where('perfil_id', 1)->delete();
            DB::connection('concurrent')->table('redmine_tic_perfiles_usuario')->where('usuario_id', 1)->delete();
            DB::connection('concurrent')->table('usuarios_nova')->where('id', 1)->delete();
        });
        self::assertFalse(app(NovaUserRepository::class)->save(['id' => 'user-1', 'name' => 'Obsoleta'])['ok']);
        self::assertFalse(DB::table('usuarios_nova')->where('uuid', 'user-1')->exists());
    }

    private function seedRoles(): void
    {
        foreach (['usuario', 'gestor'] as $role) {
            foreach (['historico', 'usuarios'] as $key) {
                DB::table('redmine_tic_permisos_rol')->insert(['modulo_id' => 1, 'rol' => $role, 'clave' => $key, 'valor' => 'no']);
            }
        }
    }

    public function test_deduplication_reads_fresh_values_and_does_not_write_unrelated_accounts(): void
    {
        DB::table('usuarios_nova')->where('id', 1)->update(['rut' => '12.345.678-5']);
        DB::table('usuarios_nova')->where('id', 2)->update(['rut' => '12345678-5', 'rol' => 'admin']);
        DB::table('usuarios_nova')->insert(['id' => 3, 'uuid' => 'untouched', 'usuario' => 'other', 'nombre' => 'Otra', 'apellido' => 'Persona', 'password' => 'synthetic-hash', 'rol' => 'usuario', 'estado' => 'activo']);
        $other = $this->rows('usuarios_nova')[2];
        $newHash = password_hash('Concurrent123!', PASSWORD_BCRYPT);
        $this->afterUsersRead(function () use ($newHash): void {
            DB::connection('concurrent')->table('usuarios_nova')->where('id', 1)->update(['password' => $newHash]);
        });
        $users = app(NovaUserRepository::class)->all();
        self::assertCount(2, $users);
        self::assertSame('admin', DB::table('usuarios_nova')->where('id', 1)->value('rol'));
        self::assertSame($newHash, DB::table('usuarios_nova')->where('id', 1)->value('password'));
        self::assertSame($other, $this->rows('usuarios_nova')[2]);
        self::assertSame(3, DB::table('usuarios_nova')->count(), 'Do not delete historical identities as part of this fix.');
    }

    public function test_deduplication_write_failure_rolls_back_the_merge(): void
    {
        DB::table('usuarios_nova')->where('id', 1)->update(['rut' => '12.345.678-5']);
        DB::table('usuarios_nova')->where('id', 2)->update(['rut' => '12345678-5', 'rol' => 'admin']);
        $before = $this->rows('usuarios_nova');
        DB::unprepared("CREATE TRIGGER reject_merge BEFORE UPDATE ON usuarios_nova FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic merge failure'");
        self::assertCount(1, app(NovaUserRepository::class)->all());
        self::assertSame($before, $this->rows('usuarios_nova'));
    }

    private function failUpdate(string $table): void
    {
        DB::unprepared("CREATE TRIGGER reject_permission BEFORE UPDATE ON {$table} FOR EACH ROW BEGIN IF NEW.clave = 'usuarios' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic permission failure'; END IF; END");
    }

    private function permissions(): RedminePermissionRepository
    {
        return new RedminePermissionRepository('redmine_tic', 'TIC');
    }

    private function rows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function afterUsersRead(callable $write): void
    {
        $done = false;
        DB::listen(function (QueryExecuted $event) use (&$done, $write): void {
            if (! $done && $event->connectionName === 'mysql' && str_contains($event->sql, 'from `usuarios_nova` order by')) {
                $done = true;
                $write();
            }
        });
    }
}
