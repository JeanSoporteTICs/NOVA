<?php

namespace RedmineTic\Services;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/** Targeted repair: keep the existing start-date link; never edit reports or groups. */
final class HoursDateReconciler
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function plan(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if ($ids === [] || min($ids) < 1 || count($ids) > 1000) {
            throw new RuntimeException('Selecciona entre 1 y 1.000 IDs de reporte válidos.');
        }
        $reports = $this->db->table('redmine_tic_reportes')->whereIn('id', $ids)->orderBy('id')->get()->keyBy('id');
        $links = $this->db->table('horas_extra_grupo_reportes')->where('origen', 'tic')->whereIn('reporte_id', $ids)->orderBy('id')->get();
        $groups = $this->db->table('horas_extra_grupos')->whereIn('id', $links->pluck('grupo_id')->unique())->orderBy('id')->get()->keyBy('id');
        $items = [];
        foreach ($ids as $id) {
            $report = $reports->get($id);
            $ownLinks = $links->where('reporte_id', $id);
            $date = (string) ($report->fecha_inicio ?? '');
            $matching = $wrong = $owners = [];
            foreach ($ownLinks as $link) {
                $group = $groups->get($link->grupo_id);
                $owners[] = $group?->usuario_id;
                if ($group !== null && $date !== '' && (string) $group->fecha === $date) {
                    $matching[] = (int) $link->id;
                } else {
                    $wrong[] = (int) $link->id;
                }
            }
            $reason = null;
            if ($report === null || $date === '' || $date !== (string) $report->fecha) {
                $reason = 'La fecha del reporte y su inicio deben existir y coincidir; se requiere revisión.';
            } elseif (count($matching) !== 1) {
                $reason = 'Se requiere exactamente un vínculo existente a la fecha de inicio.';
            } elseif ($ownLinks->count() !== $ownLinks->filter(fn ($p) => $groups->has($p->grupo_id))->count()) {
                $reason = 'Hay jornadas ausentes.';
            } elseif (count(array_unique($owners, SORT_REGULAR)) !== 1) {
                $reason = 'Las jornadas pertenecen a distintos usuarios.';
            }
            $items[] = ['report_id' => $id, 'redmine_id' => $report->redmine_id ?? null, 'date' => $date,
                'report_hash' => hash('sha256', json_encode($report, JSON_THROW_ON_ERROR)),
                'keep' => $matching, 'remove' => $wrong, 'blocked' => $reason];
        }
        $plan = ['format' => 'nova-tic-hours-date-v1', 'items' => $items,
            'links' => $links->map(fn ($r) => (array) $r)->values()->all(),
            'groups' => $groups->map(fn ($r) => (array) $r)->values()->all()];
        $plan['fingerprint'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));

        return $plan;
    }

    public function apply(array $expected, string $backupFile): int
    {
        $this->verify($expected);
        $this->assertStandalone();

        return $this->db->transaction(function () use ($expected, $backupFile): int {
            $this->lock($expected);
            $current = $this->plan(array_column($expected['items'], 'report_id'));
            if (! hash_equals($expected['fingerprint'], $current['fingerprint'])) {
                throw new RuntimeException('Los datos cambiaron desde la revisión; genera un plan nuevo.');
            }
            foreach ($current['items'] as $item) {
                if ($item['blocked'] !== null) {
                    throw new RuntimeException('Conciliación bloqueada para reporte '.$item['report_id'].': '.$item['blocked']);
                }
            }
            $ids = array_merge(...array_column($current['items'], 'remove'));
            if ($ids === []) {
                return 0;
            }
            $this->backup($current, $backupFile);
            $deleted = $this->db->table('horas_extra_grupo_reportes')->where('origen', 'tic')->whereIn('id', $ids)->delete();
            if ($deleted !== count($ids)) {
                throw new RuntimeException('No se pudieron retirar todos los vínculos seleccionados.');
            }
            $after = $this->plan(array_column($current['items'], 'report_id'));
            foreach ($after['items'] as $i => $item) {
                if ($item['blocked'] !== null || $item['remove'] !== [] || $item['keep'] !== $current['items'][$i]['keep'] || $item['report_hash'] !== $current['items'][$i]['report_hash']) {
                    throw new RuntimeException('La comprobación posterior falló; se revierte la operación.');
                }
            }
            $groups = $this->db->table('horas_extra_grupos')->whereIn('id', array_column($current['groups'], 'id'))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            if ($groups !== $current['groups']) {
                throw new RuntimeException('Una jornada cambió durante la conciliación.');
            }

            return $deleted;
        });
    }

    /** Restore only if reports, group metadata and every affected report link remain as expected. */
    public function restore(array $backup): int
    {
        $this->verify($backup);
        $this->assertStandalone();

        return $this->db->transaction(function () use ($backup): int {
            $this->lock($backup);
            $current = $this->plan(array_column($backup['items'], 'report_id'));
            $removed = array_merge(...array_column($backup['items'], 'remove'));
            $remaining = array_values(array_filter($backup['links'], fn ($p) => ! in_array($p['id'], $removed)));
            if ($current['links'] !== $remaining) {
                throw new RuntimeException('Los vínculos cambiaron después de la corrección.');
            }
            foreach ($current['items'] as $i => $item) {
                if ($item['report_hash'] !== $backup['items'][$i]['report_hash']) {
                    throw new RuntimeException('Un reporte cambió después de la corrección.');
                }
            }
            $groups = $this->db->table('horas_extra_grupos')->whereIn('id', array_column($backup['groups'], 'id'))->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            if ($groups !== $backup['groups']) {
                throw new RuntimeException('Una jornada cambió después de la corrección.');
            }
            $rows = array_values(array_filter($backup['links'], fn ($p) => in_array($p['id'], $removed)));
            if ($rows !== []) {
                $this->db->table('horas_extra_grupo_reportes')->insert($rows);
            }

            return count($rows);
        });
    }

    private function lock(array $plan): void
    {
        $ids = array_column($plan['items'], 'report_id');
        $this->db->table('redmine_tic_reportes')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
        $this->db->table('horas_extra_grupos')->whereIn('id', array_column($plan['groups'], 'id'))->orderBy('id')->lockForUpdate()->get(['id']);
        $this->db->table('horas_extra_grupo_reportes')->where('origen', 'tic')->whereIn('reporte_id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    private function assertStandalone(): void
    {
        if ($this->db->transactionLevel() !== 0 || $this->db->getPdo()->inTransaction()) {
            throw new RuntimeException('La conciliación requiere una transacción propia.');
        }
    }

    private function verify(array $plan): void
    {
        $fingerprint = $plan['fingerprint'] ?? '';
        unset($plan['fingerprint']);
        if (($plan['format'] ?? '') !== 'nova-tic-hours-date-v1' || ! hash_equals($fingerprint, hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)))) {
            throw new RuntimeException('El plan o respaldo no supera la comprobación de integridad.');
        }
    }

    private function backup(array $plan, string $file): void
    {
        $directory = realpath(dirname($file));
        $root = realpath(dirname(__DIR__, 2));
        if ($directory === false || $directory === $root || str_starts_with($directory, $root.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('El respaldo debe quedar fuera del proyecto y del directorio público.');
        }
        $contents = json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        $stream = fopen($file, 'x');
        if ($stream === false) {
            throw new RuntimeException('No se pudo crear un respaldo nuevo.');
        }
        try {
            if (! chmod($file, 0600) || fwrite($stream, $contents) !== strlen($contents) || ! fflush($stream)) {
                throw new RuntimeException('No se pudo completar el respaldo; no se modifica la base.');
            }
        } finally {
            fclose($stream);
        }
    }
}
