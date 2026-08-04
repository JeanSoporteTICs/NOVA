<?php

namespace App\Modulos\Procedimientos\Controllers;

use App\Http\Controllers\Controller;
use App\Modulos\Nova\Repositories\NovaAccessRepository;
use App\Modulos\Nova\Repositories\NovaSettingsRepository;
use App\Modulos\Nova\Repositories\UserIntegrationRepository;
use App\Modulos\Procedimientos\Services\NextcloudBrowserService;
use App\Modulos\Procedimientos\Services\OnlyOfficeHealthService;
use App\Modulos\Procedimientos\Services\OnlyOfficeJwt;
use App\Modulos\RedmineMantencion\ExternalClients\NextcloudWebdavClient;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

final class ProcedimientosController extends Controller
{
    public function index(Request $request, NovaAccessRepository $access, UserIntegrationRepository $integrations, OnlyOfficeHealthService $onlyOfficeHealth): View
    {
        $this->authorizeModule($request, $access);
        $credential = $integrations->credentialForSession((array) $request->session()->get('nova_user', []), 'nextcloud');

        return view('procedimientos.index', [
            'nextcloudConfigured' => $credential['stored'],
            'onlyOfficeHealth' => $onlyOfficeHealth->check(),
        ]);
    }

    public function browser(Request $request, NovaAccessRepository $access, NextcloudBrowserService $browser): JsonResponse|Response
    {
        $this->authorizeModule($request, $access);

        return $browser->response($request);
    }

    public function editor(Request $request, NovaAccessRepository $access, NovaSettingsRepository $settings, UserIntegrationRepository $integrations, MantencionConfigRepository $mantencion, OnlyOfficeJwt $jwt): View|RedirectResponse
    {
        $this->authorizeModule($request, $access);
        $path = $this->safePath((string) $request->query('path', ''));
        if ($path === '/' || ! $this->editable($path)) {
            return redirect()->route('procedimientos.index')->with('error', 'El formato seleccionado no admite edicion en linea.');
        }

        $office = $settings->onlyOffice();
        if (! $office['enabled']) {
            return redirect()->route('procedimientos.index')->with('error', 'OnlyOffice esta desactivado temporalmente.');
        }
        $credential = $integrations->credentialForSession((array) $request->session()->get('nova_user', []), 'nextcloud');
        $nextcloudUrl = rtrim(trim((string) ($mantencion->loadAll()['nextcloud_url'] ?? '')), '/');
        if (! $office['configured'] || ! $credential['stored'] || $nextcloudUrl === '') {
            return redirect()->route('procedimientos.index')->with('error', 'Configura OnlyOffice global y tus credenciales Nextcloud antes de editar.');
        }

        $sessionToken = bin2hex(random_bytes(24));
        Cache::put('procedimientos.editor.'.$sessionToken, [
            'user' => (array) $request->session()->get('nova_user', []),
            'path' => $path,
            'nextcloud_url' => $nextcloudUrl,
        ], now()->addHours(8));

        $fileName = basename($path);
        $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $config = [
            'document' => [
                'fileType' => $fileType,
                'key' => hash('sha256', $path.'|'.$sessionToken),
                'title' => $fileName,
                'url' => route('procedimientos.document', ['token' => $sessionToken]),
                'permissions' => ['edit' => true, 'download' => true, 'print' => true],
            ],
            'documentType' => $this->documentType($fileType),
            'editorConfig' => [
                'callbackUrl' => route('procedimientos.callback', ['token' => $sessionToken]),
                'mode' => 'edit',
                'lang' => 'es',
                'user' => [
                    'id' => (string) data_get($request->session()->get('nova_user', []), 'id', 'nova'),
                    'name' => (string) data_get($request->session()->get('nova_user', []), 'name', 'Usuario NOVA'),
                ],
            ],
        ];
        $config['token'] = $jwt->encode($config, $office['secret']);

        return view('procedimientos.editor', ['onlyOfficeUrl' => $office['url'], 'editorConfig' => $config, 'fileName' => $fileName]);
    }

