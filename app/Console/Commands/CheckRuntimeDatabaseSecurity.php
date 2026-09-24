<?php

namespace App\Console\Commands;

use App\Repositories\Database\RuntimeSecurityReview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class CheckRuntimeDatabaseSecurity extends Command
{
    protected $signature = 'nova:database-security-check {--database= : Conexión a inspeccionar}';

    protected $description = 'P01: inspección de permisos directos y cifrado de sesión, sin mostrar secretos ni cambiar datos';

    public function handle(RuntimeSecurityReview $review): int
    {
        try {
            $result = $review->review(DB::connection($this->option('database') ?: null));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            // This exit status concerns direct application grants only. TLS is reported separately.
            return $result['direct_grants_dml_only'] ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $this->error('Revisión incompleta: no se pudo consultar la conexión o sus permisos. No se certifican resultados.');

            return self::FAILURE;
        }
    }
}
