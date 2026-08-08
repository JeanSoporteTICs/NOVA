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
        self::assertStringNotContainsString('</i> Ver', $view);
        self::assertStringContainsString('data-bs-toggle="modal"', $view);
        self::assertStringContainsString('modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable', $view);
        self::assertStringContainsString('nextcloud-history-detail-table', $view);
        self::assertStringNotContainsString('data-copy-table', $view);
        self::assertStringNotContainsString('Copiar tabla', $view);
        foreach (['solicitante_nombre', 'solicitante_rut', 'solicitante_correo'] as $field) {
            self::assertStringContainsString($field, $view);
        }
        self::assertStringContainsString('Nombre del solicitante', $view);
        self::assertStringNotContainsString('<div><span>Solicitante</span>', $view);
    }

    public function test_requester_columns_are_added_by_a_reversible_migration(): void
    {
        $migration = file_get_contents(
            dirname(__DIR__, 2).'/database/migrations/2026_08_08_130000_add_requester_to_nextcloud_history_batches.php'
        );

        self::assertIsString($migration);
        foreach (['solicitante', 'solicitante_nombre', 'solicitante_rut', 'solicitante_correo'] as $column) {
            self::assertStringContainsString("'{$column}'", $migration);
        }
        self::assertStringContainsString('$table->dropColumn($columns)', $migration);
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
