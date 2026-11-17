<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user's balance is the sum of their rows here: credits are positive,
     * charges negative. The unique (generation_id, type) index means a
     * generation can be charged at most once, even if code ran twice.
     */
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->bigInteger('amount_nano');
            $table->foreignUlid('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            // Lets a top-up be retried safely: the same key never credits twice.
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->unique(['generation_id', 'type']);
            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
