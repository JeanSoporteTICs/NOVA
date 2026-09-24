<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redmine_send_attempts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('modulo_id');
            $table->string('report_key', 191);
            $table->uuid('attempt_id')->unique();
            // NULL releases the reservation while retaining the attempt for reconciliation.
            $table->unsignedTinyInteger('reservation')->nullable();
            $table->string('status', 30);
            $table->unsignedBigInteger('redmine_id')->nullable();
            $table->unsignedSmallInteger('http_code')->nullable();
            $table->decimal('finished_epoch', 20, 6)->nullable();
            $table->string('resolution_note', 500)->nullable();
            $table->timestamps(6);
            $table->unique(['modulo_id', 'report_key', 'reservation'], 'redmine_send_reservation_unique');
            $table->index(['modulo_id', 'report_key', 'id']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('redmine_send_attempts') && \Illuminate\Support\Facades\DB::table('redmine_send_attempts')->exists()) {
            throw new \RuntimeException('Conservar el registro de intentos: concilia y respalda su contenido antes de retirar esta estructura.');
        }
        Schema::dropIfExists('redmine_send_attempts');
    }
};
