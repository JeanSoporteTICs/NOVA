<?php

namespace Tests\Integration;

use App\Repositories\Redmine\SendAttemptRepository;
use App\Services\Redmine\SendAttemptReconciler;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RedmineSendReservationTest extends IsolatedMariaDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('modulos_nova', fn (Blueprint $t) => $t->id());
        DB::table('modulos_nova')->insert([['id' => 1], ['id' => 2]]);
        (require dirname(__DIR__, 2).'/database/migrations/2026_09_14_000000_create_redmine_send_attempts.php')->up();
    }

    public function test_second_connection_cannot_send_reserved_report_but_other_reports_and_modules_can(): void
    {
        $repo = new SendAttemptRepository;
        $first = $repo->reserve(1, 'tic:1', microtime(true), fn () => true);
        self::assertTrue($first['ok']);
        self::assertSame(0, DB::transactionLevel(), 'No transaction may span the HTTP call');
        DB::setDefaultConnection('concurrent');
        $second = $repo->reserve(1, 'tic:1', microtime(true), fn () => true);
        self::assertFalse($second['ok']);
        self::assertTrue($repo->reserve(1, 'tic:2', microtime(true), fn () => true)['ok']);
        self::assertTrue($repo->reserve(2, 'tic:1', microtime(true), fn () => true)['ok']);
        self::assertSame(3, DB::table(SendAttemptRepository::TABLE)->count());
        DB::setDefaultConnection('mysql');
    }

    public function test_confirmed_attempt_allows_sequential_resend_but_not_overlapping_request(): void
    {
        $repo = new SendAttemptRepository;
        $started = microtime(true);
        $attempt = $repo->reserve(1, 'tic:1', $started, fn () => true)['attempt_id'];
        self::assertTrue($repo->recordResponse($attempt, ['http_code' => 201, 'body' => '{"issue":{"id":123}}']));
        self::assertFalse($repo->reserve(1, 'tic:1', microtime(true), fn () => true)['ok']);
        self::assertTrue($repo->finish($attempt));
        self::assertFalse($repo->reserve(1, 'tic:1', $started, fn () => true)['ok']);
        self::assertTrue($repo->reserve(1, 'tic:1', microtime(true), fn () => true)['ok']);
        self::assertSame(2, DB::table(SendAttemptRepository::TABLE)->count());
    }

    public function test_timeout_server_error_and_malformed_success_remain_reserved(): void
    {
        $repo = new SendAttemptRepository;
        foreach ([['http_code' => 0, 'body' => '', 'error' => 'timeout'], ['http_code' => 503, 'body' => ''], ['http_code' => 201, 'body' => '{}']] as $i => $response) {
            $key = 'tic:'.$i;
            $attempt = $repo->reserve(1, $key, microtime(true), fn () => true)['attempt_id'];
            self::assertTrue($repo->recordResponse($attempt, $response));
            self::assertFalse($repo->finish($attempt));
            self::assertFalse($repo->reserve(1, $key, microtime(true), fn () => true)['ok']);
            self::assertSame('uncertain', DB::table(SendAttemptRepository::TABLE)->where('attempt_id', $attempt)->value('status'));
        }
    }

    public function test_definitive_rejection_can_be_retried_and_changed_report_cannot_be_reserved(): void
    {
        $repo = new SendAttemptRepository;
        self::assertFalse($repo->reserve(1, 'tic:1', microtime(true), fn () => false)['ok']);
        $attempt = $repo->reserve(1, 'tic:1', microtime(true), fn () => true)['attempt_id'];
        self::assertTrue($repo->recordResponse($attempt, ['http_code' => 422, 'body' => '{"errors":["Missing subject"]}']));
        self::assertTrue($repo->finish($attempt));
        self::assertTrue($repo->reserve(1, 'tic:1', microtime(true), fn () => true)['ok']);
    }

    public function test_reconciliation_records_ticket_atomically_and_preserves_archived_state(): void
    {
        Schema::create('redmine_tic_reportes', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('modulo_id');
            $t->string('estado');
            $t->unsignedBigInteger('redmine_id')->nullable();
            $t->dateTime('procesado_at')->nullable();
            $t->dateTime('actualizado_at')->nullable();
        });
        DB::table('redmine_tic_reportes')->insert(['id' => 1, 'modulo_id' => 1, 'estado' => 'archivado']);
        $repo = new SendAttemptRepository;
        $attempt = $repo->reserve(1, 'tic:1', microtime(true), fn () => true)['attempt_id'];
        $repo->recordResponse($attempt, ['http_code' => 201, 'body' => '{"issue":{"id":321}}']);
        DB::unprepared("CREATE TRIGGER fail_reconcile BEFORE UPDATE ON redmine_tic_reportes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic failure'");
        try {
            (new SendAttemptReconciler)->resolve($attempt, 321, 'Operator test');
            self::fail('Expected rollback');
        } catch (QueryException) {
        }
        self::assertSame(1, (int) DB::table(SendAttemptRepository::TABLE)->value('reservation'));
        DB::unprepared('DROP TRIGGER fail_reconcile');
        (new SendAttemptReconciler)->resolve($attempt, 321, 'Operator test');
        self::assertSame('archivado', DB::table('redmine_tic_reportes')->value('estado'));
        self::assertSame(321, (int) DB::table('redmine_tic_reportes')->value('redmine_id'));
        self::assertNull(DB::table(SendAttemptRepository::TABLE)->value('reservation'));
    }

    public function test_migration_can_be_reverted_on_disposable_database(): void
    {
        (require dirname(__DIR__, 2).'/database/migrations/2026_09_14_000000_create_redmine_send_attempts.php')->down();
        self::assertFalse(Schema::hasTable(SendAttemptRepository::TABLE));
        self::assertFalse((new SendAttemptRepository)->reserve(1,'tic:1',microtime(true),fn () => true)['ok']);
    }
}
