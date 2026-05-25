<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expert memory is one record per (project, expert); per-participant notes
     * live INSIDE the memory text as [NUTZER: <Name>] / [EXPERTE: <Name>]
     * blocks, not as separate rows. The user_id column from the abandoned
     * per-user-thread attempt is therefore obsolete.
     */
    public function up(): void
    {
        Schema::table('summaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('summaries', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('expert_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
        });
    }
};
