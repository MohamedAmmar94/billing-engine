<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('key', 255);
            $table->char('request_hash', 64);
            $table->string('lock_token', 36);
            $table->smallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->timestampTz('locked_at');
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['user_id', 'key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
