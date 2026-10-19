<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Managed persona attributes (origin, gender, ...) and the value each expert
     * carries. Name uniqueness ignoring case and surrounding space is checked in
     * the application (PersonaAttribute::nameTaken()), like the tag names.
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
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_persona_attribute');
        Schema::dropIfExists('persona_attributes');
    }
};
