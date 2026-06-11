<?php

namespace App\Models;

class UserModel
{
    public function all(): array
    {
        $path = APP_BASE_PATH . '/data/usuarios.json';
        $data = \storage_read_json($path, []);
        return is_array($data) ? $data : [];
    }

    public function count(): int
    {
        return count($this->all());
    }
}
