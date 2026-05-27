<?php

namespace App\Support\Auth;

final class LegacyUserProvider
{
    public function __construct(private NovaUserRepository $users)
    {
    }

    public function attempt(string $username, string $password): ?array
    {
        return $this->users->attempt($username, $password);
    }

    public function find(string $username): ?array
    {
        return $this->users->find($username);
    }
}
