<?php

namespace App\Models;

class UserModel
{
    public function all(): array
    {
        return function_exists('\auth_central_users_for_mantencion') ? \auth_central_users_for_mantencion() : [];
    }

    public function count(): int
    {
        return count($this->all());
    }
}
