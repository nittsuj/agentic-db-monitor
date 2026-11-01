<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per tool call the assistant made while writing a reply.
 *
 * chat_message_id is a real foreign key (deleting the message, its session,
 * the user or the project deletes the log). loggable is polymorphic and says
 * which agent ran the tool: a query_diagnostician, metric_analyzer or
 * mutation_planner row. A polymorphic column cannot have a foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_execution_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('chat_message_id')->constrained()->cascadeOnDelete();
            $table->morphs('loggable');
            $table->string('tool_name');
            $table->json('arguments')->nullable();
            $table->longText('result')->nullable();
            $table->string('status');
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['chat_message_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_execution_logs');
    }
};
