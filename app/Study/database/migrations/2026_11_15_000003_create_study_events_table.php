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
        Schema::create('study_events', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('level');
            $table->text('message');
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_events');
    }
};
