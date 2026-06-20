<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // C3 — backfill and harden redmine_tic_reportes.estado
        DB::statement("UPDATE redmine_tic_reportes SET estado = 'pendiente' WHERE estado IS NULL OR estado = ''");
        DB::statement("ALTER TABLE redmine_tic_reportes MODIFY estado VARCHAR(20) NOT NULL DEFAULT 'pendiente'");

        // P1 — backfill and harden redmine_tic_reportes.hora_extra
        DB::statement('UPDATE redmine_tic_reportes SET hora_extra = 0 WHERE hora_extra IS NULL');
        DB::statement('ALTER TABLE redmine_tic_reportes MODIFY hora_extra TINYINT(1) NOT NULL DEFAULT 0');

        // P1 — composite index for audit log queries by user + date (IF NOT EXISTS is MariaDB-supported)
        DB::statement('CREATE INDEX IF NOT EXISTS idx_audit_user_date ON nova_audit_logs(user_id, registrado_at)');

        // P1 — drop single-column tipo index superseded by composite unique uq_integracion_usuario_tipo
        DB::statement('ALTER TABLE integraciones_usuario DROP INDEX IF EXISTS idx_integraciones_tipo');

        // P1 — ON UPDATE CURRENT_TIMESTAMP for active tables that track mutations
        DB::statement('ALTER TABLE redmine_tic_reportes MODIFY actualizado_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
        DB::statement('ALTER TABLE permisos_usuario_modulo MODIFY actualizado_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
        DB::statement('ALTER TABLE modulos_nova MODIFY actualizado_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
        DB::statement('ALTER TABLE integraciones_usuario MODIFY actualizado_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');

        // P1 — seed nova_settings baseline defaults (clave, valor, tipo only)
        $defaults = [
            ['clave' => 'session_timeout',         'valor' => '3600', 'tipo' => 'integer'],
            ['clave' => 'notification_enabled',     'valor' => '0',    'tipo' => 'boolean'],
            ['clave' => 'health_warning_threshold', 'valor' => '1',    'tipo' => 'integer'],
        ];
        foreach ($defaults as $row) {
            DB::table('nova_settings')->insertOrIgnore($row);
        }
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE redmine_tic_reportes MODIFY estado VARCHAR(20) NULL DEFAULT NULL");
        DB::statement('ALTER TABLE redmine_tic_reportes MODIFY hora_extra TINYINT(1) NULL DEFAULT NULL');
        DB::statement('DROP INDEX IF EXISTS idx_audit_user_date ON nova_audit_logs');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_integraciones_tipo ON integraciones_usuario(tipo)');
        DB::statement('ALTER TABLE redmine_tic_reportes MODIFY actualizado_at TIMESTAMP NULL DEFAULT NULL');
        DB::statement('ALTER TABLE permisos_usuario_modulo MODIFY actualizado_at TIMESTAMP NULL DEFAULT NULL');
        DB::statement('ALTER TABLE modulos_nova MODIFY actualizado_at TIMESTAMP NULL DEFAULT NULL');
        DB::statement('ALTER TABLE integraciones_usuario MODIFY actualizado_at TIMESTAMP NULL DEFAULT NULL');
        DB::table('nova_settings')->whereIn('clave', ['session_timeout', 'notification_enabled', 'health_warning_threshold'])->delete();
    }
};
