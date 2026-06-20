<?php

namespace App\Http\Controllers;

use App\Support\Integrations\UserIntegrationRepository;
use App\Support\Modules\ProjectAccessGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class UserIntegrationController extends Controller
{
    private const MODULES = [
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
            'subtitle' => 'Credenciales personales para Redmine, CORE y Nextcloud.',
            'icon' => 'bi-tools',
            'home_route' => 'redmine.mantencion.dashboard',
            'theme' => 'mantencion',
            'types' => [
                'redmine_mantencion' => [
                    'label' => 'Redmine API Key',
                    'description' => 'Token personal usado al enviar o sincronizar datos con Redmine Mantencion.',
                    'icon' => 'bi-key',
                    'external_label' => '',
                    'secret_label' => 'API Key',
                    'external_required' => false,
                    'secret_required' => true,
                    'secret_placeholder' => 'Pega tu token personal',
                ],
                'core' => [
                    'label' => 'CORE',
                    'description' => 'Credenciales personales para importar solicitudes desde CORE.',
                    'icon' => 'bi-database-down',
                    'external_label' => 'Usuario CORE',
                    'secret_label' => 'Contrasena CORE',
                    'external_required' => true,
                    'secret_required' => true,
                    'secret_placeholder' => 'Dejar en blanco para conservar',
                ],
                'nextcloud' => [
                    'label' => 'Nextcloud',
                    'description' => 'Cuenta personal usada para operaciones documentales en Nextcloud.',
                    'icon' => 'bi-cloud',
                    'external_label' => 'Usuario Nextcloud',
                    'secret_label' => 'Contrasena Nextcloud',
                    'external_required' => true,
                    'secret_required' => true,
                    'secret_placeholder' => 'Dejar en blanco para conservar',
                ],
            ],
        ],
        'redmine_tic' => [
            'title' => 'Redmine TICS',
            'subtitle' => 'Credenciales personales para Redmine y servicios TIC.',
            'icon' => 'bi-kanban',
            'home_route' => 'redmine.native.dashboard',
            'theme' => 'tic',
            'types' => [
                'redmine_tic' => [
                    'label' => 'Redmine API Key',
                    'description' => 'Token personal usado por acciones de Redmine TIC.',
                    'icon' => 'bi-key',
                    'external_label' => '',
                    'secret_label' => 'API Key',
                    'external_required' => false,
                    'secret_required' => true,
                    'secret_placeholder' => 'Pega tu token personal',
                ],
                'tic_personal' => [
                    'label' => 'Integracion TIC personal',
                    'description' => 'Credencial personal para servicios auxiliares TIC.',
                    'icon' => 'bi-plug',
                    'external_label' => 'Usuario o identificador',
                    'secret_label' => 'Token o clave',
                    'external_required' => false,
                    'secret_required' => true,
                    'secret_placeholder' => 'Dejar en blanco para conservar',
                ],
            ],
        ],
    ];

    public function show(Request $request, UserIntegrationRepository $integrations, string $module): View
    {
        $config = $this->moduleConfig($module);
        $this->authorizeModule($request, $module);

        $sessionUser = $this->sessionUser($request);
        $types = array_keys($config['types']);

        return view('nova.integrations.user-config', [
            'moduleKey' => $module,
            'moduleConfig' => $config,
            'integrationDefinitions' => $config['types'],
            'integrations' => $integrations->integrationsForSession($sessionUser, $types),
            'homeUrl' => $this->homeUrl($config),
            'postUrl' => url()->current(),
        ]);
    }

    public function update(Request $request, UserIntegrationRepository $integrations, string $module): RedirectResponse
    {
        $config = $this->moduleConfig($module);
        $this->authorizeModule($request, $module);

        $type = (string) $request->input('type', '');
        $definition = $config['types'][$type] ?? null;
        if (!is_array($definition)) {
            return back()->with('integration_error', 'Integracion no permitida para este modulo.');
        }

        $sessionUser = $this->sessionUser($request);
        $action = (string) $request->input('action', 'save');
        if ($action === 'delete') {
            $ok = $integrations->deleteCredentialForSession($sessionUser, $type);

            return back()->with($ok ? 'integration_status' : 'integration_error', $ok ? 'Credencial eliminada.' : 'No se pudo eliminar la credencial.');
        }

        $current = $integrations->integrationForSession($sessionUser, $type);
        $externalUser = trim((string) $request->input('external_user', ''));
        $secret = (string) $request->input('secret', '');
        if (!empty($definition['external_required']) && $externalUser === '') {
            return back()->withInput()->with('integration_error', 'Completa ' . $definition['external_label'] . '.');
        }
        if (!empty($definition['secret_required']) && $secret === '' && empty($current['has_secret'])) {
            return back()->withInput()->with('integration_error', 'Completa ' . $definition['secret_label'] . '.');
        }

        $ok = $integrations->saveCredentialForSession($sessionUser, $type, $externalUser, $secret);

        return back()->with($ok ? 'integration_status' : 'integration_error', $ok ? 'Credencial guardada.' : 'No se pudo guardar la credencial.');
    }

    /**
     * @return array<string,mixed>
     */
    private function moduleConfig(string $module): array
    {
        abort_unless(isset(self::MODULES[$module]), 404);

        return self::MODULES[$module];
    }

    private function authorizeModule(Request $request, string $module): void
    {
        $user = $this->sessionUser($request);
        abort_if($user === [], 403);

        $access = app(ProjectAccessGuard::class);
        $projectUser = $access->projectUser($module, $user);
        abort_unless(is_array($projectUser), 403);
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
}
