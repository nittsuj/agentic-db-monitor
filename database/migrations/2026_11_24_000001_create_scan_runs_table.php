<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One background schema scan of a project's database, written to as it runs
 * so the page can poll its progress.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->string('current_table')->nullable();
            $table->json('findings')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_runs');
    }
};
