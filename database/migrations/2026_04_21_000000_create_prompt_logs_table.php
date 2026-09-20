<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_log_id')->nullable()->constrained('job_logs')->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('model');
            $table->longText('prompt');
            $table->longText('response');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('provider')->nullable();
            $table->string('checkpoint')->nullable();
            $table->json('config')->nullable();
            $table->string('purpose')->nullable();
            $table->foreignId('expert_id')->nullable()->constrained('experts')->nullOnDelete();
            $table->longText('reasoning')->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->unsignedInteger('tokens_reasoning')->nullable();
            $table->string('status')->default('ok');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('job_log_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_logs');
    }
};
