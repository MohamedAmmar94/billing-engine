<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_balances', function (Blueprint $table): void {
            $table->unsignedBigInteger('account_id')->primary();
            $table->bigInteger('debit_total')->default(0);
            $table->bigInteger('credit_total')->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_balances');
    }
};
