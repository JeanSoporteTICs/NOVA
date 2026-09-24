<?php

namespace App\Console\Commands;

use App\Repositories\Redmine\SendAttemptRepository;
use App\Services\Redmine\SendAttemptReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReconcileRedmineSend extends Command
{
    protected $signature = 'redmine:reconcile-send {attempt? : UUID del intento; sin UUID lista las reservas}
        {--ticket= : ID verificado manualmente en Redmine}
        {--not-created : Se verificó que Redmine no creó el ticket}
        {--note= : Referencia de la revisión, sin credenciales}
        {--writer-stopped : Se comprobó que el proceso emisor terminó o fue detenido}
        {--apply : Aplicar la conciliación; sin esta opción solo muestra el intento}';

    protected $description = 'Consultar y conciliar envíos Redmine pendientes sin crear tickets remotos';

    public function handle(SendAttemptReconciler $reconciler): int
    {
        $query = DB::table(SendAttemptRepository::TABLE)->where('reservation', 1);
        if ($this->argument('attempt')) {
            $query->where('attempt_id', $this->argument('attempt'));
        }
        $rows = $query->orderBy('id')->get(['attempt_id', 'modulo_id', 'report_key', 'status', 'redmine_id', 'http_code', 'created_at']);
        $this->table(['Intento', 'Módulo', 'Reporte', 'Estado interno', 'Ticket', 'HTTP', 'Inicio'], $rows->map(fn ($row) => (array) $row)->all());
        if (! $this->option('apply')) {
            return self::SUCCESS;
        }
        $hasTicket = $this->option('ticket') !== null;
        if (! $this->argument('attempt') || $rows->count() !== 1 || ! $this->option('writer-stopped') || $hasTicket === (bool) $this->option('not-created')) {
            $this->error('Indica un intento, --writer-stopped y exactamente una opción: --ticket=ID o --not-created. Verifica primero el resultado en Redmine.');

            return self::FAILURE;
        }
        try {
            $ticket = $hasTicket ? filter_var($this->option('ticket'), FILTER_VALIDATE_INT) : null;
            if ($hasTicket && (! $ticket || $ticket <= 0)) {
                throw new \RuntimeException('ID de ticket inválido.');
            }
            $reconciler->resolve((string) $this->argument('attempt'), $ticket, (string) $this->option('note'));
            $this->info('Conciliación guardada. No se enviaron solicitudes a Redmine.');

            return self::SUCCESS;
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
