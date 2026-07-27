<?php

namespace App\Modulos\Nova\Services;

use App\Modulos\Nova\Repositories\UserIntegrationRepository;
use App\Support\StringNormalizer;

/**
 * Domain logic for NOVA user identity, deduplication, credentials, and session projection.
 *
 * Extracted from NovaUserRepository so the repository can focus on DB persistence.
 * Controllers interact with NovaUserRepository; the service is an internal dependency.
 */
final class NovaUserService
{
    public function __construct(
        private UserIntegrationRepository $integrations,
    ) {}

    // -------------------------------------------------------------------------
    // Identity normalization
    // -------------------------------------------------------------------------

    public function normalizeIdentity(string $value): string
    {
        return StringNormalizer::normalize($value);
    }

    // -------------------------------------------------------------------------
    // RUT rules
    // -------------------------------------------------------------------------

    /**
     * Derives the system username from a RUT string.
     * Returns the numeric body without DV when the RUT contains a separator or
     * DV suffix; returns the raw digits otherwise.
     */
    public function normalizeRutUsername(string $rut): string
    {
        $raw   = trim($rut);
        $clean = strtolower((string) preg_replace('/[^0-9k]/i', '', $raw));

        if ($clean === '') {
            return '';
        }

        if (str_contains($raw, '-') || str_ends_with($clean, 'k') || strlen($clean) > 8) {
            return substr($clean, 0, -1);
        }

        return $clean;
    }

    /**
     * Canonical database representation: numeric body, hyphen and DV.
     * Keeping one representation makes the existing unique index on `rut`
     * effective even when users enter the value with or without dots.
     */
    public function canonicalRut(string $rut): string
    {
        $clean = strtolower((string) preg_replace('/[^0-9k]/i', '', trim($rut)));
        if ($clean === '') {
            return '';
        }

        if (strlen($clean) < 2) {
            return $clean;
        }

        return substr($clean, 0, -1) . '-' . substr($clean, -1);
    }

    public function isValidRut(string $rut): bool
    {
        $clean = strtolower((string) preg_replace('/[^0-9k]/i', '', trim($rut)));
        if (!preg_match('/^\d{7,8}[0-9k]$/', $clean)) {
            return false;
        }

        $number = substr($clean, 0, -1);
        $dv     = substr($clean, -1);
        $factor = 2;
        $sum    = 0;

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $sum   += (int) $number[$i] * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }

        $expected   = 11 - ($sum % 11);
        $expectedDv = match ($expected) {
            11      => '0',
            10      => 'k',
            default => (string) $expected,
        };

