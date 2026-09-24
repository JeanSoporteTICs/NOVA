<?php

use App\Repositories\Database\SqlText;
use Illuminate\Support\Facades\DB;

// SQL reader before the fixed-width duplicate check; performance/compatibility oracle.
return static function (string $needle): array {
    $normalized = [];
    foreach (['rut', 'usuario', 'usuario_core', 'redmine_id', 'uuid'] as $column) {
        $normalized[$column] = SqlText::identity($column);
    }
    $name = SqlText::identity("CONCAT(COALESCE(nombre, ''), ' ', COALESCE(apellido, ''))");
    $anchor = "COALESCE(NULLIF({$normalized['rut']}, ''), NULLIF({$normalized['usuario']}, ''), NULLIF({$normalized['usuario_core']}, ''))";
    $key = "COALESCE(CONCAT('identity:', $anchor), CASE WHEN {$normalized['redmine_id']} <> '' AND $name <> '' THEN CONCAT('redmine-name:', {$normalized['redmine_id']}, ':', $name) ELSE '' END)";
    $keys = DB::table('usuarios_nova')->selectRaw("$key AS identity_key");
    $duplicates = DB::query()->fromSub($keys, 'identity_keys')->where('identity_key', '<>', '')
        ->groupBy('identity_key')->havingRaw('COUNT(*) > 1');
    $match = DB::table('usuarios_nova')->where(function ($query) use ($normalized, $needle): void {
        foreach ($normalized as $expression) {
            $query->orWhereRaw("$expression = ?", [$needle]);
        }
    })->orderBy('nombre')->orderBy('apellido')->select('id')->limit(1);
    // One round trip and one statement snapshot for ambiguity and selection.
    $result = DB::query()->selectSub($duplicates->selectRaw('1')->limit(1), 'ambiguous')
        ->selectSub($match, 'id')->first();
    $ambiguous = (bool) $result->ambiguous;

    return ['ambiguous' => $ambiguous, 'id' => $ambiguous || $result->id === null ? null : (int) $result->id];
};
