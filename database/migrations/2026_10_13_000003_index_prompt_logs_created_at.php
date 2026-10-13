<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** prompts:prune runs hourly over created_at; without an index it reads every row, long prompt texts included. */
    public function up(): void
    {
        Schema::table('prompt_logs', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('prompt_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
