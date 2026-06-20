<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Indice compuesto para consultas de reportes por modulo, estado y fecha
        if (Schema::hasTable('redmine_tic_reportes')) {
            Schema::table('redmine_tic_reportes', function (Blueprint $table): void {
                if (!$this->indexExists('redmine_tic_reportes', 'idx_reportes_modulo_estado_fecha')) {
                    $table->index(['modulo_id', 'estado', 'fecha'], 'idx_reportes_modulo_estado_fecha');
                }
                if (!$this->indexExists('redmine_tic_reportes', 'idx_reportes_modulo_asignado_estado')) {
                    $table->index(['modulo_id', 'asignado_a', 'estado'], 'idx_reportes_modulo_asignado_estado');
                }
            });
        }

        // Indice compuesto para busquedas de integraciones por usuario y tipo
        if (Schema::hasTable('integraciones_usuario')) {
            Schema::table('integraciones_usuario', function (Blueprint $table): void {
                if (!$this->indexExists('integraciones_usuario', 'idx_integraciones_usuario_tipo')) {
                    $table->index(['usuario_id', 'tipo'], 'idx_integraciones_usuario_tipo');
                }
            });
        }

        // Indice para busquedas por estado en usuarios_nova
        if (Schema::hasTable('usuarios_nova')) {
            Schema::table('usuarios_nova', function (Blueprint $table): void {
                if (!$this->indexExists('usuarios_nova', 'idx_usuarios_nova_estado')) {
                    $table->index(['estado'], 'idx_usuarios_nova_estado');
                }
                if (!$this->indexExists('usuarios_nova', 'idx_usuarios_nova_rol_estado')) {
                    $table->index(['rol', 'estado'], 'idx_usuarios_nova_rol_estado');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('redmine_tic_reportes')) {
            Schema::table('redmine_tic_reportes', function (Blueprint $table): void {
                $table->dropIndexIfExists('idx_reportes_modulo_estado_fecha');
                $table->dropIndexIfExists('idx_reportes_modulo_asignado_estado');
            });
        }

        if (Schema::hasTable('integraciones_usuario')) {
            Schema::table('integraciones_usuario', function (Blueprint $table): void {
                $table->dropIndexIfExists('idx_integraciones_usuario_tipo');
            });
        }

        if (Schema::hasTable('usuarios_nova')) {
            Schema::table('usuarios_nova', function (Blueprint $table): void {
                $table->dropIndexIfExists('idx_usuarios_nova_estado');
                $table->dropIndexIfExists('idx_usuarios_nova_rol_estado');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $indexes = \Illuminate\Support\Facades\DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]);
            return count($indexes) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
};
