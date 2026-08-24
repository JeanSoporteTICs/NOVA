<?php

namespace Tests\Unit;

use App\Modulos\RedmineMantencion\Services\MantencionCoreImportService;
use PHPUnit\Framework\TestCase;

class CoreTotpAuthenticationTest extends TestCase
{
    private MantencionCoreImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new MantencionCoreImportService();
    }

    public function test_it_detects_totp_form_and_preserves_hidden_fields(): void
    {
        $html = <<<'HTML'
            <form method="post" action="/core/two-factor/verify">
                <input type="hidden" name="csrf_token" value="csrf-value">
                <label for="totp_code">Codigo autenticador</label>
                <input type="text" id="totp_code" name="totp_code" autocomplete="one-time-code">
                <button type="submit">Verificar</button>
            </form>
            HTML;

        $form = $this->service->dashboard_core_parse_totp_form(
            $html,
            'https://www.hbvaldivia.cl/core/two-factor'
        );

        $this->assertTrue($form['has_totp_form']);
        $this->assertSame('totp_code', $form['field']);
        $this->assertSame('csrf-value', $form['fields']['csrf_token']);
        $this->assertSame('https://www.hbvaldivia.cl/core/two-factor/verify', $form['action']);
    }

    public function test_it_detects_generic_code_from_a_totp_challenge(): void
    {
        $html = <<<'HTML'
            <form method='post' action='verify'>
                <p>Ingresa el codigo de verificacion de tu aplicacion autenticadora.</p>
                <input type='hidden' name='csrf_token' value='abc'>
                <input type='number' name='code'>
            </form>
            HTML;

        $form = $this->service->dashboard_core_parse_totp_form(
            $html,
            'https://core.example.test/auth/totp'
        );

        $this->assertTrue($form['has_totp_form']);
        $this->assertSame('code', $form['field']);
        $this->assertSame('https://core.example.test/auth/verify', $form['action']);
    }

    public function test_first_step_validates_credentials_without_sending_totp(): void
    {
        $loginForm = <<<'HTML'
            <form method="post" action="https://core.example.test/login">
                <input type="hidden" name="csrf_token" value="csrf-login">
                <input type="text" name="login_string">
                <input type="password" name="login_pass">
            </form>
            HTML;
        $totpForm = <<<'HTML'
            <form method="post" action="https://core.example.test/totp/verify">
                <input type="hidden" name="csrf_token" value="csrf-totp">
                <input type="text" name="totp_code">
            </form>
            HTML;

        $service = new class($loginForm, $totpForm) extends MantencionCoreImportService
        {
            public array $requests = [];

            private array $responses;

            public function __construct(string $loginForm, string $totpForm)
            {
                $this->responses = [
                    ['body' => $loginForm, 'error' => '', 'http_code' => 200, 'effective_url' => 'https://core.example.test/'],
                    ['body' => $totpForm, 'error' => '', 'http_code' => 200, 'effective_url' => 'https://core.example.test/totp'],
                ];
            }

            public function dashboard_core_curl(string $url, array $options = []): array
            {
                $this->requests[] = ['url' => $url, 'options' => $options];

                return array_shift($this->responses);
            }
        };

        $result = $service->dashboard_core_begin_authentication('https://core.example.test/', [
            'user' => 'core-user',
            'pass' => 'core-password',
            'totp' => '999999',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['totp_form']['has_totp_form']);
        $this->assertCount(2, $service->requests);
        parse_str((string)($service->requests[1]['options'][CURLOPT_POSTFIELDS] ?? ''), $primaryPayload);
        $this->assertSame('core-user', $primaryPayload['login_string']);
        $this->assertSame('core-password', $primaryPayload['login_pass']);
        $this->assertArrayNotHasKey('totp', $primaryPayload);
        $this->assertArrayNotHasKey('totp_code', $primaryPayload);

        @unlink((string)($result['cookie_jar'] ?? ''));
    }

    public function test_authenticated_user_without_totp_challenge_is_accepted(): void
    {
        $loginForm = <<<'HTML'
            <form method="post" action="https://core.example.test/login">
                <input type="hidden" name="csrf_token" value="csrf-login">
                <input type="text" name="login_string">
                <input type="password" name="login_pass">
            </form>
            HTML;

        $service = new class($loginForm) extends MantencionCoreImportService
        {
            private array $responses;

            public function __construct(string $loginForm)
            {
                $this->responses = [
                    ['body' => $loginForm, 'error' => '', 'http_code' => 200, 'effective_url' => 'https://core.example.test/'],
                    ['body' => '<main>Panel de solicitudes CORE</main>', 'error' => '', 'http_code' => 200, 'effective_url' => 'https://core.example.test/solicitudes/administrador'],
                ];
            }

            public function dashboard_core_curl(string $url, array $options = []): array
            {
                return array_shift($this->responses);
            }

            public function dashboard_core_response_requires_auth(array $response): bool
            {
                return false;
            }
        };

        $result = $service->dashboard_core_begin_authentication('https://core.example.test/', [
            'user' => 'core-user-without-totp',
            'pass' => 'core-password',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['totp_form']['has_totp_form']);
        @unlink((string)($result['cookie_jar'] ?? ''));
    }

    public function test_login_form_is_not_mistaken_for_totp(): void
    {
        $html = <<<'HTML'
            <form method="post" action="/core/login">
                <input type="hidden" name="csrf_token" value="abc">
                <input type="text" name="login_string">
                <input type="password" name="login_pass">
            </form>
            HTML;

        $form = $this->service->dashboard_core_parse_totp_form($html, 'https://core.example.test/');

        $this->assertFalse($form['has_totp_form']);
        $this->assertSame('', $form['field']);
    }

    public function test_runtime_totp_is_trimmed_but_not_persisted_with_credentials(): void
    {
        $credentials = $this->service->dashboard_core_runtime_credentials([
            'user' => ' core-user ',
            'pass' => ' core-pass ',
            'totp' => " 123 456\n",
        ]);

        $this->assertSame([
            'user' => 'core-user',
            'pass' => 'core-pass',
            'totp' => '123456',
        ], $credentials);
    }

    public function test_dashboard_requires_a_fresh_totp_and_marks_it_as_one_time_input(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/resources/views/redmine-mantencion/dashboard.blade.php');

        $this->assertIsString($view);
        $this->assertStringContainsString('name="core_runtime_totp"', $view);
        $this->assertStringContainsString('autocomplete="one-time-code"', $view);
        $this->assertStringContainsString('El código es temporal, se usa solo en esta consulta y nunca se guarda.', $view);
        $this->assertStringContainsString("(\$hasSavedCoreCredentials && \$corePendingToken === '') ? 'submit' : 'button'", $view);
        $this->assertStringContainsString("\$corePendingToken !== ''", $view);
        $this->assertStringContainsString("!\$hasSavedCoreCredentials", $view);
        $credentialsStart = strpos($view, 'id="coreCredentialsModal"');
        $totpStart = strpos($view, 'id="coreTotpModal"');
        $scriptStart = strpos($view, '<script>', $totpStart);
        $this->assertNotFalse($credentialsStart);
        $this->assertNotFalse($totpStart);
        $this->assertNotFalse($scriptStart);
        $credentialsModal = substr($view, $credentialsStart, $totpStart - $credentialsStart);
        $totpModal = substr($view, $totpStart, $scriptStart - $totpStart);
        $this->assertStringNotContainsString('core-runtime-totp-input', $credentialsModal);
        $this->assertStringContainsString('CORE validó tu usuario y contraseña.', $totpModal);
        $this->assertStringContainsString('core-runtime-totp-input', $totpModal);
        $this->assertSame(1, substr_count($credentialsModal, 'class="core-credentials-animation"'));
        $this->assertSame(1, substr_count($totpModal, 'class="core-credentials-animation"'));
        $this->assertStringContainsString('/assets/img/animacion-carga.gif', $totpModal);
    }

    public function test_core_password_and_totp_are_never_flashed_after_an_exception(): void
    {
        $handler = file_get_contents(dirname(__DIR__, 2).'/app/Exceptions/Handler.php');

        $this->assertIsString($handler);
        $this->assertStringContainsString("'core_runtime_pass'", $handler);
        $this->assertStringContainsString("'core_runtime_totp'", $handler);
    }
}
