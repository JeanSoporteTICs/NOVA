<?php

namespace App\Modulos\RedmineMantencion\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\Nova\Repositories\UserIntegrationRepository;
use App\Modulos\Nova\Services\ProjectAccessGuard;
use App\Modulos\Nova\Support\SecretValue;
use App\Modulos\RedmineMantencion\Repositories\MantencionActivityRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionAdministrationRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionCatalogRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use App\Modulos\RedmineMantencion\Services\MantencionAccessService;
use App\Modulos\RedmineMantencion\Services\MantencionNextcloudProvisioningService;
use App\Modulos\RedmineMantencion\Services\MantencionRedmineUserSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class MantencionAdministrationController extends Controller
{
    public function __construct(
        private readonly MantencionAccessService $access,
        private readonly MantencionAdministrationRepository $admin,
        private readonly MantencionConfigRepository $config,
        private readonly MantencionCatalogRepository $catalogs,
        private readonly MantencionActivityRepository $activity,
        private readonly UserIntegrationRepository $integrations,
        private readonly MantencionRedmineUserSyncService $userSync,
        private readonly MantencionNextcloudProvisioningService $nextcloud,
    ) {}

    public function users(Request $request, ProjectAccessGuard $guard): View|RedirectResponse
    {
        $context = $this->context($request, $guard);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->access->can($context, 'usuarios'), 403);

        return view('redmine_mantencion::native.users', $this->base($context, 'usuarios', 'Usuarios · Redmine Mantención') + ['users' => $this->admin->users(), 'roles' => $this->admin->roles()]);
    }

    public function usersAction(Request $request, ProjectAccessGuard $guard): RedirectResponse
    {
        $context = $this->context($request, $guard);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->access->can($context, 'usuarios'), 403);
        if (! empty($context['maintenance']['enabled'])) {
            return $this->back('La plataforma está en mantención.', 'warning');
        }
        $action = trim((string) $request->input('action'));

        if ($action === 'sync_remote') {
            $credential = $this->integrations->credentialForUserId((int) ($context['central_user_id'] ?? 0));
            $result = $this->userSync->sync((string) $credential['secret']);
            if ($result['ok']) {
                $this->log('USERS_SYNC', 'Sincronización Redmine: '.$result['created'].' creados, '.$result['updated'].' actualizados.', $context);
            }

            return $this->back($result['ok'] ? 'Sincronización completada: '.$result['created'].' creados y '.$result['updated'].' actualizados.' : $result['error'], $result['ok'] ? 'success' : 'danger');
        }
        $userId = (int) $request->input('user_id');
        abort_if($userId <= 0, 422);
        if ($action === 'delete') {
            abort_if($userId === (int) ($context['central_user_id'] ?? 0), 422, 'No puedes quitar tu propio acceso.');
            $ok = $this->admin->revokeUser($userId);
            if ($ok) {
                $this->log('USER_REVOKE', 'Acceso Mantención retirado al usuario '.$userId.'.', $context);
            }

            return $this->back($ok ? 'Acceso al módulo retirado.' : 'No se pudo retirar el acceso.', $ok ? 'success' : 'danger');
        }
        abort_unless($action === 'update', 422);
        $ok = $this->admin->updateUser($userId, $request->only(['nombre', 'apellido', 'rut', 'usuario_core', 'estado', 'rol_modulo']));
        if ($ok) {
            $this->log('USER_UPDATE', 'Usuario '.$userId.' actualizado.', $context);
        }

        return $this->back($ok ? 'Usuario actualizado.' : 'No se pudo actualizar el usuario.', $ok ? 'success' : 'danger');
    }

    public function config(Request $request, ProjectAccessGuard $guard): View|RedirectResponse
    {
        $context = $this->context($request, $guard);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->canConfigure($context), 403);

        return view('redmine_mantencion::native.config', $this->base($context, 'configuracion', 'Configuración · Redmine Mantención') + [
            'config' => $this->config->loadAll() ?? [], 'categories' => $this->catalogs->categorias(), 'units' => $this->catalogs->unidades(),
            'roles' => $this->admin->roles(), 'permissionKeys' => $this->admin->permissionKeys(), 'rolePermissions' => $this->admin->rolePermissions(),
        ]);
    }

    public function configAction(Request $request, ProjectAccessGuard $guard): RedirectResponse
    {
        $context = $this->context($request, $guard);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        if (! empty($context['maintenance']['enabled']) && $request->input('action') !== 'maintenance_settings') {
            return $this->back('La plataforma está en mantención.', 'warning');
        }
        $action = trim((string) $request->input('action'));
        if ($action === 'save_nextcloud_secret') {
            abort_unless($this->access->can($context, 'cfg_conexion'), 403);
            $secret = (string) $request->input('nextcloud_admin_pass');
            if (trim($secret) === '') {
                return $this->back('Ingresa una contraseña administrativa de Nextcloud.', 'warning');
            }
            $current = $this->config->loadAll() ?? [];
            $current['nextcloud_admin_pass_enc'] = SecretValue::encryptSecret($secret);
            $current['nextcloud_admin_pass'] = null;
            $this->config->saveAll($current);
            $this->log('NEXTCLOUD_CONFIG', 'Credencial administrativa Nextcloud actualizada.', $context);

            return $this->back('Credencial administrativa Nextcloud guardada.', 'success');
        }
        if ($action === 'save_settings' || $action === 'maintenance_settings') {
            abort_unless($this->access->can($context, 'configuracion'), 403);
            $current = $this->config->loadAll() ?? [];
            $keys = ['platform_url', 'project_id', 'project_name', 'users_members_url', 'retencion_horas', 'hora_extra_tiempo_estimado', 'core_enabled', 'core_admin_url', 'core_historico_url', 'nextcloud_url', 'nextcloud_admin_user', 'maintenance_mode', 'maintenance_until'];
            foreach ($keys as $key) {
                if ($request->has($key)) {
                    $current[$key] = in_array($key, ['core_enabled', 'maintenance_mode'], true) ? $request->boolean($key) : trim((string) $request->input($key));
                }
            }
            $this->config->saveAll($current);
            $this->log('CONFIG_UPDATE', 'Configuración Mantención actualizada.', $context);

            return $this->back('Configuración guardada.', 'success');
        }
        if ($action === 'save_role_permissions') {
            abort_unless($this->access->can($context, 'cfg_roles'), 403);
            $this->admin->saveRolePermissions((string) $request->input('role'), (array) $request->input('permissions', []));
            $this->log('ROLE_PERMISSIONS', 'Permisos de rol actualizados.', $context);

            return $this->back('Permisos del rol guardados.', 'success');
        }
        if (in_array($action, ['catalog_save', 'catalog_delete'], true)) {
            $catalog = trim((string) $request->input('catalog'));
            abort_unless(in_array($catalog, ['categoria', 'unidad'], true), 422);
            abort_unless($this->access->can($context, $catalog === 'categoria' ? 'cfg_categorias' : 'cfg_unidades'), 403);
            $id = trim((string) $request->input('catalog_id'));
            if ($id === '') {
                return $this->back('El ID del catálogo es obligatorio.', 'warning');
            }
            if ($action === 'catalog_delete') {
                $catalog === 'categoria' ? $this->catalogs->deactivateCategoria($id) : $this->catalogs->deactivateUnidad($id);
                $this->log('CATALOG_DELETE', ucfirst($catalog).' '.$id.' desactivada.', $context);

                return $this->back(ucfirst($catalog).' desactivada.', 'success');
            }
            $name = trim((string) $request->input('name'));
            if ($name === '') {
                return $this->back('El nombre del catálogo es obligatorio.', 'warning');
            }
            $catalog === 'categoria'
                ? $this->catalogs->upsertCategorias([['id' => $id, 'nombre' => $name]])
                : $this->catalogs->upsertUnidades([['id' => $id, 'nombre' => $name]]);
            $this->log('CATALOG_UPDATE', ucfirst($catalog).' '.$id.' guardada.', $context);

            return $this->back(ucfirst($catalog).' guardada.', 'success');
        }
        $type = trim((string) $request->input('type'));
        abort_unless(in_array($type, ['tracker', 'prioridad', 'estado'], true), 422);
        abort_unless($this->access->can($context, 'cfg_'.match ($type) {
            'tracker' => 'trackers','prioridad' => 'prioridades','estado' => 'estados'
        }), 403);
        $ok = match ($action) {
            'option_create' => $this->config->createOption($type, (string) $request->input('external_id'), (string) $request->input('name'), $request->boolean('default')),
            'option_update' => $this->config->updateOption($type, (string) $request->input('original_id'), (string) $request->input('external_id'), (string) $request->input('name'), $request->boolean('default')),
            'option_delete' => $this->config->deleteOption($type, (string) $request->input('external_id')),
            default => false,
        };

        return $this->back($ok ? 'Opción actualizada.' : 'No se pudo actualizar la opción.', $ok ? 'success' : 'danger');
    }

    public function nextcloudHistory(Request $request, ProjectAccessGuard $guard): View|RedirectResponse
    {
        $context = $this->context($request, $guard);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->access->can($context, 'integraciones_nextcloud'), 403);

        return view('redmine_mantencion::native.nextcloud-history', $this->base($context, 'nextcloud-history', 'Historial Nextcloud · Redmine Mantención') + ['batches' => $this->admin->nextcloudHistory()]);
    }

    public function nextcloudGroups(Request $request, ProjectAccessGuard $guard): View|RedirectResponse
    {
        $context = $this->context($request, $guard);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->access->can($context, 'integraciones_nextcloud'), 403);

        return view('redmine_mantencion::native.nextcloud-groups', $this->base($context, 'nextcloud-groups', 'Grupos Nextcloud · Redmine Mantención') + [
            'preview' => session('nextcloud_preview'),
            'result' => session('nextcloud_result'),
        ]);
    }

    public function nextcloudGroupsPreview(Request $request, ProjectAccessGuard $guard): RedirectResponse
    {
        $context = $this->context($request, $guard);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->access->can($context, 'integraciones_nextcloud'), 403);
        if (! empty($context['maintenance']['enabled'])) {
            return $this->back('La plataforma está en mantención.', 'warning');
        }
        $request->validate(['archivo' => ['required', 'file', 'max:5120', 'mimes:csv,xlsx'], 'grupo' => ['nullable', 'string', 'max:255']]);
        $result = $this->nextcloud->preview($request->file('archivo'), (string) $request->input('grupo'));
        if (! $result['ok']) {
            return $this->back($result['error'], 'danger');
        }

        return redirect()->route('redmine.mantencion.nextcloud.groups')
            ->with('nextcloud_preview', ['rows' => $result['rows'], 'groups' => $result['groups']]);
    }

    public function nextcloudGroupsConfirm(Request $request, ProjectAccessGuard $guard): RedirectResponse
    {
        $context = $this->context($request, $guard);
        if ($context instanceof RedirectResponse) {
            return $context;
        }
        abort_unless($this->access->can($context, 'integraciones_nextcloud'), 403);
        if (! empty($context['maintenance']['enabled'])) {
            return $this->back('La plataforma está en mantención.', 'warning');
        }
        $result = $this->nextcloud->confirmImport((array) $request->input('users', []));
        $message = $result['total'] > 0
            ? 'Nextcloud: '.$result['created'].' creados, '.$result['existing'].' existentes y '.$result['failed'].' fallidos.'
            : $result['error'];
        $this->log($result['ok'] ? 'NEXTCLOUD_IMPORT' : 'NEXTCLOUD_IMPORT_FAIL', $message, $context);

        return redirect()->route('redmine.mantencion.nextcloud.groups')
            ->with('mantencion_status', $message)
            ->with('mantencion_status_type', $result['ok'] ? 'success' : ($result['total'] > 0 ? 'warning' : 'danger'))
            ->with('nextcloud_result', $result);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function base(array $context, string $section, string $title): array
    {
        return ['context' => $context, 'permissions' => $context['permissions'], 'activeSection' => $section, 'pageTitle' => $title];
    }

    /** @param array<string,mixed> $context */
    private function log(string $tag, string $detail, array $context): void
    {
        $this->activity->record($tag, $detail, (string) $context['viewer_name'], (string) $context['viewer_id']);
    }

    private function back(string $message, string $type): RedirectResponse
    {
        return back()->with('mantencion_status', $message)->with('mantencion_status_type', $type);
    }

    /** @param array<string,mixed> $context */
    private function canConfigure(array $context): bool
    {
        foreach (['configuracion', 'categorias', 'cfg_categorias', 'cfg_unidades', 'cfg_trackers', 'cfg_prioridades', 'cfg_estados', 'cfg_roles', 'cfg_conexion'] as $permission) {
            if ($this->access->can($context, $permission)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed>|RedirectResponse */
    private function context(Request $request, ProjectAccessGuard $guard): array|RedirectResponse
    {
        return $this->access->context($request) ?? redirect()->route('home')->with('access_error', $guard->deniedMessage('Redmine Mantención'));
    }
}
