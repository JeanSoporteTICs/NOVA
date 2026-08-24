<?php

namespace App\Modulos\Nova\Controllers;

use App\Http\Controllers\Controller;

use App\Modulos\Emach\Services\EmachClientService;
use App\Modulos\Emach\Services\EmachOvertimeService;
use App\Modulos\Nova\Repositories\UserIntegrationRepository;
use App\Modulos\Nova\Repositories\NovaAccessRepository;
use App\Modulos\Nova\Repositories\NovaSettingsRepository;
use App\Modulos\Nova\Services\ProjectAccessGuard;
use App\Modulos\Procedimientos\Services\OnlyOfficeHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class UserIntegrationController extends Controller
{
    private const MODULES = [
        'nova' => [
            'title' => 'NOVA',
            'subtitle' => 'Administra tus credenciales personales compartidas por los modulos NOVA.',
            'icon' => 'bi-grid-1x2-fill',
            'home_route' => 'home',
            'theme' => 'nova',
            'types' => [
                'emach' => [
                    'label' => 'EMACH',
                    'description' => 'Usuario y contrasena usados para consultar tus marcaciones.',
                    'icon' => 'bi-fingerprint',
                    'external_label' => 'Usuario EMACH',
                    'secret_label' => 'Contrasena EMACH',
                    'external_required' => true,
                    'secret_required' => true,
                    'secret_placeholder' => 'Dejar en blanco para conservar',
                ],
                'telegram' => [
                    'label' => 'Telegram',
                    'description' => 'Chat ID personal utilizado para recibir avisos y respuestas.',
                    'icon' => 'bi-telegram',
                    'external_label' => '',
                    'secret_label' => 'Chat ID de Telegram',
                    'external_required' => false,
                    'secret_required' => true,
                    'secret_placeholder' => 'Ingresa tu Chat ID',
                    'is_plain_value' => true,
                ],
                'nextcloud' => [
                    'label' => 'Nextcloud',
                    'description' => 'Cuenta personal utilizada por Procedimientos y operaciones documentales.',
                    'icon' => 'bi-cloud',
                    'external_label' => 'Usuario Nextcloud',
                    'secret_label' => 'Contrasena Nextcloud',
                    'external_required' => true,
                    'secret_required' => true,
                    'secret_placeholder' => 'Dejar en blanco para conservar',
                ],
            ],
        ],
        'emach' => [
            'title' => 'EMACH',
            'subtitle' => 'Credenciales personales para consultar marcaciones.',
            'icon' => 'bi-heart-pulse',
            'home_route' => null,
            'theme' => 'emach',
            'types' => [
                'emach' => [
                    'label' => 'EMACH',
                    'description' => 'Usuario y contrasena usados para consultar tu planilla.',
                    'icon' => 'bi-fingerprint',
                    'external_label' => 'Usuario EMACH',
                    'secret_label' => 'Contrasena EMACH',
                    'external_required' => true,
                    'secret_required' => true,
                    'secret_placeholder' => 'Dejar en blanco para conservar',
                ],
            ],
        ],
        'redmine-mantencion' => [
            'title' => 'Redmine Mantencion',
            'subtitle' => 'Configure las cuentas personales utilizadas por Redmine Mantencion para conectarse con Redmine y CORE.',
            'icon' => 'bi-tools',
            'home_route' => 'redmine.mantencion.dashboard',
            'theme' => 'mantencion',
            'types' => [
                'redmine' => [
                    'label' => 'Redmine',
                    'description' => 'API Key personal compartida por Redmine Mantencion y Redmine TIC.',
                    'icon' => 'bi-key',
                    'external_label' => '',
                    'secret_label' => 'API Key',
                    'external_required' => false,
                    'secret_required' => true,
                    'secret_placeholder' => 'Pega tu token personal',
                ],
                'core' => [
                    'label' => 'CORE',
                    'description' => 'Credenciales personales para importar solicitudes. El código TOTP se solicita solo si CORE lo exige y nunca se guarda.',
                    'icon' => 'bi-database-down',
                    'external_label' => 'Usuario CORE',
                    'secret_label' => 'Contrasena CORE',
                    'external_required' => true,
                    'secret_required' => true,
                    'secret_placeholder' => 'Dejar en blanco para conservar',
                ],
            ],
        ],
        'redmine_tic' => [
            'title' => 'Backlog Soporte TI',
            'subtitle' => 'Credenciales personales para Redmine y servicios TIC.',
            'icon' => 'bi-kanban',
            'home_route' => 'redmine.native.dashboard',
            'theme' => 'tic',
            'types' => [
                'redmine' => [
                    'label' => 'Redmine API Key',
                    'description' => 'API Key personal compartida por Redmine Mantencion y Redmine TIC.',
                    'icon' => 'bi-key',
                    'external_label' => '',
                    'secret_label' => 'API Key',
                    'external_required' => false,
                    'secret_required' => true,
                    'secret_placeholder' => 'Pega tu token personal',
                ],
            ],
        ],
    ];

    public function show(Request $request, UserIntegrationRepository $integrations, NovaSettingsRepository $settings, OnlyOfficeHealthService $onlyOfficeHealth, string $module): View
    {
        $config = $this->moduleConfig($module);
        $projectUser = $this->authorizeModule($request, $module);

        $sessionUser = $this->sessionUser($request);
        $types = array_keys($config['types']);
        $sessionTimeout = $settings->sessionTimeout();
        $lastActivity = (int) $request->session()->get('nova_last_activity', time());
        $sessionRemaining = max(0, $sessionTimeout - (time() - $lastActivity));

        $template = $module === 'redmine-mantencion'
            ? 'nova.integrations.user-config-mantencion'
            : 'nova.integrations.user-config';

        $states = $integrations->integrationsForSession($sessionUser, array_values(array_filter($types, static fn (string $type): bool => $type !== 'telegram')));
        if (in_array('telegram', $types, true)) {
            $telegram = $integrations->telegramForSession($sessionUser);
            $states['telegram'] = [
                'type' => 'telegram',
                'external_user' => '',
                'has_external_user' => false,
                'has_secret' => $telegram['stored'],
                'stored' => $telegram['stored'],
                'updated_at' => $telegram['updated_at'],
                'masked_external_user' => '',
            ];
        }

        return view($template, [
            'moduleKey' => $module,
            'moduleConfig' => $config,
            'integrationDefinitions' => $config['types'],
            'integrations' => $states,
            'homeUrl' => $this->homeUrl($config),
            'postUrl' => url()->current(),
            'sessionTimeout' => $sessionTimeout,
            'sessionRemaining' => $sessionRemaining,
            'integrationPermissions' => is_array($projectUser['permisos'] ?? null) ? $projectUser['permisos'] : [],
            'onlyOfficeHealth' => $module === 'nova' ? $onlyOfficeHealth->check() : [],
        ]);
    }

    public function update(Request $request, UserIntegrationRepository $integrations, string $module): RedirectResponse
    {
        $config = $this->moduleConfig($module);
        $this->authorizeModule($request, $module);

        $type = (string) $request->input('type', '');
        $definition = $config['types'][$type] ?? null;
        if (!is_array($definition)) {
            return $this->integrationRedirect($module)->with('integration_error', 'Integracion no permitida para este modulo.');
        }

        $sessionUser = $this->sessionUser($request);
        $action = (string) $request->input('action', 'save');
        if ($action === 'delete') {
            $ok = $type === 'telegram'
                ? $integrations->deleteTelegramForSession($sessionUser)
                : $integrations->deleteCredentialForSession($sessionUser, $type);
            if ($ok && $type === 'emach') {
                $request->session()->forget('emach.last_query');
                $novaUser = $request->session()->get('nova_user', []);
                if (is_array($novaUser)) {
                    $novaUser['has_emach_credentials'] = false;
                    $request->session()->put('nova_user', $novaUser);
                }
            }
            if ($ok && $type === 'telegram') {
                $novaUser = $request->session()->get('nova_user', []);
                if (is_array($novaUser)) {
                    $novaUser['has_telegram_settings'] = false;
                    $request->session()->put('nova_user', $novaUser);
                }
            }

            return $this->integrationRedirect($module)
                ->with($ok ? 'integration_status' : 'integration_error', $ok ? 'Credencial eliminada.' : 'No se pudo eliminar la credencial.');
        }

        $current = $type === 'telegram'
            ? ['has_external_user' => false, 'has_secret' => $integrations->telegramForSession($sessionUser)['stored']]
            : $integrations->integrationForSession($sessionUser, $type);
        $externalUser = trim((string) $request->input('external_user', ''));
        $secret = (string) $request->input('secret', '');
        if (!empty($definition['external_required']) && $externalUser === '' && empty($current['has_external_user'])) {
            return $this->integrationRedirect($module)->withInput()->with('integration_error', 'Completa ' . $definition['external_label'] . '.');
        }
        if (!empty($definition['secret_required']) && $secret === '' && empty($current['has_secret'])) {
            return $this->integrationRedirect($module)->withInput()->with('integration_error', 'Completa ' . $definition['secret_label'] . '.');
        }

        if ($type === 'telegram' && $secret === '' && !empty($current['has_secret'])) {
            return $this->integrationRedirect($module)->with('integration_status', 'Chat ID conservado sin cambios.');
        }

        $ok = $type === 'telegram'
            ? $integrations->saveTelegramForSession($sessionUser, trim($secret))
            : $integrations->saveCredentialForSession($sessionUser, $type, $externalUser, $secret);
        if ($ok && $type === 'emach') {
            $request->session()->forget('emach.last_query');
            $novaUser = $request->session()->get('nova_user', []);
            if (is_array($novaUser)) {
                $novaUser['has_emach_credentials'] = true;
                $request->session()->put('nova_user', $novaUser);
            }
        }
        if ($ok && $type === 'telegram') {
            $novaUser = $request->session()->get('nova_user', []);
            if (is_array($novaUser)) {
                $novaUser['has_telegram_settings'] = true;
                $request->session()->put('nova_user', $novaUser);
            }
        }

        return $this->integrationRedirect($module)
            ->with($ok ? 'integration_status' : 'integration_error', $ok ? 'Cuentas actualizadas correctamente.' : 'No se pudo guardar la credencial.');
    }

    public function emachOvertimeSuggestion(Request $request, UserIntegrationRepository $integrations, EmachClientService $emach, EmachOvertimeService $overtime): JsonResponse
    {
        $sessionUser = $this->sessionUser($request);
        if ($sessionUser === []) {
            return response()->json(['ok' => false, 'message' => 'Sesion NOVA no disponible.'], 403);
        }

        $date = $this->parseDate((string) $request->input('fecha', ''));
        if (!$date) {
            return response()->json(['ok' => false, 'message' => 'Fecha invalida.'], 422);
        }

        $credentials = $integrations->emachForSession($sessionUser);
        if (empty($credentials['stored'])) {
            return response()->json(['ok' => false, 'message' => 'Configura tus credenciales EMACH antes de calcular.'], 422);
        }

        $userId = $this->centralUserIdForSession($sessionUser);
        if ($userId === null) {
            return response()->json(['ok' => false, 'message' => 'No pude asociar tu usuario NOVA con EMACH.'], 422);
        }

        $schedule = $overtime->scheduleForUser($userId);
        $weekday = (int) $date->format('N');
        $configured = $schedule[$weekday] ?? null;
        $scheduledExit = $configured && !empty($configured['activo'])
            ? $overtime->minutesFromClock((string) ($configured['salida'] ?? ''))
            : null;
        $tieneJornadaActiva = $scheduledExit !== null;

        try {
            $rows = $this->fetchEmachRows($emach, (int) $date->format('Y'), (int) $date->format('n'), (string) $credentials['user'], (string) $credentials['password']);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'No pude consultar EMACH: ' . $e->getMessage()], 502);
        }

        $request->session()->put('emach.last_query', [
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
            'username' => (string) $credentials['user'],
            'planilla' => ['rows' => $rows],
        ]);

        $dateKey = $date->format('Y-m-d');

        // Dia con jornada activa: hora extra = desde la salida programada hasta la salida real marcada.
        if ($tieneJornadaActiva) {
            $actualExit = $overtime->exitForDate($rows, $dateKey);
            if ($actualExit === null) {
                return response()->json(['ok' => false, 'message' => 'No encontre una marcacion de salida EMACH para esa fecha.'], 422);
            }

            $extraMinutes = $actualExit - $scheduledExit;
            if ($extraMinutes <= 0) {
                return response()->json(['ok' => false, 'message' => 'La salida EMACH no supera tu horario de salida.'], 422);
            }

            return response()->json([
                'ok' => true,
                'hora_inicio' => $overtime->clockFromMinutes($scheduledExit),
                'hora_fin' => $overtime->clockFromMinutes($actualExit),
                'total' => $overtime->formatMinutes($extraMinutes),
                'message' => 'Calculado consultando EMACH para ' . $date->format('d-m-Y') . '.',
            ]);
        }

        // Sin jornada activa (dia no habil o sin horario configurado): todo lo marcado ese dia es hora extra.
        $entry = $overtime->entryForDate($rows, $dateKey);
        $exit = $overtime->exitForDate($rows, $dateKey);
        if ($entry === null || $exit === null || $exit <= $entry) {
            return response()->json(['ok' => false, 'message' => 'No se encontraron marcaciones EMACH para calcular horas extra en esta fecha.'], 422);
        }

        return response()->json([
            'ok' => true,
            'hora_inicio' => $overtime->clockFromMinutes($entry),
            'hora_fin' => $overtime->clockFromMinutes($exit),
            'total' => $overtime->formatMinutes($exit - $entry),
            'message' => 'Día sin jornada laboral: se calculó todo el tiempo registrado como hora extra.',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function moduleConfig(string $module): array
    {
        abort_unless(isset(self::MODULES[$module]), 404);

        return self::MODULES[$module];
    }

    /** @return array<string,mixed> */
    private function authorizeModule(Request $request, string $module): array
    {
        $user = $this->sessionUser($request);
        abort_if($user === [], 403);

        if ($module === 'nova') {
            abort_unless(app(NovaAccessRepository::class)->canAccess($user, 'integraciones'), 403);

            return $user;
        }

        $access = app(ProjectAccessGuard::class);
        $projectUser = $access->projectUser($module, $user);
        abort_unless(is_array($projectUser), 403);

        if ($module === 'redmine-mantencion') {
            require_once base_path('RedmineMantencion/controllers/auth.php');
            abort_unless(auth_can('mis_integraciones'), 403);
        } elseif ($module === 'redmine_tic') {
            $permissions = is_array($projectUser['permisos'] ?? null) ? $projectUser['permisos'] : [];
            abort_unless(!empty($permissions['all']) || !empty($permissions['mis_integraciones']), 403);
        }

        return $projectUser;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('America/Santiago'));
            if ($date instanceof \DateTimeImmutable) {
                return $date;
            }
        }

        return null;
    }

    private function centralUserIdForSession(array $sessionUser): ?int
    {
        try {
            if (!Schema::hasTable('usuarios_nova')) {
                return null;
            }

            $candidates = [
                'uuid' => [$sessionUser['id'] ?? '', $sessionUser['_nova_user_id'] ?? '', $sessionUser['uuid'] ?? ''],
                'usuario' => [$sessionUser['username'] ?? '', $sessionUser['usuario'] ?? '', $sessionUser['rut_sin_dv'] ?? ''],
                'rut' => [$sessionUser['rut'] ?? ''],
                'redmine_id' => [$sessionUser['redmine_id'] ?? '', $sessionUser['legacy']['id'] ?? ''],
                'usuario_core' => [$sessionUser['core_user'] ?? '', $sessionUser['usuario_core'] ?? ''],
            ];

            foreach ($candidates as $column => $values) {
                foreach ($values as $value) {
                    $value = trim((string) $value);
                    if ($value === '') {
                        continue;
                    }

                    $id = DB::table('usuarios_nova')->where($column, $value)->value('id');
                    if ($id !== null) {
                        return (int) $id;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * @return array<int,array<int|string,mixed>>
     */
    private function fetchEmachRows(EmachClientService $emach, int $year, int $month, string $username, string $password): array
    {
        return $emach->fetchPlanillaRows($year, $month, $username, $password);
    }

    /**
     * @return array<string,mixed>
     */
    private function sessionUser(Request $request): array
    {
        $user = $request->session()->get('nova_user');

        return is_array($user) ? $user : [];
    }

    /**
     * @param array<string,mixed> $config
     */
    private function homeUrl(array $config): string
    {
        $route = Arr::get($config, 'home_route');
        if (is_string($route) && $route !== '') {
            return route($route);
        }

        return url('/emach');
    }

    private function integrationRedirect(string $module): RedirectResponse
    {
        $route = match ($module) {
            'nova' => 'integrations.nova',
            'emach' => 'integrations.emach',
            'redmine-mantencion' => 'integrations.redmine_mantencion',
            'redmine_tic' => 'integrations.redmine_tic',
            default => 'home',
        };

        return redirect()->route($route);
    }

}
