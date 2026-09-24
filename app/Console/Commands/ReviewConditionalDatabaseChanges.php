<?php

namespace App\Console\Commands;

use App\Repositories\Database\ConditionalChangeReview;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ReviewConditionalDatabaseChanges extends Command
{
    protected $signature = 'nova:database-review {--database= : Conexión a revisar} {--timeout=5 : Segundos por consulta (1–30)} {--json : Emitir evidencia agregada en JSON}';

    protected $description = 'P08: revisa condiciones de integridad e índices sin modificar datos ni esquema';

    public function handle(ConditionalChangeReview $review): int
    {
        try {
            $timeout = filter_var($this->option('timeout'), FILTER_VALIDATE_INT);
            if ($timeout === false) {
                throw new \RuntimeException('Timeout debe ser un entero entre 1 y 30.');
            }
            $result = $review->review(DB::connection($this->option('database') ?: null), $timeout);
            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            } else {
                $this->table(['Hallazgo', 'Comprobación', 'Cantidad', 'Estado'], array_map(fn ($r) => [$r['finding'], $r['label'], $r['count'] ?? '—', $r['status']], $result['checks']));
                $this->line('Índices con prefijo cubierto: '.count($result['index_prefix_candidates']).'. Requieren revisar uso, FK y recreación.');
                $this->line($result['note']);
            }

            // Incomplete schema must never be mistaken for a successful all-zero scan.
            return in_array('not_evaluated', array_column($result['checks'], 'status'), true) ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception instanceof QueryException || $exception instanceof \PDOException
                ? 'Revisión incompleta: fallo de conexión, permisos o plazo de consulta. No se certifican resultados.' : $exception->getMessage());

            return self::FAILURE;
        }
    }
}
