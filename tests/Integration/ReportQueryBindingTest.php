<?php

namespace Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RedmineTic\Repositories\RedmineReportRepository;

final class ReportQueryBindingTest extends IsolatedMariaDbTestCase
{
    public function test_notification_queries_use_text_identity_and_existing_index(): void
    {
        Schema::create('modulos_nova', function (Blueprint $t): void {
            $t->id();
        });
        Schema::create('redmine_tic_reportes', function (Blueprint $t): void {
            $t->id();
            $t->integer('modulo_id');
            $t->string('asignado_a', 80)->index();
            $t->string('estado_redmine')->nullable();
            $t->integer('redmine_id')->nullable();
            $t->dateTime('creado_at');
        });
        foreach (['123', '0123', '123x', '124'] as $index => $assignee) {
            foreach (['Nueva', null] as $status) {
                DB::table('redmine_tic_reportes')->insert([
                    'modulo_id' => 1, 'asignado_a' => $assignee, 'estado_redmine' => $status, 'redmine_id' => $index + 10, 'creado_at' => '2026-09-01 00:00:00',
                ]);
            }
        }
        $repo = new RedmineReportRepository('redmine_tic', 'TIC');
        $start = new \DateTimeImmutable('2026-09-01');
        $end = new \DateTimeImmutable('2026-09-02');
        DB::enableQueryLog();
        self::assertSame(['10'], $repo->staleNewIssueIdsForAssignee(1, ' 123 ', $start, $end));
        self::assertSame(1, $repo->unsyncedIssueCountForAssignee(1, '123', $start, $end));
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            if (str_contains($query['query'], '`asignado_a` = ?')) {
                self::assertSame('123', $query['bindings'][1]);
            }
        }
        // Existing validation remains unchanged; identifiers are not normalized to integers.
        foreach (['0123', '123x', '0', ''] as $invalid) {
            self::assertSame([], $repo->staleNewIssueIdsForAssignee(1, $invalid, $start, $end));
        }
        $numeric = DB::selectOne('EXPLAIN SELECT id FROM redmine_tic_reportes WHERE asignado_a = ?', [123]);
        $textual = DB::selectOne('EXPLAIN SELECT id FROM redmine_tic_reportes WHERE asignado_a = ?', ['123']);
        self::assertSame('ref', $textual->type);
        self::assertSame('index', $numeric->type);
    }
}
