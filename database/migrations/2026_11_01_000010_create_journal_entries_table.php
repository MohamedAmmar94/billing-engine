<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->timestampTz('posted_at');
            $table->string('description');
            $table->char('currency', 3)->default('USD');
            $table->string('source_type', 30);
            $table->bigInteger('source_id');
            $table->string('event', 30);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['source_type', 'source_id', 'event']);
            $table->index('posted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
