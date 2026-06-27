<?php

namespace App\Contracts;

interface ProjectUserProviderInterface
{
    /**
     * Returns all project-member user records for a given project key.
     * Returns an empty array for unsupported project keys.
     *
     * Each record must contain at least:
     *   id, rut_sin_dv, rut, core_user, nextcloud_user, estado_usuario
     *
     * @return array<int,array<string,mixed>>
     */
    public function projectUsers(string $projectKey): array;
}
