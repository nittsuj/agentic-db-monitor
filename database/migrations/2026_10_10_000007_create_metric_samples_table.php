<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_samples', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->double('value');
            $table->timestamp('captured_at');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'key', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_samples');
    }
};
