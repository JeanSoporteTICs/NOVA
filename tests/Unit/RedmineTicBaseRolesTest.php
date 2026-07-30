<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedmineTic\Repositories\RedmineDataRepository;

class RedmineTicBaseRolesTest extends TestCase
{
    public function test_only_user_and_administrator_are_base_roles(): void
    {
        $repository = new RedmineDataRepository();

        $this->assertSame(['administrador', 'usuario'], $repository->baseRoles());
    }

    public function test_base_roles_cannot_be_deleted(): void
    {
        $repository = new RedmineDataRepository();

        $this->assertFalse($repository->deleteRole('administrador')['ok']);
        $this->assertFalse($repository->deleteRole('usuario')['ok']);
    }
}
