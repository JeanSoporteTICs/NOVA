<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RedmineTic\Repositories\RedmineDataRepository;
use Tests\TestCase;

/**
 * Validates the Phase 3a relational permissions system against MariaDB.
 *
 * Tests that need a profile create their own fixture inside a rolled-back
 * transaction so the suite also represents a clean installation.
 *
 * Run: php artisan test --filter=Phase3aPermissionsTest
 */
class Phase3aPermissionsTest extends TestCase
{
    use DatabaseTransactions;

    private const EXPECTED_KEY_COUNT = 32;

    private const ALL_KEYS = [
        'mensajes', 'mensajes_acceso', 'horas_extra', 'historico', 'historico_acciones',
        'historico_scope', 'configuracion', 'estadisticas', 'usuarios',
        'simulador', 'reporte_rapido', 'actividad', 'actividad_eliminar',
        'actividad_todos', 'reportes_editar',
        'reportes_eliminar', 'horas_extra_editar', 'usuarios_editar',
        'usuarios_eliminar', 'cfg_resumen', 'cfg_conexion', 'cfg_proyecto', 'cfg_redmine',
        'cfg_campos', 'cfg_retencion', 'cfg_informes', 'cfg_mantencion', 'cfg_roles', 'cfg_usuarios',
        'cfg_categorias', 'cfg_unidades', 'mis_integraciones',
    ];

    private const SCOPE_KEYS = ['mensajes', 'historico_scope', 'horas_extra'];

    // -------------------------------------------------------------------------
    // Table existence
    // -------------------------------------------------------------------------

    public function test_phase3a_tables_exist(): void
    {
        $this->assertTrue(
            Schema::hasTable('redmine_tic_permisos_catalogo'),
            'redmine_tic_permisos_catalogo debe existir'
        );
        $this->assertTrue(
            Schema::hasTable('redmine_tic_permisos_rol'),
            'redmine_tic_permisos_rol debe existir'
        );
        $this->assertTrue(
            Schema::hasTable('redmine_tic_permisos_usuario'),
            'redmine_tic_permisos_usuario debe existir'
        );
    }

    // -------------------------------------------------------------------------
    // Catálogo
    // -------------------------------------------------------------------------

    public function test_permisos_catalogo_has_expected_entries(): void
    {
        $this->skipIfTableMissing('redmine_tic_permisos_catalogo');

        $count = DB::table('redmine_tic_permisos_catalogo')->count();
        $this->assertEquals(
            self::EXPECTED_KEY_COUNT,
            $count,
            'El catálogo debe tener exactamente '.self::EXPECTED_KEY_COUNT." filas, tiene {$count}"
        );
    }

    public function test_all_catalog_keys_are_present(): void
    {
        $this->skipIfTableMissing('redmine_tic_permisos_catalogo');

        $dbKeys = DB::table('redmine_tic_permisos_catalogo')->pluck('clave')->toArray();
        $missing = array_diff(self::ALL_KEYS, $dbKeys);

        $this->assertEmpty(
            $missing,
            'Claves faltantes en catálogo: '.implode(', ', $missing)
        );
    }

    public function test_catalog_scope_keys_have_correct_type(): void
    {
        $this->skipIfTableMissing('redmine_tic_permisos_catalogo');

        foreach (self::SCOPE_KEYS as $key) {
            $tipo = DB::table('redmine_tic_permisos_catalogo')->where('clave', $key)->value('tipo');
            $this->assertContains(
                $tipo,
                ['scope', 'scope_or_empty'],
                "La clave '{$key}' debe tener tipo scope o scope_or_empty, tiene '{$tipo}'"
            );
        }
    }

    public function test_catalog_bool_keys_have_bool_type(): void
    {
        $this->skipIfTableMissing('redmine_tic_permisos_catalogo');

        $boolKeys = array_diff(self::ALL_KEYS, self::SCOPE_KEYS);
        foreach ($boolKeys as $key) {
            $tipo = DB::table('redmine_tic_permisos_catalogo')->where('clave', $key)->value('tipo');
            $this->assertEquals(
                'bool',
                $tipo,
                "La clave booleana '{$key}' debe tener tipo 'bool', tiene '{$tipo}'"
            );
        }
    }

