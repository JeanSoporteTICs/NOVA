<?php

namespace App\Modulos\MonitorServidores\Services;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ServerProbeService
{
    /**
     * @return array{ok:bool,latency_ms:int,error:string,http_code:?int}
     */
    public function probe(object $server): array
    {
        $type = strtolower(trim((string) ($server->tipo ?? 'tcp')));

        return match ($type) {
            'icmp' => $this->probeIcmp($server),
            'tcp' => $this->probeTcp($server),
            default => $this->probeHttp($server, $type),
        };
    }

    /**
     * @return array{ok:bool,latency_ms:int,error:string,http_code:?int}
     */
    private function probeIcmp(object $server): array
    {
        $host = trim((string) ($server->host ?? ''));
        $timeout = max(1, min((int) ($server->timeout_segundos ?? 5), 30));
        if (! $this->isValidHost($host)) {
            return [
                'ok' => false,
                'latency_ms' => 0,
                'error' => 'El host ICMP no es válido.',
                'http_code' => null,
            ];
        }

        $command = PHP_OS_FAMILY === 'Windows'
            ? ['ping', '-n', '1', '-w', (string) ($timeout * 1000), $host]
            : ['ping', '-c', '1', '-W', (string) $timeout, $host];
        $started = microtime(true);

        try {
            $process = new Process($command);
            $process->setTimeout($timeout + 2);
            $process->run();
            $elapsed = max(0, (int) round((microtime(true) - $started) * 1000));
            $output = trim($process->getOutput().' '.$process->getErrorOutput());

            if ($process->isSuccessful()) {
                $latency = $this->icmpLatency($output) ?? $elapsed;

                return ['ok' => true, 'latency_ms' => $latency, 'error' => '', 'http_code' => null];
            }

            $detail = trim((string) preg_replace('/\s+/', ' ', $output));

            return [
                'ok' => false,
                'latency_ms' => $elapsed,
                'error' => $detail !== ''
                    ? 'Ping sin respuesta: '.mb_substr($detail, 0, 500)
                    : 'El host no respondió al ping dentro del tiempo configurado.',
                'http_code' => null,
            ];
        } catch (ProcessTimedOutException) {
            return [
                'ok' => false,
                'latency_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
                'error' => 'El ping agotó el tiempo máximo de espera.',
                'http_code' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'latency_ms' => max(0, (int) round((microtime(true) - $started) * 1000)),
                'error' => 'No se pudo ejecutar ping: '.mb_substr(trim($e->getMessage()), 0, 400),
                'http_code' => null,
            ];
        }
    }

    private function icmpLatency(string $output): ?int
    {
        if (preg_match('/(?:time|tiempo|temps|zeit)[=<]\s*([0-9]+(?:[.,][0-9]+)?)\s*ms/i', $output, $matches) !== 1) {
            return null;
        }

        return max(0, (int) round((float) str_replace(',', '.', $matches[1])));
    }

    /**
     * @return array{ok:bool,latency_ms:int,error:string,http_code:?int}
     */
    private function probeTcp(object $server): array
    {
        $host = trim((string) ($server->host ?? ''));
        $port = (int) ($server->puerto ?? 0);
        if (! $this->isValidHost($host) || $port < 1 || $port > 65535) {
            return [
                'ok' => false,
                'latency_ms' => 0,
                'error' => 'El host o puerto TCP no es válido.',
                'http_code' => null,
            ];
        }
        $timeout = max(1, min((int) ($server->timeout_segundos ?? 5), 30));
        $targetHost = str_contains($host, ':') && ! str_starts_with($host, '[') ? '['.$host.']' : $host;
        $started = microtime(true);
        $errno = 0;
        $error = '';
        $socket = @stream_socket_client(
            'tcp://'.$targetHost.':'.$port,
            $errno,
            $error,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        $latency = max(0, (int) round((microtime(true) - $started) * 1000));

        if (is_resource($socket)) {
            fclose($socket);

            return ['ok' => true, 'latency_ms' => $latency, 'error' => '', 'http_code' => null];
        }

        return [
            'ok' => false,
            'latency_ms' => $latency,
            'error' => trim($error) !== '' ? trim($error) : 'No se pudo abrir la conexión TCP (código '.$errno.').',
            'http_code' => null,
        ];
    }

    /**
     * @return array{ok:bool,latency_ms:int,error:string,http_code:?int}
     */
    private function probeHttp(object $server, string $scheme): array
    {
        if (! function_exists('curl_init')) {
            return ['ok' => false, 'latency_ms' => 0, 'error' => 'cURL no está disponible.', 'http_code' => null];
        }

        $host = trim((string) ($server->host ?? ''));
        $host = str_contains($host, ':') && ! str_starts_with($host, '[') ? '['.$host.']' : $host;
        $port = (int) ($server->puerto ?? 0);
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $path = trim((string) ($server->ruta ?? ''));
        $path = $path === '' ? '/' : '/'.ltrim($path, '/');
        $url = $scheme.'://'.$host.($port > 0 && $port !== $defaultPort ? ':'.$port : '').$path;
        $timeout = max(1, min((int) ($server->timeout_segundos ?? 5), 30));
        $started = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => ! empty($server->verificar_ssl),
            CURLOPT_SSL_VERIFYHOST => ! empty($server->verificar_ssl) ? 2 : 0,
            CURLOPT_USERAGENT => 'NOVA server-monitor/1.0',
            CURLOPT_WRITEFUNCTION => static fn ($curl, string $data): int => strlen($data),
        ]);
        $executed = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = trim((string) curl_error($ch));
        $errno = (int) curl_errno($ch);
        curl_close($ch);
        $latency = max(0, (int) round((microtime(true) - $started) * 1000));

        if ($executed !== false && $errno === 0 && $httpCode > 0 && $httpCode < 500) {
            return ['ok' => true, 'latency_ms' => $latency, 'error' => '', 'http_code' => $httpCode];
        }

        if ($error === '') {
            $error = $httpCode >= 500 ? 'El servicio respondió HTTP '.$httpCode.'.' : 'El servicio no entregó una respuesta HTTP.';
        }

        return ['ok' => false, 'latency_ms' => $latency, 'error' => $error, 'http_code' => $httpCode ?: null];
    }

    private function isValidHost(string $host): bool
    {
        if ($host === '' || mb_strlen($host) > 255 || preg_match('/^[A-Za-z0-9._:-]+$/', $host) !== 1) {
            return false;
        }
        if (preg_match('/^[0-9.]+$/', $host) === 1) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }
        if (str_contains($host, ':')) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }

        return true;
    }
}
