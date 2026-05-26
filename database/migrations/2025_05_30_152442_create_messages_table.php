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

            // Adjacency metadata: the typed pair label plus the polymorphic
            // addressee (expert or user). A User partner is a hand-back; the
            // partner is set from the SPEAK output or the moderator hand-off.
            $table->string('adjacency_pair_type', 50)->nullable();
            $table->nullableMorphs('adjacency_partner');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
