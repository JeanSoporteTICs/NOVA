<?php

namespace App\Repositories\Database;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/** Aggregated evidence only: no application readers, repair, DDL or personal data. */
final class ConditionalChangeReview
{
    public function review(ConnectionInterface $db, int $timeout = 5): array
    {
        if ($timeout < 1 || $timeout > 30 || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            throw new RuntimeException('Revisión requiere una conexión sin transacción y timeout entre 1 y 30 segundos.');
        }
        $version = $db->selectOne('SELECT VERSION() AS version')->version;
        if (! str_contains($version, 'MariaDB')) {
            throw new RuntimeException('Esta revisión requiere MariaDB.');
        }
        $previous = $db->selectOne('SELECT @@SESSION.max_statement_time AS timeout')->timeout;
        $deadline = microtime(true) + 60;
        $started = false;
        try {
            $db->statement('SET SESSION max_statement_time = ?', [$timeout]);
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $db->statement('SET TRANSACTION READ ONLY');
            $db->beginTransaction();
            $started = true;
            $columns = [];
            foreach ($db->table('information_schema.COLUMNS')->where('TABLE_SCHEMA', $db->getDatabaseName())->get(['TABLE_NAME', 'COLUMN_NAME']) as $row) {
                $columns[$row->TABLE_NAME][] = $row->COLUMN_NAME;
            }
            $checks = [];
            foreach ($this->definitions() as $key => [$finding, $label, $requires, $query]) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('Revisión incompleta: se agotó el plazo total de 60 segundos.');
                }
                $missing = [];
                foreach ($requires as $table => $fields) {
                    foreach ($fields as $field) {
                        if (! in_array($field, $columns[$table] ?? [], true)) {
                            $missing[] = $table.'.'.$field;
                        }
                    }
                }
                $count = $missing === [] ? (int) $db->selectOne('SELECT COUNT(*) AS n FROM ('.$query.') AS evidence')->n : null;
                $checks[$key] = ['finding' => $finding, 'label' => $label, 'count' => $count,
                    'status' => $count === null ? 'not_evaluated' : ($count > 0 ? 'observed' : 'not_observed'), 'missing' => $missing];
            }
            $indexes = $db->table('information_schema.STATISTICS')->where('TABLE_SCHEMA', $db->getDatabaseName())
                ->orderBy('TABLE_NAME')->orderBy('INDEX_NAME')->orderBy('SEQ_IN_INDEX')
                ->get(['TABLE_NAME', 'INDEX_NAME', 'NON_UNIQUE', 'COLUMN_NAME', 'SUB_PART', 'COLLATION', 'INDEX_TYPE'])->map(fn ($r) => (array) $r)->all();

