<?php

namespace RedmineTic\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thin wrapper for redmine_tic_reportes table operations that are isolated
 * from catalog lookups and user-hydration.
 *
 * The bulk of report CRUD (saveActiveReportsToDatabase, databaseReportPayload,
 * databaseReportToArray, saveArchivedReportToDatabase) remains in
 * RedmineDataRepository pending resolution of catalog / user cross-concerns.
 */
class RedmineReportRepository
{
    public function __construct(
        private string $projectKey,
        private string $projectName,
    ) {}

    public function tableAvailable(): bool
    {
        try {
            return Schema::hasTable('modulos_nova') && Schema::hasTable('redmine_tic_reportes');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Hard-deletes a single report row by numeric DB id.
     */
    public function deleteRow(int $moduleId, int $reportId): int
    {
        if (!$this->tableAvailable() || $moduleId <= 0 || $reportId <= 0) {
            return 0;
        }

        try {
            return DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->where('id', $reportId)
                ->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Hard-deletes the single archived report row identified by string $id.
     */
    public function deleteArchived(string $id): int
    {
        if (!$this->tableAvailable() || trim($id) === '') {
            return 0;
        }

        $moduleId = $this->moduleId();
        if ($moduleId === null) {
            return 0;
        }

        try {
            return DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->where('id', (int) $id)
                ->where('estado', 'archivado')
                ->delete();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Hard-deletes all active (non-archived) report rows not in $keepIds.
     */
    public function deleteActiveExcept(int $moduleId, array $keepIds): void
    {
        if (!$this->tableAvailable() || $moduleId <= 0) {
            return;
        }

        try {
            $query = DB::table('redmine_tic_reportes')
                ->where('modulo_id', $moduleId)
                ->where(function ($q): void {
                    $q->whereNull('estado')->orWhere('estado', '<>', 'archivado');
                });

            if ($keepIds !== []) {
                $query->whereNotIn('id', $keepIds);
            }

            $query->delete();
        } catch (\Throwable) {
        }
    }

    private function moduleId(): ?int
    {
        try {
            $id = DB::table('modulos_nova')->where('clave_modulo', $this->projectKey)->value('id');
            if ($id !== null) {
                return (int) $id;
            }

            DB::table('modulos_nova')->insert([
                'clave_modulo'   => $this->projectKey,
                'nombre'         => $this->projectName,
                'descripcion'    => '',
                'icono'          => '',
                'tipo'           => 'native',
                'ruta'           => $this->projectKey,
                'entrada'        => 'laravel:redmine.native.dashboard',
                'habilitado'     => 1,
                'orden'          => 100,
                'creado_at'      => now(),
                'actualizado_at' => now(),
            ]);

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }
}
