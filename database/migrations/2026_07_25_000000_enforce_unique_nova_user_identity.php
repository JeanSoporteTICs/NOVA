<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('usuarios_nova')) {
            return;
        }

        $rows = DB::table('usuarios_nova')->orderBy('id')->get(['id', 'usuario', 'rut']);
        $usernames = [];
        $ruts = [];

        foreach ($rows as $row) {
            $username = trim((string) $row->usuario);
            $usernameKey = strtolower((string) preg_replace('/[^0-9a-z]/i', '', $username));
            $rutClean = strtolower((string) preg_replace('/[^0-9k]/i', '', trim((string) $row->rut)));

            if ($usernameKey !== '' && isset($usernames[$usernameKey])) {
                throw new RuntimeException('No se puede aplicar unicidad: hay usuarios de acceso repetidos.');
            }
            if ($rutClean !== '' && isset($ruts[$rutClean])) {
                throw new RuntimeException('No se puede aplicar unicidad: hay RUT repetidos.');
            }

            if ($usernameKey !== '') {
                $usernames[$usernameKey] = (int) $row->id;
            }
            if ($rutClean !== '') {
                $ruts[$rutClean] = (int) $row->id;
            }

            $canonicalRut = strlen($rutClean) >= 2
                ? substr($rutClean, 0, -1) . '-' . substr($rutClean, -1)
                : ($rutClean !== '' ? $rutClean : null);

            DB::table('usuarios_nova')
                ->where('id', $row->id)
                ->update([
                    'usuario' => $username,
                    'rut' => $canonicalRut,
                ]);
        }

        $indexes = Schema::getIndexes('usuarios_nova');
        $hasUniqueColumn = static function (string $column) use ($indexes): bool {
            foreach ($indexes as $index) {
                if (
                    ($index['unique'] ?? false)
                    && array_values($index['columns'] ?? []) === [$column]
                ) {
                    return true;
                }
            }

            return false;
        };

        Schema::table('usuarios_nova', function (Blueprint $table) use ($hasUniqueColumn): void {
            if (!$hasUniqueColumn('usuario')) {
                $table->unique('usuario', 'uq_usuarios_nova_usuario_identity');
            }
            if (!$hasUniqueColumn('rut')) {
                $table->unique('rut', 'uq_usuarios_nova_rut_identity');
            }
        });
    }

    public function down(): void
    {
        // The canonical RUT is semantically equivalent to the previous
        // formatting and identity uniqueness must not be weakened on rollback.
    }
};
