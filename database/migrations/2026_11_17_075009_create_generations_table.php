<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * All money columns hold whole nanodollars (1 USD = 1,000,000,000), so no
     * amount is ever a float and Pixelforge's nine-decimal costs fit exactly.
     */
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            // Deleting an account keeps its generations, so the books still match Pixelforge's.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Sent by the browser with each submission, so a double click or a replayed
            // request can only ever create one generation.
            $table->string('request_id', 64)->nullable();
            $table->string('pixelforge_id')->nullable()->unique();
            $table->string('status')->default('queued')->index();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            $table->unsignedBigInteger('estimated_min_nano');
            $table->unsignedBigInteger('estimated_max_nano');

            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->unsignedBigInteger('provider_cost_nano')->nullable();
            $table->unsignedBigInteger('charged_nano')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'completed_at']);
            $table->unique(['user_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
