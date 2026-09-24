<?php

namespace Tests\Integration;

use App\Modulos\Nova\Repositories\NovaAccessRepository;
use App\Modulos\Nova\Repositories\NovaUserRepository;
use App\Modulos\RedmineMantencion\Services\MantencionUsuariosCentralService;
use App\Modulos\RedmineMantencion\Services\MantencionUsuariosRedmineSyncService;
use App\Modulos\RedmineMantencion\Services\MantencionUsuariosService;
use App\Modulos\RedmineMantencion\Services\MantencionUsuariosStorageService;
use App\Services\Database\SchemaBaseline;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Encryption\Encrypter;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdministrativeUserReadTest extends IsolatedMariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $baseline = new SchemaBaseline;
        $baseline->bootstrap(DB::connection(), $baseline->read(dirname(__DIR__, 2).'/database/baselines/2026-09-15'));
        app()->instance('encrypter', new Encrypter(str_repeat('t', 32), 'AES-256-CBC'));
        config(['cache.default' => 'array', 'cache.stores.array' => ['driver' => 'array'],
            'modules' => ['redmine-mantencion' => ['name' => 'Mantención']], 'nova.module_admin_roles' => ['admin', 'root']]);
        app()->register(CacheServiceProvider::class);
        DB::table('modulos_nova')->insert(['id' => 1, 'clave_modulo' => 'redmine-mantencion', 'nombre' => 'Mantención']);
        for ($id = 1; $id <= 8; $id++) {
            $this->insertUser($id);
            foreach (['emach', 'nextcloud', 'core', 'redmine', 'redmine_mantencion'] as $type) {
                DB::table('integraciones_usuario')->insert(['usuario_id' => $id, 'tipo' => $type,
                    'usuario_externo' => $id === 5 ? '' : $type.'-'.$id,
                    'valor_secreto' => match ($id) {
                        1 => '', 2 => "\0\t\n\r\x0b ", 3 => '0', 4 => encrypt(''), default => encrypt('secret-'.$id)
                    }]);
            }
            DB::table('mantencion_permisos_usuario')->insert(['usuario_id' => $id, 'permiso' => 'mensajes', 'valor' => 'asignados']);
        }
        foreach (['auth.php' => ['auth_mantencion_administration_user', 'auth_central_users_for_mantencion'],
            'usuarios.php' => ['usuarios_text_key', 'usuarios_detect_repeated_suffix']] as $file => $names) {
            $source = file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/controllers/'.$file);
            foreach ($names as $name) {
                $start = strpos($source, 'function '.$name.'(');
                self::assertNotFalse($start);
                $end = strpos($source, "\n}", $start) + 2;
                eval(substr($source, $start, $end - $start));
            }
        }
        eval('function auth_get_user_id() { return $GLOBALS["admin_test_user"] ?? "1"; }
            function config_mantencion_repository() { return $GLOBALS["admin_test_config"]; }
            function csrf_validate() { $GLOBALS["admin_test_csrf"] = true; }');
        $GLOBALS['admin_test_config'] = new class
        {
            public array $values = [];

            public array $writes = [];

            public function loadAll(): array
            {
                return $this->values;
            }

            public function saveAll(array $values): void
            {
                $this->values = $values;
                $this->writes[] = $values;
            }
        };
    }

    private function insertUser(int $id): void
    {
        DB::table('usuarios_nova')->insert(['id' => $id, 'uuid' => 'user-'.$id, 'usuario' => 'user'.$id,
            'redmine_id' => (string) $id, 'nombre' => 'Persona '.$id, 'apellido' => 'Pérez',
            'rol' => $id === 8 ? 'admin' : 'usuario', 'estado' => $id === 7 ? 'baneado' : 'activo',
            'password' => 'synthetic-hash-'.$id, 'telegram_id_chat' => $id % 2 ? 'chat-'.$id : null]);
        DB::table('permisos_usuario_modulo')->insert(['usuario_id' => $id, 'modulo_id' => 1, 'permitido' => 1, 'rol_modulo' => 'gestor']);
    }

    private function novaDisplay(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['password'] = '';
            foreach (['emach', 'nextcloud'] as $type) {
                $field = $type.'_credentials';
                $row['has_'.$field] = trim((string) ($row[$field]['user'] ?? '')) !== '' && trim((string) ($row[$field]['password'] ?? '')) !== '';
                if (isset($row[$field])) {
                    $row[$field]['password'] = '';
                }
            }
        }

        return $rows;
    }

    private function mantencionDisplay(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['has_api_credentials'] = trim($row['api']) !== '';
            $row['has_core_credentials'] = trim($row['core_user']) !== '' && trim($row['core_pass_enc']) !== '';
            $row['has_nextcloud_credentials'] = trim($row['nextcloud_user']) !== '' && trim($row['nextcloud_pass_enc']) !== '';
            foreach (['api', 'password', 'core_pass_enc', 'nextcloud_pass_enc'] as $field) {
                $row[$field] = '';
            }
        }

        return $rows;
    }

    private function keyedRows(array $rows): array
    {
        // Preserve row ordering and strict value types; display metadata may
        // be appended before or after the legacy default fields.
        return array_map(static function (array $row): array {
            ksort($row);

            return $row;
        }, $rows);
    }

    public function test_nova_keeps_display_fields_badges_and_access_without_transferring_secrets(): void
    {
        DB::table('integraciones_usuario')->insert(['usuario_id' => 3, 'tipo' => "\temach\n", 'usuario_externo' => 'last-wins', 'valor_secreto' => '']);
        $repo = app(NovaUserRepository::class);
        $expected = $this->novaDisplay($repo->all());
        DB::enableQueryLog();
        self::assertSame($expected, $repo->allForAdministration());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            self::assertStringNotContainsString('select *', $query['query']);
            self::assertStringNotContainsString('`password`', $query['query']);
        }
        $matrix = app(NovaAccessRepository::class)->matrix();
        $matrix['users'] = $this->novaDisplay($matrix['users']);
        foreach ($matrix['matrix'] as &$entry) {
            $entry['user'] = $this->novaDisplay([$entry['user']])[0];
        }
        unset($entry);
        self::assertSame($matrix, app(NovaAccessRepository::class)->matrix(true));
        self::assertSame('synthetic-hash-1', $repo->find('user1')['password']);
    }

    public function test_nova_duplicate_repair_retains_writes_and_failure_rollback(): void
    {
        DB::table('usuarios_nova')->where('id', 1)->update(['rut' => '12.345.678-5']);
        DB::table('usuarios_nova')->where('id', 2)->update(['rut' => '12 345 678 5', 'rol' => 'admin']);
        $secret = encrypt('real-synthetic-password');
        DB::table('integraciones_usuario')->where('usuario_id', 2)->where('tipo', 'emach')->update(['valor_secreto' => $secret]);
        $original = (array) DB::table('usuarios_nova')->where('id', 8)->first();
        DB::beginTransaction();
        try {
            $expected = $this->novaDisplay(app(NovaUserRepository::class)->all());
        } finally {
            DB::rollBack();
        }
        $rows = app(NovaUserRepository::class)->allForAdministration();
        self::assertCount(7, $rows);
        self::assertSame('admin', DB::table('usuarios_nova')->where('id', 1)->value('rol'));
        self::assertSame($secret, DB::table('integraciones_usuario')->where('usuario_id', 1)->where('tipo', 'emach')->value('valor_secreto'));
        self::assertSame('synthetic-hash-1', DB::table('usuarios_nova')->where('id', 1)->value('password'));
        self::assertSame($original, (array) DB::table('usuarios_nova')->where('id', 8)->first());
        self::assertSame($expected, $rows);

        DB::table('usuarios_nova')->where('id', 1)->update(['rol' => 'usuario']);
        $before = DB::table('usuarios_nova')->orderBy('id')->get()->toJson();
        DB::unprepared("CREATE TRIGGER reject_admin_merge BEFORE UPDATE ON usuarios_nova FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic merge failure'");
        self::assertCount(7, app(NovaUserRepository::class)->allForAdministration());
        self::assertSame($before, DB::table('usuarios_nova')->orderBy('id')->get()->toJson());
    }

    public function test_tied_names_retain_both_original_readers(): void
    {
        DB::table('usuarios_nova')->where('id', 1)->update(['nombre' => 'Ána']);
        DB::table('usuarios_nova')->where('id', 2)->update(['nombre' => 'ana']);
        self::assertSame($this->novaDisplay(app(NovaUserRepository::class)->all()), app(NovaUserRepository::class)->allForAdministration());
        DB::enableQueryLog();
        $expected = $this->mantencionDisplay(auth_central_users_for_mantencion(false));
        $originalQueries = DB::getQueryLog();
        DB::flushQueryLog();
        $actual = auth_central_users_for_mantencion(false, true, true);
        $projectedQueries = DB::getQueryLog();
        DB::disableQueryLog();
        $userReads = static fn (array $queries): array => array_values(array_map(
            fn ($query) => [$query['query'], $query['bindings']],
            array_filter($queries, fn ($query) => str_starts_with($query['query'], 'select distinct `usuarios_nova`.`id`'))
        ));
        // DISTINCT + ORDER BY nombre/apellido does not define the order within
        // a collation tie, even between executions of the same full query.
        // Assert the exact fallback SQL/bindings and every displayed field.
        self::assertCount(1, $userReads($originalQueries));
        self::assertSame($userReads($originalQueries), $userReads($projectedQueries));
        $byId = static function (array $rows): array {
            $rows = array_column($rows, null, 'id');
            ksort($rows);

            return $rows;
        };
        self::assertSame($byId($expected), $byId($actual));
    }

    public function test_nova_projection_is_consistent_during_concurrent_credential_changes(): void
    {
        $expected = $this->novaDisplay(app(NovaUserRepository::class)->all());
        $dispatcher = new Dispatcher(app());
        DB::connection()->setEventDispatcher($dispatcher);
        $changed = false;
        $dispatcher->listen(QueryExecuted::class, function ($event) use (&$changed): void {
            if (! $changed && str_contains($event->sql, 'from `usuarios_nova`') && str_contains($event->sql, '`uuid`')) {
                $changed = true;
                DB::connection('concurrent')->table('integraciones_usuario')->where('usuario_id', 1)->where('tipo', 'emach')->update(['valor_secreto' => 'new-secret']);
            }
        });
        self::assertSame($expected, app(NovaUserRepository::class)->allForAdministration());
        self::assertTrue($changed);
        self::assertNotSame($expected, app(NovaUserRepository::class)->allForAdministration());
    }

    public function test_mantencion_badges_distinguish_encrypted_empty_and_plaintext_credentials(): void
    {
        $expected = $this->mantencionDisplay(auth_central_users_for_mantencion(false));
        DB::enableQueryLog();
        $actual = auth_central_users_for_mantencion(false, true, true);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertSame($expected, $actual);
        $users = array_column($actual, null, 'id');
        self::assertFalse($users[4]['has_api_credentials'], 'Encrypted empty Redmine tokens are not configured.');
        self::assertTrue($users[4]['has_nextcloud_credentials'], 'The existing badge checks stored ciphertext, not decrypted NC content.');
        self::assertTrue($users[3]['has_api_credentials'], 'Plaintext zero is non-empty.');
        self::assertFalse($users[2]['has_core_credentials']);
        foreach ($queries as $query) {
            self::assertStringNotContainsString('select *', $query['query']);
            self::assertStringNotContainsString('`password`', $query['query']);
        }
    }

    private function mantencionServices(): array
    {
        $central = new class extends MantencionUsuariosCentralService
        {
            public array $saved = [];

            public function usuarios_central_upsert(array $user, string $moduleKey = 'redmine-mantencion'): ?int
            {
                $this->saved[] = $user;

                return 1;
            }
        };
        $storage = new class($central) extends MantencionUsuariosStorageService
        {
            public function usuarios_consume_flash(): ?string
            {
                return null;
            }
        };

        return [$central, $storage, new MantencionUsuariosService($central, $storage, new MantencionUsuariosRedmineSyncService($central, $storage))];
    }

    public function test_mantencion_get_uses_projection_and_post_keeps_complete_credentials(): void
    {
        [$central, $storage, $service] = $this->mantencionServices();
        $complete = $storage->load_usuarios('');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        self::assertSame($this->keyedRows($this->mantencionDisplay($complete)), $this->keyedRows($service->handle_usuarios()[0]));
        self::assertSame([], $central->saved);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'create'];
        self::assertSame($complete, $service->handle_usuarios()[0]);
        self::assertTrue($GLOBALS['admin_test_csrf']);
    }

    public function test_mantencion_migration_reloads_full_rows_and_preserves_original_side_effects(): void
    {
        [$central, $storage, $service] = $this->mantencionServices();
        // Exercise missing/current credentials, absent user and missing session.
        DB::table('integraciones_usuario')->where('usuario_id', 1)->where('tipo', 'nextcloud')->delete();
        foreach (['1', '6', 'missing', ''] as $userId) {
            $GLOBALS['admin_test_user'] = $userId;
            $config = $GLOBALS['admin_test_config'];
            $config->values = ['nextcloud_admin_user' => 'legacy-user', 'nextcloud_admin_pass_enc' => 'legacy-synthetic-secret'];
            $config->writes = [];
            $expected = $storage->load_usuarios('');
            $changed = $storage->usuarios_migrate_global_nextcloud_credentials($expected);
            $writes = $config->writes;
            $config->values = ['nextcloud_admin_user' => 'legacy-user', 'nextcloud_admin_pass_enc' => 'legacy-synthetic-secret'];
            $config->writes = [];
            $central->saved = [];
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $actual = $service->handle_usuarios()[0];
            self::assertSame($this->keyedRows($userId === '' ? $this->mantencionDisplay($expected) : $expected), $this->keyedRows($actual));
            self::assertSame($writes, $config->writes);
            self::assertSame($changed ? $expected : [], $central->saved);
            foreach ($central->saved as $row) {
                self::assertArrayNotHasKey('has_api_credentials', $row);
            }
        }
    }

    public function test_administrative_projections_reduce_large_credential_memory(): void
    {
        for ($id = 100; $id < 350; $id++) {
            $this->insertUser($id);
            foreach (['emach', 'core', 'nextcloud'] as $type) {
                DB::table('integraciones_usuario')->insert(['usuario_id' => $id, 'tipo' => $type,
                    'usuario_externo' => $type.'-'.$id, 'valor_secreto' => str_repeat('synthetic', 2500)]);
            }
        }
        $measure = static function (callable $read): array {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $start = hrtime(true);
            $memory = memory_get_usage();
            $rows = $read();

            return [$rows, ['elapsed_ms' => round((hrtime(true) - $start) / 1e6, 3), 'peak_bytes' => memory_get_peak_usage() - $memory]];
        };
        [$expected, $novaBefore] = $measure(fn () => $this->novaDisplay(app(NovaUserRepository::class)->all()));
        [$actual, $novaAfter] = $measure(fn () => app(NovaUserRepository::class)->allForAdministration());
        self::assertSame($expected, $actual);
        self::assertLessThan($novaBefore['peak_bytes'] / 3, $novaAfter['peak_bytes']);
        [$expected, $mantencionBefore] = $measure(fn () => $this->mantencionDisplay(auth_central_users_for_mantencion(false)));
        [$actual, $mantencionAfter] = $measure(fn () => auth_central_users_for_mantencion(false, true, true));
        self::assertSame($expected, $actual);
        self::assertLessThan($mantencionBefore['peak_bytes'] / 3, $mantencionAfter['peak_bytes']);
        file_put_contents(sys_get_temp_dir().'/nova-p06-admin-users-metrics.json', json_encode(compact('novaBefore', 'novaAfter', 'mantencionBefore', 'mantencionAfter')), LOCK_EX);
    }
}
