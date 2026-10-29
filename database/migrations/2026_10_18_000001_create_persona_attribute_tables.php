<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Managed persona attributes (role, origin, gender, ...), the value each expert
     * carries and what each project reveals of each attribute (none | own | all;
     * a missing row reads as none). Name uniqueness ignoring case and surrounding
     * space is checked in the application (PersonaAttribute::nameTaken()), like the
     * tag names. The role is an attribute too: PersonaAttribute::role() creates it.
     */
    public function up(): void
    {
        Schema::create('persona_attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('self_template');
            $table->string('roster_template');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('expert_persona_attribute', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expert_id')->constrained()->cascadeOnDelete();
            $table->foreignId('persona_attribute_id')->constrained()->cascadeOnDelete();
            $table->string('value');
            $table->unique(['expert_id', 'persona_attribute_id']);
        });

        Schema::create('project_attribute_visibility', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('persona_attribute_id')->constrained()->cascadeOnDelete();
            $table->string('visibility');
            // The generated name is 67 characters; MySQL allows 64.
            $table->unique(['project_id', 'persona_attribute_id'], 'project_attribute_visibility_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_attribute_visibility');
        Schema::dropIfExists('expert_persona_attribute');
        Schema::dropIfExists('persona_attributes');
    }
};