        return hash_equals($expectedDv, $dv);
    }

    // -------------------------------------------------------------------------
    // Role and status normalization
    // -------------------------------------------------------------------------

    public function normalizeNovaRole(string $role): string
    {
        $role = strtolower(trim($role));

        // "gestor" is a module-specific role and must never grant global
        // NOVA administration privileges.
        return in_array($role, ['admin', 'administrador', 'root'], true) ? 'admin' : 'usuario';
    }

    public function normalizeStatus(string $status): string
    {
        return strtolower(trim($status)) === 'baneado' ? 'baneado' : 'activo';
    }

    public function isBlocked(array $user): bool
    {
        $state = strtolower(trim((string) ($user['status'] ?? $user['estado'] ?? $user['estado_usuario'] ?? 'activo')));

        return in_array($state, ['baneado', 'bloqueado', 'inactivo'], true);
    }

    // -------------------------------------------------------------------------
    // Authentication helpers
    // -------------------------------------------------------------------------

    /**
     * Returns all field values that may be used to log in as this user.
     *
     * @return array<int,string>
     */
    public function loginCandidates(array $user): array
    {
        return array_values(array_filter([
            $user['username']   ?? null,
            $user['redmine_id'] ?? null,
            $user['id']         ?? null,
            $user['rut']        ?? null,
            $user['rut_sin_dv'] ?? null,
            $user['core_user']  ?? null,
        ], static fn ($v): bool => $v !== null && $v !== ''));
    }

    /**
     * Verifies a plaintext password (or API token) against the stored hash.
     */
    public function verifyCredentials(array $user, string $password, bool $allowApiToken): bool
    {
        if ($this->verifyPassword($user, $password)) {
            return true;
        }

        $api = (string) ($user['api'] ?? '');

        if ($allowApiToken && $api !== '' && hash_equals($api, $password)) {
            return true;
        }

        return false;
    }

    public function verifyPassword(array $user, string $password): bool
    {
        $hash = (string) ($user['password'] ?? '');
        if ($hash === '') {
            return false;
        }

        if (strlen($hash) > 20) {
            return password_verify($password, $hash);
        }

        return hash_equals($hash, $password);
    }

    public function passwordNeedsRehash(array $user): bool
    {
        $hash = (string) ($user['password'] ?? '');

        return $hash !== '' && (strlen($hash) <= 20 || password_needs_rehash($hash, PASSWORD_DEFAULT));
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    // -------------------------------------------------------------------------
    // Session projection
    // -------------------------------------------------------------------------

    /**
     * Converts an internal user array into the session-safe representation.
     *
     * @return array<string,mixed>
     */
    public function toSessionUser(array $user): array
    {
        $role = $this->normalizeNovaRole((string) ($user['role'] ?? 'usuario'));

        return [
            'id'                     => (string) ($user['id']         ?? ''),
            'redmine_id'             => (string) ($user['redmine_id'] ?? ''),
            'username'               => (string) ($user['username']   ?? ''),
            'name'                   => (string) ($user['name']       ?? ''),
            'apellido'               => (string) ($user['apellido']   ?? ''),
            'rut'                    => (string) ($user['rut']        ?? ''),
            'rut_sin_dv'             => (string) ($user['rut_sin_dv'] ?? ''),
            'core_user'              => (string) ($user['core_user']  ?? ''),
            'role'                   => $role,
            'has_emach_credentials'  => $this->integrations->hasEmach($user),
            'has_telegram_settings'  => $this->integrations->hasTelegram($user),
            'source'                 => 'nova',
            'legacy'                 => [
                'id'     => (string) ($user['redmine_id'] ?? $user['username'] ?? $user['id'] ?? ''),
                'nombre' => (string) ($user['name']  ?? ''),
                'rut'    => (string) ($user['rut']   ?? ''),
                'rol'    => $role,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Display helpers
    // -------------------------------------------------------------------------

    public function fullName(array $user): string
    {
        return trim((string) (($user['name'] ?? $user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')));
    }

    // -------------------------------------------------------------------------
    // Identity key generation (deduplication anchor)
    // -------------------------------------------------------------------------

    /**
     * @param  array<int,mixed> $values
     * @return array<int,string>
     */
    public function identityKeys(array $values): array
    {
        $keys = [];
        foreach ($values as $value) {
            $normalized = $this->normalizeIdentity((string) $value);
            if ($normalized !== '' && !in_array('identity:' . $normalized, $keys, true)) {
                $keys[] = 'identity:' . $normalized;
            }
        }

        return $keys;
    }

    /** @return array<int,string> */
    public function identityKeysForUser(array $user): array
    {
        return $this->identityKeys([
            $user['rut']        ?? '',
            $user['rut_sin_dv'] ?? '',
            $user['core_user']  ?? '',
        ]);
    }

    // -------------------------------------------------------------------------
    // Deduplication
    // -------------------------------------------------------------------------

    /**
     * Removes duplicate user records by identity key, merging fields from the
     * duplicate into the primary record.
     *
     * @param  array<int,array<string,mixed>> $users
     * @return array<int,array<string,mixed>>
     */
    public function deduplicateUsers(array $users): array
    {
        $result = [];
        $keys   = [];

        foreach ($users as $user) {
            if (!is_array($user)) {
                continue;
            }

            $key = $this->dedupeKey($user);
            if ($key === '' || !isset($keys[$key])) {
                $keys[$key] = count($result);
                $result[]   = $user;
                continue;
            }

            $index          = $keys[$key];
            $result[$index] = $this->mergeDuplicateUsers($result[$index], $user);
        }

        return array_values($result);
    }

    public function dedupeKey(array $user): string
    {
        $identityKeys = $this->identityKeysForUser($user);
        if ($identityKeys !== []) {
            return $identityKeys[0];
        }

        $username  = $this->normalizeIdentity((string) ($user['username']   ?? ''));
        $redmineId = $this->normalizeIdentity((string) ($user['redmine_id'] ?? ''));
        $name      = $this->normalizeIdentity($this->fullName($user));

        if ($username !== '' && $name !== '') {
            return 'user-name:' . $username . ':' . $name;
        }
        if ($redmineId !== '' && $name !== '') {
            return 'redmine-name:' . $redmineId . ':' . $name;
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Merge rules
    // -------------------------------------------------------------------------

    /**
     * Merges a duplicate user into the primary record.
     * Fields that are empty on primary are filled from duplicate.
     * Admin role and active status always win across duplicates.
     *
     * @param  array<string,mixed> $primary
     * @param  array<string,mixed> $duplicate
     * @return array<string,mixed>
     */
    public function mergeDuplicateUsers(array $primary, array $duplicate): array
    {
        $merged = $primary;

        foreach (['redmine_id', 'username', 'name', 'apellido', 'rut', 'rut_sin_dv', 'core_user', 'api', 'password'] as $field) {
            if (trim((string) ($merged[$field] ?? '')) === '' && trim((string) ($duplicate[$field] ?? '')) !== '') {
                $merged[$field] = $duplicate[$field];
            }
        }

        $merged['source'] = $this->mergeSources(
            (string) ($merged['source']    ?? ''),
            (string) ($duplicate['source'] ?? ''),
        );

        if ($this->normalizeNovaRole((string) ($duplicate['role'] ?? 'usuario')) === 'admin') {
            $merged['role'] = 'admin';
        }
        if ($this->normalizeStatus((string) ($duplicate['status'] ?? 'activo')) === 'activo') {
            $merged['status'] = 'activo';
        }

        foreach (['projects', 'emach_credentials', 'telegram_settings'] as $field) {
            if (is_array($duplicate[$field] ?? null)) {
                $merged[$field] = array_replace_recursive(
                    is_array($merged[$field] ?? null) ? $merged[$field] : [],
                    $duplicate[$field],
                );
            }
        }

        return $merged;
    }

    // -------------------------------------------------------------------------
    // Source tracking helpers
    // -------------------------------------------------------------------------

    public function mergeSources(string $current, string $next): string
    {
        $sources = $this->splitSources($current);
        $next    = trim($next);
        if ($next !== '' && !in_array($next, $sources, true)) {
            $sources[] = $next;
        }

        return implode(',', $sources);
    }

    /** @return array<int,string> */
    public function splitSources(string $source): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $source))));
    }
}
