<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * One-time audit: detect records in redmine_mantencion_reportes whose assigned-user
 * fields do not match any NOVA user identity.
 *
 * Matching priority (mirrors dashboard_user_matches_assigned):
 *   1. CORE username  (usuarios_nova.usuario_core)
 *   2. RUT            (usuarios_nova.rut / rut_sin_dv via usuarios_nova.usuario)
 *   3. Username / ID  (usuarios_nova.redmine_id or uuid)
 *   4. Full name      (nombre + apellido, bidirectional token equality)
 *
 * OUTPUT
 *   Console table (always)
 *   JSON file in storage/app/audits/audit_asignados_YYYY-MM-DD_HHmmss.json
 *
 * Usage:
 *   php artisan mantencion:audit-asignados
 *   php artisan mantencion:audit-asignados --fuente=core
 *   php artisan mantencion:audit-asignados --all   (include records with both fields empty)
 */
class AuditMantencionAsignados extends Command
{
    protected $signature = 'mantencion:audit-asignados
                            {--fuente= : Filter by fuente value (core, manual, …)}
                            {--all : Include records where both asignado fields are empty}';

    protected $description = 'Audit redmine_mantencion_reportes: detect assigned-user mismatches against NOVA identities (read-only)';

    /** @var array<string, array<string,string>> Indexed by redmine_id */
    private array $byRedmineId = [];

    /** @var array<string, array<string,string>> Indexed by normalised CORE username */
    private array $byCoreUser = [];

    /** @var array<string, array<string,string>> Indexed by digits-only RUT (with and without DV) */
    private array $byRut = [];

    /** @var array<string, list<array<string,string>>> Indexed by normalised full-name token set */
    private array $byName = [];

