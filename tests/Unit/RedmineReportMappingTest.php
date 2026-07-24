<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RedmineTic\Repositories\RedmineReportRepository;
use Tests\TestCase;

/**
 * ETAPA B / Lote B5.1 — direct unit coverage of the DB<->array mapping now
 * living in RedmineReportRepository::hydrate()/payload(), extracted verbatim
 * from RedmineDataRepository::databaseReportToArray()/databaseReportPayload().
 * Complements tests/Unit/RedmineTicReportsBaselineTest.php, which already
 * proved the facade's public contract is unchanged after the extraction —
 * these tests exercise the new component in isolation.
 */
class RedmineReportMappingTest extends TestCase
{
    use DatabaseTransactions;

    private function repo(): RedmineReportRepository
    {
        return new RedmineReportRepository('redmine_tic', 'Backlog Soporte TI');
    }

    private function row(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 42,
            'redmine_id' => 100,
            'estado' => 'pendiente',
            'estado_redmine' => null,
            'tipo' => 'Soporte',
            'prioridad' => 'NORMAL',
            'categoria_catalogo_id' => null,
            'unidad_catalogo_id' => null,
            'unidad_solicitante_catalogo_id' => null,
            'categoria' => '',
            'unidad' => '',
            'unidad_solicitante' => '',
            'solicitante' => 'Juan Perez',
            'asunto' => 'Impresora no imprime',
            'descripcion' => 'Detalle',
            'fecha' => '2026-05-01',
            'hora' => '10:00:00',
            'fecha_inicio' => '2026-05-01',
            'fecha_fin' => null,
            'chat_id_telegram' => '',
            'mensaje' => '',
            'asignado_a' => 100,
            'hora_extra' => 0,
            'tiempo_estimado' => null,
            'origen' => 'manual',
            'procesado_at' => null,
            'creado_at' => '2026-05-01 09:00:00',
            'actualizado_at' => '2026-05-01 09:00:00',
        ], $overrides);
    }

    public function test_hydrate_maps_basic_fields(): void
    {
        $result = $this->repo()->hydrate($this->row(), fn (string $id): string => 'Juan P.');

        $this->assertSame('42', $result['id']);
        $this->assertSame('100', $result['redmine_id']);
        $this->assertSame('pendiente', $result['estado']);
        $this->assertSame('Soporte', $result['tipo']);
        $this->assertSame('NORMAL', $result['prioridad']);
        $this->assertSame('Juan Perez', $result['solicitante']);
        $this->assertSame('Impresora no imprime', $result['asunto']);
        $this->assertSame('2026-05-01', $result['fecha']);
        $this->assertSame('10:00', $result['hora']);
        $this->assertSame('100', $result['asignado_a']);
        $this->assertSame('Juan P.', $result['asignado_nombre']);
        $this->assertSame('NO', $result['hora_extra']);
        $this->assertSame('manual', $result['origen']);
    }

    public function test_hydrate_handles_null_optional_fields_as_empty_strings(): void
    {
        $result = $this->repo()->hydrate($this->row([
            'redmine_id' => null,
            'estado_redmine' => null,
            'tiempo_estimado' => null,
            'fecha_fin' => null,
            'procesado_at' => null,
        ]), fn (string $id): string => '');

        $this->assertSame('', $result['redmine_id']);
        $this->assertSame('', $result['estado_redmine']);
        $this->assertSame('', $result['tiempo_estimado']);
        $this->assertSame('', $result['fecha_fin']);
    }

    public function test_hydrate_falls_back_procesado_ts_to_actualizado_at_only_for_terminal_states(): void
    {
        $processed = $this->repo()->hydrate($this->row([
            'estado' => 'procesado',
            'procesado_at' => null,
            'actualizado_at' => '2026-05-02 11:30:00',
        ]), fn (string $id): string => '');
        $this->assertSame('2026-05-02 11:30:00', $processed['procesado_ts']);

        $pending = $this->repo()->hydrate($this->row([
            'estado' => 'pendiente',
            'procesado_at' => null,
            'actualizado_at' => '2026-05-02 11:30:00',
        ]), fn (string $id): string => '');
        $this->assertSame('', $pending['procesado_ts']);
    }

    public function test_hydrate_uses_the_assigned_name_resolver_callback_with_raw_asignado_a(): void
    {
        $receivedId = null;
        $resolver = function (string $id) use (&$receivedId): string {
            $receivedId = $id;

            return 'Resolved Name';
        };

        $result = $this->repo()->hydrate($this->row(['asignado_a' => 777]), $resolver);

        $this->assertSame('777', $receivedId);
        $this->assertSame('Resolved Name', $result['asignado_nombre']);
    }

    public function test_hydrate_resolves_category_and_unit_names_via_real_catalog(): void
    {
        $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');
        $categoriaId = DB::table('catalogos_modulo')->insertGetId([
            'modulo_id' => $moduleId,
            'tipo' => 'categoria',
            'clave_externa' => 'b51-test-cat',
            'nombre' => 'Categoria B5.1',
            'activo' => 1,
            'actualizado_at' => now(),
        ]);

        $result = $this->repo()->hydrate($this->row(['categoria_catalogo_id' => $categoriaId]), fn (string $id): string => '');

        $this->assertSame('Categoria B5.1', $result['categoria']);
    }

    public function test_payload_maps_array_to_typed_db_payload(): void
    {
        $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');

        $payload = $this->repo()->payload($moduleId, [
            'estado' => 'pendiente',
            'redmine_id' => '123',
            'asignado_a' => '456',
            'hora_extra' => 'SI',
            'tiempo_estimado' => '1:30',
            'fecha' => '2026-05-01',
            'fecha_inicio' => '2026-05-01',
            'origen' => 'manual',
        ], false);

        $this->assertSame($moduleId, $payload['modulo_id']);
        $this->assertSame('pendiente', $payload['estado']);
        $this->assertSame(123, $payload['redmine_id']);
        $this->assertSame(456, $payload['asignado_a']);
        $this->assertSame(1, $payload['hora_extra']);
        $this->assertSame(1.5, $payload['tiempo_estimado']);
        $this->assertSame('2026-05-01', $payload['fecha']);
    }

    public function test_payload_forces_estado_archivado_when_archived_flag_is_true(): void
    {
        $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');

        $payload = $this->repo()->payload($moduleId, ['estado' => 'pendiente'], true);

        $this->assertSame('archivado', $payload['estado']);
    }

    public function test_payload_defaults_missing_estado_to_pendiente(): void
    {
        $moduleId = DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');

        $payload = $this->repo()->payload($moduleId, [], false);

        $this->assertSame('pendiente', $payload['estado']);
        $this->assertNull($payload['redmine_id']);
        $this->assertNull($payload['asignado_a']);
        $this->assertSame(0, $payload['hora_extra']);
    }

    public function test_is_hours_extra_report_recognizes_truthy_variants(): void
    {
        $repo = $this->repo();
        foreach (['SI', 'si', 'Sí', '1', 'true'] as $truthy) {
            $this->assertTrue($repo->isHoursExtraReport(['hora_extra' => $truthy]), "Expected '$truthy' to be truthy");
        }
        foreach (['NO', 'no', '0', '', 'false'] as $falsy) {
            $this->assertFalse($repo->isHoursExtraReport(['hora_extra' => $falsy]), "Expected '$falsy' to be falsy");
        }
    }

    public function test_decimal_hours_parses_numeric_and_hh_mm_formats(): void
    {
        $repo = $this->repo();
        $this->assertSame(1.5, $repo->decimalHours('1.5'));
        $this->assertSame(1.5, $repo->decimalHours('1:30'));
        $this->assertNull($repo->decimalHours(''));
        $this->assertNull($repo->decimalHours('not-a-time'));
    }

    public function test_unsigned_integer_or_null_rejects_non_digit_and_zero(): void
    {
        $repo = $this->repo();
        $this->assertSame(5, $repo->unsignedIntegerOrNull('5'));
        $this->assertNull($repo->unsignedIntegerOrNull('0'));
        $this->assertNull($repo->unsignedIntegerOrNull('-1'));
        $this->assertNull($repo->unsignedIntegerOrNull('abc'));
        $this->assertNull($repo->unsignedIntegerOrNull(''));
    }

    public function test_parse_date_time_returns_null_for_empty_or_invalid(): void
    {
        $repo = $this->repo();
        $this->assertSame('2026-05-01 10:30:00', $repo->parseDateTime('2026-05-01 10:30:00'));
        $this->assertNull($repo->parseDateTime(''));
        $this->assertNull($repo->parseDateTime('not-a-date'));
    }
}
