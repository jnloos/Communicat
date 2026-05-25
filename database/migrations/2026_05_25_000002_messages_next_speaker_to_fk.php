<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('next_speaker_expert_id')->nullable()->after('next_speaker')
                ->constrained('experts')->nullOnDelete();
            $table->foreignId('next_speaker_user_id')->nullable()->after('next_speaker_expert_id')
                ->constrained('users')->nullOnDelete();
        });

        // Backfill: the only value the pipeline ever wrote was the 'Nutzer'
        // sentinel (hand back to the human) → resolve it to the project owner.
        // Any stray expert-name values are left null (none occur in practice).
        DB::table('messages')
            ->whereNotNull('next_speaker')
            ->where('next_speaker', '!=', '')
            ->orderBy('id')
            ->get(['id', 'project_id', 'next_speaker'])
            ->each(function ($m) {
                if (in_array(mb_strtolower(trim($m->next_speaker)), ['nutzer', 'user'], true)) {
                    $ownerId = DB::table('projects')->where('id', $m->project_id)->value('user_id');
                    if ($ownerId) {
                        DB::table('messages')->where('id', $m->id)
                            ->update(['next_speaker_user_id' => $ownerId]);
                    }
                }
            });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('next_speaker');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('next_speaker', 100)->nullable()->after('adjacency_pair_type');
            $table->dropConstrainedForeignId('next_speaker_expert_id');
            $table->dropConstrainedForeignId('next_speaker_user_id');
        });
    }
};
