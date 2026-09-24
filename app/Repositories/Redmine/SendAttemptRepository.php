<?php

namespace App\Repositories\Redmine;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SendAttemptRepository
{
    public const TABLE = 'redmine_send_attempts';

    /** The short reservation transaction MUST finish before any HTTP call. */
    public function reserve(int $moduleId, string $key, float $requestStarted, callable $stillCurrent): array
    {
        try {
            return DB::transaction(function () use ($moduleId, $key, $requestStarted, $stillCurrent): array {
                if (! DB::table('modulos_nova')->where('id', $moduleId)->lockForUpdate()->first()) {
                    throw new \RuntimeException('Missing module');
                }
                $query = DB::table(self::TABLE)->where('modulo_id', $moduleId)->where('report_key', $key);
                $active = (clone $query)->where('reservation', 1)->first();
                if ($active) {
                    return ['ok' => false, 'error' => 'El reporte tiene un envío en curso o pendiente de conciliación (intento '.$active->attempt_id.'). No se volvió a enviar.'];
                }
                $latest = (clone $query)->orderByDesc('id')->first();
                if (($latest && (float) $latest->finished_epoch >= $requestStarted) || ! $stillCurrent()) {
                    return ['ok' => false, 'error' => 'El reporte cambió durante esta solicitud. Recarga antes de enviarlo.'];
                }
                $attempt = (string) Str::uuid();
                DB::table(self::TABLE)->insert([
                    'modulo_id' => $moduleId, 'report_key' => $key, 'attempt_id' => $attempt,
                    'reservation' => 1, 'status' => 'started', 'created_at' => now(), 'updated_at' => now(),
                ]);

                return ['ok' => true, 'attempt_id' => $attempt];
            });
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'No se pudo reservar el envío. Verifica la base de datos y la migración de intentos; no se envió el reporte.'];
        }
    }

    /** Persist remote evidence independently of report persistence and logging. */
    public function recordResponse(string $attempt, array $response): bool
    {
        $code = (int) ($response['http_code'] ?? 0);
        $payload = json_decode((string) ($response['body'] ?? ''), true);
        $ticket = filter_var($payload['issue']['id'] ?? null, FILTER_VALIDATE_INT);
        $confirmed = $code === 201 && $ticket > 0 && empty($response['error']);
        // Only definitive API rejection releases a possibly dispatched POST.
        $rejected = empty($response['error']) && in_array($code, [400, 401, 403, 404, 405, 413, 415, 422, 429], true);
        try {
            return DB::table(self::TABLE)->where('attempt_id', $attempt)->where('reservation', 1)->update([
                'status' => $confirmed ? 'confirmed_pending' : ($rejected ? 'rejected' : 'uncertain'),
                'http_code' => $code, 'redmine_id' => $confirmed ? $ticket : null, 'updated_at' => now(),
            ]) === 1;
        } catch (\Throwable) {
            return false; // A started reservation remains blocked, including after process death.
        }
    }

    public function needsReconciliation(string $attempt): bool
    {
        return DB::table(self::TABLE)->where('attempt_id', $attempt)->whereIn('status', ['started', 'uncertain'])->exists();
    }

    public function finish(string $attempt): bool
    {
        try {
            return DB::transaction(function () use ($attempt): bool {
                $row = DB::table(self::TABLE)->where('attempt_id', $attempt)->lockForUpdate()->first();
                if (! $row || ! in_array($row->status, ['confirmed_pending', 'rejected'], true)) {
                    return false;
                }
                DB::table(self::TABLE)->where('id', $row->id)->update([
                    'status' => $row->status === 'confirmed_pending' ? 'confirmed' : 'rejected',
                    'reservation' => null, 'finished_epoch' => microtime(true), 'updated_at' => now(),
                ]);

                return true;
            });
        } catch (\Throwable) {
            return false;
        }
    }
}
