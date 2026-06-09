<?php

namespace App\Http\Controllers;

use App\Support\Integrations\UserIntegrationRepository;
use App\Support\Integrations\TelegramCommandCatalog;
use App\Support\Integrations\TelegramCommandSettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class TelegramController extends Controller
{
    public function index(Request $request, UserIntegrationRepository $integrations, TelegramCommandCatalog $commands): View
    {
        $this->loadTelegramLibrary();
        $sessionUser = $this->sessionUser($request);

        return view('nova.telegram.index', [
            'mode' => 'user',
            'config' => telegram_read_config(),
            'configPath' => telegram_config_path(),
            'storageDir' => telegram_storage_path(),
            'configured' => telegram_global_is_configured(),
            'userTelegram' => $integrations->telegramForSession($sessionUser),
            'users' => [],
            'listener' => [],
            'telegramCommands' => array_values(array_filter($commands->commands(), static fn (array $command): bool => (bool) ($command['enabled'] ?? true))),
            'telegramHelpText' => $commands->helpText(),
        ]);
    }

    public function admin(Request $request, UserIntegrationRepository $integrations, TelegramCommandCatalog $commands): View
    {
        $this->authorizeAdmin($request);
        $this->loadTelegramLibrary();

        return view('nova.telegram.index', [
            'mode' => 'admin',
            'config' => telegram_read_config(),
            'configPath' => telegram_config_path(),
            'storageDir' => telegram_storage_path(),
            'configured' => telegram_global_is_configured(),
            'userTelegram' => ['chat_id' => '', 'stored' => false, 'updated_at' => ''],
            'users' => $integrations->users(),
            'listener' => telegram_listener_status(),
            'telegramCommands' => $commands->commands(),
            'telegramHelpText' => $commands->helpText(),
        ]);
    }

    public function update(Request $request, UserIntegrationRepository $integrations): RedirectResponse
    {
        $this->loadTelegramLibrary();
        $chatId = trim((string) $request->input('chat_id', ''));
        if ($chatId === '') {
            return back()->withInput()->with('telegram_error', 'Completa tu TELEGRAM_CHAT_ID.');
        }

        if (!$integrations->saveTelegramForSession($this->sessionUser($request), $chatId)) {
            return back()->withInput()->with('telegram_error', 'No se pudo guardar tu Chat ID.');
        }

        $sessionUser = $this->sessionUser($request);
        $sessionUser['has_telegram_settings'] = true;
        $request->session()->put('nova_user', $sessionUser);

        return redirect()->route('telegram.index')->with('telegram_status', 'Chat ID guardado para tu usuario.');
    }

    public function updateAdmin(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->loadTelegramLibrary();

        $current = telegram_read_config();
        $token = (string) $request->input('bot_token', '');
        $config = [
            'bot_token' => $token !== '' ? $token : (string) ($current['bot_token'] ?? ''),
            'chat_id' => trim((string) ($current['chat_id'] ?? '')),
            'proxy_url' => trim((string) $request->input('proxy_url', '')),
            'default_parse_mode' => '',
        ];

        if (!telegram_global_is_configured($config)) {
            return back()->withInput()->with('telegram_error', 'Completa BOT_TOKEN.');
        }

        if (!telegram_save_config($config)) {
            return back()->withInput()->with('telegram_error', 'No se pudo guardar la configuracion Telegram.');
        }

        return redirect()->route('telegram.admin')->with('telegram_status', 'Configuracion global Telegram guardada.');
    }

    public function test(Request $request, UserIntegrationRepository $integrations, TelegramCommandSettingsRepository $settings): RedirectResponse
    {
        $this->loadTelegramLibrary();
        $chatId = $integrations->telegramForSession($this->sessionUser($request))['chat_id'];

        try {
            telegram_send_configured_message($settings->render('test', ['fecha' => now()->format('d/m/Y H:i:s')]), [
                'chat_id' => $chatId,
            ]);
        } catch (Throwable $e) {
            return back()->with('telegram_error', $e->getMessage());
        }

        return redirect()->route('telegram.index')->with('telegram_status', 'Mensaje de prueba enviado.');
    }

    public function listener(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->loadTelegramLibrary();

        $action = (string) $request->input('action', '');

        try {
            if ($action === 'start') {
                return redirect()->route('administracion.section', 'telegram')->with('error', 'El listener Telegram ahora se administra desde Docker.');
            }
            if ($action === 'stop') {
                return redirect()->route('administracion.section', 'telegram')->with('error', 'El listener Telegram ahora se administra desde Docker.');
            }
            if ($action === 'delete_webhook') {
                $config = telegram_read_config();
                telegram_delete_webhook((string) ($config['bot_token'] ?? ''));
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

    private function loadTelegramLibrary(): void
    {
        $telegramPath = rtrim((string) data_get(config('modules.telegram', []), 'path', base_path('telegram')), DIRECTORY_SEPARATOR);
        require_once $telegramPath . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'telegram.php';
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
}
