<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_logs', function (Blueprint $table) {
            $table->id();
            $table->string('job_class');
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->enum('status', ['running', 'success', 'failed'])->default('running');
            $table->json('payload')->nullable();
            $table->unsignedInteger('turn_index')->nullable();
            $table->foreignId('expert_id')->nullable()->constrained('experts')->nullOnDelete();
            $table->unsignedSmallInteger('seat')->nullable();
            $table->unsignedInteger('words')->nullable();
            $table->unsignedInteger('chars')->nullable();
            $table->unsignedInteger('thought_words')->nullable();
            $table->unsignedInteger('thought_chars')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            $table->json('selection')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_logs');
    }
};
