<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per /api/chat-ai call, successful or failed.
 *
 * Deliberate exception to the app-wide HasUuids convention: the API contract
 * returns this row's key as an integer `session_id`, so the table uses a
 * bigint auto-increment id. user_id is bigint too because users.id is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('prompt');
            $table->longText('ai_response')->nullable();
            $table->string('provider');
            $table->string('model_used');
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->boolean('is_streamed')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_logs');
    }
};
