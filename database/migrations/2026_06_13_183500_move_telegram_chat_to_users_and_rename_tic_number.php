<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('usuarios_nova') && !Schema::hasColumn('usuarios_nova', 'telegram_id_chat')) {
            Schema::table('usuarios_nova', function (Blueprint $table): void {
                $table->string('telegram_id_chat', 120)->nullable()->after('usuario_core')->index();
            });
        }

        if (
            Schema::hasTable('usuarios_nova')
            && Schema::hasTable('integraciones_usuario')
            && Schema::hasColumn('usuarios_nova', 'telegram_id_chat')
        ) {
            foreach (DB::table('integraciones_usuario')
                ->where('tipo', 'telegram')
                ->whereNotNull('chat_id')
                ->where('chat_id', '<>', '')
                ->get(['usuario_id', 'chat_id']) as $row) {
                DB::table('usuarios_nova')
                    ->where('id', (int) $row->usuario_id)
                    ->where(function ($query): void {
                        $query->whereNull('telegram_id_chat')->orWhere('telegram_id_chat', '');
                    })
                    ->update([
                        'telegram_id_chat' => (string) $row->chat_id,
                        'actualizado_at' => now(),
                    ]);
            }

            DB::table('integraciones_usuario')->where('tipo', 'telegram')->delete();
        }

        if (Schema::hasTable('redmine_tic_reportes')) {
            if (Schema::hasColumn('redmine_tic_reportes', 'numero') && !Schema::hasColumn('redmine_tic_reportes', 'chat_id_telegram')) {
                Schema::table('redmine_tic_reportes', function (Blueprint $table): void {
                    $table->renameColumn('numero', 'chat_id_telegram');
                });
            } elseif (!Schema::hasColumn('redmine_tic_reportes', 'chat_id_telegram')) {
                Schema::table('redmine_tic_reportes', function (Blueprint $table): void {
                    $table->string('chat_id_telegram', 120)->nullable()->after('fecha_fin');
                });
            } elseif (Schema::hasColumn('redmine_tic_reportes', 'numero')) {
                DB::table('redmine_tic_reportes')
                    ->where(function ($query): void {
                        $query->whereNull('chat_id_telegram')->orWhere('chat_id_telegram', '');
                    })
                    ->whereNotNull('numero')
                    ->update(['chat_id_telegram' => DB::raw('numero')]);

                Schema::table('redmine_tic_reportes', function (Blueprint $table): void {
                    $table->dropColumn('numero');
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('redmine_tic_reportes')) {
            if (Schema::hasColumn('redmine_tic_reportes', 'chat_id_telegram') && !Schema::hasColumn('redmine_tic_reportes', 'numero')) {
                Schema::table('redmine_tic_reportes', function (Blueprint $table): void {
                    $table->renameColumn('chat_id_telegram', 'numero');
                });
            }
        }

        if (Schema::hasTable('usuarios_nova') && Schema::hasColumn('usuarios_nova', 'telegram_id_chat')) {
            Schema::table('usuarios_nova', function (Blueprint $table): void {
                $table->dropColumn('telegram_id_chat');
            });
        }
    }
};
