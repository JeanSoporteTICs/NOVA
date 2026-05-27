<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use RedmineTic\Support\Redmine\RedmineDataRepository;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('redmine:archive-processed', function (RedmineDataRepository $redmine) {
    $archived = $redmine->archiveExpiredProcessedReports();
    $this->info($archived . ' reporte(s) procesado(s) archivado(s) por retencion.');
})->purpose('Archive processed Redmine reports after configured retention hours');