    public function document(string $token, UserIntegrationRepository $integrations, NextcloudWebdavClient $client): Response
    {
        $session = $this->editorSession($token);
        $credential = $integrations->credentialForSession((array) $session['user'], 'nextcloud');
        abort_unless($credential['stored'], 404);
        $result = $client->request($this->nextcloudConfig($session, $credential), 'GET', (string) $session['path']);
        abort_unless($result['ok'], 502);

        return response($result['body'], 200, ['Content-Type' => 'application/octet-stream', 'Content-Disposition' => 'inline; filename="'.str_replace('"', '', basename((string) $session['path'])).'"']);
    }

    public function callback(Request $request, string $token, NovaSettingsRepository $settings, UserIntegrationRepository $integrations, NextcloudWebdavClient $client, OnlyOfficeJwt $jwt): JsonResponse
    {
        $office = $settings->onlyOffice();
        $bearer = $request->bearerToken() ?: (string) $request->input('token', '');
        if (! $office['enabled'] || ! $office['configured'] || $jwt->decode($bearer, $office['secret']) === null) {
            return response()->json(['error' => 1], 401);
        }
        $session = $this->editorSession($token);
        $status = (int) $request->input('status', 0);
        if (! in_array($status, [2, 6], true)) {
            return response()->json(['error' => 0]);
        }
        $downloadUrl = trim((string) $request->input('url', ''));
        if (! $this->trustedOnlyOfficeUrl($downloadUrl, $office['url'])) {
            return response()->json(['error' => 1], 422);
        }
        $download = Http::timeout(60)->get($downloadUrl);
        if (! $download->successful()) {
            return response()->json(['error' => 1], 502);
        }
        $credential = $integrations->credentialForSession((array) $session['user'], 'nextcloud');
        if (! $credential['stored']) {
            return response()->json(['error' => 1], 404);
        }
        $saved = $client->request($this->nextcloudConfig($session, $credential), 'PUT', (string) $session['path'], $download->body(), ['Content-Type: application/octet-stream']);

        return response()->json(['error' => $saved['ok'] ? 0 : 1], $saved['ok'] ? 200 : 502);
    }

    private function authorizeModule(Request $request, NovaAccessRepository $access): void
    {
        abort_unless($access->canAccess((array) $request->session()->get('nova_user', []), 'procedimientos'), 403);
    }

    private function editorSession(string $token): array
    {
        $session = Cache::get('procedimientos.editor.'.$token);
        abort_unless(is_array($session) && isset($session['user'], $session['path'], $session['nextcloud_url']), 404);

        return $session;
    }

    private function nextcloudConfig(array $session, array $credential): array
    {
        return ['url' => (string) $session['nextcloud_url'], 'admin_user' => $credential['user'], 'admin_pass' => $credential['secret']];
    }

    private function safePath(string $path): string
    {
        return (new NextcloudWebdavClient)->pathSafe($path);
    }

    private function editable(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv'], true);
    }

    private function documentType(string $extension): string
    {
        return match ($extension) {
            'xls', 'xlsx', 'ods', 'csv' => 'cell',
            'ppt', 'pptx', 'odp' => 'slide',
            default => 'word',
        };
    }

    private function trustedOnlyOfficeUrl(string $candidate, string $configured): bool
    {
        $candidateHost = strtolower((string) parse_url($candidate, PHP_URL_HOST));
        $configuredHost = strtolower((string) parse_url($configured, PHP_URL_HOST));
        $candidatePort = (int) (parse_url($candidate, PHP_URL_PORT) ?: (((string) parse_url($candidate, PHP_URL_SCHEME)) === 'https' ? 443 : 80));
        $configuredPort = (int) (parse_url($configured, PHP_URL_PORT) ?: (((string) parse_url($configured, PHP_URL_SCHEME)) === 'https' ? 443 : 80));

        return $candidateHost !== ''
            && $configuredHost !== ''
            && hash_equals($configuredHost, $candidateHost)
            && $candidatePort === $configuredPort
            && in_array((string) parse_url($candidate, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