    // -------------------------------------------------------------------------
    // redmine_tic_permisos_usuario
    // -------------------------------------------------------------------------

    public function test_all_profiles_have_rows_in_permisos_usuario(): void
    {
        $this->skipIfTableMissing('redmine_tic_permisos_usuario');
        $this->skipIfTableMissing('redmine_tic_perfiles_usuario');

        $totalPerfiles = DB::table('redmine_tic_perfiles_usuario')->count();
        if ($totalPerfiles === 0) {
            $this->markTestSkipped('No hay perfiles en la DB');
        }

        $perfilesInRelacional = DB::table('redmine_tic_permisos_usuario')
            ->distinct()
            ->count('perfil_id');

        $this->assertEquals(
            $totalPerfiles,
            $perfilesInRelacional,
            "Hay {$totalPerfiles} perfiles pero solo {$perfilesInRelacional} tienen filas en permisos_usuario"
        );
    }

    public function test_every_profile_has_all_expected_permission_keys(): void
    {
        $this->skipIfTableMissing('redmine_tic_permisos_usuario');
        $this->skipIfTableMissing('redmine_tic_perfiles_usuario');

        $countsByPerfil = DB::table('redmine_tic_permisos_usuario')
            ->select('perfil_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('perfil_id')
            ->pluck('cnt', 'perfil_id')
            ->toArray();

        if (empty($countsByPerfil)) {
            $this->markTestSkipped('Tabla permisos_usuario vacía');
        }

        $bad = array_filter($countsByPerfil, fn ($c) => $c < self::EXPECTED_KEY_COUNT);

        $this->assertEmpty(
            $bad,
            count($bad).' perfil(es) tienen menos de '.self::EXPECTED_KEY_COUNT.' claves: '.
            implode(', ', array_map(fn ($p, $c) => "perfil_{$p}={$c}", array_keys($bad), $bad))
        );
    }

    public function test_all_canonical_keys_appear_in_permisos_usuario(): void
    {
        $this->skipIfTableMissing('redmine_tic_permisos_usuario');
        $this->createPermissionFixture();

        $storedKeys = DB::table('redmine_tic_permisos_usuario')
            ->distinct()
            ->pluck('clave')
            ->toArray();

        $missing = array_diff(self::ALL_KEYS, $storedKeys);

        $this->assertEmpty(
            $missing,
            'Claves canónicas nunca encontradas en permisos_usuario: '.implode(', ', $missing)
        );
    }

    public function test_permisos_usuario_total_rows(): void
    {
        $this->skipIfTableMissing('redmine_tic_permisos_usuario');
        $this->skipIfTableMissing('redmine_tic_perfiles_usuario');

        $totalPerfiles = DB::table('redmine_tic_perfiles_usuario')->count();
        $totalRows = DB::table('redmine_tic_permisos_usuario')->count();
        $expectedMin = $totalPerfiles * self::EXPECTED_KEY_COUNT;

        $this->assertGreaterThanOrEqual(
            $expectedMin,
            $totalRows,
            "Se esperan al menos {$expectedMin} filas ({$totalPerfiles} perfiles × ".self::EXPECTED_KEY_COUNT."), hay {$totalRows}"
        );
    }

    // -------------------------------------------------------------------------
    // redmine_tic_permisos_rol
    // -------------------------------------------------------------------------

    public function test_permisos_rol_has_at_least_4_roles(): void
    {
        $this->skipIfTableMissing('redmine_tic_permisos_rol');
        $this->skipIfTableMissing('modulos_nova');

        $moduleId = (int) DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');
        if ($moduleId <= 0) {
            $this->markTestSkipped('No se encontró modulo_id para redmine_tic');
        }

        $roleCount = DB::table('redmine_tic_permisos_rol')
            ->where('modulo_id', $moduleId)
            ->distinct()
            ->count('rol');

        $this->assertGreaterThanOrEqual(
            4,
            $roleCount,
            "Debe haber al menos 4 roles (root, administrador, gestor, usuario); hay {$roleCount}"
        );
    }

    public function test_every_role_has_all_expected_permission_keys(): void
    {
        $this->skipIfTableMissing('redmine_tic_permisos_rol');
        $this->skipIfTableMissing('modulos_nova');

        $moduleId = (int) DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');
        if ($moduleId <= 0) {
            $this->markTestSkipped('No se encontró modulo_id para redmine_tic');
        }

        $countsByRol = DB::table('redmine_tic_permisos_rol')
            ->where('modulo_id', $moduleId)
            ->select('rol', DB::raw('COUNT(*) as cnt'))
            ->groupBy('rol')
            ->pluck('cnt', 'rol')
            ->toArray();

        if (empty($countsByRol)) {
            $this->markTestSkipped('No hay filas en permisos_rol');
        }

        $bad = array_filter($countsByRol, fn ($c) => $c < self::EXPECTED_KEY_COUNT);

        $this->assertEmpty(
            $bad,
            'Roles con menos de '.self::EXPECTED_KEY_COUNT.' claves: '.
            implode(', ', array_map(fn ($r, $c) => "{$r}={$c}", array_keys($bad), $bad))
        );
    }

    // -------------------------------------------------------------------------
    // Consistency JSON ↔ Relacional
    // -------------------------------------------------------------------------

    public function test_json_and_relational_values_match_for_sample_profiles(): void
    {
        $this->skipIfTableMissing('redmine_tic_permisos_usuario');
        $this->skipIfTableMissing('redmine_tic_perfiles_usuario');

        // Phase 3c drops the permisos column once relational tables are validated.
        // If the column is gone, there is no JSON source to compare — test is moot.
        if (! Schema::hasColumn('redmine_tic_perfiles_usuario', 'permisos')) {
            $this->markTestSkipped('Columna permisos eliminada (Phase 3c aplicada) — comparación JSON↔Relacional ya no aplica');
        }

        $profiles = DB::table('redmine_tic_perfiles_usuario')
            ->whereNotNull('permisos')
            ->where('permisos', '!=', '[]')
            ->where('permisos', '!=', '{}')
            ->limit(3)
            ->get(['id', 'permisos']);

        if ($profiles->isEmpty()) {
            $this->markTestSkipped('No hay perfiles con JSON no vacío para comparar');
        }

        $mismatches = [];
        foreach ($profiles as $profile) {
            $jsonPerms = json_decode((string) $profile->permisos, true);
            if (! is_array($jsonPerms)) {
                continue;
            }

            $relRows = DB::table('redmine_tic_permisos_usuario')
                ->where('perfil_id', $profile->id)
                ->pluck('valor', 'clave')
                ->toArray();

            foreach ($jsonPerms as $clave => $jsonVal) {
                if (! in_array($clave, self::ALL_KEYS, true)) {
                    continue;
                }
                $encodedJson = $this->encodeValue($clave, $jsonVal);
                $relVal = $relRows[$clave] ?? null;

                if ($relVal !== $encodedJson) {
                    $mismatches[] = "Perfil {$profile->id}.{$clave}: JSON={$encodedJson} Rel=".($relVal ?? 'NULL');
                }
            }
        }

        $this->assertEmpty(
            $mismatches,
            count($mismatches)." discrepancias JSON↔Relacional:\n".implode("\n", $mismatches)
        );
    }

    // -------------------------------------------------------------------------
    // Repository read via reflection
    // -------------------------------------------------------------------------

    public function test_repository_reads_from_relational_table(): void
    {
        $this->skipIfTableMissing('redmine_tic_permisos_usuario');
        $this->createPermissionFixture();

        $repo = new RedmineDataRepository;
        $ref = new \ReflectionClass($repo);
        $method = $ref->getMethod('allPermissionsFromRelational');
        $method->setAccessible(true);

        $result = $method->invoke($repo);

        $this->assertNotNull(
            $result,
            'allPermissionsFromRelational() devolvió null — la tabla está vacía o ausente'
        );

        $this->assertIsArray($result);
        $this->assertNotEmpty($result, 'allPermissionsFromRelational() devolvió array vacío');
    }

    public function test_repository_returns_all_profiles_from_relational(): void
    {
        $this->skipIfTableMissing('redmine_tic_permisos_usuario');
        $this->skipIfTableMissing('redmine_tic_perfiles_usuario');

        $totalPerfiles = DB::table('redmine_tic_perfiles_usuario')->count();
        if ($totalPerfiles === 0) {
            $this->markTestSkipped('No hay perfiles en la DB');
        }

        $repo = new RedmineDataRepository;
        $ref = new \ReflectionClass($repo);
        $method = $ref->getMethod('allPermissionsFromRelational');
        $method->setAccessible(true);

        $result = $method->invoke($repo);

        $this->assertNotNull($result);
        $this->assertCount(
            $totalPerfiles,
            $result,
            "allPermissionsFromRelational() devolvió {$totalPerfiles} perfiles esperados"
        );
    }

    public function test_repository_returns_all_expected_keys_per_profile(): void
    {
        $this->skipIfTableMissing('redmine_tic_permisos_usuario');

        $repo = new RedmineDataRepository;
        $ref = new \ReflectionClass($repo);
        $method = $ref->getMethod('allPermissionsFromRelational');
        $method->setAccessible(true);

        $result = $method->invoke($repo);
        if ($result === null || empty($result)) {
            $this->markTestSkipped('allPermissionsFromRelational() devolvió vacío');
        }

        $sample = reset($result);
        $keyCount = count($sample);

        $this->assertEquals(
            self::EXPECTED_KEY_COUNT,
            $keyCount,
            "El perfil de muestra tiene {$keyCount} claves, se esperan ".self::EXPECTED_KEY_COUNT
        );
    }

    public function test_dual_write_save_permissions_to_relational(): void
    {
        $this->skipIfTableMissing('redmine_tic_permisos_usuario');
        $this->skipIfTableMissing('redmine_tic_perfiles_usuario');

        $perfil = DB::table('redmine_tic_perfiles_usuario')->first();
        if (! $perfil) {
            $this->markTestSkipped('No hay perfiles para el test de dual-write');
        }

        $perfilId = (int) $perfil->id;

        // Read current relational value for 'estadisticas'
        $originalVal = DB::table('redmine_tic_permisos_usuario')
            ->where('perfil_id', $perfilId)
            ->where('clave', 'estadisticas')
            ->value('valor');

        // Build a full permission set and invoke savePermissionsToRelational via reflection
        $perms = array_fill_keys(self::ALL_KEYS, false);
        $perms['estadisticas'] = true; // known test value
        foreach (self::SCOPE_KEYS as $sk) {
            $perms[$sk] = 'asignados';
        }

        $repo = new RedmineDataRepository;
        $ref = new \ReflectionClass($repo);
        $method = $ref->getMethod('savePermissionsToRelational');
        $method->setAccessible(true);
        $method->invoke($repo, $perfilId, $perms);

        $newVal = DB::table('redmine_tic_permisos_usuario')
            ->where('perfil_id', $perfilId)
            ->where('clave', 'estadisticas')
            ->value('valor');

        $this->assertEquals('si', $newVal, "savePermissionsToRelational debe escribir 'si' para estadisticas=true");

        // Restore original value
        if ($originalVal !== null) {
            DB::table('redmine_tic_permisos_usuario')->updateOrInsert(
                ['perfil_id' => $perfilId, 'clave' => 'estadisticas'],
                ['valor' => $originalVal, 'actualizado_at' => now()]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createPermissionFixture(): int
    {
        $redmineId = (string) random_int(90000000, 99999999);
        $repo = (new RedmineDataRepository)->forProject('redmine_tic');
        $result = $repo->saveUser([
            '_creating' => true,
            'id' => $redmineId,
            'rut_sin_dv' => 'phase3a'.$redmineId,
            'nombre' => 'Phase3a',
            'apellido' => Str::random(8),
            'rol' => 'usuario',
        ]);

        $this->assertTrue($result['ok'] ?? false, $result['error'] ?? 'No se pudo crear el fixture de permisos');

        $novaId = DB::table('usuarios_nova')->where('redmine_id', $redmineId)->value('id');

        return (int) DB::table('redmine_tic_perfiles_usuario')->where('usuario_id', $novaId)->value('id');
    }

    private function skipIfTableMissing(string $table): void
    {
        if (! Schema::hasTable($table)) {
            $this->markTestSkipped("Tabla `{$table}` no existe en la BD");
        }
    }

    private function encodeValue(string $clave, mixed $value): string
    {
        if (in_array($clave, self::SCOPE_KEYS, true)) {
            if (is_string($value)) {
                return $value;
            }

            return $value ? 'asignados' : '';
        }

        return $value ? 'si' : 'no';
    }
}
