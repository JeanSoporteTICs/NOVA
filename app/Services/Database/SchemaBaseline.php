<?php

namespace App\Services\Database;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/** Schema and migration-ledger only. Never exports application rows or DEFINERs. */
final class SchemaBaseline
{
    public function capture(ConnectionInterface $db): array
    {
        $database = $db->getDatabaseName();
        foreach (['VIEWS' => 'TABLE_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $table => $column) {
            if ($db->table('information_schema.'.$table)->where($column, $database)->exists()) {
                throw new RuntimeException('Baseline incompleta: se requiere soporte explícito para '.$table.'.');
            }
        }
        $schema = ['database' => [(array) $db->table('information_schema.SCHEMATA')->where('SCHEMA_NAME', $database)
            ->first(['DEFAULT_CHARACTER_SET_NAME', 'DEFAULT_COLLATION_NAME'])]];
        $queries = [
            'tables' => ['TABLES', 'TABLE_SCHEMA', ['TABLE_NAME', 'ENGINE', 'TABLE_COLLATION', 'ROW_FORMAT'], ['TABLE_NAME']],
            'columns' => ['COLUMNS', 'TABLE_SCHEMA', ['TABLE_NAME', 'COLUMN_NAME', 'ORDINAL_POSITION', 'COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_DEFAULT', 'EXTRA', 'CHARACTER_SET_NAME', 'COLLATION_NAME', 'GENERATION_EXPRESSION'], ['TABLE_NAME', 'ORDINAL_POSITION']],
            'indexes' => ['STATISTICS', 'TABLE_SCHEMA', ['TABLE_NAME', 'INDEX_NAME', 'NON_UNIQUE', 'SEQ_IN_INDEX', 'COLUMN_NAME', 'COLLATION', 'SUB_PART', 'INDEX_TYPE', 'INDEX_COMMENT'], ['TABLE_NAME', 'INDEX_NAME', 'SEQ_IN_INDEX']],
            'keys' => ['KEY_COLUMN_USAGE', 'TABLE_SCHEMA', ['TABLE_NAME', 'CONSTRAINT_NAME', 'COLUMN_NAME', 'ORDINAL_POSITION', 'REFERENCED_TABLE_SCHEMA', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME'], ['TABLE_NAME', 'CONSTRAINT_NAME', 'ORDINAL_POSITION']],
            'references' => ['REFERENTIAL_CONSTRAINTS', 'CONSTRAINT_SCHEMA', ['TABLE_NAME', 'CONSTRAINT_NAME', 'REFERENCED_TABLE_NAME', 'UPDATE_RULE', 'DELETE_RULE', 'MATCH_OPTION'], ['TABLE_NAME', 'CONSTRAINT_NAME']],
            'checks' => ['CHECK_CONSTRAINTS', 'CONSTRAINT_SCHEMA', ['TABLE_NAME', 'CONSTRAINT_NAME', 'CHECK_CLAUSE'], ['TABLE_NAME', 'CONSTRAINT_NAME']],
            'triggers' => ['TRIGGERS', 'TRIGGER_SCHEMA', ['TRIGGER_NAME', 'EVENT_MANIPULATION', 'EVENT_OBJECT_TABLE', 'ACTION_ORDER', 'ACTION_CONDITION', 'ACTION_STATEMENT', 'ACTION_ORIENTATION', 'ACTION_TIMING', 'SQL_MODE', 'CHARACTER_SET_CLIENT', 'COLLATION_CONNECTION', 'DATABASE_COLLATION'], ['EVENT_OBJECT_TABLE', 'ACTION_TIMING', 'EVENT_MANIPULATION', 'ACTION_ORDER']],
        ];
        foreach ($queries as $key => [$table, $column, $fields, $order]) {
            $query = $db->table('information_schema.'.$table)->where($column, $database);
            foreach ($order as $field) {
                $query->orderBy($field);
            }
            $schema[$key] = array_map(function ($row) use ($database): array {
                $values = (array) $row;
                foreach ($values as $key => $value) {
                    if ($value !== null) {
                        $values[$key] = (string) $value;
                    }
                    if ($key === 'REFERENCED_TABLE_SCHEMA' && $value === $database) {
                        $values[$key] = '@schema';
                    }
                    if ($key === 'COLUMN_TYPE' && ! str_contains((string) $value, 'zerofill')) {
                        $values[$key] = preg_replace('/\b(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', (string) $value);
                    }
                }

                return $values;
            }, $query->get($fields)->all());
        }
        foreach ($schema['keys'] as $key) {
            if ($key['REFERENCED_TABLE_SCHEMA'] !== null && $key['REFERENCED_TABLE_SCHEMA'] !== '@schema') {
                throw new RuntimeException('Baseline con FK externa: se requiere revisión de portabilidad.');
            }
        }
        $ddl = ['tables' => [], 'triggers' => []];
        foreach ($schema['tables'] as $table) {
            $name = $table['TABLE_NAME'];
            $row = (array) $db->selectOne('SHOW CREATE TABLE '.$this->identifier($name));
            $sql = $row['Create Table'];
            // AUTO_INCREMENT is a data counter, not part of the schema baseline.
            $ddl['tables'][$name] = preg_replace('/^(\) ENGINE=\w+) AUTO_INCREMENT=\d+\b/m', '$1', $sql);
        }
        foreach ($schema['triggers'] as $trigger) {
            $name = $trigger['TRIGGER_NAME'];
            $body = $trigger['ACTION_STATEMENT'];
            if (str_contains($body, '`'.$database.'`.')) {
                throw new RuntimeException('Trigger con referencia calificada: revisar portabilidad de '.$name.'.');
            }
            $ddl['triggers'][$name] = 'CREATE TRIGGER '.$this->identifier($name).' '.$trigger['ACTION_TIMING'].' '.$trigger['EVENT_MANIPULATION']
                .' ON '.$this->identifier($trigger['EVENT_OBJECT_TABLE']).' FOR EACH ROW '.$body;
        }
        $ledger = $db->getSchemaBuilder()->hasTable('migrations')
            ? $db->table('migrations')->orderBy('id')->get(['migration', 'batch'])->map(fn ($r): array => ['migration' => $r->migration, 'batch' => (int) $r->batch])->all() : [];

        return ['format' => 'nova-schema-baseline-v1', 'schema' => $schema, 'ddl' => $ddl, 'migrations' => $ledger];
    }

    public function differences(array $expected, array $actual): array
    {
        $differences = [];
        foreach (array_unique(array_merge(array_keys($expected['schema']), array_keys($actual['schema']))) as $section) {
            $before = $expected['schema'][$section] ?? [];
            $after = $actual['schema'][$section] ?? [];
            $encode = static fn (array $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $missing = array_diff(array_map($encode, $before), array_map($encode, $after));
            $extra = array_diff(array_map($encode, $after), array_map($encode, $before));
            if ($missing || $extra) {
                $differences[$section] = ['missing' => array_map(fn ($v) => json_decode($v, true), array_values($missing)), 'extra' => array_map(fn ($v) => json_decode($v, true), array_values($extra))];
            }
        }

        return $differences;
    }

    public function write(array $snapshot, string $directory): void
    {
        if (is_dir($directory) && (file_exists($directory.'/schema.sql') || file_exists($directory.'/manifest.json'))) {
            throw new RuntimeException('El destino ya contiene una baseline; usa otro directorio.');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('No se pudo crear el directorio.');
        }
        $sql = $this->sql($snapshot);
        $snapshot['sql_sha256'] = hash('sha256', $sql);
        if (file_put_contents($directory.'/schema.sql', $sql, LOCK_EX) === false || file_put_contents($directory.'/manifest.json', json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", LOCK_EX) === false) {
            throw new RuntimeException('No se pudo escribir la baseline.');
        }
    }

    private function sql(array $snapshot): string
    {
        $sql = "-- NOVA: estructura solamente. El ledger se importa con nova:database-baseline bootstrap.\nSET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;\nSET FOREIGN_KEY_CHECKS=0;\n";
        foreach ($snapshot['ddl']['tables'] as $statement) {
            $sql .= $statement.";\n\n";
        }
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        foreach ($snapshot['schema']['triggers'] as $trigger) {
            $this->identifier($trigger['CHARACTER_SET_CLIENT']);
            $this->identifier($trigger['COLLATION_CONNECTION']);
            $sql .= 'SET NAMES '.$trigger['CHARACTER_SET_CLIENT'].' COLLATE '.$trigger['COLLATION_CONNECTION'].";\nSET sql_mode='".str_replace("'", "''", $trigger['SQL_MODE'])."';\nDELIMITER //\n".$snapshot['ddl']['triggers'][$trigger['TRIGGER_NAME']]."//\nDELIMITER ;\n";
        }

        return $sql;
    }

    public function read(string $directory): array
    {
        $snapshot = json_decode(file_get_contents($directory.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($snapshot['format'] ?? '') !== 'nova-schema-baseline-v1' || ! hash_equals($snapshot['sql_sha256'] ?? '', hash_file('sha256', $directory.'/schema.sql')) || ! hash_equals($snapshot['sql_sha256'] ?? '', hash('sha256', $this->sql($snapshot)))) {
            throw new RuntimeException('Formato o checksum de baseline inválido.');
        }

        return $snapshot;
    }

    /** Empty schema only. The captured ledger is installed only after exact schema verification. */
    public function bootstrap(ConnectionInterface $db, array $snapshot): void
    {
        $empty = $this->capture($db);
        if ($empty['schema']['tables'] !== [] || $empty['schema']['triggers'] !== []) {
            throw new RuntimeException('Bootstrap rechazado: la base debe estar completamente vacía. No se elimina ningún objeto.');
        }
        if ($empty['schema']['database'] !== $snapshot['schema']['database']) {
            throw new RuntimeException('El charset/collation de la base vacía no coincide con la baseline.');
        }
        $previousMode = $db->selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        $previousEncoding = $db->selectOne('SELECT @@SESSION.character_set_client AS charset, @@SESSION.collation_connection AS collation');
        $previousForeignKeys = (int) $db->selectOne('SELECT @@SESSION.foreign_key_checks AS enabled')->enabled;
        try {
            // Validate server compatibility before any table is created.
            foreach ($snapshot['schema']['triggers'] as $trigger) {
                $this->setEncoding($db, $trigger['CHARACTER_SET_CLIENT'], $trigger['COLLATION_CONNECTION']);
                $db->statement('SET SESSION sql_mode = ?', [$trigger['SQL_MODE']]);
            }
            $this->setEncoding($db, $previousEncoding->charset, $previousEncoding->collation);
            $db->statement('SET SESSION sql_mode = ?', [$previousMode]);
            $db->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($snapshot['ddl']['tables'] as $statement) {
                $db->unprepared($statement);
            }
            $db->statement('SET FOREIGN_KEY_CHECKS=1');
            foreach ($snapshot['schema']['triggers'] as $trigger) {
                $this->setEncoding($db, $trigger['CHARACTER_SET_CLIENT'], $trigger['COLLATION_CONNECTION']);
                $db->statement('SET SESSION sql_mode = ?', [$trigger['SQL_MODE']]);
                $db->unprepared($snapshot['ddl']['triggers'][$trigger['TRIGGER_NAME']]);
            }
            $actual = $this->capture($db);
            if ($this->differences($snapshot, $actual) !== []) {
                throw new RuntimeException('El esquema importado difiere de la baseline. Se conserva para revisión; no se instaló el ledger.');
            }
            $db->transaction(function () use ($db, $snapshot): void {
                foreach ($snapshot['migrations'] as $migration) {
                    $db->table('migrations')->insert($migration);
                }
            });
        } finally {
            $this->setEncoding($db, $previousEncoding->charset, $previousEncoding->collation);
            $db->statement('SET SESSION sql_mode = ?', [$previousMode]);
            $db->statement('SET FOREIGN_KEY_CHECKS='.$previousForeignKeys);
        }
    }

    private function setEncoding(ConnectionInterface $db, string $charset, string $collation): void
    {
        $this->identifier($charset);
        $this->identifier($collation);
        $db->statement('SET NAMES '.$charset.' COLLATE '.$collation);
    }

    private function identifier(string $name): string
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
            throw new RuntimeException('Nombre de objeto no soportado.');
        }

        return '`'.$name.'`';
    }
}
