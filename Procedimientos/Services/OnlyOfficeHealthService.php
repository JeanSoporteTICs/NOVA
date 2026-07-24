<?php

namespace App\Modulos\Procedimientos\Services;

use App\Modulos\Nova\Repositories\NovaSettingsRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class OnlyOfficeHealthService
{
    /** @return array{status:string,label:string,detail:string,http:int} */
    public function check(bool $force = false): array
    {
        if (!$force) {
            try {
                $cached = Cache::get('nova.onlyoffice.health');
            } catch (\Throwable) {
                $cached = null;
            }
            if (is_array($cached)) {
                return $cached;
            }
        }

        $config = app(NovaSettingsRepository::class)->onlyOffice();
        if (!$config['enabled']) {
            return $this->remember(['status' => 'disabled', 'label' => 'Desactivado', 'detail' => 'OnlyOffice esta desactivado desde Administracion.', 'http' => 0]);
        }

        if (!$config['configured']) {
            return $this->remember(['status' => 'pending', 'label' => 'Sin configurar', 'detail' => 'Falta URL o secreto JWT.', 'http' => 0]);
        }

        try {
            $response = Http::withOptions(['proxy' => ''])->connectTimeout(3)->timeout(6)->get(rtrim($config['url'], '/') . '/healthcheck');
            $healthy = $response->successful() && in_array(strtolower(trim($response->body())), ['true', 'ok', 'healthy'], true);

            return $this->remember([
                'status' => $healthy ? 'online' : 'error',
                'label' => $healthy ? 'Servidor disponible' : 'Respuesta no valida',
                'detail' => $healthy ? 'OnlyOffice respondio correctamente.' : 'El healthcheck no confirmo disponibilidad.',
                'http' => $response->status(),
            ]);
        } catch (\Throwable $exception) {
            return $this->remember(['status' => 'error', 'label' => 'Sin conexion', 'detail' => 'No se pudo contactar el servidor OnlyOffice.', 'http' => 0]);
        }
    }

    /** @return array{status:string,label:string,detail:string,http:int} */
    public function checkWithJwt(): array
    {
        $health = $this->check(true);
        if ($health['status'] !== 'online') {
            return $health;
        }

        $config = app(NovaSettingsRepository::class)->onlyOffice();
        $command = ['c' => 'version'];
        $token = app(OnlyOfficeJwt::class)->encode($command, $config['secret']);

        try {
            $response = $this->sendSignedCommand(rtrim($config['url'], '/') . '/command', $token);
            if (in_array($response->status(), [404, 405], true)) {
                $response = $this->sendSignedCommand(rtrim($config['url'], '/') . '/coauthoring/CommandService.ashx', $token);
            }

            $body = $response->json();
            $error = is_array($body) ? (int) ($body['error'] ?? -1) : -1;
            if ($response->successful() && $error === 0) {
                $version = trim((string) ($body['version'] ?? ''));

                return [
                    'status' => 'online',
                    'label' => 'Servidor y clave JWT validos',
                    'detail' => $version !== '' ? 'OnlyOffice acepto la firma JWT. Version ' . $version . '.' : 'OnlyOffice acepto la firma JWT.',
                    'http' => $response->status(),
                ];
            }

            return [
                'status' => 'error',
                'label' => $error === 6 ? 'Clave JWT rechazada' : 'No se pudo validar la clave JWT',
                'detail' => $error === 6 ? 'El secreto guardado no coincide con el configurado en OnlyOffice.' : 'El servicio de comandos respondio con error ' . $error . '.',
                'http' => $response->status(),
            ];
        } catch (\Throwable) {
            return ['status' => 'error', 'label' => 'Sin conexion', 'detail' => 'No se pudo validar la clave contra el servicio de comandos de OnlyOffice.', 'http' => 0];
        }
    }

    private function sendSignedCommand(string $url, string $token): \Illuminate\Http\Client\Response
    {
        return Http::withOptions(['proxy' => ''])->connectTimeout(3)->timeout(8)->acceptJson()->asJson()->post($url, ['token' => $token]);
    }

    private function remember(array $result): array
    {
        try {
            Cache::put('nova.onlyoffice.health', $result, now()->addMinute());
        } catch (\Throwable) {
            // La salud del servidor sigue siendo util aunque el cache runtime
            // no tenga permisos o no este disponible temporalmente.
        }

        return $result;
    }
}
