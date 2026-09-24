<?php

namespace Tests\Integration;

use App\Modulos\Nova\Repositories\NovaUserRepository;
use App\Modulos\Nova\Repositories\UserIntegrationRepository;
use App\Repositories\Database\RuntimeSecurityReview;
use App\Services\Database\SchemaBaseline;
use Illuminate\Database\QueryException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use PDO;
use RedmineTic\Repositories\RedmineHoursExtraRepository;

final class RuntimeDatabaseSecurityTest extends IsolatedMariaDbTestCase
{
    private ?PDO $administrator = null;

    private ?string $account = null;

    protected function setUp(): void
    {
        parent::setUp();
        $baseline = new SchemaBaseline;
        $baseline->bootstrap(DB::connection(), $baseline->read(dirname(__DIR__, 2).'/database/baselines/2026-09-15'));
        $this->administrator = DB::connection()->getPdo();
        $this->account = 'p01_'.bin2hex(random_bytes(8));
        $password = bin2hex(random_bytes(24));
        $scope = str_replace('_', '\\_', DB::connection()->getDatabaseName());
        $this->administrator->exec("CREATE USER '{$this->account}'@'localhost' IDENTIFIED BY '$password'");
        $this->administrator->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `$scope`.* TO '{$this->account}'@'localhost'");
        $config = DB::connection()->getConfig();
        $config['username'] = $this->account;
        $config['password'] = $password;
        app('config')->set('database.connections.runtime_security', $config);
    }

    protected function tearDown(): void
    {
        if ($this->administrator) {
            DB::setDefaultConnection('mysql');
            DB::purge('runtime_security');
            if ($this->account) {
                $this->administrator->exec("DROP USER IF EXISTS '{$this->account}'@'localhost'");
            }
        }
        parent::tearDown();
    }

    public function test_limited_account_can_persist_reports_and_hours_and_roll_back_without_tls(): void
    {
        DB::setDefaultConnection('runtime_security');
        $review = (new RuntimeSecurityReview)->review(DB::connection());
        self::assertTrue($review['direct_grants_dml_only']);
        self::assertFalse($review['session_encrypted']);
        self::assertStringNotContainsString($this->account, json_encode($review));
        self::assertStringNotContainsString(DB::connection()->getConfig('password'), json_encode($review));
        DB::table('modulos_nova')->insert(['id' => 1, 'clave_modulo' => 'redmine_tic', 'nombre' => 'TIC']);
        DB::table('redmine_tic_reportes')->insert(['id' => 1, 'modulo_id' => 1, 'fecha' => '2026-09-16', 'fecha_inicio' => '2026-09-16', 'hora_extra' => 1]);
        DB::table('horas_extra_grupos')->insert(['id' => 1, 'fecha' => '2026-09-16']);
        (new RedmineHoursExtraRepository)->attachReporte(1, 1);
        self::assertSame(1, DB::table('horas_extra_grupo_reportes')->count());
        DB::beginTransaction();
        DB::table('redmine_tic_reportes')->where('id', 1)->update(['asunto' => 'rollback']);
        self::assertSame('rollback', DB::table('redmine_tic_reportes')->value('asunto'));
        DB::rollBack();
        self::assertNotSame('rollback', DB::table('redmine_tic_reportes')->value('asunto'));
        DB::table('horas_extra_grupo_reportes')->delete();
        self::assertSame(0, DB::table('horas_extra_grupo_reportes')->count());
    }

    public function test_limited_account_cannot_change_schema_grants_or_read_system_users(): void
    {
        $connection = DB::connection('runtime_security');
        $connection->getPdo();
        foreach ([
            'CREATE TABLE p01_forbidden (id INT)',
            'ALTER TABLE redmine_tic_reportes ADD COLUMN p01_forbidden INT',
            'DROP TABLE redmine_tic_reportes',
            'SELECT * FROM mysql.user',
            "GRANT ALL PRIVILEGES ON *.* TO '{$this->account}'@'localhost'",
        ] as $sql) {
            try {
                $connection->statement($sql);
                self::fail('The runtime account accepted an administrative operation.');
            } catch (QueryException $exception) {
                self::assertContains((int) $exception->errorInfo[1], [1044, 1045, 1142, 1227]);
            }
        }
    }

    public function test_login_password_edit_and_encrypted_personal_integration_work_with_limited_account(): void
    {
        DB::setDefaultConnection('runtime_security');
        app()->instance('encrypter', new Encrypter(str_repeat('t', 32), 'AES-256-CBC'));
        foreach ([1, 2] as $id) {
            DB::table('usuarios_nova')->insert([
                'id' => $id, 'uuid' => 'user-'.$id, 'usuario' => 'user'.$id,
                'nombre' => 'Persona'.$id, 'apellido' => 'Prueba', 'rol' => 'usuario', 'estado' => 'activo',
                'password' => password_hash('Original123!', PASSWORD_BCRYPT),
            ]);
        }
        $users = app(NovaUserRepository::class);
        self::assertNotNull($users->attempt('user1', 'Original123!'));
        self::assertTrue($users->changePassword('user-1', 'Updated123!', 'Updated123!')['ok']);
        self::assertNull($users->attempt('user1', 'Original123!'));
        self::assertNotNull($users->attempt('user1', 'Updated123!'));
        self::assertNotNull($users->attempt('user2', 'Original123!'));
        self::assertTrue($users->save(['id' => 'user-1', 'name' => 'Editada', 'apellido' => 'Prueba'])['ok']);
        self::assertSame('Editada', DB::table('usuarios_nova')->where('id', 1)->value('nombre'));
        $integrations = app(UserIntegrationRepository::class);
        self::assertTrue($integrations->saveCredentialForSession(['id' => 'user-1'], 'redmine', 'external', 'synthetic-secret'));
        $stored = DB::table('integraciones_usuario')->where('usuario_id', 1)->value('valor_secreto');
        self::assertNotSame('synthetic-secret', $stored);
        self::assertSame('synthetic-secret', decrypt($stored));
        self::assertTrue($integrations->deleteCredentialForSession(['id' => 'user-1'], 'redmine'));
    }

    public function test_admin_and_extra_ddl_privileges_are_reported_as_unsuitable_for_runtime(): void
    {
        $review = new RuntimeSecurityReview;
        self::assertFalse($review->review(DB::connection())['direct_grants_dml_only']);
        $scope = str_replace('_', '\\_', DB::connection()->getDatabaseName());
        $this->administrator->exec("GRANT CREATE ON `$scope`.* TO '{$this->account}'@'localhost'");
        self::assertFalse($review->review(DB::connection('runtime_security'))['direct_grants_dml_only']);
    }
}
