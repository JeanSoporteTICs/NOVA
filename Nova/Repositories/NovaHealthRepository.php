<?php

namespace App\Modulos\Nova\Repositories;

use App\Modulos\Nova\Repositories\ModuleRegistry;
use App\Modulos\Telegram\Services\TelegramService;
use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;
use App\Modulos\Procedimientos\Services\OnlyOfficeHealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

final class NovaHealthRepository
{
    public function __construct(
        private ModuleRegistry $modules,
        private TelegramService $telegram,
        private OnlyOfficeHealthService $onlyOfficeHealth,
    ) {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function checks(): array
    {
        $checks   = [];
        $checks[] = $this->tableCheck('Usuarios NOVA', 'usuarios_nova');
        $checks[] = $this->tableCheck('Permisos de modulos', 'permisos_usuario_modulo');
        $checks[] = $this->settingsCheck();

        foreach ($this->modules->all() as $key => $module) {
            $path     = rtrim((string) ($module['path'] ?? ''), DIRECTORY_SEPARATOR);
            $checks[] = [
                'name'   => 'Modulo ' . ($module['name'] ?? $key),
                'status' => is_dir($path) ? 'ok' : 'error',
                'detail' => is_dir($path) ? $path : 'No existe: ' . $path,
            ];
        }

        $checks[] = $this->telegram->healthCheck();
        $checks[] = $this->dockerTelegramCheck();
        $checks[] = $this->coreCheck();
        $checks[] = $this->emachCheck();
        $checks[] = $this->nextcloudCheck();
        $checks[] = $this->onlyOfficeCheck();

        return $checks;
    }

    private function onlyOfficeCheck(): array
    {
        $health = $this->onlyOfficeHealth->check(true);
        $status = match ((string) ($health['status'] ?? '')) {
            'online', 'disabled' => 'ok',
            'error' => 'error',
            default => 'warn',
        };

        return [
            'name' => 'OnlyOffice',
            'status' => $status,
            'detail' => trim((string) ($health['label'] ?? '') . '. ' . (string) ($health['detail'] ?? '')),
        ];
    }

    private function settingsCheck(): array
    {
        try {
            $count = DB::table('nova_settings')->count();
            return ['name' => 'Configuracion NOVA', 'status' => 'ok', 'detail' => 'nova_settings OK (' . $count . ' claves)'];
        } catch (\Throwable) {
            return ['name' => 'Configuracion NOVA', 'status' => 'warn', 'detail' => 'Tabla nova_settings no disponible'];
        }
    }

    private function tableCheck(string $name, string $table): array
    {
        try {
            if (!Schema::hasTable($table)) {
                return ['name' => $name, 'status' => 'error', 'detail' => 'Tabla no existe: ' . $table];
            }

            return ['name' => $name, 'status' => 'ok', 'detail' => 'DB OK (' . DB::table($table)->count() . ' registros)'];
        } catch (\Throwable $e) {
            return ['name' => $name, 'status' => 'error', 'detail' => $e->getMessage()];
        }
    }

    private function nextcloudCheck(): array
    {
        $url = '';
        try {
            $repo   = app(MantencionConfigRepository::class);
            $config = $repo->loadAll() ?? [];
            $url    = trim((string) ($config['nextcloud_url'] ?? ''));
        } catch (\Throwable) {
        }

        return $this->httpCheck('Nextcloud', $url);
    }

    private function coreCheck(): array
    {
        $url = '';
        try {
            $repo   = app(MantencionConfigRepository::class);
            $config = $repo->loadAll() ?? [];
            $url    = trim((string) ($config['core_admin_url'] ?? ''));
        } catch (\Throwable) {
        }

        return $this->httpCheck('CORE', $url !== '' ? $url : 'https://www.hbvaldivia.cl/core/solicitudes/administrador');
    }

    private function emachCheck(): array
    {
        return $this->httpCheck('EMACH', 'http://10.6.206.19/index.php');
    }

    private function dockerTelegramCheck(): array
    {
        try {
            $process = new Process([
                'docker',
                'inspect',
                '-f',
                '{{.State.Running}}|{{.State.Status}}|{{.RestartCount}}',
                'nova-telegram',
            ], base_path(), null, null, 3);
            $process->run();

            if (!$process->isSuccessful()) {
                $detail = trim($process->getErrorOutput() . ' ' . $process->getOutput());
                $fallback = $this->telegramProcessFallback();
                if ($fallback !== null) {
                    return $fallback;
                }

                return [
                    'name' => 'Docker Telegram',
                    'status' => 'warn',
                    'detail' => $detail !== '' ? $detail : 'No se pudo consultar el contenedor nova-telegram',
                ];
            }

            $parts = explode('|', trim($process->getOutput()));
            $running = ($parts[0] ?? '') === 'true';
            $status = (string) ($parts[1] ?? 'desconocido');
            $restarts = (string) ($parts[2] ?? '0');

            return [
                'name' => 'Docker Telegram',
                'status' => $running ? 'ok' : 'error',
                'detail' => 'nova-telegram ' . $status . ' | reinicios: ' . $restarts,
            ];
        } catch (\Throwable $e) {
            return [
                'name' => 'Docker Telegram',
                'status' => 'warn',
                'detail' => 'No se pudo consultar Docker: ' . $e->getMessage(),
            ];
        }
    }

    private function telegramProcessFallback(): ?array
    {
        $heartbeat = $this->telegramHeartbeatCheck();
        if ($heartbeat !== null) {
            return $heartbeat;
        }

        try {
            $process = new Process(['ps', '-eo', 'args'], base_path(), null, null, 3);
            $process->run();
            if (!$process->isSuccessful()) {
                return null;
            }

            $output = $process->getOutput();
            if (str_contains($output, 'telegram/bin/service.php') || str_contains($output, 'telegram/bin/listen.php')) {
                return [
                    'name' => 'Docker Telegram',
                    'status' => 'ok',
                    'detail' => 'Proceso Telegram activo. Docker API no accesible desde PHP web.',
                ];
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function telegramHeartbeatCheck(): ?array
    {
        $path = storage_path('app/telegram/listener.heartbeat.json');
        if (!is_file($path)) {
            return null;
        }

        $age = time() - (int) filemtime($path);
        if ($age <= 120) {
            return [
                'name' => 'Docker Telegram',
                'status' => 'ok',
                'detail' => 'Heartbeat Telegram activo hace ' . $age . 's. Docker API no accesible desde PHP web.',
            ];
        }

        return [
            'name' => 'Docker Telegram',
            'status' => 'error',
            'detail' => 'Heartbeat Telegram vencido hace ' . $age . 's.',
        ];
    }

    private function httpCheck(string $name, string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['name' => $name, 'status' => 'warn', 'detail' => 'URL no configurada'];
        }

        if (!function_exists('curl_init')) {
            return ['name' => $name, 'status' => 'warn', 'detail' => 'cURL no disponible'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_NOBODY => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'NOVA health-check',
        ]);
        curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        $errno = (int) curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $httpCode === 0) {
            return ['name' => $name, 'status' => 'error', 'detail' => $url . ' | ' . ($error !== '' ? $error : 'Sin respuesta HTTP')];
        }

        if ($httpCode >= 500) {
            return ['name' => $name, 'status' => 'error', 'detail' => $url . ' | HTTP ' . $httpCode];
        }

        if ($httpCode >= 400) {
            return ['name' => $name, 'status' => 'warn', 'detail' => $url . ' | HTTP ' . $httpCode];
        }

        return ['name' => $name, 'status' => 'ok', 'detail' => $url . ' | HTTP ' . $httpCode];
    }
}
