<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expert memory is now kept per user: a separate Summary thread per
     * (project, expert, user). user_id is nullable to preserve a shared/legacy
     * bucket and to degrade gracefully when no human has spoken yet.
     */
    public function up(): void
    {
        Schema::table('summaries', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('expert_id')
                ->constrained('users')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
        });

        // Backfill existing per-project memories onto the project owner so they
        // are not orphaned under the new per-user keying.
        DB::table('summaries')
            ->whereNull('user_id')
            ->update([
                'user_id' => DB::raw('(select user_id from projects where projects.id = summaries.project_id)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('summaries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
