<?php

namespace Tests\Unit;

use Mockery;
use PHPUnit\Framework\TestCase;
use RedmineTic\Repositories\RedmineUserRepository;

class RedmineUserPermissionIsolationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_saving_permissions_persists_only_the_selected_user_and_preserves_statuses(): void
    {
        $selected = [
            'id' => '42',
            'rol' => 'usuario',
            'estado_usuario' => 'activo',
            'permisos' => ['historico' => false],
        ];
        $other = [
            'id' => '50',
            'rol' => 'usuario',
            'estado_usuario' => 'baneado',
            'permisos' => ['historico' => false],
        ];
        $expected = $selected;
        $expected['rol'] = 'administrador';
        $expected['permisos'] = ['historico' => true];

        $repository = Mockery::mock(RedmineUserRepository::class, ['redmine_tic', 'Backlog Soporte TI'])
            ->makePartial();
        $repository->shouldReceive('projectUsers')
            ->once()
            ->andReturn([$selected, $other]);
        $repository->shouldReceive('persistUsers')
            ->once()
            ->with([$expected], true, 'baneado');

        $this->assertTrue($repository->saveUserPermissions('42', 'administrador', ['historico' => true]));
    }
}
