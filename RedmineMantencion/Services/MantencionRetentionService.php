<?php

namespace App\Modulos\RedmineMantencion\Services;

class MantencionRetentionService
{
    public function apply_retention_archive(array &$messages): bool {
        $threshold = (new \DateTimeImmutable())->modify('-' . $this->get_retencion_horas() . ' hours');
        $removed = [];
        foreach ($messages as $key => $message) {
            $estado = strtolower($message['estado'] ?? '');
            if ($estado !== 'procesado') {
                continue;
            }
            $ts = parse_message_timestamp($message);
            if ($ts === null || $ts > $threshold) {
                continue;
            }
            $removed[] = $message;
            unset($messages[$key]);
        }
        if (empty($removed)) {
            return false;
        }
        $messages = array_values($messages);
        foreach ($removed as $item) {
            $this->archive_message_record($item);
        }
        return true;
    }

    public function archive_message_record(array $message, string $archivedBy = 'retencion'): void {
        $repo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
        if ($repo !== null && $repo->tableReady()) {
            $message['estado'] = 'archivado';
            $repo->markArchived($message);
            append_hours_extra_record($message);
        }
    }

    public function archive_selected_messages(array &$messages, array $ids): int {
        $ids = array_filter(array_map('trim', $ids));
        if (empty($ids)) {
            return 0;
        }
        $archived = 0;
        foreach ($messages as $key => $message) {
            if (!in_array(($message['id'] ?? ''), $ids, true)) {
                continue;
            }
            if (strtolower(trim((string)($message['estado'] ?? ''))) !== 'procesado') {
                continue;
            }
            $this->archive_message_record($message, 'manual');
            unset($messages[$key]);
            $archived++;
        }
        if ($archived > 0) {
            $messages = array_values($messages);
            save_messages($messages);
        }
        return $archived;
    }

    public function ensure_dir(string $path): void {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    public function get_retencion_horas(int $default = 24): int {
        $cfg = load_platform_config();
        $value = isset($cfg['retencion_horas']) ? (int)$cfg['retencion_horas'] : $default;
        return max(1, $value);
    }
}
