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
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('payment_link_id')->nullable()->unique()
                ->constrained('payment_links')->restrictOnDelete();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->bigInteger('amount');
            $table->char('currency', 3)->default('USD');
            $table->boolean('is_applied')->default(true);
            $table->timestampTz('paid_at');
            $table->timestampsTz();

            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
