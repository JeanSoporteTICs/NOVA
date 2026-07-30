<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RedmineTic\Repositories\RedmineDataRepository;

/**
 * Validates that Phase 3a (relational permissions) is correctly populated
 * before Phase 3c (DROP COLUMN permisos) is approved.
 *
 * Usage:
 *   php artisan nova:validate-phase3a
 */
class ValidatePhase3aPermisos extends Command
{
    protected $signature = 'nova:validate-phase3a';
    protected $description = 'Validate Phase 3a: relational permission tables vs JSON source';

    private const EXPECTED_KEYS = 30;

    private const SCOPE_KEYS = ['mensajes', 'historico_scope', 'horas_extra'];

    private const ALL_KEYS = [
        'mensajes', 'mensajes_acceso', 'horas_extra', 'historico', 'historico_acciones',
        'historico_scope', 'configuracion', 'estadisticas', 'usuarios',
        'categorias', 'unidades', 'simulador', 'actividad', 'reportes_editar',
        'reportes_eliminar', 'horas_extra_editar', 'usuarios_editar',
        'usuarios_eliminar', 'cfg_resumen', 'cfg_conexion', 'cfg_proyecto', 'cfg_redmine',
        'cfg_campos', 'cfg_retencion', 'cfg_mantencion', 'cfg_roles', 'cfg_usuarios',
        'cfg_categorias', 'cfg_unidades', 'mis_integraciones',
    ];

    private int $passed = 0;
    private int $failed = 0;
    private array $issues = [];

    public function handle(): int
    {
        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════╗');
        $this->info('║      NOVA — Validación Phase 3a Permisos Relacionales    ║');
        $this->info('╚══════════════════════════════════════════════════════════╝');
        $this->info('');

        $this->checkTableExistence();
        $this->checkCatalogo();
        $this->checkPermisosUsuario();
        $this->checkPermisosRol();
        $this->checkJsonConsistency();
        $this->checkRelationalReadViaRepository();
        $this->checkDualWriteConsistency();

        $this->printSummary();

        return $this->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Check groups
    // -------------------------------------------------------------------------

    private function checkTableExistence(): void
    {
        $this->section('1. Existencia de tablas');

        $tables = [
            'redmine_tic_permisos_catalogo',
            'redmine_tic_permisos_rol',
            'redmine_tic_permisos_usuario',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $this->pass("Tabla `{$table}` existe");
            } else {
                $this->checkFail("Tabla `{$table}` NO existe — ejecutar: php artisan migrate");
            }
        }
    }

    private function checkCatalogo(): void
    {
        $this->section('2. Catálogo de permisos');

        if (!Schema::hasTable('redmine_tic_permisos_catalogo')) {
            $this->warn('  (Saltado — tabla ausente)');
            return;
        }

        $count = DB::table('redmine_tic_permisos_catalogo')->count();
        if ($count === self::EXPECTED_KEYS) {
            $this->pass("Catálogo tiene exactamente {$count} filas");
        } else {
            $this->checkFail("Catálogo tiene {$count} filas, se esperan " . self::EXPECTED_KEYS);
        }

        $dbKeys = DB::table('redmine_tic_permisos_catalogo')->pluck('clave')->toArray();
        $missing = array_diff(self::ALL_KEYS, $dbKeys);
        if (empty($missing)) {
            $this->pass('Todas las claves canónicas están en el catálogo');
        } else {
            $this->checkFail('Claves faltantes en catálogo: ' . implode(', ', $missing));
        }

        // Verify scope key types
        $scopeErrors = [];
        foreach (self::SCOPE_KEYS as $sk) {
            $tipo = DB::table('redmine_tic_permisos_catalogo')->where('clave', $sk)->value('tipo');
            if (!in_array($tipo, ['scope', 'scope_or_empty'], true)) {
                $scopeErrors[] = "{$sk}={$tipo}";
            }
        }
        if (empty($scopeErrors)) {
            $this->pass('Tipos de claves scope correctos (scope / scope_or_empty)');
        } else {
            $this->checkFail('Tipos incorrectos en catálogo: ' . implode(', ', $scopeErrors));
        }
    }

