<?php

namespace App\Modulos\RedmineMantencion\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** The common alphabet runs in SQL; iconv-dependent exceptions use the real legacy helper. */
final class HistorySqlText
{
    public function quote(string $value): string
    {
        return DB::connection()->getPdo()->quote($value);
    }

    public function apply(Builder $query, string $expression, callable $predicate, callable $legacyMatches): void
    {
        $value = "COALESCE($expression, '')";
        $groups = ['a' => 'áÁàÀäÄâÂ', 'e' => 'éÉèÈëËêÊ', 'i' => 'íÍìÌïÏîÎ',
            'o' => 'óÓòÒöÖôÔ', 'u' => 'úÚùÙüÜûÛ', 'n' => 'ñÑ'];
        $unsupported = "($value COLLATE utf8mb4_bin REGEXP ".$this->quote('(?-i)[^\x00-\x7f'.implode('', $groups).']')
            ." OR $value COLLATE utf8mb4_bin REGEXP '(?-i)[ÃÂâ]')";
        $normalized = $value;
        foreach ($groups as $replacement => $characters) {
            $normalized = "REGEXP_REPLACE($normalized COLLATE utf8mb4_bin, '(?-i)[$characters]', '$replacement')";
        }
        $normalized = "TRIM(REGEXP_REPLACE(LOWER($normalized), '(?-i)[^a-z0-9]+', ' ')) COLLATE utf8mb4_bin";
        $ids = [];
        foreach ((clone $query)->whereRaw($unsupported)->select('id')->selectRaw("$value AS legacy_value")->cursor() as $row) {
            if ($legacyMatches((string) $row->legacy_value)) {
                $ids[(string) $row->id] = $this->quote((string) $row->id);
            }
        }
        $query->where(function (Builder $where) use ($unsupported, $normalized, $predicate, $ids): void {
            $where->whereRaw("NOT $unsupported AND (".$predicate($normalized).')');
            if ($ids !== []) {
                // Quoted DB values preserve BIGINT UNSIGNED beyond PHP_INT_MAX
                // and avoid the prepared statement placeholder ceiling.
                $where->orWhereRaw('id IN ('.implode(',', $ids).')');
            }
        });
    }

    public function contains(Builder $query, string $expression, string $needle): void
    {
        $needle = dashboard_normalize_text($needle);
        if ($needle === '') {
            return;
        }
        $quoted = $this->quote($needle);
        $this->apply($query, $expression, fn ($sql) => "LOCATE($quoted, $sql) > 0",
            fn ($text) => str_contains(dashboard_normalize_text($text), $needle));
    }

    public function names(Builder $query, array $names): void
    {
        $normalized = array_values(array_filter(array_map('dashboard_normalize_text', $names), fn ($name) => $name !== ''));
        $this->apply($query, 'asignado_nombre', function ($sql) use ($normalized): string {
            $conditions = [];
            foreach ($normalized as $name) {
                $quoted = $this->quote($name);
                $matches = ["LOCATE($quoted, $sql) > 0", "LOCATE($sql, $quoted) > 0"];
                $tokens = array_values(array_filter(explode(' ', $name)));
                if ($tokens !== []) {
                    $matches[] = '('.implode(' AND ', array_map(fn ($token) => 'LOCATE('.$this->quote(' '.$token.' ').", CONCAT(' ', $sql, ' ')) > 0", $tokens)).')';
                }
                $conditions[] = "($sql <> '' AND (".implode(' OR ', $matches).'))';
            }

            return $conditions === [] ? '1 = 0' : implode(' OR ', $conditions);
        }, function ($candidate) use ($names): bool {
            foreach ($names as $name) {
                if ($name !== '' && dashboard_name_tokens_match($name, trim($candidate))) {
                    return true;
                }
            }

            return false;
        });
    }
}
