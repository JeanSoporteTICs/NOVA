<?php

namespace App\Services\Auth;

use App\Repositories\Nova\NovaUserRepository;

final class LegacyUserProvider
{
    public function __construct(private NovaUserRepository $users)
    {
    }

    public function attempt(string $username, string $password, bool $allowApiToken = false): ?array
    {
        return $this->users->attempt($username, $password, $allowApiToken);
    }

    public function find(string $username): ?array
    {
        return $this->users->find($username);
    }
}
