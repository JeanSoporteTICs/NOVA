<?php

namespace App\Console\Commands;

use App\Services\Database\UpgradeSafety;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CheckDatabaseUpgrade extends Command
{
    protected $signature = 'nova:database-upgrade-check {--database= : Conexión Laravel a revisar}';

    protected $description = 'Comprueba sin escribir si la limpieza histórica pendiente destruiría datos';

    public function handle(UpgradeSafety $safety): int
    {
        try {
            $safety->assertMayUpgrade(DB::connection($this->option('database') ?: null));
        } catch (\RuntimeException $exception) {
            $this->error($exception instanceof QueryException || $exception instanceof \PDOException
                ? 'No se pudo comprobar la base de datos; revisa la conexión y sus permisos.' : $exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Limpieza histórica: aplicada o sin datos que eliminar. Esto no certifica otras migraciones ni la equivalencia de esquema.');

        return self::SUCCESS;
    }
}
