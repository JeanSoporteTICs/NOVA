<?php

namespace App\Modulos\RedmineMantencion\ExternalClients;

use Illuminate\Support\Facades\Http;

final class NextcloudOcsClient
{
    /**
     * @param  array{url:string,admin_user:string,admin_pass:string}  $config
     * @param  array<string,mixed>  $payload
     * @return array{ok:bool,http:int,statuscode:int,message:string,data:mixed}
     */
    public function request(array $config, string $method, string $path, array $payload = []): array
    {
        $path = '/'.ltrim($path, '/');
        $url = rtrim($config['url'], '/').'/ocs/v2.php/apps/files_sharing/api/v1'.$path;
        $url .= str_contains($url, '?') ? '&format=json' : '?format=json';

        try {
            $request = Http::withBasicAuth($config['admin_user'], $config['admin_pass'])
                ->withHeaders(['OCS-APIRequest' => 'true'])
                ->acceptJson()
                ->asForm()
                ->timeout(15);
            $response = $request->send(strtoupper($method), $url, $payload === [] ? [] : ['form_params' => $payload]);
            $json = $response->json();
            $meta = is_array($json) ? data_get($json, 'ocs.meta', []) : [];
            $statusCode = (int) data_get($meta, 'statuscode', 0);
            $message = trim((string) data_get($meta, 'message', ''));

            return [
                'ok' => $response->status() < 400 && $statusCode === 100,
                'http' => $response->status(),
                'statuscode' => $statusCode,
                'message' => $message !== '' ? $message : ($response->failed() ? 'HTTP '.$response->status() : ''),
                'data' => is_array($json) ? data_get($json, 'ocs.data') : null,
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'http' => 0,
                'statuscode' => 0,
                'message' => 'No fue posible conectar con Nextcloud: '.$exception->getMessage(),
                'data' => null,
            ];
        }
    }
}
