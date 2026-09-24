<?php

namespace Tests\Integration;

use App\Modulos\RedmineMantencion\Services\MantencionPendientesService;
use App\Services\Database\SchemaBaseline;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RedmineTic\Repositories\RedmineUserRepository;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProjectUserProjectionTest extends IsolatedMariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $baseline = new SchemaBaseline;
        $baseline->bootstrap(DB::connection(), $baseline->read(dirname(__DIR__, 2).'/database/baselines/2026-09-15'));
        app()->instance('encrypter', new class(str_repeat('t', 32), 'AES-256-CBC') extends Encrypter
        {
            public int $decryptions = 0;

            public function decrypt($payload, $unserialize = true)
            {
                $this->decryptions++;

                return parent::decrypt($payload, $unserialize);
            }
        });
        DB::table('modulos_nova')->insert([
            ['id' => 1, 'clave_modulo' => 'redmine_tic', 'nombre' => 'TIC'],
            ['id' => 2, 'clave_modulo' => 'redmine-mantencion', 'nombre' => 'Mantención'],
        ]);
        for ($id = 1; $id <= 12; $id++) {
            $this->insertUser($id);
            DB::table('permisos_usuario_modulo')->insert([
                ['usuario_id' => $id, 'modulo_id' => 1, 'permitido' => $id <= 3 ? 1 : 0, 'rol_modulo' => null],
                ['usuario_id' => $id, 'modulo_id' => 2, 'permitido' => $id <= 8 ? 1 : 0,
                    'rol_modulo' => $id === 2 ? 'gestor' : null],
            ]);
            foreach (['redmine', 'redmine_mantencion', 'redmine_tic', 'core', 'nextcloud', 'emach'] as $type) {
                DB::table('integraciones_usuario')->insert(['usuario_id' => $id, 'tipo' => $type,
                    'usuario_externo' => $type.'-'.$id,
                    'valor_secreto' => $id % 2 ? encrypt($type.'-synthetic-'.$id) : $type.'-plain-'.$id]);
            }
            DB::table('mantencion_permisos_usuario')->insert([
                ['usuario_id' => $id, 'permiso' => 'mensajes', 'valor' => $id % 2 ? 'todos' : 'asignados'],
                ['usuario_id' => $id, 'permiso' => 'usuarios', 'valor' => $id % 2 ? '1' : ''],
            ]);
        }
        // Load the real functions without sessions, .env or legacy bootstrap.
        $source = file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/controllers/auth.php');
        foreach (['auth_central_users_for_mantencion', 'auth_norm_key', 'auth_find_user', 'auth_find_user_by_id'] as $name) {
            $this->loadFunction($source, $name);
        }
        $source = file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/controllers/dashboard.php');
        foreach (['dashboard_active_mantencion_users', 'dashboard_user_is_active'] as $name) {
            $this->loadFunction($source, $name);
        }
    }

    private function loadFunction(string $source, string $name): void
    {
        $start = strpos($source, 'function '.$name.'(');
        self::assertNotFalse($start);
        $end = strpos($source, "\n}", $start) + 2;
        eval(substr($source, $start, $end - $start));
    }

    private function insertUser(int|string $id): void
    {
        DB::table('usuarios_nova')->insert(['id' => $id, 'uuid' => 'user-'.$id, 'usuario' => 'user'.$id,
            'nombre' => 'Persona '.$id, 'apellido' => 'Pérez', 'redmine_id' => (int) $id < 1000 ? (string) $id : null,
            'rol' => match ($id) {
                9 => 'admin', 10 => 'administrador', 11 => 'root', default => 'usuario'
            },
            'estado' => $id === 3 ? 'baneado' : ($id === 4 ? 'active' : 'activo'),
            'password' => 'synthetic-hash-'.$id, 'usuario_core' => $id === 1 ? 'central-core' : null,
        ]);
        DB::table('redmine_tic_perfiles_usuario')->insert(['id' => $id, 'usuario_id' => $id,
            'rol' => 'usuario', 'estado_usuario' => $id === 2 ? 'baneado' : 'activo']);
        DB::table('redmine_tic_permisos_usuario')->insert([
            ['perfil_id' => $id, 'clave' => 'mensajes', 'valor' => $id === 1 ? 'todos' : 'asignados'],
            ['perfil_id' => $id, 'clave' => 'usuarios', 'valor' => $id === 1 ? 'si' : 'no'],
        ]);
    }

    private function withoutSecrets(array $users): array
    {
        return array_map(static fn ($user): array => array_replace($user, [
            'api' => '', 'password' => '', 'core_pass_enc' => '', 'nextcloud_pass_enc' => '',
        ]), $users);
    }

    private function ticReference(bool $credentials): array
    {
        $reference = require __DIR__.'/fixtures/tic_user_projection_reference.php';

        return $reference->call(new RedmineUserRepository('redmine_tic', 'TIC'), $credentials);
    }

    public function test_mantencion_projection_preserves_identity_access_roles_permissions_and_external_names(): void
    {
        foreach ([true, false] as $admins) {
            $full = auth_central_users_for_mantencion($admins);
            self::assertNotEmpty($full);
            self::assertSame($full, auth_central_users_for_mantencion($admins, true));
            app('encrypter')->decryptions = 0;
            DB::flushQueryLog();
            DB::enableQueryLog();
            self::assertSame($this->withoutSecrets($full), auth_central_users_for_mantencion($admins, false));
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            self::assertSame(0, app('encrypter')->decryptions);
            foreach ($queries as $query) {
                self::assertStringNotContainsString('password', $query['query']);
                self::assertStringNotContainsString('valor_secreto', $query['query']);
                self::assertStringNotContainsString('select *', $query['query']);
            }
        }
        $indexed = array_column(auth_central_users_for_mantencion(true, false), null, 'id');
        self::assertSame('gestor', $indexed[2]['rol']);
        self::assertSame('administrador', $indexed[9]['rol']);
        self::assertTrue($indexed[11]['permisos']['all']);
        self::assertArrayNotHasKey(12, $indexed);
        self::assertSame('central-core', $indexed[1]['core_user']);
        self::assertSame('core-2', $indexed[2]['core_user']);
        self::assertSame('nextcloud-2', $indexed[2]['nextcloud_user']);
        self::assertSame('redmine-synthetic-1', auth_find_user_by_id('1')['api']);
        self::assertSame('synthetic-hash-1', auth_find_user('user1')['password']);
    }

    public function test_dashboard_and_manual_selectors_keep_labels_sorting_and_active_states(): void
    {
        $expected = [];
        foreach ($this->withoutSecrets(auth_central_users_for_mantencion()) as $user) {
            if (! dashboard_user_is_active($user)) {
                continue;
            }
            $user['nombre_completo'] = trim($user['nombre'].' '.$user['apellido']);
            $expected[] = $user;
        }
        usort($expected, fn ($a, $b) => strcasecmp($a['nombre_completo'], $b['nombre_completo']));
        self::assertSame($expected, dashboard_active_mantencion_users());
        self::assertSame(array_map(fn ($u) => ['id' => $u['id'], 'nombre' => $u['nombre_completo']], $expected),
            (new MantencionPendientesService)->users());
        self::assertNotContains('3', array_column($expected, 'id'));
        self::assertContains('4', array_column($expected, 'id'));
        self::assertContains('11', array_column($expected, 'id'));
    }

    public function test_mantencion_tied_names_keep_original_query_order_and_strip_secrets(): void
    {
        DB::table('usuarios_nova')->where('id', 1)->update(['nombre' => 'Ána', 'apellido' => 'Pérez']);
        DB::table('usuarios_nova')->where('id', 2)->update(['nombre' => 'ana', 'apellido' => 'Perez']);
        DB::enableQueryLog();
        $full = auth_central_users_for_mantencion();
        $originalQueries = DB::getQueryLog();
        DB::flushQueryLog();
        app('encrypter')->decryptions = 0;
        $actual = auth_central_users_for_mantencion(true, false);
        $projectedQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $userReads = static fn (array $queries): array => array_values(array_map(
            fn ($query) => [$query['query'], $query['bindings']],
            array_filter($queries, fn ($query) => str_starts_with($query['query'], 'select distinct `usuarios_nova`.`id`'))
        ));
        // The full reader defines no tie breaker for collation-equivalent names.
        // Verify its exact SQL/bindings and all fields, without asserting an
        // undefined order between independent executions of that same query.
        self::assertCount(1, $userReads($originalQueries));
        self::assertSame($userReads($originalQueries), $userReads($projectedQueries));
        $byId = static function (array $rows): array {
            $rows = array_column($rows, null, 'id');
            ksort($rows);

            return $rows;
        };
        self::assertSame($byId($this->withoutSecrets($full)), $byId($actual));
        self::assertGreaterThan(0, app('encrypter')->decryptions, 'Collation ties deliberately retain the original reader.');
    }

    public function test_mantencion_optional_columns_and_missing_module_keep_existing_rules(): void
    {
        Schema::table('usuarios_nova', fn ($table) => $table->string('email')->nullable());
        DB::table('usuarios_nova')->where('id', 1)->update(['email' => 'test@example.invalid']);
        Schema::table('permisos_usuario_modulo', fn ($table) => $table->dropColumn('rol_modulo'));
        foreach ([true, false] as $admins) {
            self::assertSame($this->withoutSecrets(auth_central_users_for_mantencion($admins)), auth_central_users_for_mantencion($admins, false));
        }
        DB::table('modulos_nova')->where('id', 2)->update(['clave_modulo' => 'other']);
        self::assertSame([], auth_central_users_for_mantencion(false, false));
        self::assertCount(3, auth_central_users_for_mantencion(true, false));
        self::assertSame($this->withoutSecrets(auth_central_users_for_mantencion()), auth_central_users_for_mantencion(true, false));
    }

    public function test_tic_member_scoped_profiles_match_previous_full_reader_and_refresh_access(): void
    {
        foreach ([true, false] as $credentials) {
            $expected = $this->ticReference($credentials);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $actual = (new RedmineUserRepository('redmine_tic', 'TIC'))->projectUsers($credentials);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            self::assertSame($expected, $actual);
            self::assertCount(3, $actual);
            foreach (['redmine_tic_perfiles_usuario' => 'usuario_id', 'redmine_tic_permisos_usuario' => 'perfil_id'] as $table => $column) {
                $reads = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], 'from `'.$table.'`')));
                self::assertCount(1, $reads);
                self::assertStringContainsString('`'.$column.'` in (', $reads[0]['query']);
            }
        }
        $repo = new RedmineUserRepository('redmine_tic', 'TIC');
        $repo->projectUsers(false);
        DB::table('permisos_usuario_modulo')->where('modulo_id', 1)->where('usuario_id', 1)->update(['permitido' => 0]);
        DB::table('permisos_usuario_modulo')->where('modulo_id', 1)->where('usuario_id', 4)->update(['permitido' => 1]);
        DB::table('redmine_tic_permisos_usuario')->where('perfil_id', 4)->where('clave', 'usuarios')->update(['valor' => 'si']);
        self::assertSame($this->ticReference(false), $repo->projectUsers(false));
        self::assertNotContains('1', array_column($repo->projectUsers(false), 'id'));
        DB::table('permisos_usuario_modulo')->where('modulo_id', 1)->update(['permitido' => 0]);
        self::assertSame([], $repo->projectUsers(false));
    }

    public function test_tic_missing_profiles_permissions_and_oversized_ids_keep_fallbacks(): void
    {
        DB::table('redmine_tic_perfiles_usuario')->where('usuario_id', 3)->delete();
        DB::table('redmine_tic_permisos_usuario')->where('perfil_id', 2)->delete();
        self::assertSame($this->ticReference(false), (new RedmineUserRepository('redmine_tic', 'TIC'))->projectUsers(false));
        foreach (['9223372036854775807', '9223372036854775808'] as $id) {
            $this->insertUser($id);
            DB::table('permisos_usuario_modulo')->insert(['usuario_id' => $id, 'modulo_id' => 1, 'permitido' => 1]);
        }
        foreach ([true, false] as $credentials) {
            self::assertSame($this->ticReference($credentials), (new RedmineUserRepository('redmine_tic', 'TIC'))->projectUsers($credentials));
        }
    }

    public function test_projections_reduce_memory_for_unrelated_permissions_and_large_credentials(): void
    {
        for ($id = 100; $id < 400; $id++) {
            $this->insertUser($id);
            DB::table('permisos_usuario_modulo')->insert(['usuario_id' => $id, 'modulo_id' => 2, 'permitido' => 1]);
            DB::table('integraciones_usuario')->insert(['usuario_id' => $id, 'tipo' => 'emach', 'valor_secreto' => str_repeat('synthetic', 5000)]);
            $permissions = [];
            for ($key = 0; $key < 35; $key++) {
                $permissions[] = ['perfil_id' => $id, 'clave' => 'permission-'.$key, 'valor' => 'si'];
            }
            DB::table('redmine_tic_permisos_usuario')->insert($permissions);
        }
        unset($permissions);
        $measure = static function (callable $read): array {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $memory = memory_get_usage();
            $start = hrtime(true);
            $value = $read();

            return [$value, ['elapsed_ms' => round((hrtime(true) - $start) / 1e6, 3), 'peak_bytes' => memory_get_peak_usage() - $memory]];
        };
        [$expected, $ticBefore] = $measure(fn () => $this->ticReference(false));
        [$actual, $ticAfter] = $measure(fn () => (new RedmineUserRepository('redmine_tic', 'TIC'))->projectUsers(false));
        self::assertSame($expected, $actual);
        self::assertLessThan($ticBefore['peak_bytes'] / 3, $ticAfter['peak_bytes']);
        [$expected, $mantencionBefore] = $measure(fn () => $this->withoutSecrets(auth_central_users_for_mantencion()));
        [$actual, $mantencionAfter] = $measure(fn () => auth_central_users_for_mantencion(true, false));
        self::assertSame($expected, $actual);
        self::assertLessThan($mantencionBefore['peak_bytes'] / 3, $mantencionAfter['peak_bytes']);
        file_put_contents(sys_get_temp_dir().'/nova-p06-project-users-metrics.json',
            json_encode(compact('ticBefore', 'ticAfter', 'mantencionBefore', 'mantencionAfter')), LOCK_EX);
    }
}
