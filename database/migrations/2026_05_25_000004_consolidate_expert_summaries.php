<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The short-lived per-(project, expert, user) split fragmented each expert's
     * memory across users, leaving empty threads. Expert memory is one record per
     * (project, expert) again: keep the richest content per group, drop the rest,
     * and null the user_id on survivors. The user_id column is kept for the
     * upcoming per-user (participant) memory work.
     */
    public function up(): void
    {
        $groups = DB::table('summaries')
            ->get()
            ->groupBy(fn ($r) => $r->project_id . '-' . $r->expert_id);

        foreach ($groups as $rows) {
            $best = $rows->sortByDesc(fn ($r) => strlen((string) ($r->content ?? '')))->first();

            $deleteIds = $rows->pluck('id')->reject(fn ($id) => $id === $best->id)->all();
            if (! empty($deleteIds)) {
                DB::table('summaries')->whereIn('id', $deleteIds)->delete();
            }

            DB::table('summaries')->where('id', $best->id)->update(['user_id' => null]);
        }
    }

    public function down(): void
    {
        // Irreversible: the dropped rows were empty duplicates.
    }
};
