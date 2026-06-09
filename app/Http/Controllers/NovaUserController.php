<?php

namespace App\Http\Controllers;

use App\Support\Auth\NovaUserRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NovaUserController extends Controller
{
    public function index(Request $request, NovaUserRepository $users): View
    {
        $this->authorizeUserAdmin($request);

        return view('nova.users.index', [
            'users' => $users->all(),
        ]);
    }

    public function update(Request $request, NovaUserRepository $users): RedirectResponse
    {
        $this->authorizeUserAdmin($request);

        $action = (string) $request->input('action', 'save');
        if ($action === 'delete') {
            $users->delete((string) $request->input('id'));

            return redirect()->route('nova-users.index')->with('status', 'Usuario marcado como baneado.');
        }

        if ($action === 'activate') {
            $users->activate((string) $request->input('id'));

            return redirect()->route('nova-users.index')->with('status', 'Usuario activado.');
        }

        if ($action === 'password') {
            $result = $users->changePassword(
                (string) $request->input('id'),
                (string) $request->input('password'),
                (string) $request->input('password_confirmation')
            );

            return redirect()
                ->route('nova-users.index')
                ->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Contrasena actualizada.' : $result['error']);
        }

        $result = $users->save($request->all());

        return redirect()
            ->route('nova-users.index')
            ->with($result['ok'] ? 'status' : 'error', $result['ok'] ? 'Usuario guardado.' : $result['error']);
    }

    private function authorizeUserAdmin(Request $request): void
    {
        $role = (string) data_get($request->session()->get('nova_user'), 'role', 'usuario');
        $allowed = config('nova.module_admin_roles', []);

        abort_unless(in_array($role, $allowed, true), 403);
    }
}
