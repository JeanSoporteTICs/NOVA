<?php

namespace App\Modulos\RedmineMantencion\Services;

use App\Modulos\RedmineMantencion\Repositories\MantencionHoursExtraRepository;
use App\Modulos\RedmineMantencion\Repositories\MantencionReportRepository;

function mantencion_report_repository(): MantencionReportRepository
{
    return app(MantencionReportRepository::class);
}

function append_hours_extra_record(array $message): bool
{
    return app(MantencionHoursExtraRepository::class)->syncMessage($message);
}
