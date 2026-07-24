<?php

namespace App\Console\Commands;

use App\Modulos\Nova\Services\NovaHealthAlertService;
use Illuminate\Console\Command;

class NovaHealthAlerts extends Command
{
    protected $signature = 'nova:health-alerts';

    protected $description = 'Check NOVA service health and notify administrators when services fail or recover.';

    public function handle(NovaHealthAlertService $alerts): int
    {
        $result = $alerts->run();
        $this->info(
            'Checks: ' . $result['checks']
            . ' | alertas: ' . $result['alerts']
            . ' | recuperaciones: ' . $result['recoveries']
        );

        return self::SUCCESS;
    }
}
