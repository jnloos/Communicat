<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A drawer in one person's sidebar. Groups are private: they belong to a
        // user, never to a project, so two people sorting the same shared project
        // never see each other's filing.
        Schema::create('project_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            // Per person, not globally: two users may both keep a "Pilotläufe".
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_groups');
    }
};
