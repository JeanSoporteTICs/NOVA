<?php

namespace App\Modulos\Nova\Repositories;

use App\Repositories\Database\SqlText;
use Illuminate\Support\Facades\DB;

final class NovaIdentityLookupRepository
{
    /** SQL projection of NovaUserService keys; ambiguous directories retain its merge path. */
    public function lookup(string $needle): array
    {
        $normalized = [];
        foreach (['rut', 'usuario', 'usuario_core', 'redmine_id', 'uuid'] as $column) {
            $normalized[$column] = SqlText::identity($column);
        }
        $name = SqlText::identity("CONCAT(COALESCE(nombre, ''), ' ', COALESCE(apellido, ''))");
        $anchor = "COALESCE(NULLIF({$normalized['rut']}, ''), NULLIF({$normalized['usuario']}, ''), NULLIF({$normalized['usuario_core']}, ''))";
        $groupKey = "COALESCE(CONCAT('identity:', $anchor), CASE WHEN {$normalized['redmine_id']} <> '' AND $name <> '' THEN CONCAT('redmine-name:', {$normalized['redmine_id']}, ':', $name) ELSE CONCAT('empty:', id) END)";
        // LIKE is only a cheap superset: normalization remains the final test.
        // The needle comes from NovaUserService::normalizeIdentity (ASCII).
        $prefilter = '%'.implode('%', str_split($needle)).'%';
        $conditions = [];
        $bindings = [];
        foreach ($normalized as $column => $expression) {
            $conditions[] = "($column COLLATE utf8mb4_general_ci LIKE ? AND $expression = ?)";
            array_push($bindings, $prefilter, $needle);
        }
        // Fixed-width binary keys avoid DISTINCT over REGEXP_REPLACE's text
        // result. A hash collision can only request the established PHP merge;
        // hashes never choose an account. Empty identities remain unique by ID.
        // One round trip and one statement snapshot for ambiguity and selection.
        $result = DB::selectOne(
            "SELECT (SELECT COUNT(*) > COUNT(DISTINCT UNHEX(SHA2($groupKey, 256))) FROM usuarios_nova) AS ambiguous, "
            .'(SELECT id FROM usuarios_nova WHERE '.implode(' OR ', $conditions).' ORDER BY nombre, apellido LIMIT 1) AS id',
            $bindings
        );
        $ambiguous = (bool) $result->ambiguous;

        return ['ambiguous' => $ambiguous, 'id' => $ambiguous || $result->id === null ? null : (int) $result->id];
    }
}
