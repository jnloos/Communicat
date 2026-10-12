<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Free text beside the title: what the question asks and what the researcher concluded. */
    public function up(): void
    {
        Schema::table('comparisons', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('comparisons', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