    public function handle(): int
    {
        if (! Schema::hasTable('redmine_mantencion_reportes') || ! Schema::hasTable('usuarios_nova')) {
            $this->error('Required tables are not present. Run migrations first.');
            return self::FAILURE;
        }

        $this->info('Loading NOVA users…');
        $this->buildUserMaps();

        $this->info('Querying redmine_mantencion_reportes…');
        $rows = $this->fetchReports();

        if ($rows->isEmpty()) {
            $this->info('Table is empty — nothing to audit.');
            return self::SUCCESS;
        }

        $this->info("Analysing {$rows->count()} record(s)…");

        $mismatches = [];
        $fuente = $this->option('fuente');
        $includeEmpty = (bool) $this->option('all');

        foreach ($rows as $row) {
            if ($fuente !== null && $fuente !== '' && ($row->fuente ?? '') !== $fuente) {
                continue;
            }

            $result = $this->analyseRow($row);

            if ($result === null) {
                continue;
            }

            // Skip the AMBOS_VACIOS class unless --all is set
            if ($result['reason_mismatch'] === 'AMBOS_VACIOS' && ! $includeEmpty) {
                continue;
            }

            $mismatches[] = $result;
        }

        if (empty($mismatches)) {
            $this->info('✔ No mismatches found.');
            return self::SUCCESS;
        }

        $this->warn(count($mismatches) . ' mismatch(es) detected.');
        $this->renderTable($mismatches);
        $this->writeJson($mismatches);

        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------
    // Analysis
    // -------------------------------------------------------------------------

    /**
     * Returns a mismatch record or null if the row is clean.
     *
     * @param  object $row  DB row
     * @return array<string,string>|null
     */
    private function analyseRow(object $row): ?array
    {
        $asignadoA    = trim((string) ($row->id_redmine_asignado ?? ''));
        $asignadoNom  = trim((string) ($row->asignado_nombre ?? ''));

        // Both fields empty
        if ($asignadoA === '' && $asignadoNom === '') {
            return $this->mismatch($row, null, 'AMBOS_VACIOS',
                'Ningún campo de asignado está poblado.');
        }

        $userById   = $asignadoA !== '' ? $this->resolveById($asignadoA)   : null;
        $userByName = $asignadoNom !== '' ? $this->resolveByName($asignadoNom) : null;

        // id set but doesn't match any NOVA user
        if ($asignadoA !== '' && $userById === null) {
            $expected = $userByName;
            return $this->mismatch(
                $row,
                $expected,
                'ASIGNADO_A_NO_EXISTE',
                "id_redmine_asignado=\"{$asignadoA}\" no coincide con ningún usuario NOVA."
                . ($expected !== null ? ' Nombre sugiere: ' . $this->userName($expected) : '')
            );
        }

        // name set but doesn't match any NOVA user
        if ($asignadoNom !== '' && $userByName === null) {
            $expected = $userById;
            return $this->mismatch(
                $row,
                $expected,
                'NOMBRE_NO_EXISTE',
                "asignado_nombre=\"{$asignadoNom}\" no coincide con ningún usuario NOVA."
                . ($expected !== null ? ' ID sugiere: ' . $this->userName($expected) : '')
            );
        }

        // id set, name empty (possible orphan — flag as informational)
        if ($asignadoA !== '' && $asignadoNom === '' && $userById !== null) {
            return $this->mismatch(
                $row,
                $userById,
                'ASIGNADO_A_SIN_NOMBRE',
                "id_redmine_asignado=\"{$asignadoA}\" resuelve a {$this->userName($userById)} pero asignado_nombre está vacío."
            );
        }

        // name set, id empty
        if ($asignadoNom !== '' && $asignadoA === '' && $userByName !== null) {
            return $this->mismatch(
                $row,
                $userByName,
                'NOMBRE_SIN_ASIGNADO_A',
                "asignado_nombre=\"{$asignadoNom}\" resuelve a {$this->userName($userByName)} pero id_redmine_asignado está vacío."
            );
        }

        // Both resolve — but to different users?
        if ($userById !== null && $userByName !== null
            && $userById['nova_id'] !== $userByName['nova_id']) {
            return $this->mismatch(
                $row,
                $userById,
                'ID_NOMBRE_CONTRADICEN',
                "id_redmine_asignado resuelve a {$this->userName($userById)} "
                . "pero asignado_nombre=\"{$asignadoNom}\" resuelve a {$this->userName($userByName)}."
            );
        }

        return null; // clean
    }

    /** @param array<string,string>|null $expectedUser */
    private function mismatch(object $row, ?array $expectedUser, string $reason, string $detail): array
    {
        return [
            'id'                       => (string) $row->id,
            'fuente'                   => (string) ($row->fuente ?? ''),
            'fuente_id'                => (string) ($row->fuente_id ?? ''),
            'asignado_a'               => (string) ($row->id_redmine_asignado ?? ''),
            'asignado_nombre'          => (string) ($row->asignado_nombre ?? ''),
            'usuario_asociado_esperado'=> $expectedUser !== null ? $this->userName($expectedUser) : 'DESCONOCIDO',
            'nova_id_esperado'         => $expectedUser !== null ? (string) ($expectedUser['nova_id'] ?? '') : '',
            'reason_mismatch'          => $reason,
            'detalle'                  => $detail,
        ];
    }

    // -------------------------------------------------------------------------
    // User resolution
    // -------------------------------------------------------------------------

    /** Resolve by id_redmine_asignado → matches usuarios_nova.redmine_id or uuid/usuario */
    private function resolveById(string $id): ?array
    {
        $norm = $this->normalise($id);
        if ($norm === '') {
            return null;
        }
        return $this->byRedmineId[$norm] ?? null;
    }

    /**
     * Resolve asignado_nombre against all identity maps (priority order):
     *   1. CORE username
     *   2. RUT
     *   3. Username/ID
     *   4. Full name
     */
    private function resolveByName(string $candidate): ?array
    {
        $norm = $this->normalise($candidate);
        if ($norm === '') {
            return null;
        }

        // 1. CORE username
        if (isset($this->byCoreUser[$norm])) {
            return $this->byCoreUser[$norm];
        }

        // 2. RUT (digits + optional K)
        $digits = strtolower(preg_replace('/[^0-9kK]/i', '', $candidate));
        if ($digits !== '') {
            if (isset($this->byRut[$digits])) {
                return $this->byRut[$digits];
            }
            // without DV
            $noK = preg_replace('/[^0-9]/', '', $digits);
            if ($noK !== '' && isset($this->byRut[$noK])) {
                return $this->byRut[$noK];
            }
        }

        // 3. Username/ID
        if (isset($this->byRedmineId[$norm])) {
            return $this->byRedmineId[$norm];
        }

        // 4. Full name — bidirectional token set equality
        $tokens = array_values(array_filter(explode(' ', $norm)));
        if (! empty($tokens)) {
            sort($tokens);
            $tokenKey = implode(' ', $tokens);
            $candidates = $this->byName[$tokenKey] ?? [];
            if (count($candidates) === 1) {
                return $candidates[0];
            }
            // Ambiguous match: multiple users share the same tokens — treat as unresolved
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // User maps
    // -------------------------------------------------------------------------

    private function buildUserMaps(): void
    {
        $users = DB::table('usuarios_nova')
            ->select('id as nova_id', 'redmine_id', 'uuid', 'usuario', 'rut', 'nombre', 'apellido', 'usuario_core')
            ->get();

        foreach ($users as $u) {
            $user = [
                'nova_id'      => (string) $u->nova_id,
                'redmine_id'   => trim((string) ($u->redmine_id ?? '')),
                'uuid'         => trim((string) ($u->uuid ?? '')),
                'usuario'      => trim((string) ($u->usuario ?? '')),
                'rut'          => trim((string) ($u->rut ?? '')),
                'nombre'       => trim((string) ($u->nombre ?? '')),
                'apellido'     => trim((string) ($u->apellido ?? '')),
                'usuario_core' => trim((string) ($u->usuario_core ?? '')),
            ];

            // Map by redmine_id (primary ID used in id_redmine_asignado)
            foreach ([$user['redmine_id'], $user['uuid'], $user['usuario']] as $candidate) {
                if ($candidate !== '') {
                    $key = $this->normalise($candidate);
                    if ($key !== '' && ! isset($this->byRedmineId[$key])) {
                        $this->byRedmineId[$key] = $user;
                    }
                }
            }

            // Map by CORE username
            if ($user['usuario_core'] !== '') {
                $key = $this->normalise($user['usuario_core']);
                if ($key !== '' && ! isset($this->byCoreUser[$key])) {
                    $this->byCoreUser[$key] = $user;
                }
            }

            // Map by RUT (digits+K with DV, digits-only without DV)
            if ($user['rut'] !== '') {
                $withDv = strtolower(preg_replace('/[^0-9kK]/i', '', $user['rut']));
                $withoutDv = preg_replace('/[^0-9]/', '', $withDv);
                foreach (array_unique(array_filter([$withDv, $withoutDv])) as $key) {
                    if (! isset($this->byRut[$key])) {
                        $this->byRut[$key] = $user;
                    }
                }
            }
            // Also index rut_sin_dv stored in the usuario column (legacy mapping)
            if ($user['usuario'] !== '') {
                $usuarioDigits = preg_replace('/[^0-9]/', '', $user['usuario']);
                if (strlen($usuarioDigits) >= 7 && ! isset($this->byRut[$usuarioDigits])) {
                    $this->byRut[$usuarioDigits] = $user;
                }
            }

            // Map by full name tokens (bidirectional)
            $fullName = trim($user['nombre'] . ' ' . $user['apellido']);
            if ($fullName !== '') {
                $tokens = array_values(array_filter(explode(' ', $this->normalise($fullName))));
                if (! empty($tokens)) {
                    sort($tokens);
                    $tokenKey = implode(' ', $tokens);
                    $this->byName[$tokenKey][] = $user;
                }
            }
        }

        $this->info(sprintf(
            '  Usuarios cargados: %d | por ID: %d | por RUT: %d | por CORE user: %d | por nombre: %d',
            $users->count(),
            count($this->byRedmineId),
            count($this->byRut),
            count($this->byCoreUser),
            count($this->byName)
        ));
    }

    // -------------------------------------------------------------------------
    // DB query
    // -------------------------------------------------------------------------

    private function fetchReports(): \Illuminate\Support\Collection
    {
        return DB::table('redmine_mantencion_reportes')
            ->select(
                'id',
                'fuente',
                'fuente_id',
                'id_redmine_asignado',
                'asignado_nombre',
                'estado',
                'creado_at',
            )
            ->orderBy('id')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Output
    // -------------------------------------------------------------------------

    /** @param list<array<string,string>> $mismatches */
    private function renderTable(array $mismatches): void
    {
        $headers = [
            'id', 'fuente', 'fuente_id (truncado)', 'asignado_a', 'asignado_nombre',
            'usuario_esperado', 'nova_id_esperado', 'reason_mismatch',
        ];

        $rows = array_map(function (array $m): array {
            return [
                $m['id'],
                $m['fuente'],
                strlen($m['fuente_id']) > 24 ? substr($m['fuente_id'], 0, 24) . '…' : $m['fuente_id'],
                $m['asignado_a'],
                mb_strimwidth($m['asignado_nombre'], 0, 30, '…'),
                mb_strimwidth($m['usuario_asociado_esperado'], 0, 28, '…'),
                $m['nova_id_esperado'],
                $m['reason_mismatch'],
            ];
        }, $mismatches);

        $this->table($headers, $rows);
    }

    /** @param list<array<string,string>> $mismatches */
    private function writeJson(array $mismatches): void
    {
        $timestamp = now()->format('Y-m-d_His');
        $filename  = "audits/audit_asignados_{$timestamp}.json";

        $payload = [
            'generado_en'   => now()->toIso8601String(),
            'total_mismatch'=> count($mismatches),
            'criterios'     => [
                'tabla'          => 'redmine_mantencion_reportes',
                'reglas_matching'=> [
                    '1_core_username' => 'usuarios_nova.usuario_core (exact normalized)',
                    '2_rut'           => 'usuarios_nova.rut digits±K (with/without DV)',
                    '3_username_id'   => 'usuarios_nova.redmine_id / uuid / usuario (exact normalized)',
                    '4_full_name'     => 'nombre+apellido bidirectional token set equality',
                ],
            ],
            'mismatches' => $mismatches,
        ];

        Storage::put($filename, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $path = storage_path('app/' . $filename);
        $this->info("Reporte escrito en: {$path}");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function normalise(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) {
            $value = $ascii;
        }
        $value = strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);
        return trim($value);
    }

    /** @param array<string,string> $user */
    private function userName(array $user): string
    {
        $nombre   = trim($user['nombre'] ?? '');
        $apellido = trim($user['apellido'] ?? '');
        $full = trim($nombre . ($apellido !== '' ? ' ' . $apellido : ''));
        $id   = $user['nova_id'] ?? '';
        return $full !== '' ? "{$full} (nova_id={$id})" : "nova_id={$id}";
    }
}
