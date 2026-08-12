<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Watermark: the newest message that has been folded into this expert's memory.
 *
 * Memory is only written when an expert is picked to THINK, so rarely-picked
 * personas fell arbitrarily far behind — in the logged production project two of
 * four experts still held a 35-character memory after 80 messages. The watermark
 * makes that backlog measurable so RunExpertsThink can send the most stale
 * persona through a catch-up THINK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('summaries', function (Blueprint $table) {
            $table->unsignedBigInteger('last_message_id')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('summaries', function (Blueprint $table) {
            $table->dropColumn('last_message_id');
        });
    }
};
