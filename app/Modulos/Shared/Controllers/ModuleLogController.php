<?php

namespace App\Modulos\Shared\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class ModuleLogController extends Controller
{
    public function index(Request $request, string $module): View
    {
        abort_unless(in_array($module, ['telegram', 'emach'], true), 404);
        $role = (string) data_get($request->session()->get('nova_user'), 'role', 'usuario');
        abort_unless(in_array($role, config('nova.module_admin_roles', []), true), 403);

        $rows = DB::table($module . '_log')->latest('registrado_at')->paginate(50);
        $userIds = $rows->getCollection()
            ->pluck('usuario_id')
            ->map(static fn ($value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();
        $numericIds = $userIds->filter(static fn (string $value): bool => ctype_digit($value))->map(static fn (string $value): int => (int) $value)->all();
        $usersByIdentity = [];

        if ($userIds->isNotEmpty()) {
            $users = DB::table('usuarios_nova')
                ->where(function ($query) use ($userIds, $numericIds): void {
                    $query->whereIn('uuid', $userIds->all())
                        ->orWhereIn('usuario', $userIds->all());
                    if ($numericIds !== []) {
                        $query->orWhereIn('id', $numericIds);
                    }
                })
                ->get(['id', 'uuid', 'usuario', 'nombre', 'apellido']);

            foreach ($users as $user) {
                $name = trim((string) ($user->nombre ?? '') . ' ' . (string) ($user->apellido ?? ''));
                $name = $name !== '' ? $name : (string) ($user->usuario ?? 'Usuario');
                foreach ([(string) $user->id, (string) $user->uuid, (string) $user->usuario] as $identity) {
                    if ($identity !== '') {
                        $usersByIdentity[$identity] = $name;
                    }
                }
            }
        }

        $contextLabels = [
            'metodo' => 'Método',
            'estado_http' => 'Estado HTTP',
            'ano' => 'Año',
            'mes' => 'Mes',
            'filas' => 'Resultados',
        ];
        $rows->setCollection($rows->getCollection()->map(static function (object $row) use ($usersByIdentity, $contextLabels): object {
            $identity = trim((string) ($row->usuario_id ?? ''));
            $row->usuario_nombre = $identity !== '' ? ($usersByIdentity[$identity] ?? 'Usuario no encontrado') : 'Sistema';
            $decoded = json_decode((string) ($row->contexto ?? ''), true);
            $row->contexto_items = [];
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (!array_key_exists((string) $key, $contextLabels) || $value === '' || $value === null) {
                        continue;
                    }
                    $row->contexto_items[] = [
                        'label' => $contextLabels[(string) $key] ?? ucfirst(str_replace('_', ' ', (string) $key)),
                        'value' => is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '-'),
                    ];
                }
            }

            return $row;
        }));

        return view('nova.module-log', [
            'module' => $module,
            'moduleName' => $module === 'telegram' ? 'Telegram' : 'EMACH',
            'rows' => $rows,
        ]);
    }
}
