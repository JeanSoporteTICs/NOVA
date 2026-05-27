<?php

namespace App\Support\Auth;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Str;

final class NovaUserRepository
{
    public function __construct(private ModuleRegistry $modules)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        $this->ensureSeeded();
        $this->syncProjectUsers();
        $users = $this->readUsersFile();

        return is_array($users) ? array_values(array_filter($users, 'is_array')) : [];
    }

    public function attempt(string $username, string $password): ?array
    {
        $user = $this->find($username);
        if ($user === null || $this->isBlocked($user)) {
            return null;
        }

        $hash = (string) ($user['password'] ?? '');
        $api = (string) ($user['api'] ?? '');
        $valid = false;

        if ($hash !== '' && strlen($hash) > 20) {
            $valid = password_verify($password, $hash);
        }

        if (!$valid && $hash !== '' && strlen($hash) <= 20 && hash_equals($hash, $password)) {
            $valid = true;
        }

        if (!$valid && $api !== '' && hash_equals($api, $password)) {
            $valid = true;
        }

        return $valid ? $this->toSessionUser($user) : null;
    }

    public function find(string $username): ?array
    {
        $needle = $this->normalize($username);
        if ($needle === '') {
            return null;
        }

        foreach ($this->all() as $user) {
            foreach ($this->loginCandidates($user) as $candidate) {
                if ($needle === $this->normalize((string) $candidate)) {
                    return $user;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{ok:bool,error:string}
     */
    public function save(array $payload): array
    {
        $users = $this->all();
        $id = trim((string) ($payload['id'] ?? ''));
        $isNew = $id === '';

        if ($isNew) {
            $id = (string) Str::uuid();
        }

        $rut = trim((string) ($payload['rut'] ?? ''));
        $username = $this->rutAccessUser($rut);
        $name = trim((string) ($payload['name'] ?? ''));
        $apellido = trim((string) ($payload['apellido'] ?? ''));
        if ($rut === '' || $username === '' || $name === '' || $apellido === '') {
            return ['ok' => false, 'error' => 'RUT, nombre y apellidos son obligatorios.'];
        }

        if (!$this->isValidRut($rut)) {
            return ['ok' => false, 'error' => 'El RUT ingresado no es valido.'];
        }

        $index = null;
        foreach ($users as $i => $user) {
            if ((string) ($user['id'] ?? '') === $id) {
                $index = $i;
                break;
            }
        }

        foreach ($users as $i => $user) {
            if ($index !== null && $i === $index) {
                continue;
            }

            if ($this->normalize((string) ($user['username'] ?? '')) === $this->normalize($username)) {
                return ['ok' => false, 'error' => 'Ya existe un usuario con ese acceso.'];
            }
        }

        $current = $index !== null ? $users[$index] : [];
        $redmineId = trim((string) ($payload['redmine_id'] ?? $current['redmine_id'] ?? ''));

        foreach ($users as $i => $user) {
            if ($index !== null && $i === $index) {
                continue;
            }

            if ($this->normalize((string) ($user['rut'] ?? '')) === $this->normalize($rut)) {
                return ['ok' => false, 'error' => 'Ya existe un usuario con ese RUT.'];
            }

            if ($redmineId !== '' && $this->normalize((string) ($user['redmine_id'] ?? '')) === $this->normalize($redmineId)) {
                return ['ok' => false, 'error' => 'Ya existe un usuario con ese ID Redmine.'];
            }
        }

        $password = (string) ($payload['password'] ?? '');
        $passwordConfirm = (string) ($payload['password_confirmation'] ?? $payload['password_confirm'] ?? '');
        $passwordHash = (string) ($current['password'] ?? '');
        if ($password !== '' || $passwordConfirm !== '') {
            if ($password === '' || $passwordConfirm === '' || !hash_equals($password, $passwordConfirm)) {
                return ['ok' => false, 'error' => 'La contrasena y su validacion no coinciden.'];
            }

            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        }

        if ($passwordHash === '') {
            return ['ok' => false, 'error' => 'La contrasena es obligatoria para usuarios nuevos.'];
        }

        $row = [
            'id' => $id,
            'redmine_id' => $redmineId,
            'username' => $username,
            'name' => $name,
            'apellido' => $apellido,
            'rut' => $rut,
            'rut_sin_dv' => $username,
            'core_user' => trim((string) ($payload['core_user'] ?? $current['core_user'] ?? '')),
            'role' => $this->normalizeNovaRole((string) ($payload['role'] ?? 'usuario')),
            'status' => $this->normalizeStatus((string) ($payload['status'] ?? 'activo')),
            'password' => $passwordHash,
        ];

        if ($index === null) {
            $users[] = $row;
        } else {
            $users[$index] = $row;
        }

        $this->write($users);

        return ['ok' => true, 'error' => ''];
    }

    public function delete(string $id): int
    {
        return $this->setStatus($id, 'baneado');
    }

    public function activate(string $id): int
    {
        return $this->setStatus($id, 'activo');
    }

    /**
     * @return array<int,string>
     */
    private function loginCandidates(array $user): array
    {
        return array_values(array_filter([
            $user['username'] ?? null,
            $user['redmine_id'] ?? null,
            $user['id'] ?? null,
            $user['rut'] ?? null,
            $user['rut_sin_dv'] ?? null,
            $user['core_user'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== ''));
    }

    private function toSessionUser(array $user): array
    {
        return [
            'id' => (string) ($user['id'] ?? ''),
            'redmine_id' => (string) ($user['redmine_id'] ?? ''),
            'username' => (string) ($user['username'] ?? ''),
            'name' => (string) ($user['name'] ?? ''),
            'apellido' => (string) ($user['apellido'] ?? ''),
            'rut' => (string) ($user['rut'] ?? ''),
            'rut_sin_dv' => (string) ($user['rut_sin_dv'] ?? ''),
            'core_user' => (string) ($user['core_user'] ?? ''),
            'role' => $this->normalizeNovaRole((string) ($user['role'] ?? 'usuario')),
            'source' => 'nova',
            'legacy' => [
                'id' => (string) ($user['redmine_id'] ?? $user['username'] ?? $user['id'] ?? ''),
                'nombre' => (string) ($user['name'] ?? ''),
                'rut' => (string) ($user['rut'] ?? ''),
                'rol' => $this->normalizeNovaRole((string) ($user['role'] ?? 'usuario')),
            ],
        ];
    }

    private function ensureSeeded(): void
    {
        if (is_file($this->path())) {
            return;
        }

        $this->write([]);
    }

    private function syncProjectUsers(): void
    {
        $users = $this->readUsersFile();
        $projectUsers = $this->projectUsers();

        $changed = false;
        foreach ($projectUsers as $projectUser) {
            if (!is_array($projectUser) || $this->isBlocked($projectUser)) {
                continue;
            }

            $username = $this->projectUsername($projectUser);
            $name = $this->projectFirstName($projectUser);
            $apellido = $this->projectLastName($projectUser);
            $redmineId = trim((string) ($projectUser['id'] ?? ''));
            if ($username === '' || $name === '') {
                continue;
            }

            $index = $this->findIndexForProjectUser($users, $projectUser, $username);
            if ($index === null) {
                $users[] = [
                    'id' => (string) Str::uuid(),
                    'redmine_id' => $redmineId,
                    'username' => $username,
                    'name' => $name,
                    'apellido' => $apellido,
                    'rut' => (string) ($projectUser['rut'] ?? ''),
                    'rut_sin_dv' => (string) ($projectUser['rut_sin_dv'] ?? ''),
                    'core_user' => (string) ($projectUser['core_user'] ?? ''),
                    'role' => 'usuario',
                    'status' => 'activo',
                    'password' => '',
                ];
                $changed = true;
                continue;
            }

            $updated = array_merge($users[$index], [
                'redmine_id' => $redmineId !== '' ? $redmineId : (string) ($users[$index]['redmine_id'] ?? ''),
                'username' => (string) ($users[$index]['username'] ?? '') !== '' ? (string) $users[$index]['username'] : $username,
                'name' => $name,
                'apellido' => $apellido !== '' ? $apellido : (string) ($users[$index]['apellido'] ?? ''),
                'rut' => (string) ($projectUser['rut'] ?? $users[$index]['rut'] ?? ''),
                'rut_sin_dv' => (string) ($projectUser['rut_sin_dv'] ?? $users[$index]['rut_sin_dv'] ?? ''),
                'core_user' => (string) ($projectUser['core_user'] ?? $users[$index]['core_user'] ?? ''),
            ]);

            if (($users[$index]['status'] ?? 'activo') !== 'baneado') {
                $updated['status'] = 'activo';
            }

            if ($updated !== $users[$index]) {
                $users[$index] = $updated;
                $changed = true;
            }
        }

        if ($changed) {
            $this->write($users);
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function projectUsers(): array
    {
        $users = [];
        foreach (['redmine_tic', 'redmine-mantencion'] as $moduleKey) {
            try {
                $module = $this->modules->get($moduleKey);
                $sourcePath = rtrim((string) ($module['path'] ?? ''), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'usuarios.json';
                $sourceUsers = json_decode((string) @file_get_contents($sourcePath), true);
                if (is_array($sourceUsers)) {
                    foreach ($sourceUsers as $user) {
                        if (is_array($user)) {
                            $user['_nova_project'] = $moduleKey;
                            $users[] = $user;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $users;
    }

    /**
     * @param array<string,mixed> $user
     * @param array<int,array<string,mixed>> $legacyUsers
     * @return array<string,mixed>|null
     */
    private function findIndexForProjectUser(array $users, array $projectUser, string $username): ?int
    {
        $needles = array_filter(array_map([$this, 'normalize'], [
            $username,
            $projectUser['id'] ?? '',
            $projectUser['rut'] ?? '',
            $projectUser['rut_sin_dv'] ?? '',
            $projectUser['core_user'] ?? '',
        ]));

        foreach ($users as $index => $user) {
            $candidates = array_filter(array_map([$this, 'normalize'], [
                $user['username'] ?? '',
                $user['redmine_id'] ?? '',
                $user['id'] ?? '',
                $user['rut'] ?? '',
                $user['rut_sin_dv'] ?? '',
                $user['core_user'] ?? '',
            ]));

            if (array_intersect($needles, $candidates) !== []) {
                return $index;
            }
        }

        return null;
    }

    private function projectUsername(array $user): string
    {
        $rutUser = $this->rutAccessUser((string) ($user['rut'] ?? ''));
        if ($rutUser !== '') {
            return $rutUser;
        }

        return trim((string) ($user['rut_sin_dv'] ?? $user['core_user'] ?? $user['id'] ?? ''));
    }

    private function projectFirstName(array $user): string
    {
        return trim((string) ($user['nombre'] ?? ''));
    }

    private function projectLastName(array $user): string
    {
        return trim((string) ($user['apellido'] ?? ''));
    }

    /**
     * @param array<int,array<string,mixed>> $users
     */
    private function write(array $users): void
    {
        $directory = dirname($this->path());
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($this->path(), json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function path(): string
    {
        return storage_path('app/nova/users.json');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function readUsersFile(): array
    {
        $raw = (string) @file_get_contents($this->path());
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $users = json_decode($raw, true);

        return is_array($users) ? array_values(array_filter($users, 'is_array')) : [];
    }

    private function isBlocked(array $user): bool
    {
        $state = strtolower(trim((string) ($user['status'] ?? $user['estado'] ?? $user['estado_usuario'] ?? 'activo')));

        return in_array($state, ['baneado', 'bloqueado', 'inactivo'], true);
    }

    private function setStatus(string $id, string $status): int
    {
        $users = $this->all();
        $changed = 0;

        foreach ($users as $index => $user) {
            if ((string) ($user['id'] ?? '') !== $id) {
                continue;
            }

            $users[$index]['status'] = $this->normalizeStatus($status);
            $changed = 1;
            break;
        }

        if ($changed === 1) {
            $this->write($users);
        }

        return $changed;
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return $status === 'baneado' ? 'baneado' : 'activo';
    }

    private function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^0-9a-z]/i', '', $value));
    }

    private function normalizeNovaRole(string $role): string
    {
        $role = strtolower(trim($role));

        return in_array($role, ['admin', 'administrador', 'gestor', 'root'], true) ? 'admin' : 'usuario';
    }

    private function rutAccessUser(string $rut): string
    {
        $raw = trim($rut);
        $clean = strtolower((string) preg_replace('/[^0-9k]/i', '', $raw));

        if ($clean === '') {
            return '';
        }

        if (str_contains($raw, '-') || str_ends_with($clean, 'k') || strlen($clean) > 8) {
            return substr($clean, 0, -1);
        }

        return $clean;
    }

    private function isValidRut(string $rut): bool
    {
        $clean = strtolower((string) preg_replace('/[^0-9k]/i', '', trim($rut)));
        if (!preg_match('/^\d{7,8}[0-9k]$/', $clean)) {
            return false;
        }

        $number = substr($clean, 0, -1);
        $dv = substr($clean, -1);
        $factor = 2;
        $sum = 0;

        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $sum += (int) $number[$i] * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }

        $expected = 11 - ($sum % 11);
        $expectedDv = match ($expected) {
            11 => '0',
            10 => 'k',
            default => (string) $expected,
        };

        return hash_equals($expectedDv, $dv);
    }
}
