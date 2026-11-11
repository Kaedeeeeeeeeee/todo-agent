<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_extractions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 30);
            $table->string('status', 30)->default('reading');
            $table->unsignedInteger('read_count')->default(0);
            $table->unsignedInteger('candidate_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_extractions');
    }
};
