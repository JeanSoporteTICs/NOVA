<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CoreCredentialIdentityKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/RedmineMantencion/controllers/dashboard.php';
    }

    public function test_numeric_nova_id_is_marked_as_central_identity(): void
    {
        $this->assertSame('nova:1', dashboard_core_current_credential_user_key([
            '_nova_user_id' => '1',
            'id' => '42',
        ]));
    }

    public function test_uuid_is_marked_as_uuid_identity(): void
    {
        $this->assertSame('uuid:49f047bc-61e5-11f1-9a41-f6e8c8121a9b', dashboard_core_current_credential_user_key([
            '_nova_user_id' => '49f047bc-61e5-11f1-9a41-f6e8c8121a9b',
            'id' => '42',
        ]));
    }

    public function test_legacy_id_is_marked_as_redmine_identity(): void
    {
        $this->assertSame('redmine:42', dashboard_core_current_credential_user_key([
            'id' => '42',
        ]));
    }
}