            return ['format' => 'nova-conditional-review-v1', 'server' => $version, 'automatic_changes' => false,
                'checks' => $checks, 'index_prefix_candidates' => $this->prefixCandidates($indexes),
                'note' => 'Conteos e índices son evidencia, no autorización de saneamiento/DDL. Cero casos no prueba un contrato de negocio.'];
        } finally {
            try {
                if ($started) {
                    $db->rollBack();
                }
            } finally {
                // MariaDB requires a numeric literal here; PDO binds fractional values as strings.
                $db->statement('SET SESSION max_statement_time = '.sprintf('%.6F', (float) $previous));
            }
        }
    }

    /** Prefix coverage is a candidate for review, never proof an index can be removed. */
    public function prefixCandidates(array $rows): array
    {
        $indexes = [];
        foreach ($rows as $row) {
            $key = $row['TABLE_NAME'].'.'.$row['INDEX_NAME'];
            $indexes[$key]['table'] = $row['TABLE_NAME'];
            $indexes[$key]['name'] = $row['INDEX_NAME'];
            $indexes[$key]['non_unique'] = (int) $row['NON_UNIQUE'];
            $indexes[$key]['type'] = $row['INDEX_TYPE'];
            $indexes[$key]['parts'][] = [$row['COLUMN_NAME'], $row['SUB_PART'] === null ? null : (int) $row['SUB_PART'], $row['COLLATION']];
        }
        $candidates = [];
        foreach ($indexes as $short) {
            foreach ($indexes as $long) {
                if ($short['non_unique'] !== 1 || $short['type'] !== 'BTREE' || $short['table'] !== $long['table'] || $short['type'] !== $long['type'] || count($short['parts']) >= count($long['parts'])) {
                    continue;
                }
                if ($short['parts'] !== array_slice($long['parts'], 0, count($short['parts']))) {
                    continue;
                }
                $candidates[] = ['table' => $short['table'], 'index' => $short['name'], 'covered_by' => $long['name'],
                    'requires' => 'Planes y uso de todos los consumidores, dependencias FK y ensayo de recreación; no retirar automáticamente.'];
            }
        }

        return $candidates;
    }

    private function definitions(): array
    {
        $groups = ['horas_extra_grupos' => ['id', 'usuario_id', 'fecha']];
        $links = ['horas_extra_grupo_reportes' => ['grupo_id', 'origen', 'reporte_id']];
        $checks = [
            'hours_multiple_groups' => ['DB-004', 'Pares origen/reporte vinculados a más de una jornada', $links,
                'SELECT origen, reporte_id FROM horas_extra_grupo_reportes GROUP BY origen,reporte_id HAVING COUNT(DISTINCT grupo_id)>1'],
            'hours_empty_groups' => ['DB-004', 'Jornadas sin vínculos (no implica que deban borrarse)', $groups + $links,
                'SELECT g.id FROM horas_extra_grupos g WHERE NOT EXISTS (SELECT 1 FROM horas_extra_grupo_reportes p WHERE p.grupo_id=g.id)'],
            'hours_unknown_origin' => ['DB-004', 'Vínculos con origen distinto de tic/mantencion', $links,
                "SELECT grupo_id FROM horas_extra_grupo_reportes WHERE origen NOT IN ('tic','mantencion') OR origen IS NULL"],
            'hours_null_owner' => ['DB-012', 'Jornadas sin usuario, admitidas por el esquema', $groups,
                'SELECT id FROM horas_extra_grupos WHERE usuario_id IS NULL'],
            'hours_null_owner_same_date' => ['DB-012', 'Fechas con varias jornadas sin usuario', $groups,
                'SELECT fecha FROM horas_extra_grupos WHERE usuario_id IS NULL GROUP BY fecha HAVING COUNT(*)>1'],
            'mantencion_source_duplicates' => ['DB-008', 'Claves completas módulo/fuente/fuente_id repetidas', ['redmine_mantencion_reportes' => ['modulo_id', 'fuente', 'fuente_id']],
                'SELECT modulo_id,fuente,fuente_id FROM redmine_mantencion_reportes WHERE modulo_id IS NOT NULL AND fuente IS NOT NULL AND fuente_id IS NOT NULL GROUP BY modulo_id,fuente,fuente_id HAVING COUNT(*)>1'],
            'mantencion_incomplete_source' => ['DB-008', 'Reportes con identidad de fuente NULL o vacía', ['redmine_mantencion_reportes' => ['modulo_id', 'fuente', 'fuente_id']],
                "SELECT modulo_id FROM redmine_mantencion_reportes WHERE modulo_id IS NULL OR fuente IS NULL OR TRIM(fuente)='' OR fuente_id IS NULL OR TRIM(fuente_id)=''"],
            'mantencion_core_duplicates' => ['DB-008', 'Claves CORE módulo/id_core no vacías repetidas', ['redmine_mantencion_reportes' => ['modulo_id', 'id_core']],
                "SELECT modulo_id,id_core FROM redmine_mantencion_reportes WHERE modulo_id IS NOT NULL AND id_core IS NOT NULL AND TRIM(id_core)<>'' GROUP BY modulo_id,id_core HAVING COUNT(*)>1"],
            'hours_outside_clock' => ['DB-022', 'Horarios fuera del rango de reloj diario', ['horas_extra_grupos' => ['id', 'hora_inicio', 'hora_fin']],
                "SELECT id FROM horas_extra_grupos WHERE hora_inicio<'00:00:00' OR hora_inicio>'23:59:59' OR hora_fin<'00:00:00' OR hora_fin>'23:59:59'"],
            'hours_overnight' => ['DB-007/022', 'Jornadas que cruzan medianoche; no se consideran inválidas', ['horas_extra_grupos' => ['id', 'hora_inicio', 'hora_fin']],
                'SELECT id FROM horas_extra_grupos WHERE hora_inicio>hora_fin'],
            'audit_rows' => ['DB-020', 'Eventos globales conservados; no mide eventos ya purgados', ['nova_audit_logs' => ['id']], 'SELECT id FROM nova_audit_logs'],
        ];
        foreach (['tic' => ['redmine_tic_reportes', 'fecha'], 'mantencion' => ['redmine_mantencion_reportes', 'fecha_reporte']] as $origin => [$table, $date]) {
            $requires = $groups + $links + [$table => ['id', 'fecha_inicio', $date]];
            $checks[$origin.'_hours_date_mismatch'] = ['DB-004', 'Vínculos '.$origin.' con fecha distinta de la fecha actual del reporte', $requires,
                "SELECT p.grupo_id FROM horas_extra_grupo_reportes p JOIN horas_extra_grupos g ON g.id=p.grupo_id JOIN $table r ON r.id=p.reporte_id WHERE p.origen='$origin' AND COALESCE(r.fecha_inicio,r.$date) IS NOT NULL AND g.fecha<>COALESCE(r.fecha_inicio,r.$date)"];
            $checks[$origin.'_orphan_report_links'] = ['DB-004', 'Vínculos '.$origin.' sin reporte', $links + [$table => ['id']],
                "SELECT p.grupo_id FROM horas_extra_grupo_reportes p LEFT JOIN $table r ON r.id=p.reporte_id WHERE p.origen='$origin' AND r.id IS NULL"];
            $checks[$origin.'_invalid_boolean'] = ['DB-022', 'Reportes '.$origin.' con hora_extra fuera de 0/1', [$table => ['id', 'hora_extra']], "SELECT id FROM $table WHERE hora_extra NOT IN (0,1)"];
            $checks[$origin.'_negative_estimate'] = ['DB-022', 'Reportes '.$origin.' con tiempo estimado negativo', [$table => ['id', 'tiempo_estimado']], "SELECT id FROM $table WHERE tiempo_estimado<0"];
        }
        foreach (['categorias', 'unidades'] as $table) {
            $requires = [$table => ['modulo_id', 'clave_externa']];
            $checks[$table.'_external_duplicates'] = ['DB-013', 'Claves externas repetidas de '.$table.' por módulo (no NULL)', $requires,
                "SELECT modulo_id,clave_externa FROM $table WHERE modulo_id IS NOT NULL AND clave_externa IS NOT NULL GROUP BY modulo_id,clave_externa HAVING COUNT(*)>1"];
            $checks[$table.'_incomplete_identity'] = ['DB-013', 'Ítems '.$table.' con módulo/clave NULL o clave vacía; pueden ser manuales válidos', $requires,
                "SELECT modulo_id FROM $table WHERE modulo_id IS NULL OR clave_externa IS NULL OR TRIM(clave_externa)=''"];
        }
        foreach (['categoria_catalogo_id' => 'categoria', 'unidad_catalogo_id' => 'unidad', 'unidad_solicitante_catalogo_id' => 'unidad'] as $column => $type) {
            $checks['tic_'.$column.'_mismatch'] = ['DB-014', 'Referencias TIC '.$column.' sin catálogo o con módulo/tipo distinto',
                ['redmine_tic_reportes' => ['id', 'modulo_id', $column], 'catalogos_modulo' => ['id', 'modulo_id', 'tipo']],
                "SELECT r.id FROM redmine_tic_reportes r LEFT JOIN catalogos_modulo c ON c.id=r.$column WHERE r.$column IS NOT NULL AND (c.id IS NULL OR NOT(c.modulo_id <=> r.modulo_id) OR NOT(c.tipo <=> '$type'))"];
        }
        $checks['mantencion_category_mismatch'] = ['DB-014', 'Categorías Mantención ausentes o de módulo distinto (incluye NULL)',
            ['redmine_mantencion_reportes' => ['id', 'modulo_id', 'categoria_id'], 'categorias' => ['id', 'modulo_id']],
            'SELECT r.id FROM redmine_mantencion_reportes r LEFT JOIN categorias c ON c.id=r.categoria_id WHERE r.categoria_id IS NOT NULL AND (c.id IS NULL OR NOT(c.modulo_id <=> r.modulo_id))'];

        return $checks;
    }
}
