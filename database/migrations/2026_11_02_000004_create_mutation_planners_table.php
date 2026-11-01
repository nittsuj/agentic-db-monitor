<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One of the agents a tool execution log can point to (tool_execution_logs.loggable).
 * The row is inserted here because the app needs it at runtime, not only in seeded data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mutation_planners', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description');
            $table->json('handles');
            $table->timestamps();
        });

        DB::table('mutation_planners')->insert([
            'slug' => 'mutation-planner',
            'name' => 'Mutation Planner',
            'description' => 'Queues a database change the user asked for as a pending action that needs approval. It never runs SQL itself.',
            'handles' => json_encode(['propose_mutation']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('mutation_planners');
    }
};
