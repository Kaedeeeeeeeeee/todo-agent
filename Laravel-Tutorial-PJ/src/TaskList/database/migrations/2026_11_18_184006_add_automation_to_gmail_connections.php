<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gmail_connections', function (Blueprint $table) {
            $table->boolean('schedule_enabled')->default(false);
            $table->string('schedule_time', 5)->default('09:00');
            $table->string('schedule_scope', 30)->default('BIZMATES');
            $table->timestamp('next_run_at')->nullable()->index();
            $table->json('scan_state')->nullable();
        });
        Schema::table('task_extractions', function (Blueprint $table) {
            $table->string('trigger', 20)->default('manual');
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->uuid('retry_of')->nullable();
            $table->timestamp('retry_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('scan_options')->nullable();
            $table->boolean('has_more')->default(false);
        });
        Schema::create('gmail_processed_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 30);
            $table->string('message_id', 64);
            $table->timestamp('processed_at');
            $table->unique(['user_id', 'scope', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_processed_messages');
        Schema::table('task_extractions', function (Blueprint $table) {
            $table->dropColumn(['trigger', 'attempt', 'retry_of', 'retry_at', 'completed_at', 'skipped_count', 'scan_options', 'has_more']);
        });
        Schema::table('gmail_connections', function (Blueprint $table) {
            $table->dropColumn(['schedule_enabled', 'schedule_time', 'schedule_scope', 'next_run_at', 'scan_state']);
        });
    }
};
