<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Services\MantencionCoreImportService;
use PHPUnit\Framework\TestCase;

class CoreCredentialIdentityKeyTest extends TestCase
{
    private MantencionCoreImportService $coreImport;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/RedmineMantencion/controllers/dashboard.php';
        $this->coreImport = new MantencionCoreImportService();
    }

    public function test_numeric_nova_id_is_marked_as_central_identity(): void
    {
        $this->assertSame('nova:1', $this->coreImport->dashboard_core_current_credential_user_key([
            '_nova_user_id' => '1',
            'id' => '42',
        ]));
    }

    public function test_uuid_is_marked_as_uuid_identity(): void
    {
        $this->assertSame('uuid:49f047bc-61e5-11f1-9a41-f6e8c8121a9b', $this->coreImport->dashboard_core_current_credential_user_key([
            '_nova_user_id' => '49f047bc-61e5-11f1-9a41-f6e8c8121a9b',
            'id' => '42',
        ]));
    }

    public function test_legacy_id_is_marked_as_redmine_identity(): void
    {
        $this->assertSame('redmine:42', $this->coreImport->dashboard_core_current_credential_user_key([
            'id' => '42',
        ]));
    }
}
