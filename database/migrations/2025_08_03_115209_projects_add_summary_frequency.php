<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void {
        Schema::table('projects', function (Blueprint $table) {
            $table->integer('summary_frequency')->after('description')->default(10);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('summary_frequency');
        });
    }
};
