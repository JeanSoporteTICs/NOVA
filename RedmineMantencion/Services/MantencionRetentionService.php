<?php

namespace App\Modulos\RedmineMantencion\Services;

class MantencionRetentionService
{
    public function retention_threshold(): \DateTimeImmutable {
        return (new \DateTimeImmutable())->modify('-' . $this->get_retencion_horas() . ' hours');
    }

    public function apply_retention_archive(array &$messages, ?\DateTimeImmutable $threshold = null): bool {
        $threshold ??= $this->retention_threshold();
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
            if ($this->archive_message_record($message)) {
                $removed[] = $message;
                unset($messages[$key]);
            }
        }
        if (empty($removed)) {
            return false;
        }
        $messages = array_values($messages);
        return true;
    }

    public function archive_message_record(array $message, string $archivedBy = 'retencion'): bool {
        $repo = function_exists('mantencion_report_repository') ? mantencion_report_repository() : null;
        if ($repo !== null && $repo->tableReady()) {
            try {
                return \Illuminate\Support\Facades\DB::transaction(function () use ($repo, $message): bool {
                    if (!$repo->markArchived($message)) return false;
                    $message['estado'] = 'archivado';
                    if (!append_hours_extra_record($message)) throw new \RuntimeException('No se pudo guardar la jornada de horas extra.');
                    return true;
                }, 3);
            } catch (\Throwable) { return false; }
        }
        return false;
    }

    public function archive_selected_messages(array &$messages, array $ids): int {
        $ids = array_filter(array_map('trim', $ids));
        if (empty($ids)) {
            return 0;
        }
        $selected = array_fill_keys($ids, true);
        $archived = 0;
        foreach ($messages as $key => $message) {
            $id = $message['id'] ?? '';
            if (!is_string($id) || !isset($selected[$id])) {
                continue;
            }
            if (strtolower(trim((string)($message['estado'] ?? ''))) !== 'procesado') {
                continue;
            }
            if (!$this->archive_message_record($message, 'manual')) {
                continue;
            }
            unset($messages[$key]);
            $archived++;
        }
        if ($archived > 0) {
            $messages = array_values($messages);
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
