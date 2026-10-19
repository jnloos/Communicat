<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** none | own | all: how much of the personas' attributes the prompts reveal. */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('attribute_visibility')->default('none');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('attribute_visibility');
        });
    }
};
