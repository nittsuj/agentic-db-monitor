<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A chat session belongs to the user who chats in it. Nullable only for
 * sessions created before there were users; new sessions always get one.
 * Deleting a user deletes their sessions, and through the existing cascade
 * on chat_messages, their messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('project_id')->constrained()->cascadeOnDelete();
            $table->index(['user_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'project_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
