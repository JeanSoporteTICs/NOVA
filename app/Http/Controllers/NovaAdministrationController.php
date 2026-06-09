<?php

namespace App\Http\Controllers;

use App\Support\Auth\NovaUserRepository;
use App\Support\Integrations\TelegramCommandCatalog;
use App\Support\Integrations\TelegramCommandSettingsRepository;
use App\Support\Nova\NovaAuditRepository;
use App\Support\Nova\NovaAccessRepository;
use App\Support\Nova\NovaBackupRepository;
use App\Support\Nova\NovaHealthRepository;
use App\Support\Nova\NovaNotificationService;
use App\Support\Nova\NovaSettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class NovaAdministrationController extends Controller
{
    public function index(Request $request, NovaUserRepository $users, NovaSettingsRepository $settings, NovaAccessRepository $access, NovaAuditRepository $audit, NovaHealthRepository $health, NovaBackupRepository $backups, TelegramCommandCatalog $telegramCommands, TelegramCommandSettingsRepository $telegramSettings, string $section = 'centro'): View
    {
        $this->authorizeAdmin($request);
        $section = in_array($section, ['centro', 'configuracion', 'plataforma', 'salud', 'auditoria', 'respaldos', 'telegram', 'telegram-mensajes', 'emach', 'usuarios', 'accesos'], true) ? $section : 'centro';
        $this->loadTelegramLibrary();
        $needsHealth = in_array($section, ['centro', 'salud'], true);
        $needsAudit = in_array($section, ['centro', 'auditoria'], true);
        $needsBackups = in_array($section, ['centro', 'respaldos'], true);
        $needsTelegram = in_array($section, ['centro', 'telegram', 'telegram-mensajes'], true);

        return view('nova.admin.index', [
            'section' => $section,
            'users' => $users->all(),
            'settings' => $settings->all(),
            'accessMatrix' => $access->matrix(),
            'telegramConfig' => telegram_read_config(),
            'telegramConfigured' => telegram_global_is_configured(),
            'telegramListener' => $needsTelegram ? telegram_listener_status() : [],
            'telegramCommands' => $telegramCommands->commands(),
            'telegramHelpText' => $telegramCommands->helpText(),
            'telegramCommandSettings' => $telegramSettings->all(),
            'telegramCommandSettingsPath' => $telegramSettings->path(),
            'emachConfig' => $this->readEmachMonitorConfig(),
            'emachConfigPath' => $this->emachMonitorConfigPath(),
            'auditItems' => $needsAudit ? $audit->recent($section === 'centro' ? 8 : 120) : [],
            'healthChecks' => $needsHealth ? $health->checks() : [],
            'backupTargets' => $backups->targets(),
            'backupItems' => $needsBackups ? $backups->recent() : [],
        ]);
    }

    public function updateSettings(Request $request, NovaSettingsRepository $settings, NovaAuditRepository $audit, NovaNotificationService $notifications): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $action = (string) $request->input('action', 'settings');

        if ($action === 'telegram') {
            $this->loadTelegramLibrary();
            $current = telegram_read_config();
            $token = (string) $request->input('bot_token', '');
            $config = [
                'bot_token' => $token !== '' ? $token : (string) ($current['bot_token'] ?? ''),
                'chat_id' => (string) ($current['chat_id'] ?? ''),
                'proxy_url' => trim((string) $request->input('proxy_url', '')),
                'default_parse_mode' => '',
            ];

            if (!telegram_global_is_configured($config)) {
                return redirect()->route('administracion.section', 'telegram')->with('error', 'Completa TELEGRAM_BOT_TOKEN.');
            }

            if (!telegram_save_config($config)) {
                return redirect()->route('administracion.section', 'telegram')->with('error', 'No se pudo guardar Telegram global.');
            }
            $audit->record('settings_telegram', 'Telegram global actualizado.', ['proxy' => $config['proxy_url']], $request);

            return redirect()->route('administracion.section', 'telegram')->with('status', 'Telegram global actualizado.');
        }

        if ($action === 'telegram_messages') {
            $repository = app(TelegramCommandSettingsRepository::class);
            if (!$repository->save($request->all())) {
                return redirect()->route('administracion.section', 'telegram-mensajes')->with('error', 'No se pudo guardar mensajes Telegram.');
            }
            $audit->record('settings_telegram_messages', 'Mensajes Telegram actualizados.', ['path' => $repository->path()], $request);

            return redirect()->route('administracion.section', 'telegram-mensajes')->with('status', 'Mensajes Telegram actualizados.');
        }

        if ($action === 'emach') {
            $config = [
                'schedule' => trim((string) $request->input('schedule', '07:00-09:30=15,16:30-19:30=15')),
                'slow_interval' => max(15, (int) $request->input('slow_interval', 300)),
                'updated_at' => date(DATE_ATOM),
            ];

            if (!$this->writeEmachMonitorConfig($config)) {
                return redirect()->route('administracion.section', 'emach')->with('error', 'No se pudo guardar la configuracion EMACH.');
            }
            $audit->record('settings_emach', 'Configuracion EMACH actualizada.', $config, $request);

            return redirect()->route('administracion.section', 'emach')->with('status', 'Configuracion EMACH actualizada.');
        }

        $payload = $request->validate([
            'session_timeout' => ['required', 'integer', 'min:60'],
            'notification_enabled' => ['nullable', 'boolean'],
            'health_warning_threshold' => ['nullable', 'integer', 'min:1'],
        ]);
        $payload['notification_enabled'] = $request->boolean('notification_enabled');
        $settings->save($payload);
        $audit->record('settings_global', 'Configuracion global actualizada.', $payload, $request);
        if (!empty($payload['notification_enabled'])) {
            $notifications->notify('Configuracion global actualizada.');
        }

        return redirect()->route('administracion.section', 'configuracion')->with('status', 'Configuracion actualizada.');
    }

    public function telegramListener(Request $request): RedirectResponse
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

    public function updateUsers(Request $request, NovaUserRepository $users, NovaAuditRepository $audit, NovaNotificationService $notifications): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $action = (string) $request->input('action', 'save');
        if ($action === 'delete') {
            $users->delete((string) $request->input('id'));
            $audit->record('user_banned', 'Usuario marcado como baneado.', ['id' => (string) $request->input('id')], $request);
            $notifications->notify('Usuario marcado como baneado: ' . (string) $request->input('id'));

            return redirect()->route('administracion.section', 'usuarios')->with('status', 'Usuario marcado como baneado.');
        }

        if ($action === 'activate') {
            $users->activate((string) $request->input('id'));
            $audit->record('user_activated', 'Usuario activado.', ['id' => (string) $request->input('id')], $request);

            return redirect()->route('administracion.section', 'usuarios')->with('status', 'Usuario activado.');
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

        return redirect()->route('administracion.section', 'accesos')->with('status', 'Accesos actualizados.');
    }

    public function createBackup(Request $request, NovaBackupRepository $backups, NovaAuditRepository $audit): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $target = (string) $request->input('target', 'all');
        $count = $backups->create($target);
        $audit->record('backup_created', 'Respaldo manual creado.', ['target' => $target, 'files' => $count], $request);

        return redirect()
            ->route('administracion.section', 'respaldos')
            ->with($count > 0 ? 'status' : 'error', $count > 0 ? "Respaldo creado: {$count} archivo(s)." : 'No se generaron respaldos.');
    }

    private function authorizeAdmin(Request $request): void
    {
        $role = (string) data_get($request->session()->get('nova_user'), 'role', 'usuario');
        $allowed = config('nova.module_admin_roles', []);

        abort_unless(in_array($role, $allowed, true), 403);
    }

    private function loadTelegramLibrary(): void
    {
        $telegramPath = rtrim((string) data_get(config('modules.telegram', []), 'path', base_path('telegram')), DIRECTORY_SEPARATOR);
        require_once $telegramPath . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'telegram.php';
    }

    /**
     * @return array<string,mixed>
     */
    private function readEmachMonitorConfig(): array
    {
        $config = json_decode((string) @file_get_contents($this->emachMonitorConfigPath()), true);

        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string,mixed> $config
     */
    private function writeEmachMonitorConfig(array $config): bool
    {
        $path = $this->emachMonitorConfigPath();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        $written = @file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL, LOCK_EX);
        if ($written !== false) {
            @chmod($path, 0666);
        }

        return $written !== false;
    }

    private function emachMonitorConfigPath(): string
    {
        return storage_path('app/emach/monitor_config.json');
    }
}
