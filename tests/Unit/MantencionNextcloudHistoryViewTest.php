<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MantencionNextcloudHistoryViewTest extends TestCase
{
    public function test_history_uses_one_summary_table_and_modal_detail_tables(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/redmine-mantencion/integraciones-nextcloud-historial.blade.php'
        );

        self::assertIsString($view);
        self::assertStringContainsString('nextcloud-history-table', $view);
        self::assertStringContainsString('Importaciones procesadas', $view);
        self::assertStringContainsString('nextcloud-history-open', $view);
        self::assertStringContainsString('aria-label="Ver detalle del lote', $view);
        self::assertStringContainsString("'number' => \$batchNumber", $view);
        self::assertStringContainsString("<small class=\"nextcloud-history-id\"><?= (int)\$row['number'] ?></small>", $view);
        self::assertStringContainsString("Lote <?= (int)\$row['number'] ?>", $view);
        self::assertStringNotContainsString("#<?= \$h(\$batch['id'] ?? '') ?>", $view);
        self::assertStringNotContainsString('</i> Ver', $view);
        self::assertStringContainsString('data-bs-toggle="modal"', $view);
        self::assertStringContainsString('modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable', $view);
        self::assertStringContainsString('nextcloud-history-detail-table', $view);
        self::assertStringNotContainsString('data-copy-table', $view);
        self::assertStringNotContainsString('Copiar tabla', $view);
        self::assertStringContainsString('nextcloud-history-actions', $view);
        self::assertStringContainsString('btn-nova-icon-only nextcloud-history-manual', $view);
        self::assertStringContainsString('title="Crear reporte manual"', $view);
        self::assertStringContainsString('data-app-no-loading', $view);
        self::assertStringNotContainsString('</i> Crear manual', $view);
        self::assertStringContainsString('name="action" value="create_manual_report"', $view);
        self::assertStringContainsString('name="numero_lote" value="<?= (int)$row[\'number\'] ?>"', $view);
        self::assertStringContainsString('<?php if ($canCreateManualReport): ?>', $view);
        self::assertStringNotContainsString("navigator.clipboard.writeText(rowsText)", $view);
        self::assertStringNotContainsString("document.execCommand('copy')", $view);
        foreach (['solicitante_nombre', 'solicitante_rut', 'solicitante_correo'] as $field) {
            self::assertStringContainsString($field, $view);
        }
        self::assertStringContainsString('Nombre del solicitante', $view);
        self::assertStringContainsString('<th>Usuario</th>', $view);
        self::assertStringContainsString('<th>Nombre</th>', $view);
        self::assertStringNotContainsString('<th>Nombre a desplegar</th>', $view);
        self::assertStringNotContainsString('<div><span>Solicitante</span>', $view);
    }

    public function test_requester_columns_are_added_by_a_reversible_migration(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_08_08_130000_add_requester_to_nextcloud_history_batches.php'
        );

        self::assertIsString($migration);
        foreach (['solicitante_nombre', 'solicitante_rut', 'solicitante_correo'] as $column) {
            self::assertStringContainsString("'{$column}'", $migration);
        }
        self::assertStringContainsString('$table->dropColumn($columns)', $migration);
    }

    public function test_empty_legacy_requester_column_is_removed_reversibly(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_08_25_180000_drop_empty_legacy_requester_from_nextcloud_history.php'
        );
        $service = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/Services/MantencionNextcloudService.php'
        );

        self::assertIsString($migration);
        self::assertIsString($service);
        self::assertStringContainsString("\$table->dropColumn('solicitante')", $migration);
        self::assertStringContainsString("\$table->string('solicitante', 150)->nullable()", $migration);
        self::assertStringNotContainsString("'solicitante' => (string)(\$batch->solicitante", $service);
        self::assertStringNotContainsString("'solicitante' => \$batch['solicitante']", $service);
    }

    public function test_existing_batches_receive_a_persistent_correlative_number(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_08_25_160000_add_correlative_number_to_nextcloud_history_batches.php'
        );
        $service = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/Services/MantencionNextcloudService.php'
        );

        self::assertIsString($migration);
        self::assertIsString($service);
        self::assertStringContainsString("->orderBy('created_at_cl')", $migration);
        self::assertStringContainsString('MODIFY created_at_cl DATETIME NOT NULL', $migration);
        self::assertStringContainsString("'numero_lote' => \$index + 1", $migration);
        self::assertStringContainsString("'created_at_cl' => \$row->created_at_cl", $migration);
        self::assertStringContainsString("rm_nextcloud_lotes_numero_unique", $migration);
        self::assertStringContainsString("->orderByDesc('numero_lote')", $service);
        self::assertStringContainsString("'numero_lote' => \$batchNumber", $service);
    }

    public function test_redundant_legacy_id_is_removed_reversibly(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_08_25_190000_drop_redundant_legacy_id_from_nextcloud_history.php'
        );
        $service = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/Services/MantencionNextcloudService.php'
        );

        self::assertIsString($migration);
        self::assertIsString($service);
        self::assertStringContainsString("\$table->dropColumn('legacy_id')", $migration);
        self::assertStringContainsString("\$table->string('legacy_id', 32)->nullable()", $migration);
        self::assertStringNotContainsString("'legacy_id' => (string)\$batchNumber", $service);
        self::assertStringNotContainsString("\$batch->legacy_id", $service);
    }

    public function test_redundant_module_id_is_removed_reversibly(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_08_25_200000_drop_redundant_module_id_from_nextcloud_history.php'
        );
        $service = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/Services/MantencionNextcloudService.php'
        );

        self::assertIsString($migration);
        self::assertIsString($service);
        self::assertStringContainsString("\$table->dropConstrainedForeignId('modulo_id')", $migration);
        self::assertStringContainsString("\$table->foreignId('modulo_id')", $migration);
        self::assertStringNotContainsString("'modulo_id' => \$moduleId", $service);
        self::assertStringNotContainsString('function nextcloud_history_module_id', $service);
    }

    public function test_summary_exposes_status_counts_groups_and_short_detail(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/redmine-mantencion/integraciones-nextcloud-historial.blade.php'
        );

        self::assertIsString($view);
        foreach (['created_count', 'existing_count', 'failed_count', 'nextcloud-history-groups', 'nextcloud-history-detail'] as $needle) {
            self::assertStringContainsString($needle, $view);
        }
        self::assertStringContainsString('data-history-search=', $view);
        self::assertStringContainsString('data-history-groups=', $view);
        self::assertStringContainsString('visibleBatches', $view);
    }

    public function test_history_tables_are_compact_and_responsive(): void
    {
        $css = file_get_contents(
            dirname(__DIR__, 2).'/RedmineMantencion/assets/css/nextcloud-historial.css'
        );

        self::assertIsString($css);
        self::assertMatchesRegularExpression('/\.nextcloud-history-table\s*\{[^}]*width:\s*100%;[^}]*min-width:\s*0;[^}]*table-layout:\s*fixed;/s', $css);
        self::assertMatchesRegularExpression('/\.nextcloud-history-wrap\s*\{[^}]*overflow-x:\s*visible;/s', $css);
        self::assertMatchesRegularExpression('/\.nextcloud-history-detail-wrap\s*\{[^}]*overflow-x:\s*visible;/s', $css);
        self::assertMatchesRegularExpression('/\.nextcloud-history-open\.btn-nova\s*\{[^}]*width:\s*2\.35rem;[^}]*height:\s*2\.35rem;/s', $css);
        self::assertMatchesRegularExpression('/\.nextcloud-history-open\.btn-nova\s*>\s*i:first-child\s*\{[^}]*min-width:\s*0;[^}]*background:\s*transparent;/s', $css);
        self::assertStringContainsString('content: attr(data-label)', $css);
        self::assertStringContainsString('@media (max-width: 991.98px)', $css);
        self::assertStringContainsString('@media (max-width: 767.98px)', $css);
    }

    public function test_summary_groups_related_fields_into_five_columns(): void
    {
        $view = file_get_contents(
            dirname(__DIR__, 2).'/resources/views/redmine-mantencion/integraciones-nextcloud-historial.blade.php'
        );

        self::assertIsString($view);
        self::assertStringContainsString('<th>Solicitante / contacto</th>', $view);
        self::assertStringContainsString('<th>Grupos / detalle</th>', $view);
        self::assertStringContainsString('data-label="Solicitante / contacto"', $view);
        self::assertStringContainsString('data-label="Grupos / detalle"', $view);
        self::assertStringNotContainsString('<th>Contacto</th>', $view);
        self::assertStringNotContainsString("<th>Grupos</th>\n                <th>Detalle</th>", $view);
    }

    public function test_partial_navigation_loads_page_styles_and_history_script_before_rendering(): void
    {
        $root = dirname(__DIR__, 2);
        $navbar = file_get_contents($root.'/RedmineMantencion/views/partials/navbar.php');
        $view = file_get_contents($root.'/resources/views/redmine-mantencion/integraciones-nextcloud-historial.blade.php');

        self::assertIsString($navbar);
        self::assertIsString($view);
        self::assertStringContainsString('const syncPageStyles = async (doc, targetUrl)', $navbar);
        self::assertStringContainsString('await syncPageStyles(doc, url);', $navbar);
        self::assertStringContainsString("doc.querySelectorAll('script[data-partial-nav-script]')", $navbar);
        self::assertStringContainsString('<script data-partial-nav-script>', $view);
    }
}
