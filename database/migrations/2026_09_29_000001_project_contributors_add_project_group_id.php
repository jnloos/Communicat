<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The group hangs on the pivot row, not on the project.
     *
     * A row in project_contributors already says "this contributor belongs to
     * this project"; the group on top of it says "and this is how *this user*
     * files it". That is what lets one shared project sit in two different
     * groups for two different people — the core requirement, and impossible
     * with a column on projects.
     *
     * The same table already carries `seat`, which only ever applies to expert
     * rows; `project_group_id` only ever applies to user rows. The pattern is
     * established, and the existing unique(project_id, contributor_type,
     * contributor_id) guarantees for free that a person files a project into at
     * most one group.
     *
     * nullOnDelete: deleting a group must not take its projects with it — they
     * simply fall back into the ungrouped list.
     */
    public function up(): void
    {
        Schema::table('project_contributors', function (Blueprint $table) {
            $table->foreignId('project_group_id')->nullable()->constrained('project_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_contributors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_group_id');
        });
    }
};
