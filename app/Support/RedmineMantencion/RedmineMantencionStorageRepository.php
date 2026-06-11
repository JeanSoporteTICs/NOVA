<?php

namespace App\Support\RedmineMantencion;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class RedmineMantencionStorageRepository
{
    private const TABLE = 'redmine_mantencion_storage';

    public function tableReady(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    public function readJson(string $path): mixed
    {
        $row = $this->row($path);
        if (!$row || (string) ($row->content_type ?? '') !== 'json') {
            return null;
        }

        $decoded = json_decode((string) ($row->payload_json ?? ''), true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    public function readText(string $path): ?string
    {
        $row = $this->row($path);
        if (!$row || (string) ($row->content_type ?? '') !== 'text') {
            return null;
        }

        return (string) ($row->payload_text ?? '');
    }

    public function writeJson(string $path, mixed $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('No se pudo serializar JSON para ' . $path);
        }

        $this->upsert($path, 'json', [
            'payload_json' => $json,
            'payload_text' => null,
            'bytes' => strlen($json),
            'checksum' => hash('sha256', $json),
        ]);
    }

    public function writeText(string $path, string $contents): void
    {
        $this->upsert($path, 'text', [
            'payload_json' => null,
            'payload_text' => $contents,
            'bytes' => strlen($contents),
            'checksum' => hash('sha256', $contents),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonByPrefix(string $prefix): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        $prefix = trim($this->normalizePath($prefix), '/');
        if ($prefix !== '') {
            $prefix .= '/';
        }

        return DB::table(self::TABLE)
            ->where('content_type', 'json')
            ->where('path', 'like', $prefix . '%')
            ->orderBy('path')
            ->get(['path', 'payload_json'])
            ->mapWithKeys(function ($row): array {
                $decoded = json_decode((string) ($row->payload_json ?? ''), true);

                return [(string) $row->path => json_last_error() === JSON_ERROR_NONE ? $decoded : null];
            })
            ->filter(static fn ($value): bool => $value !== null)
            ->all();
    }

    public function importDataDirectory(string $dataDir): array
    {
        if (!$this->tableReady()) {
            throw new RuntimeException('La tabla ' . self::TABLE . ' no existe. Ejecuta las migraciones primero.');
        }

        $dataDir = rtrim($dataDir, DIRECTORY_SEPARATOR);
        if (!is_dir($dataDir)) {
            throw new RuntimeException('Directorio no encontrado: ' . $dataDir);
        }

        $summary = [
            'json_imported' => 0,
            'text_imported' => 0,
            'skipped' => 0,
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dataDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dataDir))), '/');
            if ($relative === '' || str_starts_with($relative, 'backups/') || str_starts_with($relative, 'logs/')) {
                $summary['skipped']++;
                continue;
            }

            $extension = strtolower($file->getExtension());
            if ($extension === 'json') {
                $raw = (string) file_get_contents($file->getPathname());
                $decoded = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $summary['skipped']++;
                    continue;
                }
                $this->writeJson($relative, $decoded);
                $this->markSourceMtime($relative, $file->getMTime());
                $summary['json_imported']++;
                continue;
            }

            if (in_array($extension, ['log', 'txt', 'csv'], true)) {
                $raw = (string) file_get_contents($file->getPathname());
                $this->upsert($relative, 'text', [
                    'payload_json' => null,
                    'payload_text' => $raw,
                    'bytes' => strlen($raw),
                    'checksum' => hash('sha256', $raw),
                    'source_mtime' => Carbon::createFromTimestamp($file->getMTime()),
                ]);
                $summary['text_imported']++;
                continue;
            }

            $summary['skipped']++;
        }

        return $summary;
    }

    private function row(string $path): ?object
    {
        if (!$this->tableReady()) {
            return null;
        }

        return DB::table(self::TABLE)->where('path', $this->normalizePath($path))->first();
    }

    private function markSourceMtime(string $path, int $mtime): void
    {
        DB::table(self::TABLE)
            ->where('path', $this->normalizePath($path))
            ->update(['source_mtime' => Carbon::createFromTimestamp($mtime)]);
    }

    private function upsert(string $path, string $contentType, array $values): void
    {
        $now = now();
        DB::table(self::TABLE)->updateOrInsert(
            ['path' => $this->normalizePath($path)],
            array_merge($values, [
                'content_type' => $contentType,
                'updated_at' => $now,
                'created_at' => $now,
            ])
        );
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = ltrim($path, '/');
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                throw new RuntimeException('Ruta no permitida: ' . $path);
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }
}
