<?php

namespace App\Modulos\RedmineMantencion\Repositories;

use Illuminate\Support\Facades\DB;

final class MantencionPlantillaManualRepository
{
    private const TABLE = 'redmine_mantencion_plantillas_manuales';

    public function all(string $userId): array
    {
        return DB::table(self::TABLE)
            ->where('usuario_nova_id', $userId)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'asunto', 'categoria'])
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }

    public function nameExists(string $userId, string $nombre, ?int $exceptId = null): bool
    {
        $query = DB::table(self::TABLE)->where('usuario_nova_id', $userId)->where('nombre', $nombre);
        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }

        return $query->exists();
    }

    public function create(string $userId, string $nombre, string $asunto, string $categoria): void
    {
        DB::table(self::TABLE)->insert([
            'usuario_nova_id' => $userId,
            'nombre' => $nombre,
            'asunto' => $asunto,
            'categoria' => $categoria,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function update(string $userId, int $id, string $nombre, string $asunto, string $categoria): bool
    {
        $query = DB::table(self::TABLE)->where('usuario_nova_id', $userId)->where('id', $id);
        if (! (clone $query)->exists()) {
            return false;
        }

        $query->update(['nombre' => $nombre, 'asunto' => $asunto, 'categoria' => $categoria, 'updated_at' => now()]);

        return true;
    }

    public function delete(string $userId, int $id): void
    {
        DB::table(self::TABLE)->where('usuario_nova_id', $userId)->where('id', $id)->delete();
    }
}
