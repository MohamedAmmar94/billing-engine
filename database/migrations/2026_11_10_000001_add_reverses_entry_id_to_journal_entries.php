<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            // القيد ينعكس مرة واحدة بس: الـ UNIQUE هو اللي بيضمن ده
            $table->foreignId('reverses_entry_id')->nullable()->unique()
                ->constrained('journal_entries')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reverses_entry_id');
        });
    }
};
