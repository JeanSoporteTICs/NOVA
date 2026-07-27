<?php

namespace App\Modulos\Nova\Services;

use App\Support\StringNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciles the mutable Redmine numeric ID against NOVA's stable identity.
 *
 * A Redmine ID can change after a user is recreated remotely. The Redmine
 * login is therefore used only as a safe bridge to NOVA's unique access user
 * or RUT. Names are deliberately never used for automatic matching.
 */
final class RedmineIdentityService
{
    /** @var array<int,array{id:int,uuid:string,usuario:string,rut:string,redmine_id:string}>|null */
    private ?array $centralUsers = null;

    /**
     * @param array<int,array<string,mixed>> $users
     */
    public function projectUserIndexByLogin(array $users, string $login): ?int
    {
        $needle = $this->normalize($login);
        if ($needle === '') {
            return null;
        }

        $matches = [];
        foreach ($users as $index => $user) {
            if (!is_array($user)) {
                continue;
            }

            foreach (['rut_sin_dv', 'username', 'rut'] as $field) {
                if ($this->normalize((string) ($user[$field] ?? '')) === $needle) {
                    $matches[] = $index;
                    break;
                }
            }
        }

        $matches = array_values(array_unique($matches));

        return count($matches) === 1 ? (int) $matches[0] : null;
    }

    /**
     * @return array{id:int,uuid:string,usuario:string,rut:string,redmine_id:string}|null
     */
    public function centralUserByLogin(string $login): ?array
    {
        $needle = $this->normalize($login);
        if ($needle === '' || !$this->usersTableAvailable()) {
            return null;
        }

        try {
            if ($this->centralUsers === null) {
                $this->centralUsers = DB::table('usuarios_nova')
                    ->get(['id', 'uuid', 'usuario', 'rut', 'redmine_id'])
                    ->map(static fn (object $user): array => [
                        'id' => (int) $user->id,
                        'uuid' => trim((string) $user->uuid),
                        'usuario' => trim((string) $user->usuario),
                        'rut' => trim((string) $user->rut),
                        'redmine_id' => trim((string) $user->redmine_id),
                    ])
                    ->all();
            }

            $matches = array_values(array_filter(
                $this->centralUsers,
                fn (array $user): bool => $this->normalize($user['usuario']) === $needle
                    || $this->normalize($user['rut']) === $needle
            ));
        } catch (\Throwable) {
            return null;
        }

        if (count($matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    public function syncRedmineIdAndIntegrations(int $userId, string $newRedmineId): void
    {
        $newRedmineId = trim($newRedmineId);
        if ($userId <= 0 || ($newRedmineId !== '' && !ctype_digit($newRedmineId))) {
            return;
        }

        DB::table('usuarios_nova')
            ->where('id', $userId)
            ->update([
                'redmine_id' => $newRedmineId !== '' ? (int) $newRedmineId : null,
                'actualizado_at' => now(),
            ]);

        if ($this->centralUsers !== null) {
            foreach ($this->centralUsers as &$user) {
                if ($user['id'] === $userId) {
                    $user['redmine_id'] = $newRedmineId;
                    break;
                }
            }
            unset($user);
        }

        if (!$this->integrationsTableAvailable()) {
            return;
        }

        DB::table('integraciones_usuario')
            ->where('usuario_id', $userId)
            ->whereIn('tipo', ['redmine_tic', 'redmine_mantencion'])
            ->update([
                'usuario_externo' => $newRedmineId !== '' ? $newRedmineId : null,
                'actualizado_at' => now(),
            ]);
    }

    public function accessUsernameFromLogin(string $login): string
    {
        $login = trim($login);
        if ($login === '') {
            return '';
        }

        $cleanRut = strtolower((string) preg_replace('/[^0-9k]/i', '', $login));
        if (preg_match('/^\d{7,8}[0-9k]$/', $cleanRut)) {
            return substr($cleanRut, 0, -1);
        }

        return $login;
    }

    public function rutFromLogin(string $login): string
    {
        $clean = strtolower((string) preg_replace('/[^0-9k]/i', '', trim($login)));
        if (!preg_match('/^\d{7,8}[0-9k]$/', $clean)) {
            return '';
        }

        return substr($clean, 0, -1) . '-' . substr($clean, -1);
    }

    private function normalize(string $value): string
    {
        return StringNormalizer::normalize($value);
    }

    private function usersTableAvailable(): bool
    {
        try {
            return Schema::hasTable('usuarios_nova');
        } catch (\Throwable) {
            return false;
        }
    }

    private function integrationsTableAvailable(): bool
    {
        try {
            return Schema::hasTable('integraciones_usuario');
        } catch (\Throwable) {
            return false;
        }
    }
}
