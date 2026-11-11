<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('task_extraction_id')->constrained()->cascadeOnDelete();
            $table->string('message_id', 64);
            $table->text('subject');
            $table->text('sender');
            $table->string('received_at', 50);
            $table->string('title', 100);
            $table->text('description');
            $table->text('reason');
            $table->text('evidence');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_candidates');
    }
};
