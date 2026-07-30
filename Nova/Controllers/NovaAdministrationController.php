<?php

namespace App\Modulos\Nova\Controllers;

use App\Http\Controllers\Controller;

use App\Modulos\Telegram\Repositories\TelegramCommandCatalog;
use App\Modulos\Telegram\Repositories\TelegramCommandSettingsRepository;
use App\Modulos\Nova\Repositories\NovaAccessRepository;
use App\Modulos\Nova\Repositories\NovaAuditRepository;
use App\Modulos\Nova\Repositories\NovaHealthRepository;
use App\Modulos\Nova\Repositories\NovaUserRepository;
use App\Modulos\Nova\Services\NovaHealthAlertService;
use App\Modulos\Nova\Services\NovaNotificationService;
use App\Modulos\Telegram\Services\TelegramService;
use App\Modulos\Nova\Repositories\NovaSettingsRepository;
use App\Modulos\Procedimientos\Services\OnlyOfficeHealthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class NovaAdministrationController extends Controller
{
    public function __construct(private TelegramService $telegram)
    {
    }

    public function index(Request $request, NovaUserRepository $users, NovaSettingsRepository $settings, NovaAccessRepository $access, NovaAuditRepository $audit, NovaHealthRepository $health, TelegramCommandCatalog $telegramCommands, TelegramCommandSettingsRepository $telegramSettings, string $section = 'centro'): View|RedirectResponse
    {
        $this->authorizeAdmin($request);
        if ($section === 'emach') {
            return redirect()->route('administracion.section', 'telegram-mensajes');
        }
        if ($section === 'horas-extra') {
            return redirect()->route('horas-extra.index');
        }
        $section = in_array($section, ['centro', 'configuracion', 'onlyoffice', 'salud', 'auditoria', 'telegram', 'telegram-mensajes', 'usuarios', 'accesos'], true) ? $section : 'centro';
        $this->telegram->load();
        $needsHealth = in_array($section, ['centro', 'salud'], true);
        $needsAudit = in_array($section, ['centro', 'auditoria'], true);
        $needsTelegram = in_array($section, ['centro', 'telegram', 'telegram-mensajes'], true);

        return view('nova.admin.index', [
            'section'                     => $section,
            'users'                       => $users->all(),
            'settings'                    => $settings->all(),
            'onlyOffice'                  => $settings->onlyOfficeStatus(),
            'accessMatrix'                => $access->matrix(),
            'telegramConfig'              => $this->telegram->readConfig(),
            'telegramConfigured'          => $this->telegram->isConfigured(),
            'telegramListener'            => $needsTelegram ? $this->telegram->listenerStatus() : [],
            'telegramCommands'            => $telegramCommands->commands(),
            'telegramHelpText'            => $telegramCommands->helpText(),
            'telegramCommandSettings'     => $telegramSettings->all(),
            'telegramCommandSettingsPath' => $telegramSettings->path(),
            'auditItems'                  => $needsAudit ? $audit->recent($section === 'centro' ? 8 : 120) : [],
            'healthChecks'                => $needsHealth ? $health->checks() : [],
        ]);
    }

    public function updateSettings(Request $request, NovaSettingsRepository $settings, NovaAuditRepository $audit, NovaNotificationService $notifications): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $action = (string) $request->input('action', 'settings');

        if ($action === 'telegram') {
            $current = $this->telegram->readConfig();
            $token   = (string) $request->input('bot_token', '');
            $config  = [
                'bot_token'          => $token !== '' ? $token : (string) ($current['bot_token'] ?? ''),
                'chat_id'            => (string) ($current['chat_id'] ?? ''),
                'proxy_url'          => trim((string) $request->input('proxy_url', '')),
                'default_parse_mode' => '',
            ];

            if (!$this->telegram->isConfigured($config)) {
                return redirect()->route('administracion.section', 'telegram')->with('error', 'Completa TELEGRAM_BOT_TOKEN.');
            }

            if (!$this->telegram->saveConfig($config)) {
                return redirect()->route('administracion.section', 'telegram')->with('error', 'No se pudo guardar Telegram global.');
            }
            $audit->record('settings_telegram', 'Telegram global actualizado.', ['proxy' => $config['proxy_url']], $request);
            $changes = [];
            if ($token !== '' && !hash_equals((string) ($current['bot_token'] ?? ''), $token)) {
                $changes[] = 'TELEGRAM_BOT_TOKEN: actualizado';
            }
            if ((string) ($current['proxy_url'] ?? '') !== $config['proxy_url']) {
                $changes[] = 'TELEGRAM_PROXY_URL: actualizado';
            }
            if ($changes !== []) {
                $notifications->notify("Configuracion global de Telegram actualizada:\n- " . implode("\n- ", $changes));
            }

            return redirect()->route('administracion.section', 'telegram')->with('status', 'Telegram global actualizado.');
        }

        if ($action === 'telegram_messages') {
            $repository = app(TelegramCommandSettingsRepository::class);
            if (!$repository->save($request->all())) {
                return redirect()->route('administracion.section', 'telegram-mensajes')->with('error', 'No se pudo guardar mensajes Telegram.');
            }
            $audit->record('settings_telegram_messages', 'Mensajes Telegram actualizados.', ['path' => $repository->path()], $request);
            $notifications->notify('Configuracion global de mensajes Telegram actualizada.');

            return redirect()->route('administracion.section', 'telegram-mensajes')->with('status', 'Mensajes Telegram actualizados.');
        }

        if ($action === 'emach') {
            return redirect()->route('administracion.section', 'telegram-mensajes')->with('status', 'La configuracion global EMACH fue retirada. Usa el comando /emach desde Telegram.');
        }

        if ($action === 'onlyoffice') {
            $before = $settings->onlyOffice();
            $payload = $request->validate([
                'onlyoffice_url' => ['required', 'url', 'max:500'],
                'onlyoffice_jwt_secret' => ['nullable', 'string', 'min:8', 'max:500'],
            ]);
            $settings->saveOnlyOffice((string) $payload['onlyoffice_url'], (string) ($payload['onlyoffice_jwt_secret'] ?? ''));
            $audit->record('settings_onlyoffice', 'Configuracion OnlyOffice actualizada.', ['url' => (string) $payload['onlyoffice_url'], 'secret_replaced' => !empty($payload['onlyoffice_jwt_secret'])], $request);
            $changes = [];
            if ((string) ($before['url'] ?? '') !== (string) $payload['onlyoffice_url']) {
                $changes[] = 'Servidor: actualizado';
            }
            if (!empty($payload['onlyoffice_jwt_secret'])) {
                $changes[] = 'JWT_SECRET: actualizado';
            }
            if ($changes !== []) {
                $notifications->notify("Editor OnlyOffice actualizado:\n- " . implode("\n- ", $changes));
            }

            return redirect()->route('administracion.section', 'onlyoffice')->with('status', 'Configuracion OnlyOffice actualizada.');
        }

        if ($action === 'onlyoffice_toggle') {
            $enabled = $request->boolean('onlyoffice_enabled');
            $settings->setOnlyOfficeEnabled($enabled);
            $audit->record('settings_onlyoffice_toggle', 'Estado de OnlyOffice actualizado.', ['enabled' => $enabled], $request);
            $notifications->notify('Editor OnlyOffice: ' . ($enabled ? 'activado' : 'desactivado') . '.');

            return redirect()->route('administracion.section', 'onlyoffice')->with('status', $enabled ? 'OnlyOffice activado.' : 'OnlyOffice desactivado.');
        }

        $before = $settings->all();
        $payload = $request->validate([
            'session_timeout'          => ['required', 'integer', 'min:60'],
            'notification_enabled'     => ['nullable', 'boolean'],
        ]);
        $payload['notification_enabled'] = $request->boolean('notification_enabled');
        $changes = $this->settingsChanges($before, $payload);
        $settings->save($payload);
        $audit->record('settings_global', 'Configuracion global actualizada.', $payload, $request);
        if (!empty($payload['notification_enabled']) && $changes !== []) {
            $notifications->notify("Configuracion global actualizada:\n- " . implode("\n- ", $changes));
        }

        return redirect()->route('administracion.section', 'configuracion')->with('status', 'Configuracion actualizada.');
    }

    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<int,string>
     */
    private function settingsChanges(array $before, array $after): array
    {
        $fields = [
            'session_timeout' => 'Tiempo de sesion',
            'notification_enabled' => 'Notificaciones Telegram a admins',
        ];
        $changes = [];

        foreach ($fields as $key => $label) {
            $previous = $this->normalizedSettingValue($key, $before[$key] ?? null);
            $current = $this->normalizedSettingValue($key, $after[$key] ?? null);
            if ($previous === $current) {
                continue;
            }

            $changes[] = $label . ': ' . $this->settingDisplayValue($key, $previous) . ' -> ' . $this->settingDisplayValue($key, $current);
        }

        return $changes;
    }

    private function normalizedSettingValue(string $key, mixed $value): int|string
    {
        return match ($key) {
            'notification_enabled' => !empty($value) ? 1 : 0,
            'session_timeout' => (int) $value,
            default => (string) $value,
        };
    }

    private function settingDisplayValue(string $key, int|string $value): string
    {
        return match ($key) {
            'notification_enabled' => ((int) $value === 1) ? 'activadas' : 'desactivadas',
            'session_timeout' => (string) $value . ' segundos',
            default => (string) $value,
        };
    }

    public function telegramListener(Request $request): RedirectResponse
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

    public function notifyHealth(Request $request, NovaHealthRepository $health, NovaNotificationService $notifications, NovaAuditRepository $audit): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $checks = array_values(array_filter($health->checks(), static function (array $check): bool {
            return in_array((string) ($check['name'] ?? ''), NovaHealthAlertService::ALERT_CHECKS, true);
        }));

        $lines = array_map(static function (array $check): string {
            $name = (string) ($check['name'] ?? 'Servicio');
            $status = strtolower((string) ($check['status'] ?? 'warn'));
            $detail = trim((string) ($check['detail'] ?? ''));
            $icon = match ($status) {
                'ok' => '✅',
                'error' => '❌',
                default => '⚠️',
            };
            $label = match ($status) {
                'ok' => 'OK',
                'error' => 'ERROR',
                default => 'WARNING',
            };

            return $icon . ' ' . $name . ' ' . $label . ($status !== 'ok' && $detail !== '' ? ' - ' . $detail : '');
        }, $checks);

        $sent = $notifications->notify("Estado servicios principales:\n" . implode("\n", $lines));
        $audit->record('health_notify', 'Estado de salud enviado por Telegram.', ['checks' => count($checks), 'sent' => $sent], $request);

        return redirect()
            ->route('administracion.section', 'salud')
            ->with($sent ? 'status' : 'error', $sent ? 'Estado enviado a administradores por Telegram.' : 'No se pudo enviar. Revisa que las notificaciones Telegram a admins esten activas y que existan Chat ID configurados.');
    }

    public function testOnlyOffice(Request $request, OnlyOfficeHealthService $health, NovaAuditRepository $audit): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $result = $health->checkWithJwt();
        $ok = $result['status'] === 'online';
        $audit->record('onlyoffice_test', 'Prueba de conexion y JWT de OnlyOffice ejecutada.', ['status' => $result['status'], 'http' => $result['http']], $request);

        return redirect()->route('administracion.section', 'onlyoffice')->with(
            $ok ? 'status' : 'error',
            $result['label'] . ($result['http'] > 0 ? ' (HTTP ' . $result['http'] . ')' : '') . '. ' . $result['detail']
        );
    }

    public function updateUsers(Request $request, NovaUserRepository $users, NovaAuditRepository $audit): RedirectResponse|JsonResponse
    {
        $this->authorizeAdmin($request);
        $this->authorizeRootUserMutation($request, $users);

        $action = (string) $request->input('action', 'save');
        if ($action === 'delete') {
            $id = (string) $request->input('id');
            $changed = $users->delete($id) > 0;
            if ($changed) {
                $audit->record('user_banned', 'Usuario marcado como baneado.', ['id' => $id], $request);
            }

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'ok' => $changed,
                    'status' => 'baneado',
                    'message' => $changed ? 'Usuario marcado como baneado.' : 'No se pudo banear el usuario.',
                ], $changed ? 200 : 422);
            }

            return redirect()->route('administracion.section', 'usuarios')->with($changed ? 'status' : 'error', $changed ? 'Usuario marcado como baneado.' : 'No se pudo banear el usuario.');
        }

        if ($action === 'activate') {
            $id = (string) $request->input('id');
            $changed = $users->activate($id) > 0;
            if ($changed) {
                $audit->record('user_activated', 'Usuario activado.', ['id' => $id], $request);
            }

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'ok' => $changed,
                    'status' => 'activo',
                    'message' => $changed ? 'Usuario activado.' : 'No se pudo activar el usuario.',
                ], $changed ? 200 : 422);
            }

            return redirect()->route('administracion.section', 'usuarios')->with($changed ? 'status' : 'error', $changed ? 'Usuario activado.' : 'No se pudo activar el usuario.');
        }

        if ($action === 'password') {
            $result = $users->changePassword(
                (string) $request->input('id'),
                (string) $request->input('password'),
                (string) $request->input('password_confirmation')
            );
            $audit->record($result['ok'] ? 'user_password_changed' : 'user_password_error', $result['ok'] ? 'Contrasena de usuario actualizada.' : $result['error'], ['id' => (string) $request->input('id')], $request);

            return redirect()
                ->route('administracion.section', 'usuarios')
                ->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Contrasena actualizada.' : $result['error']);
        }

        $result = $users->save($request->all());
        $audit->record($result['ok'] ? 'user_saved' : 'user_save_error', $result['ok'] ? 'Usuario guardado.' : $result['error'], ['username' => (string) $request->input('username')], $request);

        return redirect()
            ->route('administracion.section', 'usuarios')
            ->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Usuario guardado.' : $result['error']);
    }

    public function updateAccess(Request $request, NovaAccessRepository $access, NovaAuditRepository $audit): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $access->save($request->all());
        $audit->record('access_updated', 'Accesos NOVA actualizados.', ['identity' => (string) $request->input('selected_identity')], $request);

        return redirect()
            ->route('administracion.section', 'accesos')
            ->with('status', 'Accesos actualizados.')
            ->with('access_selected_identity', (string) $request->input('selected_identity'));
    }

    private function authorizeAdmin(Request $request): void
    {
        $role    = (string) data_get($request->session()->get('nova_user'), 'role', 'usuario');
        $allowed = config('nova.module_admin_roles', []);

        abort_unless(in_array($role, $allowed, true), 403);
    }

    private function authorizeRootUserMutation(Request $request, NovaUserRepository $users): void
    {
        $actorRole = strtolower(trim((string) data_get(
            $request->session()->get('nova_user'),
            'role',
            'usuario'
        )));
        if ($actorRole === 'root') {
            return;
        }

        $targetId = trim((string) $request->input('id', ''));
        $targetRole = '';
        if ($targetId !== '') {
            foreach ($users->all() as $user) {
                if ((string) ($user['id'] ?? '') === $targetId) {
                    $targetRole = strtolower(trim((string) ($user['role'] ?? 'usuario')));
                    break;
                }
            }
        }

        $requestedRole = strtolower(trim((string) $request->input('role', '')));
        abort_if($targetRole === 'root' || $requestedRole === 'root', 403);
    }

}
