<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('redmine_tic_reportes')) {
            return;
        }

        Schema::table('redmine_tic_reportes', function (Blueprint $table): void {
            if (!Schema::hasColumn('redmine_tic_reportes', 'fecha_inicio')) {
                $table->date('fecha_inicio')->nullable()->after('hora')->index();
            }
            if (!Schema::hasColumn('redmine_tic_reportes', 'fecha_fin')) {
                $table->date('fecha_fin')->nullable()->after('fecha_inicio')->index();
            }
            if (!Schema::hasColumn('redmine_tic_reportes', 'chat_id_telegram')) {
                $table->string('chat_id_telegram', 120)->nullable()->after('fecha_fin');
            }
            if (!Schema::hasColumn('redmine_tic_reportes', 'mensaje')) {
                $table->text('mensaje')->nullable()->after('chat_id_telegram');
            }
        });

        if (Schema::hasColumn('redmine_tic_reportes', 'datos_extra')) {
            foreach (DB::table('redmine_tic_reportes')->whereNotNull('datos_extra')->get(['id', 'datos_extra']) as $row) {
                $extra = json_decode((string) ($row->datos_extra ?? ''), true);
                if (!is_array($extra)) {
                    continue;
                }

                DB::table('redmine_tic_reportes')
                    ->where('id', $row->id)
                    ->update([
                        'fecha_inicio' => $this->parseDate((string) ($extra['fecha_inicio'] ?? '')),
                        'fecha_fin' => $this->parseDate((string) ($extra['fecha_fin'] ?? '')),
                        'chat_id_telegram' => $this->nullableString($extra['numero'] ?? $extra['chat_id_telegram'] ?? null, 120),
                        'mensaje' => $this->nullableString($extra['mensaje'] ?? null),
                        'actualizado_at' => now(),
                    ]);
            }

            Schema::table('redmine_tic_reportes', function (Blueprint $table): void {
                $table->dropColumn('datos_extra');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('redmine_tic_reportes')) {
            return;
        }

        Schema::table('redmine_tic_reportes', function (Blueprint $table): void {
            if (!Schema::hasColumn('redmine_tic_reportes', 'datos_extra')) {
                $table->longText('datos_extra')->nullable()->after('actualizado_at');
            }
        });

        foreach (DB::table('redmine_tic_reportes')->get(['id', 'fecha_inicio', 'fecha_fin', 'chat_id_telegram', 'mensaje']) as $row) {
            DB::table('redmine_tic_reportes')
                ->where('id', $row->id)
                ->update([
                    'datos_extra' => json_encode([
                        'fecha_inicio' => $this->dateString($row->fecha_inicio ?? null),
                        'fecha_fin' => $this->dateString($row->fecha_fin ?? null),
                        'numero' => (string) ($row->chat_id_telegram ?? ''),
                        'chat_id_telegram' => (string) ($row->chat_id_telegram ?? ''),
                        'mensaje' => (string) ($row->mensaje ?? ''),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
        }
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->toDateString();
            } catch (Throwable) {
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value, ?int $max = null): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }

        return $max !== null ? mb_substr($text, 0, $max) : $text;
    }

    private function dateString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return '';
        }
    }
};
