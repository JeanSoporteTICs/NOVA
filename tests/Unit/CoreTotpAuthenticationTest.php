<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Services\MantencionCoreImportService;
use PHPUnit\Framework\TestCase;

class CoreTotpAuthenticationTest extends TestCase
{
    private MantencionCoreImportService $service;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/RedmineMantencion/controllers/dashboard.php';
        $this->service = new MantencionCoreImportService();
    }

    public function test_it_detects_an_optional_totp_challenge_and_preserves_hidden_fields(): void
    {
        $html = <<<'HTML'
            <form method="post" action="/core/verificar-totp">
                <input type="hidden" name="csrf_token" value="csrf-value">
                <input type="hidden" name="challenge_id" value="challenge-value">
                <input type="text" name="totp" value="">
                <button type="submit">Verificar</button>
            </form>
            HTML;

        $form = $this->service->dashboard_core_parse_totp_form(
            $html,
            'https://core.example.test/core/login'
        );

        $this->assertTrue($form['has_totp_form']);
        $this->assertSame('totp', $form['field']);
        $this->assertSame('https://core.example.test/core/verificar-totp', $form['action']);
        $this->assertSame('csrf-value', $form['fields']['csrf_token']);
        $this->assertSame('challenge-value', $form['fields']['challenge_id']);
    }

    public function test_it_accepts_common_alternative_totp_field_names(): void
    {
        foreach (['otp', 'verification_code', 'codigo', 'authenticator', 'token'] as $name) {
            $form = $this->service->dashboard_core_parse_totp_form(
                '<form><input name="' . $name . '"></form>',
                'https://core.example.test/login'
            );

            $this->assertTrue($form['has_totp_form'], $name);
            $this->assertSame($name, $form['field']);
        }
    }

    public function test_users_without_totp_are_not_treated_as_a_challenge(): void
    {
        $form = $this->service->dashboard_core_parse_totp_form(
            '<main><h1>Solicitudes CORE</h1></main>',
            'https://core.example.test/solicitudes'
        );

        $this->assertFalse($form['has_totp_form']);
        $this->assertSame('', $form['field']);
    }

    public function test_runtime_credentials_keep_totp_optional(): void
    {
        $withoutTotp = $this->service->dashboard_core_runtime_credentials([
            'user' => ' user ',
            'pass' => ' pass ',
        ]);
        $withTotp = $this->service->dashboard_core_runtime_credentials([
            'user' => 'user',
            'pass' => 'pass',
            'totp' => ' 123456 ',
        ]);

        $this->assertSame(['user' => 'user', 'pass' => 'pass', 'totp' => ''], $withoutTotp);
        $this->assertSame('123456', $withTotp['totp']);
        $this->assertTrue($this->service->dashboard_core_has_runtime_credentials($withoutTotp));
    }

    public function test_primary_credentials_are_validated_before_totp_is_requested(): void
    {
        $service = $this->serviceWithResponses([
            $this->response($this->loginForm()),
            $this->response($this->totpForm(), 'https://core.example.test/verificar-totp'),
        ]);

        $result = $service->dashboard_core_begin_authentication('https://core.example.test/login', [
            'user' => 'user',
            'pass' => 'password',
        ]);

        $this->assertFalse($result['authenticated']);
        $this->assertTrue($result['credentials_validated']);
        $this->assertTrue($result['requires_totp']);
        $this->assertSame('', $result['error']);
    }

    public function test_a_user_without_totp_authenticates_in_the_primary_step(): void
    {
        $service = $this->serviceWithResponses([
            $this->response($this->loginForm()),
            $this->response('<main>Solicitudes CORE</main>', 'https://core.example.test/solicitudes'),
        ]);

        $result = $service->dashboard_core_begin_authentication('https://core.example.test/login', [
            'user' => 'user',
            'pass' => 'password',
        ]);

        $this->assertTrue($result['authenticated']);
        $this->assertTrue($result['credentials_validated']);
        $this->assertFalse($result['requires_totp']);
        $this->assertFileExists($result['cookie_jar']);
        @unlink($result['cookie_jar']);
    }

    public function test_a_rejected_totp_never_invalidates_the_primary_credentials(): void
    {
        $service = $this->serviceWithResponses([
            $this->response($this->loginForm()),
            $this->response($this->totpForm(), 'https://core.example.test/verificar-totp'),
            $this->response($this->loginForm(), 'https://core.example.test/login'),
        ]);

        $result = $service->dashboard_core_begin_authentication('https://core.example.test/login', [
            'user' => 'user',
            'pass' => 'password',
            'totp' => '123456',
        ]);

        $this->assertFalse($result['authenticated']);
        $this->assertTrue($result['credentials_validated']);
        $this->assertTrue($result['requires_totp']);
        $this->assertStringContainsString('código TOTP', $result['error']);
    }

    private function serviceWithResponses(array $responses): MantencionCoreImportService
    {
        return new class($responses) extends MantencionCoreImportService {
            public function __construct(private array $responses)
            {
            }

            public function dashboard_core_curl(string $url, array $options = []): array
            {
                return array_shift($this->responses) ?? [
                    'body' => '',
                    'error' => 'Respuesta simulada ausente.',
                    'http_code' => 500,
                    'effective_url' => $url,
                ];
            }
        };
    }

    private function response(string $body, string $effectiveUrl = 'https://core.example.test/login'): array
    {
        return [
            'body' => $body,
            'error' => '',
            'http_code' => 200,
            'effective_url' => $effectiveUrl,
        ];
    }

    private function loginForm(): string
    {
        return <<<'HTML'
            <form method="post" action="/login">
                <input type="hidden" name="csrf_token" value="csrf-value">
                <input name="login_string">
                <input type="password" name="login_pass">
            </form>
            HTML;
    }

    private function totpForm(): string
    {
        return <<<'HTML'
            <form method="post" action="/verificar-totp">
                <input type="hidden" name="csrf_token" value="csrf-value">
                <input name="totp">
            </form>
            HTML;
    }
}
