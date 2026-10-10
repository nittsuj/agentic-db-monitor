<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_name');
            $table->string('engine');
            $table->foreignUuid('pending_action_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('chat_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('decision');
            $table->text('command');
            $table->string('actor')->default('user');
            $table->text('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('action_logs');
    }
};
