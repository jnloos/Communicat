<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single polymorphic pivot: experts AND users join a project through the
        // same table (contributor_type + contributor_id).
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
    }
};
