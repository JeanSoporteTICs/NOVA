<?php

namespace App\Http\Controllers;

use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ModuleAdminController extends Controller
{
    public function index(Request $request, ModuleRegistry $modules): View
    {
        $this->authorizeModuleAdmin($request);

        return view('nova.modules.index', [
            'modules' => $modules->all(),
            'state' => $modules->state(),
        ]);
    }

    public function update(Request $request, ModuleRegistry $modules): RedirectResponse
    {
        $this->authorizeModuleAdmin($request);

        $configured = array_keys($modules->all());
        $enabled = array_fill_keys((array) $request->input('enabled', []), true);
        $labels = (array) $request->input('labels', []);
        $order = (array) $request->input('order', []);
        $state = [];

        foreach ($configured as $key) {
            $state[$key] = [
                'enabled' => isset($enabled[$key]),
                'label' => trim((string) ($labels[$key] ?? '')),
                'order' => (int) ($order[$key] ?? 100),
            ];
        }

        $modules->saveState($state);

        return redirect()->route('modules.index')->with('status', 'Modulos actualizados.');
    }

    private function authorizeModuleAdmin(Request $request): void
    {
        $role = (string) data_get($request->session()->get('nova_user'), 'role', 'usuario');
        $allowed = config('nova.module_admin_roles', []);

        abort_unless(in_array($role, $allowed, true), 403);
    }
}
