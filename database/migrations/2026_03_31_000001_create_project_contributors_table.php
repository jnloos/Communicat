<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('expert_project');
        Schema::dropIfExists('user_project');

        Schema::create('project_contributors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('contributor_type');
            $table->unsignedBigInteger('contributor_id');
            $table->timestamps();
            $table->unique(['project_id', 'contributor_type', 'contributor_id'], 'project_contributor_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_contributors');

        Schema::create('expert_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('expert_id')->constrained('experts')->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['project_id', 'expert_id']);
        });

        Schema::create('user_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
        });
    }
};