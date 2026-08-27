<?php

namespace App\Support\Reports;

final class ManagementReportFormatter
{
    /**
     * @param  array<int,array{name:string,ids:array<int,string>}>  $openByUser
     */
    public static function message(string $moduleName, string $icon, string $managerName, array $openByUser, string $periodLabel): string
    {
        $ticketCount = array_sum(array_map(static fn (array $row): int => count($row['ids']), $openByUser));
        $managerGreeting = trim($managerName) !== '' ? 'Hola '.trim($managerName).'.' : 'Hola.';
        $lines = [];

        foreach (array_slice($openByUser, 0, 15) as $row) {
            $ids = array_values(array_map('strval', $row['ids']));
            $visible = array_slice($ids, 0, 8);
            $tickets = implode(', ', array_map(static fn (string $id): string => '#'.$id, $visible));
            if (count($ids) > count($visible)) {
                $tickets .= ' y '.(count($ids) - count($visible)).' más';
            }
            $name = mb_strimwidth(trim($row['name']), 0, 60, '…');
            $lines[] = '• '.$name.': '.count($ids).' ('.$tickets.')';
        }
        if (count($openByUser) > count($lines)) {
            $lines[] = '• Y '.(count($openByUser) - count($lines)).' responsable(s) más.';
        }

        return $icon." [NOVA] INFORME JEFATURA {$moduleName}\n"
            .$managerGreeting."\n"
            .count($openByUser).' responsable(s) mantienen '.$ticketCount." reporte(s) abierto(s).\n"
            ."Estado: Nueva\n"
            ."Período informado: {$periodLabel}\n"
            .implode("\n", $lines)."\n"
            .'Revisa la gestión del equipo en NOVA.';
    }
}
