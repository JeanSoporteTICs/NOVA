<?php

// Only loaded by the isolated service tests; replaces legacy side effects.

namespace App\Modulos\RedmineMantencion\Services;

use App\Modulos\RedmineMantencion\Repositories\MantencionConfigRepository;

function load_name_map(string $type): array
{
    return [];
}
function log_security_event(string $event, string $message): void {}
function mantencion_current_user(): array
{
    return ['nombre' => 'Usuario sintético'];
}
function append_hours_extra_record(array $message): void {}
function config_mantencion_repository(): MantencionConfigRepository
{
    return app(MantencionConfigRepository::class);
}
