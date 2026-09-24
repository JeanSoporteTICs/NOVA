<?php

namespace Tests\Integration;

use App\Modulos\Nova\Repositories\NovaIdentityLookupRepository;
use App\Modulos\Nova\Repositories\NovaUserRepository;
use App\Modulos\Nova\Repositories\UserIntegrationRepository;
use App\Modulos\Nova\Services\NovaUserService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RedmineTic\Repositories\RedmineUserRepository;

final class UserReadQueryTest extends IsolatedMariaDbTestCase
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

    public function test_lookup_preserves_all_login_identifiers_and_first_normalized_match(): void
    {
        DB::table('usuarios_nova')->where('id', 1)->update(['usuario' => '12.345.678', 'rut' => '12.345.678-K', 'usuario_core' => 'CORE-Ana']);
        $repo = app(NovaUserRepository::class);
        $expected = $repo->all()[0];
        foreach (['user-1', 'USER 1', '12345678', '12.345.678', '12345678k', 'CORE Ana', '41'] as $needle) {
            self::assertSame($expected, $repo->find($needle), $needle);
        }
        foreach (['Persona1', 'user1@example.invalid', '', 'no-existe'] as $needle) {
            self::assertNull($repo->find($needle));
        }
    }

    public function test_sql_identity_matches_byte_normalization_without_unicode_collation_aliases(): void
    {
        $service = app(NovaUserService::class);
        $repo = app(NovaUserRepository::class);
        $values = ['José Pérez', 'ÁÉÍÓÚñ', 'USER-%_ 123', "\tCORE\nA\r", 'İstanbul', 'Key', 'Straße', 'abc００1', '00123'];
        foreach ($values as $value) {
            DB::table('usuarios_nova')->where('id', 1)->update(['usuario_core' => $value]);
            $needle = $service->normalizeIdentity($value);
            $lookup = app(NovaIdentityLookupRepository::class)->lookup($needle);
            self::assertFalse($lookup['ambiguous']);
            if ($needle !== '') {
                self::assertSame(1, $lookup['id'], $value);
                self::assertSame('user-1', $repo->find($value)['id']);
            }
        }
        DB::table('usuarios_nova')->where('id', 1)->update(['usuario_core' => 'José']);
        self::assertNull($repo->find('Jose'), 'Login removes accented bytes; it does not transliterate them.');
        self::assertSame('user-1', $repo->find('Jos')['id']);
        DB::enableQueryLog();
        $repo->find('user1');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertNotEmpty(array_filter($queries, fn ($q) => str_contains($q['query'], 'REGEXP_REPLACE')));
        self::assertEmpty(array_filter($queries, fn ($q) => str_starts_with($q['query'], 'select `id`, `uuid`, `usuario`')));
    }

    public function test_sql_lookup_keeps_first_match_and_duplicate_name_fallback(): void
    {
        $lookup = app(NovaIdentityLookupRepository::class);
        DB::table('usuarios_nova')->where('id', 2)->update(['usuario_core' => 'user1', 'nombre' => 'A primera']);
        self::assertSame(2, $lookup->lookup('user1')['id']);
        // A duplicate unrelated to the requested identifier still invokes the old global merge.
        DB::table('usuarios_nova')->update(['rut' => null, 'usuario_core' => '', 'redmine_id' => '51', 'nombre' => 'Igual', 'apellido' => 'Prueba']);
        DB::table('usuarios_nova')->where('id', 1)->update(['usuario' => '...']);
        DB::table('usuarios_nova')->where('id', 2)->update(['usuario' => '---']);
        self::assertTrue($lookup->lookup('51')['ambiguous']);
    }

    public function test_find_loads_credentials_only_for_the_selected_account(): void
    {
        foreach ([1, 2] as $id) {
            DB::table('integraciones_usuario')->insert(['usuario_id' => $id, 'tipo' => 'emach', 'usuario_externo' => 'external-'.$id, 'valor_secreto' => 'synthetic-'.$id]);
        }
        DB::enableQueryLog();
        $user = app(NovaUserRepository::class)->find('user-1');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertSame('synthetic-1', $user['emach_credentials']['password']);
        $integrations = array_values(array_filter($queries, fn ($q) => str_contains($q['query'], 'select * from `integraciones_usuario`')));
        self::assertCount(1, $integrations);
        self::assertSame([1], $integrations[0]['bindings']);
        self::assertStringContainsString('where `usuario_id` in (?)', $integrations[0]['query']);
        self::assertStringNotContainsString('password', $queries[0]['query']);
    }

    public function test_compact_keys_preserve_empty_long_and_ambiguous_identities(): void
    {
        $reference = require __DIR__.'/fixtures/identity_lookup_reference.php';
        $lookup = new NovaIdentityLookupRepository;
        $service = app(NovaUserService::class);
        $scenarios = [
            [['usuario' => '...', 'rut' => null, 'redmine_id' => null], ['usuario' => '---', 'rut' => null, 'redmine_id' => null]],
            [['usuario_core' => 'alpha - 9', 'nombre' => 'Igual'], ['usuario_core' => 'ALPHA9', 'nombre' => 'Igual']],
            [['rut' => '12.345.678-K'], ['rut' => '12345678k']],
            [['usuario' => '...', 'nombre' => str_repeat('A', 255), 'apellido' => str_repeat('B', 255), 'redmine_id' => str_repeat('1', 254).'2'],
                ['usuario' => '---', 'nombre' => str_repeat('A', 255), 'apellido' => str_repeat('B', 255), 'redmine_id' => str_repeat('1', 254).'3']],
            [['usuario_core' => "\0CÓRÉ - a\t%_\\ 09"], ['usuario_core' => 'core-a09']],
        ];
        foreach ($scenarios as $changes) {
            foreach ($changes as $i => $values) {
                DB::table('usuarios_nova')->where('id', $i + 1)->update(array_replace([
                    'usuario' => 'user'.($i + 1), 'rut' => null, 'usuario_core' => null, 'redmine_id' => null,
                    'nombre' => 'Persona', 'apellido' => 'Prueba',
                ], $values));
            }
            foreach (['user1', 'user-2', 'alpha9', '12345678k', 'core-a09', 'cr-a09', 'no-existe', str_repeat('1', 254).'2'] as $needle) {
                $needle = $service->normalizeIdentity($needle);
                self::assertSame($reference($needle), $lookup->lookup($needle));
            }
        }
        // No request-global uniqueness cache: a later duplicate must be seen.
        DB::table('usuarios_nova')->update(['rut' => 'same-anchor']);
        self::assertTrue($lookup->lookup('user1')['ambiguous']);
        DB::table('redmine_tic_permisos_usuario')->delete();
        DB::table('redmine_tic_perfiles_usuario')->delete();
        DB::table('usuarios_nova')->delete();
        self::assertSame(['ambiguous' => false, 'id' => null], $lookup->lookup('missing'));
    }

    public function test_normalized_duplicate_keeps_the_existing_merge_path(): void
    {
        DB::table('usuarios_nova')->where('id', 1)->update(['rut' => '12.345.678-K']);
        DB::table('usuarios_nova')->where('id', 2)->update(['rut' => '12345678k', 'rol' => 'admin']);
        $repo = app(NovaUserRepository::class);
        $found = $repo->find('user1');
        self::assertSame('admin', $found['role']);
        self::assertSame('admin', DB::table('usuarios_nova')->where('id', 1)->value('rol'));
        self::assertSame($repo->all()[0], $repo->find('12345678k'));
    }

    public function test_batch_credentials_preserve_canonical_and_legacy_precedence_without_cross_user_cache(): void
    {
        DB::table('integraciones_usuario')->insert([
            ['usuario_id' => 1, 'tipo' => 'redmine', 'valor_secreto' => 'canonical-1', 'actualizado_at' => '2026-01-01'],
            ['usuario_id' => 1, 'tipo' => 'redmine_tic', 'valor_secreto' => 'legacy-1', 'actualizado_at' => '2026-09-01'],
            ['usuario_id' => 2, 'tipo' => 'redmine', 'valor_secreto' => '', 'actualizado_at' => '2026-01-01'],
            ['usuario_id' => 2, 'tipo' => 'redmine_mantencion', 'valor_secreto' => 'legacy-2', 'actualizado_at' => '2026-09-01'],
        ]);
        $repo = app(UserIntegrationRepository::class);
        $expected = [1 => $repo->credentialForUserId(1), 2 => $repo->credentialForUserId(2)];
        self::assertSame($expected, $repo->redmineCredentialsForUserIds([1, 2]));
        DB::table('integraciones_usuario')->where('usuario_id', 1)->where('tipo', 'redmine')->update(['valor_secreto' => 'rotated-1']);
        self::assertSame('rotated-1', $repo->redmineCredentialsForUserIds([1])[1]['secret']);
        self::assertSame('legacy-2', $repo->redmineCredentialsForUserIds([2])[2]['secret']);
    }

    public function test_project_projection_uses_one_credential_query_and_list_never_reads_secrets(): void
    {
        foreach ([1, 2] as $id) {
            DB::table('integraciones_usuario')->insert(['usuario_id' => $id, 'tipo' => 'redmine', 'valor_secreto' => 'token-'.$id]);
        }
        $repo = new RedmineUserRepository('redmine_tic', 'TIC');
        DB::enableQueryLog();
        $full = $repo->projectUsers();
        $queries = DB::getQueryLog();
        DB::flushQueryLog();
        $list = $repo->projectUsers(false);
        $listQueries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertCount(1, array_filter($queries, fn ($q) => str_contains($q['query'], 'select * from `integraciones_usuario`')));
        foreach ($full as &$user) {
            $user['api'] = '';
            $user['password'] = '';
        }
        self::assertSame($full, $list);
        foreach ($listQueries as $q) {
            self::assertStringNotContainsString('integraciones_usuario', $q['query']);
            self::assertStringNotContainsString('password', $q['query']);
        }
    }

    public function test_user_list_query_count_is_constant_as_the_directory_grows(): void
    {
        $seed = (array) DB::table('usuarios_nova')->where('id', 1)->first();
        for ($id = 3; $id <= 100; $id++) {
            $row = array_replace($seed, ['id' => $id, 'uuid' => 'user-'.$id, 'usuario' => 'user'.$id, 'nombre' => 'Persona'.$id, 'redmine_id' => (string) (1000 + $id)]);
            DB::table('usuarios_nova')->insert($row);
            DB::table('permisos_usuario_modulo')->insert(['usuario_id' => $id, 'modulo_id' => 1, 'permitido' => 1]);
        }
        for ($id = 1; $id <= 100; $id++) {
            DB::table('integraciones_usuario')->insert(['usuario_id' => $id, 'tipo' => 'redmine', 'valor_secreto' => 'synthetic-token-'.$id]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);
        $users = (new RedmineUserRepository('redmine_tic', 'TIC'))->projectUsers();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $credentials = array_filter($queries, fn ($q) => str_contains($q['query'], 'select * from `integraciones_usuario`'));
        file_put_contents(sys_get_temp_dir().'/nova-p06-users-metrics.json', json_encode(['users' => count($users), 'queries' => count($queries), 'credential_queries' => count($credentials), 'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 2)]), LOCK_EX);
        self::assertCount(100, $users);
        self::assertCount(1, $credentials);
        foreach ($users as $user) {
            self::assertSame('synthetic-token-'.$user['_nova_user_db_id'], $user['api']);
        }
    }

    public function test_sql_identity_lookup_avoids_loading_the_directory(): void
    {
        $rows = [];
        for ($id = 3; $id <= 2000; $id++) {
            $rows[] = ['id' => $id, 'uuid' => 'user-'.$id, 'usuario' => 'user'.$id,
                'nombre' => 'Persona'.$id, 'apellido' => 'Prueba'];
        }
        foreach (array_chunk($rows, 250) as $batch) {
            DB::table('usuarios_nova')->insert($batch);
        }
        unset($rows, $batch);
        $service = app(NovaUserService::class);
        $lookupNeedle = 'user1999';
        $previous = function () use ($service, &$lookupNeedle): ?int {
            $rows = DB::table('usuarios_nova')->orderBy('nombre')->orderBy('apellido')
                ->get(['id', 'uuid', 'usuario', 'redmine_id', 'rut', 'usuario_core', 'nombre', 'apellido']);
            $keys = [];
            $target = null;
            foreach ($rows as $row) {
                $identity = ['id' => (string) $row->uuid, 'username' => trim((string) $row->usuario),
                    'rut_sin_dv' => trim((string) $row->usuario), 'redmine_id' => trim((string) $row->redmine_id),
                    'rut' => trim((string) $row->rut), 'core_user' => trim((string) $row->usuario_core),
                    'name' => trim((string) $row->nombre), 'apellido' => trim((string) $row->apellido)];
                $key = $service->dedupeKey($identity);
                if ($key !== '' && isset($keys[$key])) {
                    throw new \LogicException('The performance fixture must not contain duplicate identities.');
                }
                $keys[$key] = true;
                if ($target === null) {
                    foreach ($service->loginCandidates($identity) as $candidate) {
                        if ($service->normalizeIdentity((string) $candidate) === $lookupNeedle) {
                            $target = (int) $row->id;
                            break;
                        }
                    }
                }
            }

            return $target;
        };
        $measure = function (callable $read): array {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $memory = memory_get_usage();
            $start = hrtime(true);
            $result = $read();

            return [$result, ['peak_bytes' => memory_get_peak_usage() - $memory,
                'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 2)]];
        };
        [$expected, $before] = $measure($previous);
        [$result, $after] = $measure(fn () => app(NovaIdentityLookupRepository::class)->lookup('user1999'));
        self::assertFalse($result['ambiguous']);
        self::assertSame($expected, $result['id']);
        self::assertSame(1999, $result['id']);
        self::assertLessThan($before['peak_bytes'], $after['peak_bytes']);
        // Alternate readers across directory sizes, with formatted RUT/core IDs
        // and Unicode names. Timings are evidence, not unstable CI thresholds.
        DB::table('usuarios_nova')->update([
            'rut' => DB::raw("CONCAT('12.', LPAD(id, 6, '0'), '-K')"),
            'usuario_core' => DB::raw("CONCAT('CORE-', id)"),
            'redmine_id' => DB::raw('CAST(100000 + id AS CHAR)'),
            'nombre' => DB::raw("CASE WHEN MOD(id, 3) = 0 THEN 'José' ELSE nombre END"),
        ]);
        $workloads = [];
        $previousSql = require __DIR__.'/fixtures/identity_lookup_reference.php';
        foreach ([2000, 500, 50] as $size) {
            DB::table('usuarios_nova')->where('id', '>', $size)->delete();
            $lookupNeedle = 'user'.($size - 1);
            $timings = ['previous' => [], 'previous_sql' => [], 'sql' => []];
            for ($round = 0; $round < 12; $round++) {
                foreach ($round % 2 ? ['sql', 'previous_sql', 'previous'] : ['previous', 'previous_sql', 'sql'] as $reader) {
                    [$value, $stats] = $measure(match ($reader) {
                        'previous' => $previous,
                        'previous_sql' => fn () => $previousSql($lookupNeedle)['id'],
                        default => fn () => app(NovaIdentityLookupRepository::class)->lookup($lookupNeedle)['id'],
                    });
                    self::assertSame($size - 1, $value);
                    $timings[$reader][] = $stats['elapsed_ms'];
                }
            }
            foreach ($timings as &$values) {
                sort($values);
                $values = round(($values[5] + $values[6]) / 2, 3);
            }
            unset($values);
            $workloads[$size] = $timings;
        }
        file_put_contents(sys_get_temp_dir().'/nova-p06-identity-metrics.json', json_encode([
            'users' => 2000, 'before' => $before, 'after' => $after, 'formatted_identity_median_ms' => $workloads,
        ]), LOCK_EX);
    }
}
