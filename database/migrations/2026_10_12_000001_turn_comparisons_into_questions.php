<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A comparison becomes one question (Question::toArray()) instead of a
     * list of series and a view. The old rows cannot be translated into a
     * question and are dropped (spec 2026-10-12). A nameless question is
     * called by its sentence; a template is a question everyone may copy.
     */
    public function up(): void
    {
        DB::table('comparisons')->delete();

        Schema::table('comparisons', function (Blueprint $table) {
            $table->dropColumn(['series', 'view']);
        });

        Schema::table('comparisons', function (Blueprint $table) {
            $table->string('name', 100)->nullable()->change();
            $table->json('question')->nullable();
            $table->boolean('is_template')->default(false)->index();
        });
    }

    public function down(): void
    {
        DB::table('comparisons')->delete();

        Schema::table('comparisons', function (Blueprint $table) {
            $table->dropIndex(['is_template']);
            $table->dropColumn(['question', 'is_template']);
        });

        Schema::table('comparisons', function (Blueprint $table) {
            $table->string('name', 100)->nullable(false)->change();
            $table->json('series')->nullable();
            $table->json('view')->nullable();
        });
    }
};
