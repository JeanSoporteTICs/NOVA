<?php

namespace Tests\Unit;

use App\Modulos\Nova\Repositories\NovaUserRepository;
use App\Modulos\Nova\Services\NovaUserService;
use App\Modulos\Nova\Services\RedmineIdentityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class NovaUserIdentityTest extends TestCase
{
    use DatabaseTransactions;

    public function test_rut_is_saved_canonically_and_cannot_be_repeated_with_other_format(): void
    {
        $repo = app(NovaUserRepository::class);
        $firstRut = $this->validRut(random_int(30000000, 39999999));

        $first = $repo->save([
            'name' => 'Identidad',
            'apellido' => 'Uno',
            'rut' => $this->formatRut($firstRut),
            'password' => 'Clave-segura-1',
            'password_confirmation' => 'Clave-segura-1',
        ]);
        $this->assertTrue($first['ok'], $first['error']);

        $storedRut = DB::table('usuarios_nova')
            ->where('usuario', substr($firstRut, 0, -1))
            ->value('rut');
        $this->assertSame(substr($firstRut, 0, -1) . '-' . substr($firstRut, -1), $storedRut);

        $duplicate = $repo->save([
            'name' => 'Identidad',
            'apellido' => 'Dos',
            'rut' => $firstRut,
            'password' => 'Clave-segura-2',
            'password_confirmation' => 'Clave-segura-2',
        ]);

        $this->assertFalse($duplicate['ok']);
        $this->assertMatchesRegularExpression('/RUT|acceso/i', $duplicate['error']);
    }

    public function test_nova_administration_cannot_change_redmine_id_manually(): void
    {
        $uuid = (string) Str::uuid();
        $rut = $this->validRut(random_int(40000000, 49999999));
        $oldId = (string) random_int(70000000, 79999999);
        $newId = (string) random_int(80000000, 89999999);
        $userId = DB::table('usuarios_nova')->insertGetId([
            'uuid' => $uuid,
            'usuario' => substr($rut, 0, -1),
            'rut' => substr($rut, 0, -1) . '-' . substr($rut, -1),
            'redmine_id' => $oldId,
            'nombre' => 'Cambio',
            'apellido' => 'Redmine',
            'rol' => 'usuario',
            'estado' => 'activo',
            'password' => Hash::make('Clave-segura-3'),
            'creado_at' => now(),
            'actualizado_at' => now(),
        ]);
        foreach (['redmine_tic', 'redmine_mantencion'] as $type) {
            DB::table('integraciones_usuario')->insert([
                'usuario_id' => $userId,
                'tipo' => $type,
                'usuario_externo' => $oldId,
                'valor_secreto' => null,
                'creado_at' => now(),
                'actualizado_at' => now(),
            ]);
        }

        $result = app(NovaUserRepository::class)->save([
            'id' => $uuid,
            'name' => 'Cambio',
            'apellido' => 'Redmine',
            'rut' => $rut,
            'redmine_id' => $newId,
            'role' => 'usuario',
            'status' => 'activo',
        ]);

        $this->assertTrue($result['ok'], $result['error']);
        $this->assertSame($oldId, (string) DB::table('usuarios_nova')->where('id', $userId)->value('redmine_id'));
        $this->assertSame(
            [$oldId, $oldId],
            DB::table('integraciones_usuario')
                ->where('usuario_id', $userId)
                ->whereIn('tipo', ['redmine_tic', 'redmine_mantencion'])
                ->orderBy('tipo')
                ->pluck('usuario_externo')
                ->map(static fn ($value): string => (string) $value)
                ->all()
        );
    }

    public function test_redmine_login_matches_user_access_or_formatted_rut_but_not_name(): void
    {
        $service = app(RedmineIdentityService::class);

        $this->assertSame(0, $service->projectUserIndexByLogin([
            ['rut_sin_dv' => '12345678', 'rut' => '12.345.678-5', 'nombre' => 'Ana'],
        ], '12.345.678-5'));

        $this->assertNull($service->projectUserIndexByLogin([
            ['rut_sin_dv' => '87654321', 'rut' => '87.654.321-0', 'nombre' => 'Ana Perez'],
        ], 'Ana Perez'));
    }

    public function test_user_service_canonicalizes_rut(): void
    {
        $service = app(NovaUserService::class);

        $this->assertSame('12345678-5', $service->canonicalRut('12.345.678-5'));
        $this->assertSame('12345678', $service->normalizeRutUsername('12.345.678-5'));
        $this->assertSame('usuario', $service->normalizeNovaRole('gestor'));
        $this->assertSame('admin', $service->normalizeNovaRole('administrador'));
    }

    private function validRut(int $body): string
    {
        $number = (string) $body;
        $factor = 2;
        $sum = 0;
        for ($i = strlen($number) - 1; $i >= 0; $i--) {
            $sum += (int) $number[$i] * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }
        $expected = 11 - ($sum % 11);
        $dv = match ($expected) {
            11 => '0',
            10 => 'k',
            default => (string) $expected,
        };

        return $number . $dv;
    }

    private function formatRut(string $rut): string
    {
        $body = substr($rut, 0, -1);

        return number_format((int) $body, 0, ',', '.') . '-' . substr($rut, -1);
    }
}
