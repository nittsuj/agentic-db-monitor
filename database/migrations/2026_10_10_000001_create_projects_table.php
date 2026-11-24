<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            // Sized so the unique index below fits MySQL's 3072-byte key limit
            // in utf8mb4: 253 is the longest hostname, 64 the longest database
            // and user name MySQL and PostgreSQL accept.
            $table->string('driver', 16);
            $table->string('host', 253);
            $table->unsignedSmallInteger('port');
            $table->string('database', 64);
            $table->string('username', 64);
            $table->text('password');
            $table->json('options')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['driver', 'host', 'port', 'database', 'username']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
