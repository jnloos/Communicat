<?php

// Thesis study, see docs/study.md. The schema does not depend on STUDY_MODULE.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_waves', function (Blueprint $table) {
            $table->text('failed_reason')->nullable();
            $table->timestamp('failed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('study_waves', function (Blueprint $table) {
            $table->dropColumn(['failed_reason', 'failed_at']);
        });
    }
};
