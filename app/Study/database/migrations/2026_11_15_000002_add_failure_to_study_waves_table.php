<?php

// Study-only, removable: see app/Study/README.md. Loaded by StudyServiceProvider
// whatever the switch says, so the schema does not depend on it.

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
