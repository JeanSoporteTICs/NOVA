<?php

namespace App\Http\Controllers;

use App\Support\Auth\NovaUserRepository;
use App\Support\Nova\NovaAccessRepository;
use App\Support\Nova\NovaSettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NovaAdministrationController extends Controller
{
    public function index(Request $request, NovaUserRepository $users, NovaSettingsRepository $settings, NovaAccessRepository $access, string $section = 'configuracion'): View
    {
        $this->authorizeAdmin($request);
        $section = in_array($section, ['configuracion', 'usuarios', 'accesos'], true) ? $section : 'configuracion';

        return view('nova.admin.index', [
            'section' => $section,
            'users' => $users->all(),
            'settings' => $settings->all(),
            'accessMatrix' => $access->matrix(),
        ]);
    }

    public function updateSettings(Request $request, NovaSettingsRepository $settings): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $settings->save($request->validate([
            'session_timeout' => ['required', 'integer', 'min:60'],
        ]));

        return redirect()->route('administracion.section', 'configuracion')->with('status', 'Configuracion actualizada.');
    }

    public function updateUsers(Request $request, NovaUserRepository $users): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $action = (string) $request->input('action', 'save');
        if ($action === 'delete') {
            $users->delete((string) $request->input('id'));

            return redirect()->route('administracion.section', 'usuarios')->with('status', 'Usuario marcado como baneado.');
        }

        if ($action === 'activate') {
            $users->activate((string) $request->input('id'));

            return redirect()->route('administracion.section', 'usuarios')->with('status', 'Usuario activado.');
        }

        $result = $users->save($request->all());

        return redirect()
            ->route('administracion.section', 'usuarios')
            ->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Usuario guardado.' : $result['error']);
    }

    public function updateAccess(Request $request, NovaAccessRepository $access): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $access->save($request->all());

        return redirect()->route('administracion.section', 'accesos')->with('status', 'Accesos actualizados.');
    }

    private function authorizeAdmin(Request $request): void
    {
        $role = (string) data_get($request->session()->get('nova_user'), 'role', 'usuario');
        $allowed = config('nova.module_admin_roles', []);

        abort_unless(in_array($role, $allowed, true), 403);
    }
}
