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
        Schema::create('metric_analyzers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description');
            $table->json('handles');
            $table->timestamps();
        });

        DB::table('metric_analyzers')->insert([
            'slug' => 'metric-analyzer',
            'name' => 'Metric Analyzer',
            'description' => 'Summarises the collected metrics of the project over a time window.',
            'handles' => json_encode(['get_metric_summary']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_analyzers');
    }
};
