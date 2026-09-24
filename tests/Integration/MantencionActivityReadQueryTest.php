<?php

namespace Tests\Integration;

use App\Modulos\RedmineMantencion\Services\MantencionSecurityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MantencionActivityReadQueryTest extends IsolatedMariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('mantencion_log', function (Blueprint $table): void {
            $table->id();
            $table->string('canal', 30);
            $table->string('tipo', 80)->nullable();
            $table->string('mensaje_id', 160)->nullable();
            $table->text('detalle')->nullable();
            // Also covers legacy installations without a JSON validity CHECK.
            $table->longText('contexto')->nullable();
            $table->timestamp('registrado_at')->nullable();
        });
        $contexts = [
            '{"user_id":"101","user_name":"Ana Pérez"}', '{"user_id":"202","user_name":"Ana Pérez"}',
            '{"user_id":" 101\\t","user_name":"Otra Persona"}', '{"user_id":"0101","user_name":"Ana"}',
            '{"user_id":101}', '{"user_id":null,"user_name":"Ana Otra"}', '{"user_name":"ÁNA Pérez"}',
            '{"user_id":"202","user_id":"101"}', '{"user_id":"101","user_id":"202"}',
            '{"user_\\u0069d":"101"}', '{"user_id":"202","user_\\u0069d":"101"}',
            '{"user_id":"","user_name":"Sistema"}', '{}', 'invalid', null,
            '{"user_id":18446744073709551615}', '{"user_id":true}', '{"user_id":[]}',
            '{"user_id":"101","deep":'.str_repeat('[', 513).'0'.str_repeat(']', 513).'}',
        ];
        $details = ['NOVA User Ana Pérez | token=synthetic-secret mensaje %_\\',
            'Ana Otra (ID 101) | cambio contraseña: synthetic', 'Cambio | Usuario=Ana Pérez',
            'NOVA sesion extendida por José | prueba', 'Detalle sin actor'];
        $tags = ['ENVIO', 'LOGIN_SUCCESS', ' LOGIN_SUCCESS ', 'envio', 'NEXTCLOUD_SHARE', '0', '101', '0101', 'CORE_IMPORT_TRACE', 'MOVIMIENTO', 'ÉVENTO', null];
        for ($id = 1; $id <= 180; $id++) {
            DB::table('mantencion_log')->insert([
                'id' => $id, 'canal' => ['web', 'WEB ', 'redmine', '0', '101', '0101'][$id % 6],
                'tipo' => $tags[$id % count($tags)], 'mensaje_id' => $id % 2 ? (string) $id : null,
                'detalle' => $details[$id % count($details)], 'contexto' => $contexts[$id % count($contexts)],
                'registrado_at' => '2026-09-'.str_pad((string) (1 + $id % 3), 2, '0', STR_PAD_LEFT).' 12:00:00',
            ]);
        }
    }

    private function read(callable $reader): array
    {
        // Characterize the existing PHP array-to-string cast, not a new policy.
        set_error_handler(static fn (int $level, string $message): bool => $level === E_WARNING && $message === 'Array to string conversion');
        try {
            return $reader();
        } finally {
            restore_error_handler();
        }
    }

    public function test_sql_scope_pages_and_options_match_the_previous_reader(): void
    {
        $reference = require __DIR__.'/fixtures/mantencion_activity_reference.php';
        $service = new MantencionSecurityService;
        $viewers = [['', true, ''], ['Ana Pérez', false, '101'], ['Ana Pérez', false, '202'],
            ['Ana Pérez', false, '0101'], ['Ana Pérez', false, ''], ['', false, '101'], ['', false, ''],
            ['José', false, 'missing'], ['', false, 'Array'], ['', false, '1.844674407371E+19']];
        $filters = [[], ['tag' => 'envio'], ['tag' => 'NEXTCLOUD'], ['tag' => 'CORE_IMPORT_TRACE'],
            ['canal' => 'web'], ['canal' => '0101'], ['buscar' => '%_\\'], ['buscar' => 'synthetic'],
            ['desde' => '2026-09-02'], ['hasta' => '2026-09-01'], ['desde' => 'invalid'],
            ['desde' => '2026-09-03', 'hasta' => '2026-09-01'], ['tag' => 'LOGIN_SUCCESS', 'buscar' => 'Ana']];
        self::assertGreaterThan(100, $this->read(fn () => $reference([], 1, 50, '', true, ''))['total']);
        foreach ($viewers as [$name, $all, $id]) {
            foreach ($filters as $filter) {
                foreach ([[1, 25], [2, 50], [999, 100], [0, 17]] as [$page, $perPage]) {
                    $expected = $this->read(fn () => $reference($filter, $page, $perPage, $name, $all, $id));
                    $actual = $this->read(fn () => $service->searchEvents($filter, $page, $perPage, $name, $all, $id));
                    self::assertSame($expected, $actual, json_encode([$filter, $page, $name, $id]));
                }
            }
        }
    }

    public function test_large_activity_only_fetches_page_details_for_ordinary_actors(): void
    {
        for ($batch = 0; $batch < 10; $batch++) {
            $rows = [];
            for ($i = 1; $i <= 100; $i++) {
                $rows[] = ['id' => 1000 + $batch * 100 + $i, 'tipo' => 'ENVIO', 'canal' => 'web',
                    'mensaje_id' => '123', 'registrado_at' => '2026-09-10 12:00:00',
                    'detalle' => str_repeat('Detalle extenso. ', 2000),
                    'contexto' => json_encode(['user_id' => $i % 2 ? '101' : '202', 'padding' => str_repeat('contexto ', 2000)])];
            }
            DB::table('mantencion_log')->insert($rows);
        }
        unset($rows);
        $reference = require __DIR__.'/fixtures/mantencion_activity_reference.php';
        $service = new MantencionSecurityService;
        $measure = function (callable $reader): array {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $memory = memory_get_usage();
            $start = hrtime(true);
            DB::flushQueryLog();
            DB::enableQueryLog();
            $result = $this->read($reader);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            DB::flushQueryLog();

            return [$result, ['elapsed_ms' => round((hrtime(true) - $start) / 1e6, 3),
                'peak_bytes' => memory_get_peak_usage() - $memory, 'queries' => count($queries)], $queries];
        };
        $metrics = [];
        foreach ([true, false] as $all) {
            [$expected, $before] = $measure(fn () => $reference([], 1, 25, 'Ana Pérez', $all, '101'));
            [$actual, $after, $queries] = $measure(fn () => $service->searchEvents([], 1, 25, 'Ana Pérez', $all, '101'));
            self::assertSame($expected, $actual);
            self::assertLessThan($before['peak_bytes'] / 4, $after['peak_bytes']);
            $pages = array_filter($queries, fn ($q) => str_contains($q['query'], '`mensaje_id`, `detalle`, `contexto`'));
            self::assertCount(1, $pages);
            foreach ($pages as $page) {
                self::assertCount(25, DB::select($page['query'], $page['bindings']));
            }
            self::assertEmpty(array_filter($queries, fn ($q) => str_starts_with($q['query'], 'select * from `mantencion_log`')));
            $metrics[$all ? 'admin' : 'scoped'] = compact('before', 'after');
        }
        file_put_contents(sys_get_temp_dir().'/nova-p06-mantencion-activity-metrics.json', json_encode($metrics), LOCK_EX);
    }
}
