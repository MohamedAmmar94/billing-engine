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
        Schema::create('payment_links', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->integer('attempt');
            $table->string('stripe_session_id')->nullable()->unique();
            $table->text('url')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('response')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['invoice_id', 'attempt']);
            $table->index(['status', 'expires_at']);
        });

        // لينك حي واحد (pending أو open) لكل فاتورة، والـ DB هي اللي بتفرضها
        DB::statement("CREATE UNIQUE INDEX payment_links_one_live_per_invoice
    ON payment_links (invoice_id) WHERE status IN ('pending', 'open')");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_links');
    }
};
