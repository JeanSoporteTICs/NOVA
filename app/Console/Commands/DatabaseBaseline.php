<?php

namespace App\Console\Commands;

use App\Services\Database\SchemaBaseline;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class DatabaseBaseline extends Command
{
    protected $signature = 'nova:database-baseline {action : capture, verify o bootstrap} {directory : Baseline revisada fuera de public} {--database= : Conexión Laravel explícita}';

    protected $description = 'Captura/verifica estructura y ledger; bootstrap solo admite una base vacía';

    public function handle(SchemaBaseline $baseline): int
    {
        $action = $this->argument('action');
        if (! in_array($action, ['capture', 'verify', 'bootstrap'], true)) {
            $this->error('Acción inválida. Usa capture, verify o bootstrap.');

            return self::FAILURE;
        }
        if ($action === 'bootstrap' && ! $this->option('database')) {
            $this->error('Bootstrap requiere --database con una conexión dedicada a la base vacía.');

            return self::FAILURE;
        }
        $directory = (string) $this->argument('directory');
        try {
            $db = DB::connection($this->option('database') ?: null);
            if ($action === 'capture') {
                $baseline->write($baseline->capture($db), $directory);
                $this->info('Baseline de estructura y ledger guardada. No incluye datos de la aplicación.');

                return self::SUCCESS;
            }
            $reference = $baseline->read($directory);
            if ($action === 'bootstrap') {
                $baseline->bootstrap($db, $reference);
                $this->info('Estructura verificada y ledger de la baseline instalado; no se ejecutaron migraciones históricas.');

                return self::SUCCESS;
            }
            $actual = $baseline->capture($db);
            $differences = $baseline->differences($reference, $actual);
            $ledgerMatches = $reference['migrations'] === $actual['migrations'];
            foreach ($differences as $section => $changes) {
                $this->line($section.': faltantes='.count($changes['missing']).', adicionales='.count($changes['extra']));
            }
            $this->line('Ledger: '.($ledgerMatches ? 'coincide' : 'difiere'));
            if ($differences !== [] || ! $ledgerMatches) {
                return self::FAILURE;
            }
            $this->info('Esquema y ledger coinciden con la baseline.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            // A connection/SQL exception can contain credentials or server details.
            $this->error($exception instanceof QueryException || $exception instanceof \PDOException
                ? 'Falló una operación de base de datos; revisa el destino y sus permisos.' : $exception->getMessage());

            return self::FAILURE;
        }
    }
}
