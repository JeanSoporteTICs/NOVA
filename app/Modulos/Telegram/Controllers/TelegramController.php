<?php

namespace App\Modulos\Telegram\Controllers;

use App\Http\Controllers\Controller;

use App\Modulos\Telegram\Repositories\TelegramCommandCatalog;
use App\Modulos\Telegram\Repositories\TelegramCommandSettingsRepository;
use App\Modulos\Telegram\Services\TelegramService;
use App\Modulos\Shared\Repositories\ModuleLogRepository;
use App\Modulos\Nova\Repositories\UserIntegrationRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class TelegramController extends Controller
{
    public function __construct(private TelegramService $telegram, private ModuleLogRepository $moduleLogs)
    {
    }

    public function index(Request $request, UserIntegrationRepository $integrations, TelegramCommandCatalog $commands): View
    {
        $this->telegram->load();
        $sessionUser = $this->sessionUser($request);

        return view('nova.telegram.index', [
            'mode'             => 'user',
            'config'           => $this->telegram->readConfig(),
            'configPath'       => $this->telegram->configPath(),
            'storageDir'       => $this->telegram->storagePath(),
            'configured'       => $this->telegram->isConfigured(),
            'userTelegram'     => $integrations->telegramForSession($sessionUser),
            'users'            => [],
            'listener'         => [],
            'telegramCommands' => array_values(array_filter($commands->commands(), static fn (array $command): bool => (bool) ($command['enabled'] ?? true))),
            'telegramHelpText' => $commands->helpText(),
        ]);
    }

    public function admin(Request $request, UserIntegrationRepository $integrations, TelegramCommandCatalog $commands): View
    {
        $this->authorizeAdmin($request);
        $this->telegram->load();

        return view('nova.telegram.index', [
            'mode'             => 'admin',
            'config'           => $this->telegram->readConfig(),
            'configPath'       => $this->telegram->configPath(),
            'storageDir'       => $this->telegram->storagePath(),
            'configured'       => $this->telegram->isConfigured(),
            'userTelegram'     => ['chat_id' => '', 'stored' => false, 'updated_at' => ''],
            'users'            => $integrations->users(),
            'listener'         => $this->telegram->listenerStatus(),
            'telegramCommands' => $commands->commands(),
            'telegramHelpText' => $commands->helpText(),
        ]);
    }

    public function updateAdmin(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->telegram->load();

        $current = $this->telegram->readConfig();
        $token   = (string) $request->input('bot_token', '');
        $config  = [
            'bot_token'          => $token !== '' ? $token : (string) ($current['bot_token'] ?? ''),
            'chat_id'            => trim((string) ($current['chat_id'] ?? '')),
            'proxy_url'          => trim((string) $request->input('proxy_url', '')),
            'default_parse_mode' => '',
        ];

        if (!$this->telegram->isConfigured($config)) {
            return back()->withInput()->with('telegram_error', 'Completa BOT_TOKEN.');
        }

        if (!$this->telegram->saveConfig($config)) {
            return back()->withInput()->with('telegram_error', 'No se pudo guardar la configuracion Telegram.');
        }

        $this->log($request, 'configuracion_global_guardada');

        return redirect()->route('telegram.admin')->with('telegram_status', 'Configuracion global Telegram guardada.');
    }

    public function test(Request $request, UserIntegrationRepository $integrations, TelegramCommandSettingsRepository $settings): RedirectResponse
    {
        $chatId = $integrations->telegramForSession($this->sessionUser($request))['chat_id'];

        try {
            $this->telegram->sendConfiguredMessage($settings->render('test', ['fecha' => now()->format('d/m/Y H:i:s')]), [
                'chat_id' => $chatId,
            ]);
        } catch (Throwable $e) {
            $this->log($request, 'mensaje_prueba_error', $e->getMessage());
            return back()->with('telegram_error', $e->getMessage());
        }

        $this->log($request, 'mensaje_prueba_enviado');

        return redirect()->route('telegram.index')->with('telegram_status', 'Mensaje de prueba enviado.');
    }

    public function listener(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->telegram->load();

        $action = (string) $request->input('action', '');

        try {
            if ($action === 'start') {
                return redirect()->route('administracion.section', 'telegram')->with('error', 'El listener Telegram ahora se administra desde Docker.');
            }
            if ($action === 'stop') {
                return redirect()->route('administracion.section', 'telegram')->with('error', 'El listener Telegram ahora se administra desde Docker.');
            }
            if ($action === 'delete_webhook') {
                $config = $this->telegram->readConfig();
                $this->telegram->deleteWebhook((string) ($config['bot_token'] ?? ''));
                return redirect()->route('administracion.section', 'telegram')->with('status', 'Webhook Telegram eliminado.');
            }
            if ($action === 'clear_log') {
                return redirect()->route('administracion.section', 'telegram')->with('error', 'El log del listener Telegram ahora se revisa desde Docker.');
            }
        } catch (Throwable $e) {
            return redirect()->route('administracion.section', 'telegram')->with('error', $e->getMessage());
        }

        return redirect()->route('administracion.section', 'telegram')->with('error', 'Accion de listener no reconocida.');
    }

    /**
     * @return array<string,mixed>
     */
    private function sessionUser(Request $request): array
    {
        $user = $request->session()->get('nova_user');
        return is_array($user) ? $user : [];
    }

    private function authorizeAdmin(Request $request): void
    {
        $role = (string) data_get($request->session()->get('nova_user'), 'role', 'usuario');
        abort_unless(in_array($role, config('nova.module_admin_roles', []), true), 403);
    }

    private function log(Request $request, string $event, string $detail = ''): void
    {
        $user = $this->sessionUser($request);
        $this->moduleLogs->append('telegram', $event, (string) ($user['id'] ?? $user['uuid'] ?? ''), $detail);
    }
}
