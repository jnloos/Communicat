<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Experts and projects get a uuid that travels with an export (see HasPublicUuid). */
    public function up(): void
    {
        foreach (['experts', 'projects'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->uuid('uuid')->nullable();
            });

            DB::table($table)->whereNull('uuid')->pluck('id')
                ->each(fn (int $id) => DB::table($table)->where('id', $id)->update(['uuid' => (string) Str::uuid()]));

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unique('uuid');
            });
        }
    }

    public function down(): void
    {
        foreach (['experts', 'projects'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropUnique(['uuid']);
                $blueprint->dropColumn('uuid');
            });
        }
    }
};
