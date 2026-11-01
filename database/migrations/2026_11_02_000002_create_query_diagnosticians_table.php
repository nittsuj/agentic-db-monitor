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
        Schema::create('query_diagnosticians', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description');
            $table->json('handles');
            $table->timestamps();
        });

        DB::table('query_diagnosticians')->insert([
            'slug' => 'query-diagnostician',
            'name' => 'Query Diagnostician',
            'description' => 'Runs the read-only diagnostic checks on the target database: tables, schemas, slow queries, the connection pool and table bloat.',
            'handles' => json_encode(['list_tables', 'get_table_schema', 'get_slow_queries', 'check_connection_pool', 'analyze_table_bloat']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('query_diagnosticians');
    }
};
