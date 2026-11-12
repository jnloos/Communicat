<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * JobStatus::Interrupted: a turn cut short by the infrastructure (TransientFailure),
 * kept apart from failed so it stays out of the study's failure rate. On SQLite the
 * change rebuilds the table, so back the database up first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_logs', function (Blueprint $table) {
            $table->enum('status', ['running', 'success', 'failed', 'interrupted'])->default('running')->change();
        });
    }

    public function down(): void
    {
        Schema::table('job_logs', function (Blueprint $table) {
            $table->enum('status', ['running', 'success', 'failed'])->default('running')->change();
        });
    }
};
