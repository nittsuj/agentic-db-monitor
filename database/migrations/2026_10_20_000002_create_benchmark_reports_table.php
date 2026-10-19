<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('benchmark_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('engine');
            $table->string('config');
            $table->string('scenario');
            $table->unsignedInteger('cases');
            $table->unsignedInteger('correct');
            $table->double('accuracy');
            $table->json('details');
            $table->timestamps();

            $table->index(['engine', 'config', 'scenario']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('benchmark_reports');
    }
};
