<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            // Sender: exactly one of expert/user is set; both null = system message.
            $table->foreignId('expert_id')->nullable()->constrained('experts')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('job_log_id')->nullable()->constrained('job_logs')->nullOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete()->cascadeOnUpdate();

            $table->text('content');

            // Whom this contribution addresses. Only a contributing expert can be:
            // the chat has no composer, and Speak drops a U token. Null means the
            // turn spoke to the group.
            $table->foreignId('addressee_expert_id')->nullable()->constrained('experts')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
