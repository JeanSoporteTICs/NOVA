<?php

namespace App\Services\Redmine;

use App\Repositories\Redmine\SendAttemptRepository;
use Illuminate\Support\Facades\DB;

/** Explicit operator reconciliation after checking the remote ticket and stopping its writer. */
final class SendAttemptReconciler
{
    public function resolve(string $attempt, ?int $ticket, string $note): void
    {
        if (trim($note) === '' || mb_strlen($note) > 500 || ($ticket !== null && $ticket <= 0)) {
            throw new \RuntimeException('Indica una referencia de revisión de hasta 500 caracteres y un ticket válido.');
        }
        DB::transaction(function () use ($attempt, $ticket, $note): void {
            $row = DB::table(SendAttemptRepository::TABLE)->where('attempt_id', $attempt)->lockForUpdate()->first();
            if (! $row || ! $row->reservation) {
                throw new \RuntimeException('El intento no tiene una reserva pendiente.');
            }
            if ($row->redmine_id && (int) $row->redmine_id !== $ticket) {
                throw new \RuntimeException('La respuesta guardada confirma otro ticket; no se puede descartar esa evidencia.');
            }
            [$type, $id] = explode(':', $row->report_key, 2);
            $table = match ($type) {
                'tic' => 'redmine_tic_reportes', 'mantencion' => 'redmine_mantencion_reportes', default => throw new \RuntimeException('Tipo de reporte inválido.')
            };
            $idColumn = $type === 'tic' ? 'redmine_id' : 'numero_ticket_redmine';
            $query = DB::table($table)->where('modulo_id', $row->modulo_id)->where('id', $id);
            $report = (clone $query)->lockForUpdate()->first();
            if (! $report) {
                throw new \RuntimeException('El reporte ya no existe. Conservar el intento para revisión manual.');
            }
            if ($ticket !== null) {
                // A sequential resend can have an older ID; replacing it requires the operator's explicit review.
                $values = [$idColumn => $ticket, 'actualizado_at' => now()];
                if ($report->estado !== 'archivado') {
                    $values['estado'] = 'procesado';
                }
                if ($type === 'tic') {
                    $values['procesado_at'] = now();
                }
                $query->update($values);
            }
            DB::table(SendAttemptRepository::TABLE)->where('id', $row->id)->update([
                'status' => $ticket !== null ? 'reconciled' : 'not_created', 'redmine_id' => $ticket,
                'reservation' => null, 'resolution_note' => trim($note), 'finished_epoch' => microtime(true), 'updated_at' => now(),
            ]);
        });
    }
}
