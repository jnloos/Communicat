<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SQLite creates no index for a foreign key, so every "the turns of one project" read
     * scanned the whole table. The statistics page asks that hundreds of times per answer
     * (the measured effect: a question opened 3x faster, an edit 3x faster on a copy of the
     * study database).
     */
    public function up(): void
    {
        Schema::table('job_logs', fn (Blueprint $table) => $table->index(['project_id', 'turn_index'], 'job_logs_project_turn_index'));
        Schema::table('messages', fn (Blueprint $table) => $table->index('project_id', 'messages_project_id_index'));
        Schema::table('project_contributors', function (Blueprint $table) {
            $table->index('project_id', 'project_contributors_project_id_index');
            $table->index(['contributor_type', 'contributor_id'], 'project_contributors_contributor_index');
        });
    }

    public function down(): void
    {
        Schema::table('project_contributors', function (Blueprint $table) {
            $table->dropIndex('project_contributors_contributor_index');
            $table->dropIndex('project_contributors_project_id_index');
        });
        Schema::table('messages', fn (Blueprint $table) => $table->dropIndex('messages_project_id_index'));
        Schema::table('job_logs', fn (Blueprint $table) => $table->dropIndex('job_logs_project_turn_index'));
    }
};
