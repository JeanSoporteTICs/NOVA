<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3 — normaliza el dominio Horas Extra: reemplaza las tablas separadas
 * redmine_mantencion_horas_extra_* / redmine_tic_horas_extra_* por un par de
 * tablas compartidas (horas_extra_grupos / horas_extra_grupo_reportes),
 * agrupadas por (usuario_id, fecha) en vez de por módulo. Las tablas
 * originales NO se tocan ni se eliminan: quedan como respaldo/rollback.
 *
 * Esto NO fusiona reportes, permisos, catálogos, workflows ni integraciones
 * de Mantención/TIC: ambos módulos siguen siendo independientes y solo
 * comparten este dominio (una jornada de horas extra por usuario+fecha).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('horas_extra_grupos')) {
            Schema::create('horas_extra_grupos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('usuario_id')->nullable()->constrained('usuarios_nova')->nullOnDelete();
                $table->date('fecha');
                $table->time('hora_inicio')->nullable();
                $table->time('hora_fin')->nullable();
                $table->unsignedInteger('total_minutos')->nullable();
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();

                $table->unique(['usuario_id', 'fecha'], 'uq_horas_extra_usuario_fecha');
                $table->index('fecha', 'idx_horas_extra_fecha');
            });
        }

        if (! Schema::hasTable('horas_extra_grupo_reportes')) {
            Schema::create('horas_extra_grupo_reportes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('grupo_id')->constrained('horas_extra_grupos')->cascadeOnDelete();
                $table->string('origen', 30);
                $table->unsignedBigInteger('reporte_id');
                $table->timestamp('creado_at')->useCurrent();
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();

                $table->unique(['grupo_id', 'origen', 'reporte_id'], 'uq_he_reporte');
                $table->index(['origen', 'reporte_id'], 'idx_he_origen_reporte');
            });
        }

        $this->backfillFrom(
            grupoTabla: 'redmine_mantencion_horas_extra_grupos',
            pivotTabla: 'redmine_mantencion_horas_extra_reportes',
            reportesTabla: 'redmine_mantencion_reportes',
            asignadoColumna: 'id_redmine_asignado',
            origen: 'mantencion',
        );

        $this->backfillFrom(
            grupoTabla: 'redmine_tic_horas_extra_grupos',
            pivotTabla: 'redmine_tic_horas_extra_grupo_reportes',
            reportesTabla: 'redmine_tic_reportes',
            asignadoColumna: 'asignado_a',
            origen: 'tic',
        );
    }

    private function backfillFrom(
        string $grupoTabla,
        string $pivotTabla,
        string $reportesTabla,
        string $asignadoColumna,
        string $origen,
    ): void {
        if (! Schema::hasTable($grupoTabla) || ! Schema::hasTable($pivotTabla) || ! Schema::hasTable($reportesTabla) || ! Schema::hasTable('usuarios_nova')) {
            return;
        }

        $usuariosPorRedmineId = DB::table('usuarios_nova')
            ->whereNotNull('redmine_id')
            ->pluck('id', 'redmine_id');

        DB::table("{$grupoTabla} as g")
            ->join("{$pivotTabla} as p", 'p.grupo_id', '=', 'g.id')
            ->join("{$reportesTabla} as r", 'r.id', '=', 'p.reporte_id')
            ->orderBy('g.fecha')
            ->orderBy('r.id')
            ->get([
                'g.fecha',
                'g.hora_inicio',
                'g.hora_fin',
                'r.id as reporte_id',
                "r.{$asignadoColumna} as asignado",
            ])
            ->each(function (object $row) use ($usuariosPorRedmineId, $origen): void {
                $fecha = (string) $row->fecha;
                $asignado = trim((string) ($row->asignado ?? ''));
                $usuarioId = $asignado !== '' ? ($usuariosPorRedmineId[$asignado] ?? null) : null;

                $query = DB::table('horas_extra_grupos')->where('fecha', $fecha);
                $usuarioId !== null ? $query->where('usuario_id', $usuarioId) : $query->whereNull('usuario_id');
                $grupoId = $query->value('id');

                if ($grupoId === null) {
                    $grupoId = DB::table('horas_extra_grupos')->insertGetId([
                        'usuario_id' => $usuarioId,
                        'fecha' => $fecha,
                        'hora_inicio' => $row->hora_inicio,
                        'hora_fin' => $row->hora_fin,
                        'total_minutos' => $this->minutesDiff($row->hora_inicio, $row->hora_fin),
                        'creado_at' => now(),
                        'actualizado_at' => now(),
                    ]);
                } else {
                    // No pisar horas ya definidas por otro modulo con valores vacios de este.
                    $values = [];
                    $current = DB::table('horas_extra_grupos')->where('id', $grupoId)->first(['hora_inicio', 'hora_fin']);
                    if (($current->hora_inicio ?? null) === null && $row->hora_inicio !== null) {
                        $values['hora_inicio'] = $row->hora_inicio;
                    }
                    if (($current->hora_fin ?? null) === null && $row->hora_fin !== null) {
                        $values['hora_fin'] = $row->hora_fin;
                    }
                    if ($values !== []) {
                        $horaInicio = $values['hora_inicio'] ?? $current->hora_inicio ?? null;
                        $horaFin = $values['hora_fin'] ?? $current->hora_fin ?? null;
                        $values['total_minutos'] = $this->minutesDiff($horaInicio, $horaFin);
                        $values['actualizado_at'] = now();
                        DB::table('horas_extra_grupos')->where('id', $grupoId)->update($values);
                    }
                }

                DB::table('horas_extra_grupo_reportes')->updateOrInsert(
                    ['grupo_id' => $grupoId, 'origen' => $origen, 'reporte_id' => (int) $row->reporte_id],
                    ['actualizado_at' => now()],
                );
            });
    }

    private function minutesDiff(?string $horaInicio, ?string $horaFin): ?int
    {
        if ($horaInicio === null || $horaFin === null || trim($horaInicio) === '' || trim($horaFin) === '') {
            return null;
        }

        $start = strtotime('1970-01-01 ' . $horaInicio);
        $end = strtotime('1970-01-01 ' . $horaFin);
        if ($start === false || $end === false) {
            return null;
        }
        if ($end < $start) {
            $end += 86400;
        }

        return (int) round(($end - $start) / 60);
    }

    public function down(): void
    {
        Schema::dropIfExists('horas_extra_grupo_reportes');
        Schema::dropIfExists('horas_extra_grupos');
    }
};
