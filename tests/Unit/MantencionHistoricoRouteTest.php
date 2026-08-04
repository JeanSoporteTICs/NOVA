<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MantencionHistoricoRouteTest extends TestCase
{
    public function test_native_history_posts_status_changes_to_its_named_route(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/views/native/history.blade.php');

        self::assertStringContainsString("route('redmine.mantencion.history.action')", $view);
        self::assertStringContainsString('name="action" value="update_redmine_status"', $view);
        self::assertStringContainsString('name="ids" id="history-status-ids"', $view);
        self::assertStringNotContainsString('historico.php', $view);
    }

    public function test_native_history_validates_a_selection_before_submitting(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/views/native/history.blade.php');

        self::assertStringContainsString('if(!selected.length)return;', $view);
        self::assertStringContainsString("document.getElementById('history-status-ids').value=selected.join(',')", $view);
    }

    public function test_native_history_loads_redmine_status_for_every_ticket_regardless_of_source(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/views/native/history.blade.php');
        $routes = (string) file_get_contents(dirname(__DIR__, 2).'/routes/web.php');

        self::assertStringContainsString('<th>Estado Redmine</th>', $view);
        self::assertStringContainsString("route('redmine.mantencion.history.statuses')", $view);
        self::assertStringContainsString('class="historico-redmine-status historico-redmine-status--syncing js-redmine-status"', $view);
        self::assertStringContainsString('payload.statuses?.[id]', $view);
        self::assertStringNotContainsString('isCore?data.core_estado:data.estado_redmine', $view);
        self::assertStringContainsString("'historyStatuses'", $routes);
        self::assertStringContainsString("name('redmine.mantencion.history.statuses')", $routes);
    }

    public function test_native_history_restores_the_individual_status_action(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__, 2).'/RedmineMantencion/views/native/history.blade.php');

        self::assertStringContainsString('btn-action btn-action-sync dropdown-toggle no-caret js-redmine-status-menu d-none', $view);
        self::assertStringContainsString('title="Cambiar estado en Redmine"', $view);
        self::assertStringContainsString('name="action" value="update_redmine_status"', $view);
        self::assertStringContainsString('name="ids" value="{{ $message[\'id\'] }}"', $view);
        self::assertStringContainsString("button.classList.toggle('d-none',!available||Boolean(status.closed))", $view);
    }
}
