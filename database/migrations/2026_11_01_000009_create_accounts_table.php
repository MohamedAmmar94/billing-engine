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
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 10)->unique();
            $table->string('name');
            $table->string('type', 20);
            $table->string('normal_side', 6);
            $table->char('currency', 3)->default('USD');
            $table->boolean('is_postable')->default(true);
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
