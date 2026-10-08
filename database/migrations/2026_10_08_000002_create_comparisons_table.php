<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A saved comparison on the statistics page: personal, like a sidebar
     * group. series holds SeriesDefinition::toArray() per series, view
     * ComparisonView::toArray(). Tag values are referenced by id, so renaming
     * a value never breaks a saved filter; a deleted one drops out on load.
     */
    public function up(): void
    {
        Schema::create('comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('series');
            $table->json('view');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comparisons');
    }
};
