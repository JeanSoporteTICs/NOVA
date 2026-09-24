<?php

namespace App\Repositories\Database;

use Illuminate\Database\Connection;

final class RuntimeSecurityReview
{
    /** Read only. Never return grants: MariaDB can include authentication hashes. */
    public function review(Connection $connection): array
    {
        $database = $connection->getDatabaseName();
        $grants = array_map(fn ($row) => (string) array_values((array) $row)[0], $connection->select('SHOW GRANTS FOR CURRENT_USER'));
        $cipher = (array) $connection->selectOne("SHOW SESSION STATUS LIKE 'Ssl_cipher'");
        $version = (array) $connection->selectOne("SHOW SESSION STATUS LIKE 'Ssl_version'");

        return [
            'direct_grants_dml_only' => $this->directGrantsAreDmlOnly($grants, $database),
            'session_encrypted' => ($cipher['Value'] ?? '') !== '',
            'tls_version' => $version['Value'] ?? '',
            'certificate_ca_configured' => ! empty($connection->getConfig('options')[\PDO::MYSQL_ATTR_SSL_CA] ?? null),
            'note' => 'Solo evalúa concesiones directas y esta sesión. No certifica roles, otros consumidores ni confianza del certificado.',
        ];
    }

    public function directGrantsAreDmlOnly(array $grants, string $database): bool
    {
        $found = [];
        // GRANT database scopes interpret underscore and percent as wildcards.
        $scope = '`'.str_replace(['\\', '_', '%', '`'], ['\\\\', '\\_', '\\%', '``'], $database).'`.*';
        foreach ($grants as $grant) {
            if (stripos($grant, 'WITH GRANT OPTION') !== false
                || ! preg_match('/^GRANT (.+?) ON (.+?) TO /i', $grant, $parts)) {
                return false;
            }
            if ($parts[1] === 'USAGE' && $parts[2] === '*.*') {
                continue;
            }
            $permissions = explode(', ', $parts[1]);
            if ($parts[2] !== $scope || array_diff($permissions, ['SELECT', 'INSERT', 'UPDATE', 'DELETE'])) {
                return false;
            }
            $found = array_merge($found, $permissions);
        }

        return count(array_unique($found)) === 4;
    }
}
