<?php

use App\Modulos\Nova\Support\SecretValue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SHARED_TYPE = 'redmine';

    private const LEGACY_TYPES = ['redmine_mantencion', 'redmine_tic'];

    public function up(): void
    {
        if (! Schema::hasTable('integraciones_usuario')) {
            return;
        }

        $types = array_merge([self::SHARED_TYPE], self::LEGACY_TYPES);
        $userIds = DB::table('integraciones_usuario')
            ->whereIn('tipo', $types)
            ->distinct()
            ->pluck('usuario_id');

        foreach ($userIds as $userId) {
            $rows = DB::table('integraciones_usuario')
                ->where('usuario_id', $userId)
                ->whereIn('tipo', $types)
                ->orderByDesc('actualizado_at')
                ->get();

            $canonical = $rows->firstWhere('tipo', self::SHARED_TYPE);
            $selected = $canonical !== null && SecretValue::inspect($canonical->valor_secreto ?? null)['decryptable']
                ? $canonical
                : ($rows->first(fn (object $row): bool => SecretValue::inspect($row->valor_secreto ?? null)['decryptable'])
                    ?? $canonical
                    ?? $rows->first());

            if ($selected === null) {
                continue;
            }

            DB::table('integraciones_usuario')->updateOrInsert(
                ['usuario_id' => $userId, 'tipo' => self::SHARED_TYPE],
                [
                    'usuario_externo' => $selected->usuario_externo,
                    'valor_secreto' => $selected->valor_secreto,
                    'actualizado_at' => $selected->actualizado_at ?? now(),
                ]
            );

            DB::table('integraciones_usuario')
                ->where('usuario_id', $userId)
                ->whereIn('tipo', self::LEGACY_TYPES)
                ->delete();
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('integraciones_usuario')) {
            return;
        }

        foreach (DB::table('integraciones_usuario')->where('tipo', self::SHARED_TYPE)->get() as $row) {
            foreach (self::LEGACY_TYPES as $type) {
                DB::table('integraciones_usuario')->updateOrInsert(
                    ['usuario_id' => $row->usuario_id, 'tipo' => $type],
                    [
                        'usuario_externo' => $row->usuario_externo,
                        'valor_secreto' => $row->valor_secreto,
                        'actualizado_at' => $row->actualizado_at ?? now(),
                    ]
                );
            }
        }

        DB::table('integraciones_usuario')->where('tipo', self::SHARED_TYPE)->delete();
    }
};
