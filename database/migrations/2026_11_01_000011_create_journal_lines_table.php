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
        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->index('journal_entry_id');
            $table->index(['account_id', 'journal_entry_id']);
        });

        // واحد من الاتنين > 0 والتاني = 0
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side_positive
    CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
