<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-project summarization parameters. Nullable: a project that names
        // neither number falls back to the configured defaults, so existing rows
        // keep behaving exactly as before.
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedInteger('summarize_threshold')->nullable()->after('seed');
            $table->unsignedInteger('summarize_oldest')->nullable()->after('summarize_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['summarize_threshold', 'summarize_oldest']);
        });
    }
};
