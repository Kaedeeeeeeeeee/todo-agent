<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_extractions', function (Blueprint $table) {
            $table->string('stage', 20)->default('legacy');
            $table->uuid('batch_id')->nullable();
            $table->timestamp('batch_started_at')->nullable();
            $table->timestamp('next_batch_at')->nullable();
            $table->json('mail_plan')->nullable();
            $table->json('listing_tokens')->nullable();
            $table->unsignedInteger('total_count')->nullable();
            $table->unsignedInteger('scanned_count')->default(0);
            $table->unsignedInteger('batch_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('task_extractions', function (Blueprint $table) {
            $table->dropColumn(['stage', 'batch_id', 'batch_started_at', 'next_batch_at', 'mail_plan', 'listing_tokens', 'total_count', 'scanned_count', 'batch_count']);
        });
    }
};
