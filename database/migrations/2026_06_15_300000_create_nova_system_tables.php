<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S30 — Replace nova JSON files with relational tables.
 *
 * Creates:
 *   nova_audit_logs — replaces storage/app/nova/audit.json
 *   nova_settings   — replaces storage/app/nova/settings.json
 *
 * Imports existing audit.json data if the file is present, then removes the file.
 * settings.json has no existing data to import (defaults are applied in code).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nova_audit_logs')) {
            Schema::create('nova_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->string('event', 80)->index();
                $table->string('message', 500);
                $table->string('user_id', 160)->default('');
                $table->string('user_name', 255)->default('');
                $table->string('ip', 45)->default('');
                $table->json('contexto')->nullable();
                $table->timestamp('registrado_at')->useCurrent()->index();
            });
        }

        if (! Schema::hasTable('nova_settings')) {
            Schema::create('nova_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('clave', 80)->unique();
                $table->text('valor')->nullable();
                $table->string('tipo', 20)->default('string');
                $table->timestamp('actualizado_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        // Import existing audit.json if present
        $auditPath = storage_path('app/nova/audit.json');
        if (is_file($auditPath)) {
            $raw  = (string) @file_get_contents($auditPath);
            $rows = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    try {
                        DB::table('nova_audit_logs')->insert([
                            'event'          => (string) ($row['event'] ?? 'unknown'),
                            'message'        => (string) ($row['message'] ?? ''),
                            'user_id'        => (string) ($row['user_id'] ?? ''),
                            'user_name'      => (string) ($row['user_name'] ?? ''),
                            'ip'             => (string) ($row['ip'] ?? ''),
                            'contexto'       => is_array($row['context'] ?? null)
                                ? json_encode($row['context'], JSON_UNESCAPED_UNICODE)
                                : null,
                            'registrado_at'  => ! empty($row['at'])
                                ? (string) $row['at']
                                : now()->toDateTimeString(),
                        ]);
                    } catch (\Throwable) {
                        // Skip malformed rows
                    }
                }
            }
            @unlink($auditPath);
        }

        // Remove settings.json if present (defaults live in code)
        $settingsPath = storage_path('app/nova/settings.json');
        if (is_file($settingsPath)) {
            $raw      = (string) @file_get_contents($settingsPath);
            $settings = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($settings)) {
                $map = [
                    'session_timeout'           => ['tipo' => 'int'],
                    'notification_enabled'       => ['tipo' => 'bool'],
                    'health_warning_threshold'   => ['tipo' => 'int'],
                ];
                foreach ($map as $key => $meta) {
                    if (! array_key_exists($key, $settings)) {
                        continue;
                    }
                    $value = $settings[$key];
                    $stored = match ($meta['tipo']) {
                        'bool' => $value ? '1' : '0',
                        'int'  => (string) (int) $value,
                        default => (string) $value,
                    };
                    try {
                        DB::table('nova_settings')->updateOrInsert(
                            ['clave' => $key],
                            ['valor' => $stored, 'tipo' => $meta['tipo']]
                        );
                    } catch (\Throwable) {
                    }
                }
            }
            @unlink($settingsPath);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nova_settings');
        Schema::dropIfExists('nova_audit_logs');
    }
};
