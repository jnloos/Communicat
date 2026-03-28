<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['summary_frequency', 'prompting_strategy']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->json('settings')->default('{}')->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('settings');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->integer('summary_frequency')->after('description')->default(10);
            $table->string('prompting_strategy')->after('summary_frequency')
                ->default('App\\Services\\MultiplePrompting');
        });
    }
};