    private function checkPermisosUsuario(): void
    {
        $this->section('3. redmine_tic_permisos_usuario');

        if (!Schema::hasTable('redmine_tic_permisos_usuario') ||
            !Schema::hasTable('redmine_tic_perfiles_usuario')) {
            $this->warn('  (Saltado — tabla ausente)');
            return;
        }

        $totalPerfiles = DB::table('redmine_tic_perfiles_usuario')->count();
        $totalRows     = DB::table('redmine_tic_permisos_usuario')->count();
        $expectedMin   = $totalPerfiles * self::EXPECTED_KEYS;

        $this->line("  Perfiles en DB: {$totalPerfiles}");
        $this->line("  Filas en permisos_usuario: {$totalRows}  (mínimo esperado: {$expectedMin})");

        if ($totalRows >= $expectedMin) {
            $this->pass("Conteo de filas correcto ({$totalRows} ≥ {$expectedMin})");
        } else {
            $this->checkFail("Insuficientes filas: {$totalRows} < {$expectedMin}");
        }

        // Check profiles with fewer than the canonical key count.
        $countsByPerfil = DB::table('redmine_tic_permisos_usuario')
            ->select('perfil_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('perfil_id')
            ->pluck('cnt', 'perfil_id')
            ->toArray();

        $perfilesInRelational = count($countsByPerfil);

        if ($perfilesInRelational === $totalPerfiles) {
            $this->pass("Todos los {$totalPerfiles} perfiles tienen filas relacionales");
        } else {
            $missing = $totalPerfiles - $perfilesInRelational;
            $this->checkFail("{$missing} perfiles sin ninguna fila en permisos_usuario");
            $this->issues[] = "Ejecutar: php artisan migrate (backfill migration)";
        }

        $profilesWithFewer = array_filter($countsByPerfil, fn($c) => $c < self::EXPECTED_KEYS);
        if (empty($profilesWithFewer)) {
            $this->pass('Todos los perfiles tienen exactamente ' . self::EXPECTED_KEYS . ' claves');
        } else {
            $detail = implode(', ', array_map(
                fn($pid, $cnt) => "perfil_{$pid}={$cnt}",
                array_keys($profilesWithFewer),
                $profilesWithFewer
            ));
            $this->checkFail(count($profilesWithFewer) . ' perfil(es) con permisos incompletos: ' . $detail);
        }

        // Verify the distinct canonical keys actually stored.
        $storedKeys   = DB::table('redmine_tic_permisos_usuario')->distinct()->pluck('clave')->toArray();
        $missingKeys  = array_diff(self::ALL_KEYS, $storedKeys);
        if (empty($missingKeys)) {
            $this->pass('Las claves canónicas aparecen al menos una vez en la tabla');
        } else {
            $this->checkFail('Claves nunca vistas en permisos_usuario: ' . implode(', ', $missingKeys));
        }
    }

    private function checkPermisosRol(): void
    {
        $this->section('4. redmine_tic_permisos_rol');

        if (!Schema::hasTable('redmine_tic_permisos_rol') ||
            !Schema::hasTable('modulos_nova')) {
            $this->warn('  (Saltado — tabla ausente)');
            return;
        }

        $moduleId = (int) DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');
        if ($moduleId <= 0) {
            $this->checkFail('No se encontró modulo_id para redmine_tic en modulos_nova');
            return;
        }

        $roles = DB::table('redmine_tic_permisos_rol')
            ->where('modulo_id', $moduleId)
            ->select('rol', DB::raw('COUNT(*) as cnt'))
            ->groupBy('rol')
            ->pluck('cnt', 'rol')
            ->toArray();

        $totalRoles = count($roles);
        $this->line("  Roles encontrados ({$totalRoles}): " . implode(', ', array_keys($roles)));

        if ($totalRoles < 4) {
            $this->checkFail("Se esperan al menos 4 roles (root, administrador, gestor, usuario); hay {$totalRoles}");
        } else {
            $this->pass("{$totalRoles} roles en permisos_rol");
        }

        $rolesWithFewer = array_filter($roles, fn($c) => $c < self::EXPECTED_KEYS);
        if (empty($rolesWithFewer)) {
            $this->pass('Todos los roles tienen el catálogo completo');
        } else {
            $detail = implode(', ', array_map(
                fn($r, $c) => "{$r}={$c}",
                array_keys($rolesWithFewer),
                $rolesWithFewer
            ));
            $this->checkFail('Roles con permisos incompletos: ' . $detail);
        }
    }

    private function checkJsonConsistency(): void
    {
        $this->section('5. Consistencia JSON ↔ Relacional (muestra)');

        if (!Schema::hasTable('redmine_tic_permisos_usuario') ||
            !Schema::hasTable('redmine_tic_perfiles_usuario')) {
            $this->warn('  (Saltado — tabla ausente)');
            return;
        }

        // Take up to 5 profiles that have non-empty JSON
        $profiles = DB::table('redmine_tic_perfiles_usuario')
            ->whereNotNull('permisos')
            ->where('permisos', '!=', '[]')
            ->where('permisos', '!=', '{}')
            ->limit(5)
            ->get(['id', 'permisos']);

        if ($profiles->isEmpty()) {
            $this->warn('  Sin perfiles con JSON no vacío para comparar');
            return;
        }

        $mismatches = 0;

        foreach ($profiles as $profile) {
            $jsonPerms = json_decode((string) $profile->permisos, true);
            if (!is_array($jsonPerms)) {
                continue;
            }

            $relRows = DB::table('redmine_tic_permisos_usuario')
                ->where('perfil_id', $profile->id)
                ->pluck('valor', 'clave')
                ->toArray();

            foreach ($jsonPerms as $clave => $jsonVal) {
                if (!in_array($clave, self::ALL_KEYS, true)) {
                    continue;
                }
                $encodedJson = $this->encodeValue($clave, $jsonVal);
                $relVal      = $relRows[$clave] ?? null;

                if ($relVal === null) {
                    $mismatches++;
                    $this->issues[] = "Perfil {$profile->id}: clave '{$clave}' falta en relacional";
                } elseif ($relVal !== $encodedJson) {
                    $mismatches++;
                    $this->issues[] = "Perfil {$profile->id}: '{$clave}' JSON={$encodedJson} vs Rel={$relVal}";
                }
            }
        }

        if ($mismatches === 0) {
            $this->pass('Muestra de ' . $profiles->count() . ' perfiles: valores JSON y relacionales coinciden');
        } else {
            $this->checkFail("{$mismatches} discrepancias encontradas en la muestra");
        }
    }

    private function checkRelationalReadViaRepository(): void
    {
        $this->section('6. Lectura desde repositorio (RedmineDataRepository)');

        if (!Schema::hasTable('redmine_tic_permisos_usuario')) {
            $this->warn('  (Saltado — tabla ausente)');
            return;
        }

        try {
            $repo = new RedmineDataRepository();
            $ref  = new \ReflectionClass($repo);

            $method = $ref->getMethod('allPermissionsFromRelational');
            $method->setAccessible(true);
            $result = $method->invoke($repo);

            if ($result === null) {
                $this->checkFail('allPermissionsFromRelational() devolvió null (tabla vacía o ausente)');
                return;
            }

            $count = count($result);
            $this->pass("allPermissionsFromRelational() devolvió {$count} perfiles");

            $totalPerfiles = DB::table('redmine_tic_perfiles_usuario')->count();
            if ($count === $totalPerfiles) {
                $this->pass("Conteo coincide con total de perfiles ({$totalPerfiles})");
            } else {
                $this->checkFail("Conteo relacional ({$count}) ≠ total perfiles ({$totalPerfiles})");
            }

            // Verify at least one profile has all canonical keys.
            $sample    = reset($result);
            $keyCount  = is_array($sample) ? count($sample) : 0;
            if ($keyCount === self::EXPECTED_KEYS) {
                $this->pass("Perfil de muestra tiene exactamente {$keyCount} claves");
            } else {
                $this->checkFail("Perfil de muestra tiene {$keyCount} claves (se esperan " . self::EXPECTED_KEYS . ')');
            }
        } catch (\Throwable $e) {
            $this->checkFail('Error al invocar allPermissionsFromRelational: ' . $e->getMessage());
        }
    }

    private function checkDualWriteConsistency(): void
    {
        $this->section('7. Consistencia dual-write (rol JSON ↔ redmine_tic_permisos_rol)');

        if (!Schema::hasTable('redmine_tic_permisos_rol') ||
            !Schema::hasTable('configuraciones_modulo') ||
            !Schema::hasTable('modulos_nova')) {
            $this->warn('  (Saltado — tabla ausente)');
            return;
        }

        $moduleId = (int) DB::table('modulos_nova')->where('clave_modulo', 'redmine_tic')->value('id');
        if ($moduleId <= 0) {
            $this->warn('  (Saltado — modulo_id no encontrado)');
            return;
        }

        $rolesJson = DB::table('configuraciones_modulo')
            ->where('modulo_id', $moduleId)
            ->where('clave', 'roles')
            ->value('valor');

        if ($rolesJson === null) {
            $this->warn('  Sin fila roles en configuraciones_modulo — JSON ya eliminado o nunca existió');
            return;
        }

        $jsonRoles = json_decode((string) $rolesJson, true);
        if (!is_array($jsonRoles)) {
            $this->checkFail('El JSON de roles no decodifica a array');
            return;
        }

        $mismatches = 0;
        foreach ($jsonRoles as $rolName => $jsonPerms) {
            if (!is_array($jsonPerms)) {
                continue;
            }
            foreach ($jsonPerms as $clave => $jsonVal) {
                if (!in_array($clave, self::ALL_KEYS, true)) {
                    continue;
                }
                $relVal    = DB::table('redmine_tic_permisos_rol')
                    ->where('modulo_id', $moduleId)
                    ->where('rol', $rolName)
                    ->where('clave', $clave)
                    ->value('valor');
                $encodedJson = $this->encodeValue($clave, $jsonVal);
                if ($relVal !== $encodedJson) {
                    $mismatches++;
                    $this->issues[] = "Rol {$rolName}.{$clave}: JSON={$encodedJson} vs Rel=" . ($relVal ?? 'NULL');
                }
            }
        }

        if ($mismatches === 0) {
            $this->pass('Roles JSON ↔ relacional: sin discrepancias');
        } else {
            $this->checkFail("{$mismatches} discrepancias en roles JSON vs relacional");
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function section(string $title): void
    {
        $this->line('');
        $this->line("  <fg=cyan>▶ {$title}</>");
    }

    private function pass(string $msg): void
    {
        $this->line("    <fg=green>✓</> {$msg}");
        $this->passed++;
    }

    private function checkFail(string $msg): void
    {
        $this->line("    <fg=red>✗</> {$msg}");
        $this->failed++;
        $this->issues[] = $msg;
    }

    private function printSummary(): void
    {
        $total = $this->passed + $this->failed;
        $this->line('');
        $this->line('  ──────────────────────────────────────────────────────────');

        if ($this->failed === 0) {
            $this->info("  ✅  RESULTADO: APROBADO — {$this->passed}/{$total} verificaciones pasadas");
            $this->info('  Phase 3c (DROP COLUMN permisos) puede planificarse con seguridad.');
        } else {
            $this->error("  ❌  RESULTADO: PENDIENTE — {$this->failed}/{$total} verificaciones fallaron");
            $this->warn('  Phase 3c NO debe ejecutarse hasta resolver los problemas indicados.');
            $this->line('');
            $this->line('  Problemas encontrados:');
            foreach ($this->issues as $i => $issue) {
                $this->line('    ' . ($i + 1) . '. ' . $issue);
            }
        }

        $this->line('  ──────────────────────────────────────────────────────────');
        $this->line('');
    }
}
