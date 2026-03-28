<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('contributor_project');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['contributor_id']);
            $table->dropColumn('contributor_id');
            $table->foreignId('expert_id')->nullable()->after('id')->constrained('experts')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('user_id')->nullable()->after('expert_id')->constrained('users')->nullOnDelete()->cascadeOnUpdate();
        });

        Schema::dropIfExists('contributors');

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

    public function down(): void
    {
        Schema::dropIfExists('user_project');
        Schema::dropIfExists('expert_project');

        Schema::create('contributors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('expert_id')->nullable()->constrained('experts')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('contributor_project', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('contributor_id')->constrained('contributors')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'contributor_id']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['expert_id']);
            $table->dropForeign(['user_id']);
            $table->dropColumn(['expert_id', 'user_id']);
            $table->foreignId('contributor_id')->constrained('contributors')->cascadeOnDelete();
        });
    }
};
