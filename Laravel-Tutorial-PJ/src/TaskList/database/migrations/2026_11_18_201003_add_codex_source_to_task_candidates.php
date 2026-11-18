<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_candidates', function (Blueprint $table) {
            $table->string('source_type', 20)->default('GMAIL')->index();
            $table->string('source_url', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_candidates', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'source_url']);
        });
    }
};
