<?php

namespace App\Console\Commands;

use App\Modulos\MonitorServidores\Repositories\ServerMonitorRepository;
use App\Modulos\MonitorServidores\Services\ServerMonitorService;
use Illuminate\Console\Command;

final class MonitorServers extends Command
{
    protected $signature = 'nova:monitor-servers
        {--daemon : Mantener el monitor ejecutándose}
        {--sleep=10 : Segundos entre ciclos del daemon}
        {--healthcheck : Validar que el worker tenga heartbeat reciente}';

    protected $description = 'Comprueba servidores configurados y envía alertas de caída o recuperación por Telegram.';

    public function handle(ServerMonitorService $monitor, ServerMonitorRepository $repository): int
    {
        if ($this->option('healthcheck')) {
            return $repository->workerIsHealthy(90) ? self::SUCCESS : self::FAILURE;
        }

        $daemon = (bool) $this->option('daemon');
        $sleep = max(5, min((int) $this->option('sleep'), 60));
        $instance = trim((string) (gethostname() ?: 'nova-monitor'));
        $this->info($daemon ? 'Monitor de servidores iniciado.' : 'Ejecutando un ciclo de monitoreo.');

        do {
            try {
                $result = $monitor->runDue($instance);
                if (!$daemon) {
                    $this->info(
                        'Comprobados: ' . $result['checked']
                        . ' | caídas: ' . $result['alerts']
                        . ' | recuperaciones: ' . $result['recoveries']
                    );
                }
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                if (!$daemon) {
                    return self::FAILURE;
                }
            }

            if ($daemon) {
                sleep($sleep);
            }
        } while ($daemon);

        return self::SUCCESS;
    }
}
