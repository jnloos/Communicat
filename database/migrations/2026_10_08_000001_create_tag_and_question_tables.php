<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Global tags and the statistics page's saved questions.
     *
     * Tags: categories with fixed values, one set for personas and one for
     * projects. They describe a run after the fact and never reach a model.
     * taggables carries the category as well as the value, so the unique key
     * (taggable, category) lets the database itself enforce "at most one value
     * per category". Deleting a value or a category cascades to its
     * assignments. The tagged row itself has no foreign key (it is
     * polymorphic); HasTags removes its assignments when the model is deleted.
     *
     * comparisons: one saved question per row (Question::toArray() in
     * `question`), with an optional title (`name`; null reads as "Untitled
     * question") and the researcher's free text (`notes`). A template is the
     * same row with `is_template` set: everyone may open and copy it, only its
     * owner renames or deletes it.
     */
    public function up(): void
    {
        Schema::create('tag_categories', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 20);
            $table->string('name', 50);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['scope', 'name']);
        });

        Schema::create('tag_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_category_id')->constrained('tag_categories')->cascadeOnDelete();
            $table->string('name', 50);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['tag_category_id', 'name']);
        });

        Schema::create('taggables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_value_id')->constrained('tag_values')->cascadeOnDelete();
            $table->foreignId('tag_category_id')->constrained('tag_categories')->cascadeOnDelete();
            $table->string('taggable_type');
            $table->unsignedBigInteger('taggable_id');
            $table->timestamps();
            $table->unique(['taggable_type', 'taggable_id', 'tag_category_id']);
        });

        Schema::create('comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100)->nullable();
            $table->text('notes')->nullable();
            $table->json('question')->nullable();
            $table->boolean('is_template')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comparisons');
        Schema::dropIfExists('taggables');
        Schema::dropIfExists('tag_values');
        Schema::dropIfExists('tag_categories');
    }
};
