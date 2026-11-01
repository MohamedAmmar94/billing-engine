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
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('number')->nullable()->unique();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->char('currency', 3)->default('USD');
            $table->bigInteger('subtotal')->default(0);
            $table->integer('tax_rate_bp')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('total')->default(0);
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->index('customer_id');
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
