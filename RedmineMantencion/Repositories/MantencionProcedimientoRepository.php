<?php

namespace App\Modulos\RedmineMantencion\Repositories;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MantencionProcedimientoRepository
{
    private const MODULE_KEY = 'redmine-mantencion';

    private ?int $moduleId = null;
    private bool $moduleIdResolved = false;

    public function tableReady(): bool
    {
        try {
            return Schema::hasTable('modulos_nova')
                && Schema::hasTable('redmine_mantencion_procedimientos');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        $moduleId = $this->resolveModuleId();
        if (! $this->tableReady() || $moduleId === null) {
            return [];
        }

        return DB::table('redmine_mantencion_procedimientos')
            ->where('modulo_id', $moduleId)
            ->orderByDesc('actualizado_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (object $row): array => $this->rowToArray($row))
            ->all();
    }

    /** @param array<int,array<string,mixed>> $items */
    public function replaceAll(array $items): bool
    {
        $moduleId = $this->resolveModuleId();
        if (! $this->tableReady() || $moduleId === null) {
            return false;
        }

        DB::transaction(function () use ($items, $moduleId): void {
            $ids = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $legacyId = trim((string) ($item['id'] ?? ''));
                if ($legacyId === '') {
                    continue;
                }
                $ids[] = $legacyId;
                DB::table('redmine_mantencion_procedimientos')->updateOrInsert(
                    ['legacy_id' => $legacyId],
                    $this->payload($moduleId, $item),
                );
            }
            DB::table('redmine_mantencion_procedimientos')
                ->where('modulo_id', $moduleId)
                ->when($ids !== [], fn ($query) => $query->whereNotIn('legacy_id', $ids))
                ->delete();
        });

        return true;
    }

    /** @return array<string,mixed> */
    private function rowToArray(object $row): array
    {
        return [
            'id' => (string) $row->legacy_id,
            'record_type' => (string) ($row->record_type ?? 'document'),
            'folder_id' => (string) ($row->folder_id ?? ''),
            'share_token' => (string) ($row->share_token ?? ''),
            'title' => (string) ($row->title ?? 'Sin título'),
            'summary' => '',
            'content_html' => (string) ($row->content_html ?? ''),
            'page_size' => (string) ($row->page_size ?? 'letter'),
            'file_name' => (string) ($row->file_name ?? ''),
            'file_original_name' => (string) ($row->file_original_name ?? ''),
            'file_mime' => (string) ($row->file_mime ?? ''),
            'file_size' => (int) ($row->file_size ?? 0),
            'file_url' => (string) ($row->file_url ?? ''),
            'storage_driver' => (string) ($row->storage_driver ?? 'nextcloud'),
            'nextcloud_path' => (string) ($row->nextcloud_path ?? ''),
            'nextcloud_share_id' => (string) ($row->nextcloud_share_id ?? ''),
            'nextcloud_share_url' => (string) ($row->nextcloud_share_url ?? ''),
            'uploaded_at' => $this->formatDate($row->uploaded_at ?? null),
            'draft_pending' => (bool) ($row->draft_pending ?? false),
            'created_at' => $this->formatDate($row->creado_at ?? $row->created_at ?? null),
            'updated_at' => $this->formatDate($row->actualizado_at ?? $row->updated_at ?? null),
            'author_id' => (string) ($row->author_id ?? ''),
            'author_name' => (string) ($row->author_name ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function payload(int $moduleId, array $item): array
    {
        return [
            'modulo_id' => $moduleId,
            'record_type' => trim((string) ($item['record_type'] ?? 'document')) ?: 'document',
            'folder_id' => trim((string) ($item['folder_id'] ?? '')) ?: null,
            'share_token' => trim((string) ($item['share_token'] ?? '')) ?: bin2hex(random_bytes(16)),
            'title' => trim((string) ($item['title'] ?? 'Sin título')) ?: 'Sin título',
            'content_html' => (string) ($item['content_html'] ?? ''),
            'page_size' => trim((string) ($item['page_size'] ?? 'letter')) ?: 'letter',
            'file_name' => trim((string) ($item['file_name'] ?? '')) ?: null,
            'file_original_name' => trim((string) ($item['file_original_name'] ?? '')) ?: null,
            'file_mime' => trim((string) ($item['file_mime'] ?? '')) ?: null,
            'file_size' => max(0, (int) ($item['file_size'] ?? 0)),
            'file_url' => trim((string) ($item['file_url'] ?? '')) ?: null,
            'storage_driver' => trim((string) ($item['storage_driver'] ?? 'nextcloud')) ?: 'nextcloud',
            'nextcloud_path' => trim((string) ($item['nextcloud_path'] ?? '')) ?: null,
            'nextcloud_share_id' => trim((string) ($item['nextcloud_share_id'] ?? '')) ?: null,
            'nextcloud_share_url' => trim((string) ($item['nextcloud_share_url'] ?? '')) ?: null,
            'uploaded_at' => $this->parseDate($item['uploaded_at'] ?? ''),
            'draft_pending' => ! empty($item['draft_pending']),
            'author_id' => trim((string) ($item['author_id'] ?? '')) ?: null,
            'author_name' => trim((string) ($item['author_name'] ?? '')) ?: null,
            'creado_at' => $this->parseDate($item['created_at'] ?? ''),
            'actualizado_at' => $this->parseDate($item['updated_at'] ?? '') ?? now(),
            'updated_at' => now(),
        ];
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatDate(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }

        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return trim((string) $value);
        }
    }

    private function resolveModuleId(): ?int
    {
        if ($this->moduleIdResolved) {
            return $this->moduleId;
        }

        $this->moduleIdResolved = true;

        try {
            $id = DB::table('modulos_nova')->where('clave_modulo', self::MODULE_KEY)->value('id');
            $this->moduleId = $id !== null ? (int) $id : null;
        } catch (\Throwable) {
            $this->moduleId = null;
        }

        return $this->moduleId;
    }
}
