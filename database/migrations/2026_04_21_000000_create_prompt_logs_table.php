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
            $table->timestamps();

            $table->index('job_log_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_logs');
    }
};
