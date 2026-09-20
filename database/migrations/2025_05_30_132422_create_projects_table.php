<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->longText('description');
            $table->string('model')->default('openai');
            $table->string('pipeline')->default('RoundRobinPipeline');
            $table->unsignedInteger('turn_budget')->nullable();
            $table->unsignedBigInteger('seed')->default(1);
            $table->json('run_config')->nullable();
            $table->longText('long_term_memory')->nullable();
            // Watermark only, compared with ">". Deliberately no foreign key:
            // projects → messages → projects would be a circular constraint.
            $table->unsignedBigInteger('summarized_until_message_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
