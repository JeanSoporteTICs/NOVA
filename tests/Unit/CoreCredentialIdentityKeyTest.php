<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CoreCredentialIdentityKeyTest extends TestCase
{
    public function test_native_integration_repository_resolves_unambiguous_central_identity_fields(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/Nova/Repositories/UserIntegrationRepository.php');

        self::assertStringContainsString("'uuid' =>", $source);
        self::assertStringContainsString("'redmine_id' =>", $source);
        self::assertStringContainsString("'usuario_core' =>", $source);
        self::assertStringNotContainsString('dashboard_core_current_credential_user_key', $source);
    }
}
