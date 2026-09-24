<?php

namespace App\Services\Database;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

final class UpgradeSafety
{
    public const CLEANUP = '2026_06_15_000002_cleanup_operational_data';

    public function assertMayUpgrade(ConnectionInterface $db): void
    {
        $schema = $db->getSchemaBuilder();
        if ($schema->hasTable('migrations') && $db->table('migrations')->where('migration', self::CLEANUP)->exists()) {
            return;
        }
        $this->assertCleanupIsEmpty($db);
    }

    public function assertCleanupIsEmpty(ConnectionInterface $db): void
    {
        $schema = $db->getSchemaBuilder();
        foreach (['redmine_tic_horas_extra_grupo_reportes', 'horas_extras', 'redmine_tic_horas_extra_grupos',
            'redmine_tic_reportes', 'redmine_mantencion_reportes', 'redmine_tic_activity_logs'] as $table) {
            if ($schema->hasTable($table) && $db->table($table)->exists()) {
                throw new RuntimeException('Actualización detenida: la limpieza histórica pendiente eliminaría datos de '.$table.'. Restaura el ledger correspondiente o prepara una conversión revisada; no ejecutes la limpieza ni marques migraciones manualmente.');
            }
        }
        if ($schema->hasTable('redmine_mantencion_storage') && $db->table('redmine_mantencion_storage')->whereNotIn('path', ['configuracion.json', 'roles.json'])->exists()) {
            throw new RuntimeException('Actualización detenida: la limpieza histórica pendiente eliminaría contenido de redmine_mantencion_storage. Consulta el procedimiento de recuperación.');
        }
    }
}
