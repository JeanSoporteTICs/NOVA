<?php

namespace App\Modulos\Procedimientos\Services;

use App\Modulos\Nova\Repositories\UserIntegrationRepository;
use App\Modulos\RedmineMantencion\ExternalClients\NextcloudOcsClient;
use App\Modulos\RedmineMantencion\ExternalClients\NextcloudWebdavClient;
use App\Modulos\RedmineMantencion\Repositories\MantencionActivityRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class NextcloudBrowserService
{
    public function __construct(
        private readonly UserIntegrationRepository $integrations,
        private readonly MantencionConfigRepository $configuration,
        private readonly NextcloudWebdavClient $webdav,
        private readonly NextcloudOcsClient $ocs,
        private readonly MantencionActivityRepository $activity,
    ) {}

    public function response(Request $request): JsonResponse|Response
    {
        $sessionUser = (array) $request->session()->get('nova_user', []);
        $config = $this->personalConfig($sessionUser);
        if ($config === null) {
            return response()->json([
                'ok' => false,
                'error' => 'sin_credenciales',
                'message' => 'Debe configurar sus credenciales de Nextcloud antes de usar Procedimientos.',
            ], 403);
        }

        $action = trim((string) $request->input('action', ''));
        $userKey = trim((string) ($sessionUser['id'] ?? $sessionUser['username'] ?? 'anonymous'));

        if ($action === 'download') {
            return $this->download($config, (string) $request->input('path', ''));
        }

        if ($request->isMethod('get')) {
            return $this->readResponse($request, $config, $userKey, $action);
        }

        return $this->writeResponse($request, $config, $userKey, $action, $sessionUser);
    }

    /** @param array<string,mixed> $sessionUser */
    private function personalConfig(array $sessionUser): ?array
    {
        $credential = $this->integrations->credentialForSession($sessionUser, 'nextcloud');
        $settings = $this->configuration->loadAll() ?? [];
        $url = rtrim(trim((string) ($settings['nextcloud_url'] ?? '')), '/');

        if (empty($credential['stored']) || $url === '') {
            return null;
        }

        return ['url' => $url, 'admin_user' => $credential['user'], 'admin_pass' => $credential['secret']];
    }

    /** @param array{url:string,admin_user:string,admin_pass:string} $config */
    private function readResponse(Request $request, array $config, string $userKey, string $action): JsonResponse
    {
        if ($action === 'list') {
            $path = $this->webdav->pathSafe((string) $request->input('path', '/'));
            $result = $this->cached($userKey, 'list', $path, $request->boolean('refresh'), fn (): array => $this->webdav->listDirectory($config, $path));

            return response()->json($result, ! empty($result['ok']) ? 200 : 502);
        }
        if ($action === 'shares_with_me') {
            $result = $this->cached($userKey, 'shares', '', $request->boolean('refresh'), fn (): array => $this->sharesWithMe($config));

            return response()->json($result, ! empty($result['ok']) ? 200 : 502);
        }
        if ($action === 'share_users') {
            return response()->json(['ok' => true, 'users' => $this->shareUsers($this->centralUserId((array) $request->session()->get('nova_user', [])))]);
        }

        return response()->json(['ok' => false, 'error' => 'Acción no reconocida.'], 400);
    }

    /**
     * @param  array{url:string,admin_user:string,admin_pass:string}  $config
     * @param  array<string,mixed>  $sessionUser
     */
    private function writeResponse(Request $request, array $config, string $userKey, string $action, array $sessionUser): JsonResponse
    {
        $result = match ($action) {
            'mkdir' => $this->makeDirectory($config, (string) $request->input('path', '/'), (string) $request->input('name', '')),
            'rename' => $this->rename($config, (string) $request->input('path', ''), (string) $request->input('name', '')),
            'transfer' => $this->transfer($config, (string) $request->input('path', ''), (string) $request->input('destination_dir', '/'), (string) $request->input('operation', 'move')),
            'delete' => $this->delete($config, (string) $request->input('path', '')),
            'upload' => $this->upload($request, $config),
            'share_user' => $this->shareUser($config, (string) $request->input('path', ''), (string) $request->input('share_with', '')),
            'share_delete' => $this->deleteShare($config, (string) $request->input('share_id', '')),
            'share_link' => ['ok' => false, 'error' => 'Los enlaces públicos están deshabilitados. Comparta solo con usuarios Nextcloud registrados.', 'status' => 410],
            default => ['ok' => false, 'error' => 'Acción no reconocida.', 'status' => 400],
        };

        if (! empty($result['ok'])) {
            $this->invalidate($userKey);
        }
        $this->audit($action, $result, $sessionUser, $request);
        $status = (int) ($result['status'] ?? (! empty($result['ok']) ? 200 : 422));
        unset($result['status']);

        return response()->json($result, $status);
    }

    /** @param array{url:string,admin_user:string,admin_pass:string} $config */
    private function download(array $config, string $rawPath): Response
    {
        $path = $this->webdav->pathSafe($rawPath);
        if ($path === '/') {
            return response('Ruta inválida.', 400);
        }
        $result = $this->webdav->request($config, 'GET', $path);
        if (! $result['ok']) {
            return response('No se pudo obtener el archivo desde Nextcloud.', 502);
        }
        $fileName = str_replace('"', '', basename($path));
        $mime = 'application/octet-stream';
        if (preg_match('/(?:^|\r?\n)Content-Type:\s*([^\r\n;]+)/i', $result['headers'], $match)) {
            $mime = trim($match[1]);
        }
        $inline = in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'], true);

        return response($result['body'], 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$fileName.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safeName(string $name): string
    {
        $name = str_replace(["\0", '/', '\\'], '-', trim($name));
        $name = preg_replace('/[<>:"|?*\x00-\x1F]+/u', '-', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name, " .-\t\n\r\0\x0B");
    }

    /** @param array{url:string,admin_user:string,admin_pass:string} $config */
    private function makeDirectory(array $config, string $directory, string $name): array
    {
        $name = $this->safeName($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'El nombre de la carpeta es obligatorio.'];
        }
        $path = rtrim($this->webdav->pathSafe($directory), '/').'/'.$name;
        $current = '';
        foreach (array_filter(explode('/', trim($path, '/'))) as $part) {
            $current .= '/'.$part;
            $result = $this->webdav->request($config, 'MKCOL', $current);
            if (! $result['ok'] && ! in_array($result['http'], [405, 409], true)) {
                return ['ok' => false, 'error' => $result['message'] ?: 'No se pudo crear la carpeta.'];
            }
        }

        return ['ok' => true, 'path' => $path];
    }

    /** @param array{url:string,admin_user:string,admin_pass:string} $config */
    private function rename(array $config, string $rawPath, string $rawName): array
    {
        $path = $this->webdav->pathSafe($rawPath);
        $name = $this->safeName($rawName);
        if ($path === '/' || $name === '') {
            return ['ok' => false, 'error' => 'Parámetros inválidos.'];
        }

        return $this->moveOrCopy($config, $path, rtrim(dirname($path), '/').'/'.$name, 'MOVE');
    }

    /** @param array{url:string,admin_user:string,admin_pass:string} $config */
    private function transfer(array $config, string $rawPath, string $rawDestination, string $rawOperation): array
    {
        $path = $this->webdav->pathSafe($rawPath);
        $destination = $this->webdav->pathSafe($rawDestination);
        $operation = strtolower(trim($rawOperation)) === 'copy' ? 'COPY' : 'MOVE';
        if ($path === '/' || ($operation === 'MOVE' && str_starts_with(rtrim($destination, '/').'/', rtrim($path, '/').'/'))) {
            return ['ok' => false, 'error' => 'Destino inválido.'];
        }

        return $this->moveOrCopy($config, $path, rtrim($destination, '/').'/'.basename($path), $operation);
    }

    /** @param array{url:string,admin_user:string,admin_pass:string} $config */
    private function moveOrCopy(array $config, string $source, string $destination, string $method): array
    {
        $destinationUrl = $this->webdav->baseUrl($config).implode('/', array_map('rawurlencode', explode('/', '/'.ltrim($destination, '/'))));
        $result = $this->webdav->request($config, $method, $source, null, ['Destination: '.$destinationUrl, 'Overwrite: T']);

        return $result['ok']
            ? ['ok' => true, 'path' => $destination, 'operation' => strtolower($method)]
            : ['ok' => false, 'error' => $result['message'] ?: 'No se pudo completar la operación.'];
    }

    /** @param array{url:string,admin_user:string,admin_pass:string} $config */
    private function delete(array $config, string $rawPath): array
    {
        $path = $this->webdav->pathSafe($rawPath);
        if ($path === '/') {
            return ['ok' => false, 'error' => 'No se puede eliminar la raíz.'];
        }
        $result = $this->webdav->request($config, 'DELETE', $path);

        return $result['ok'] ? ['ok' => true] : ['ok' => false, 'error' => $result['message'] ?: 'No se pudo eliminar.'];
    }

    /** @param array{url:string,admin_user:string,admin_pass:string} $config */
    private function upload(Request $request, array $config): array
    {
        $file = $request->file('file');
        if ($file === null || ! $file->isValid() || $file->getSize() > 100 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'No se recibió un archivo válido de hasta 100 MB.'];
        }
        $name = $this->safeName($file->getClientOriginalName());
        $binary = $file->getContent();
        $path = rtrim($this->webdav->pathSafe((string) $request->input('path', '/')), '/').'/'.$name;
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $result = $this->webdav->request($config, 'PUT', $path, $binary, ['Content-Type: '.$mime]);

        return $result['ok']
            ? ['ok' => true, 'path' => $path, 'name' => $name, 'size' => strlen($binary), 'mime' => $mime]
            : ['ok' => false, 'error' => $result['message'] ?: 'No se pudo subir el archivo.'];
    }

    /** @param array{url:string,admin_user:string,admin_pass:string} $config */
    private function shareUser(array $config, string $rawPath, string $shareWith): array
    {
        $path = $this->webdav->pathSafe($rawPath);
        $shareWith = trim($shareWith);
        if ($path === '/' || $shareWith === '') {
            return ['ok' => false, 'error' => 'Parámetros inválidos.'];
        }
        $result = $this->ocs->request($config, 'POST', '/shares', [
            'path' => $path,
            'shareType' => 0,
            'shareWith' => $shareWith,
            'permissions' => 17,
        ]);

        return $result['ok'] ? ['ok' => true, 'data' => $result['data']] : ['ok' => false, 'error' => $result['message'] ?: 'No se pudo compartir con el usuario.'];
    }

    /** @param array{url:string,admin_user:string,admin_pass:string} $config */
    private function deleteShare(array $config, string $shareId): array
    {
        if (trim($shareId) === '') {
            return ['ok' => false, 'error' => 'Identificador de compartición inválido.'];
        }
        $result = $this->ocs->request($config, 'DELETE', '/shares/'.rawurlencode($shareId));

        return $result['ok'] ? ['ok' => true] : ['ok' => false, 'error' => $result['message'] ?: 'No se pudo eliminar la compartición.'];
    }

    /** @param array{url:string,admin_user:string,admin_pass:string} $config */
    private function sharesWithMe(array $config): array
    {
        $result = $this->ocs->request($config, 'GET', '/shares?shared_with_me=true');
        if (! $result['ok']) {
            return ['ok' => false, 'error' => $result['message'] ?: 'No se pudieron consultar los archivos compartidos.'];
        }
        $shares = collect(is_array($result['data']) ? $result['data'] : [])->filter('is_array')->map(static fn (array $share): array => [
            'id' => (string) ($share['id'] ?? ''),
            'path' => (string) ($share['path'] ?? ''),
            'name' => basename((string) ($share['file_target'] ?? $share['path'] ?? '')),
            'uid_owner' => (string) ($share['uid_owner'] ?? ''),
            'displayname_owner' => (string) ($share['displayname_owner'] ?? ''),
            'item_type' => (string) ($share['item_type'] ?? 'file'),
            'size' => (int) ($share['size'] ?? 0),
        ])->values()->all();

        return ['ok' => true, 'shares' => $shares];
    }

    /** @return array<int,array{user:string,label:string}> */
    private function shareUsers(?int $currentUserId): array
    {
        if (! Schema::hasTable('usuarios_nova') || ! Schema::hasTable('integraciones_usuario')) {
            return [];
        }

        return DB::table('integraciones_usuario')
            ->join('usuarios_nova', 'usuarios_nova.id', '=', 'integraciones_usuario.usuario_id')
            ->where('integraciones_usuario.tipo', 'nextcloud')
            ->where('usuarios_nova.estado', 'activo')
            ->whereNotNull('integraciones_usuario.usuario_externo')
            ->where('integraciones_usuario.usuario_externo', '<>', '')
            ->when($currentUserId !== null, fn ($query) => $query->where('usuarios_nova.id', '<>', $currentUserId))
            ->orderBy('usuarios_nova.nombre')->orderBy('usuarios_nova.apellido')
            ->get(['integraciones_usuario.usuario_externo', 'usuarios_nova.nombre', 'usuarios_nova.apellido', 'usuarios_nova.usuario'])
            ->map(static function (object $row): array {
                $name = trim((string) $row->nombre.' '.(string) $row->apellido);

                return ['user' => (string) $row->usuario_externo, 'label' => $name !== '' ? $name : (string) $row->usuario];
            })->values()->all();
    }

    /** @param array<string,mixed> $sessionUser */
    private function centralUserId(array $sessionUser): ?int
    {
        if (! Schema::hasTable('usuarios_nova')) {
            return null;
        }
        foreach (['uuid' => $sessionUser['id'] ?? '', 'usuario' => $sessionUser['username'] ?? '', 'rut' => $sessionUser['rut'] ?? ''] as $column => $value) {
            if (trim((string) $value) !== '' && ($id = DB::table('usuarios_nova')->where($column, $value)->value('id')) !== null) {
                return (int) $id;
            }
        }

        return null;
    }

    private function cached(string $userKey, string $bucket, string $path, bool $refresh, callable $loader): array
    {
        $version = (int) Cache::get($this->cachePrefix($userKey).':version', 1);
        $key = $this->cachePrefix($userKey).':'.$version.':'.$bucket.':'.sha1($path);
        if (! $refresh && is_array($cached = Cache::get($key))) {
            return [...$cached, 'cached' => true, 'elapsed_ms' => 0];
        }
        $started = microtime(true);
        $result = $loader();
        $result['cached'] = false;
        $result['elapsed_ms'] = (int) round((microtime(true) - $started) * 1000);
        if (! empty($result['ok'])) {
            Cache::put($key, $result, 600);
        }

        return $result;
    }

    private function invalidate(string $userKey): void
    {
        $key = $this->cachePrefix($userKey).':version';
        Cache::forever($key, max(1, (int) Cache::get($key, 1)) + 1);
    }

    private function cachePrefix(string $userKey): string
    {
        return 'nova:procedimientos:nextcloud:'.sha1($userKey !== '' ? $userKey : 'anonymous');
    }

    /** @param array<string,mixed> $result @param array<string,mixed> $sessionUser */
    private function audit(string $action, array $result, array $sessionUser, Request $request): void
    {
        $tag = 'NEXTCLOUD_'.strtoupper(match ($action) {
            'mkdir' => 'MKDIR', 'rename' => 'RENAME', 'transfer' => strtoupper((string) $request->input('operation', 'move')),
            'delete' => 'DELETE', 'upload' => 'UPLOAD', 'share_user' => 'SHARE_USER', 'share_delete' => 'SHARE_DELETE',
            default => 'ACTION',
        });
        $name = trim((string) ($sessionUser['name'] ?? $sessionUser['username'] ?? 'Usuario NOVA'));
        $id = trim((string) ($sessionUser['id'] ?? $sessionUser['username'] ?? ''));
        $details = (! empty($result['ok']) ? 'OK' : 'FAIL').' | acción '.$action.' | path '.trim((string) $request->input('path', ''));
        $this->activity->record($tag, $details, $name, $id);
    }
}
