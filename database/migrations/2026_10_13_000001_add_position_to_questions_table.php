<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A manual order for the statistics page's lists: the templates are
     * ordered once for everyone, each user's own questions among themselves.
     * Existing rows keep the order they were shown in: templates by name,
     * questions most recently changed first.
     */
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->unsignedInteger('position')->default(0);
        });

        $number = fn ($rows) => $rows->values()->each(fn ($row, $offset) => DB::table('questions')->where('id', $row->id)->update(['position' => $offset + 1]));

        $number(DB::table('questions')->where('is_template', true)->orderBy('name')->orderBy('id')->get(['id']));
        DB::table('questions')->where('is_template', false)->orderByDesc('updated_at')->orderByDesc('id')->get(['id', 'user_id'])
            ->groupBy('user_id')->each($number);
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
