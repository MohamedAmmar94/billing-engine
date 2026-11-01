<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->bigInteger('last_value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
