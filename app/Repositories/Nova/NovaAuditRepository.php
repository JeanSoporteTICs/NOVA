<?php

namespace App\Repositories\Nova;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NovaAuditRepository
{
    /**
     * @param array<string,mixed> $context
     */
    public function record(string $event, string $message, array $context = [], ?Request $request = null): void
    {
        $sessionUser = $request?->session()->get('nova_user', []);

        if ($this->tableReady()) {
            try {
                DB::table('nova_audit_logs')->insert([
                    'event'         => $event,
                    'message'       => $message,
                    'user_id'       => is_array($sessionUser) ? (string) ($sessionUser['id'] ?? '') : '',
                    'user_name'     => is_array($sessionUser)
                        ? trim((string) (($sessionUser['name'] ?? '') . ' ' . ($sessionUser['apellido'] ?? '')))
                        : '',
                    'ip'            => $request?->ip() ?? '',
                    'contexto'      => $context !== [] ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
                    'registrado_at' => now('America/Santiago')->toDateTimeString(),
                ]);

                $oldest = DB::table('nova_audit_logs')
                    ->orderByDesc('id')
                    ->skip(500)
                    ->take(1)
                    ->value('id');
                if ($oldest !== null) {
                    DB::table('nova_audit_logs')->where('id', '<=', $oldest)->delete();
                }

                return;
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 100): array
    {
        if ($this->tableReady()) {
            try {
                $rows = DB::table('nova_audit_logs')
                    ->orderByDesc('registrado_at')
                    ->orderByDesc('id')
                    ->limit(max(1, $limit))
                    ->get(['event', 'message', 'user_id', 'user_name', 'ip', 'contexto', 'registrado_at'])
                    ->map(static function (object $row): array {
                        $ctx = $row->contexto !== null ? json_decode((string) $row->contexto, true) : [];
                        return [
                            'at'        => (string) $row->registrado_at,
                            'event'     => (string) $row->event,
                            'message'   => (string) $row->message,
                            'user_id'   => (string) $row->user_id,
                            'user_name' => (string) $row->user_name,
                            'ip'        => (string) $row->ip,
                            'context'   => is_array($ctx) ? $ctx : [],
                        ];
                    })
                    ->all();
                return $rows;
            } catch (\Throwable) {
            }
        }

        return [];
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('nova_audit_logs');
        } catch (\Throwable) {
            return false;
        }
    }
}
