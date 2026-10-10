<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('chat_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('engine');
            $table->string('category');
            $table->text('command');
            $table->string('status')->default('pending');
            $table->json('meta')->nullable();
            $table->timestamp('proposed_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_actions');
    }
};
