<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider')->default('ollama');
            $table->string('model')->nullable();
            $table->string('base_url')->nullable();
            $table->text('api_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
