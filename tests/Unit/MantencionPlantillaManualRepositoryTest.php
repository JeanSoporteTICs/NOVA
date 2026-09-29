<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Repositories\MantencionPlantillaManualRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MantencionPlantillaManualRepositoryTest extends TestCase
{
    public function test_templates_are_private_and_can_be_updated_or_deleted(): void
    {
        $originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.plantillas_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        DB::setDefaultConnection('plantillas_test');

        try {
            Schema::create('redmine_mantencion_plantillas_manuales', function (Blueprint $table): void {
                $table->id();
                $table->string('usuario_nova_id');
                $table->string('nombre');
                $table->string('asunto');
                $table->string('categoria');
                $table->timestamps();
                $table->unique(['usuario_nova_id', 'nombre']);
            });

            $repo = new MantencionPlantillaManualRepository;
            $repo->create('usuario-a', 'Reactivar', 'Reactivar usuario CORE bloqueado', 'CORE');
            self::assertSame([], $repo->all('usuario-b'));
            self::assertSame('CORE', $repo->all('usuario-a')[0]['categoria']);

            $id = (int) $repo->all('usuario-a')[0]['id'];
            self::assertFalse($repo->update('usuario-b', $id, 'Otro', 'Privado', 'Accesos'));
            self::assertTrue($repo->update('usuario-a', $id, 'Reactivar CORE', 'Reactivar cuenta', 'Accesos'));
            self::assertCount(1, $repo->all('usuario-a'));
            self::assertSame('Reactivar CORE', $repo->all('usuario-a')[0]['nombre']);
            self::assertSame('Reactivar cuenta', $repo->all('usuario-a')[0]['asunto']);
            self::assertTrue($repo->nameExists('usuario-a', 'Reactivar CORE'));
            self::assertFalse($repo->nameExists('usuario-b', 'Reactivar CORE'));
            $repo->delete('usuario-b', $id);
            self::assertCount(1, $repo->all('usuario-a'));
            $repo->delete('usuario-a', $id);
            self::assertSame([], $repo->all('usuario-a'));
        } finally {
            DB::setDefaultConnection($originalConnection);
            DB::purge('plantillas_test');
        }
    }
}
