<?php

namespace App\Modulos\Shared\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ModuleLogRepository
{
    private const TABLES = [
        'telegram' => 'telegram_log',
        'emach' => 'emach_log',
    ];

    /** @param array<string,mixed> $context */
    public function append(string $module, string $event, ?string $userId = null, string $detail = '', array $context = []): void
    {
        $table = self::TABLES[$module] ?? null;
        if ($table === null || ! Schema::hasTable($table)) {
            return;
        }

        DB::table($table)->insert([
            'evento' => trim($event) !== '' ? trim($event) : 'evento',
            'usuario_id' => ($userId = trim((string) $userId)) !== '' ? $userId : null,
            'detalle' => trim($detail) !== '' ? trim($detail) : null,
            'contexto' => $context !== []
                ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'registrado_at' => now(),
        ]);
    }
}
