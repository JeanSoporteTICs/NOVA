<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('redmine_mantencion_procedimientos')) {
            Schema::create('redmine_mantencion_procedimientos', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')->nullable()->constrained('modulos_nova')->nullOnDelete();
                $table->string('legacy_id')->unique();
                $table->string('record_type', 20)->default('document');
                $table->string('folder_id')->nullable()->index();
                $table->string('share_token', 64)->unique();
                $table->string('title')->default('Sin título');
                $table->longText('content_html')->nullable();
                $table->string('page_size', 20)->default('letter');
                $table->string('file_name')->nullable();
                $table->string('file_original_name')->nullable();
                $table->string('file_mime')->nullable();
                $table->unsignedBigInteger('file_size')->default(0);
                $table->string('file_url', 2048)->nullable();
                $table->string('storage_driver', 40)->default('local');
                $table->string('nextcloud_path', 2048)->nullable();
                $table->string('nextcloud_share_id')->nullable();
                $table->string('nextcloud_share_url', 2048)->nullable();
                $table->timestamp('uploaded_at')->nullable();
                $table->boolean('draft_pending')->default(false);
                $table->string('author_id')->nullable()->index();
                $table->string('author_name')->nullable();
                $table->timestamp('creado_at')->nullable();
                $table->timestamp('actualizado_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('redmine_mantencion_nextcloud_historial_lotes')) {
            Schema::create('redmine_mantencion_nextcloud_historial_lotes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('modulo_id')->nullable()->constrained('modulos_nova')->nullOnDelete();
                $table->string('legacy_id', 32)->unique();
                $table->timestamp('created_at_cl')->index();
                $table->timestamp('expires_at')->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('redmine_mantencion_nextcloud_historial_usuarios')) {
            Schema::create('redmine_mantencion_nextcloud_historial_usuarios', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('lote_id')
                    ->constrained('redmine_mantencion_nextcloud_historial_lotes')
                    ->cascadeOnDelete();
                $table->string('tipo', 20)->index();
                $table->string('userid')->nullable();
                $table->string('display_name')->nullable();
                $table->string('email')->nullable();
                $table->string('grupo')->nullable();
                $table->string('password')->nullable();
                $table->string('status')->nullable();
                $table->text('message')->nullable();
                $table->timestamps();
            });
        }

        $moduleId = Schema::hasTable('modulos_nova')
            ? DB::table('modulos_nova')->where('clave_modulo', 'redmine-mantencion')->value('id')
            : null;

        if ($moduleId !== null && Schema::hasTable('redmine_mantencion_storage')) {
            $row = DB::table('redmine_mantencion_storage')->where('path', 'procedimientos/index.json')->first();
            $items = json_decode((string) ($row->payload_json ?? '[]'), true);
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $legacyId = trim((string) ($item['id'] ?? ''));
                    if ($legacyId === '') {
                        continue;
                    }
                    DB::table('redmine_mantencion_procedimientos')->updateOrInsert(
                        ['legacy_id' => $legacyId],
                        $this->procedurePayload($moduleId, $item),
                    );
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('redmine_mantencion_nextcloud_historial_usuarios');
        Schema::dropIfExists('redmine_mantencion_nextcloud_historial_lotes');
        Schema::dropIfExists('redmine_mantencion_procedimientos');
    }

    /** @return array<string,mixed> */
    private function procedurePayload(int $moduleId, array $item): array
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
            'storage_driver' => trim((string) ($item['storage_driver'] ?? 'local')) ?: 'local',
            'nextcloud_path' => trim((string) ($item['nextcloud_path'] ?? '')) ?: null,
            'nextcloud_share_id' => trim((string) ($item['nextcloud_share_id'] ?? '')) ?: null,
            'nextcloud_share_url' => trim((string) ($item['nextcloud_share_url'] ?? '')) ?: null,
            'uploaded_at' => $this->dateOrNull((string) ($item['uploaded_at'] ?? '')),
            'draft_pending' => ! empty($item['draft_pending']),
            'author_id' => trim((string) ($item['author_id'] ?? '')) ?: null,
            'author_name' => trim((string) ($item['author_name'] ?? '')) ?: null,
            'creado_at' => $this->dateOrNull((string) ($item['created_at'] ?? '')),
            'actualizado_at' => $this->dateOrNull((string) ($item['updated_at'] ?? '')),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function dateOrNull(string $value): ?string
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }
};
