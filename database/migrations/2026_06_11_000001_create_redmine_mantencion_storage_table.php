<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('redmine_mantencion_storage', function (Blueprint $table): void {
            $table->id();
            $table->string('path', 255)->unique();
            $table->string('content_type', 40)->default('json');
            $table->longText('payload_json')->nullable();
            $table->longText('payload_text')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->char('checksum', 64)->nullable();
            $table->timestamp('source_mtime')->nullable();
            $table->timestamps();

            $table->index('content_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redmine_mantencion_storage');
    }
};
