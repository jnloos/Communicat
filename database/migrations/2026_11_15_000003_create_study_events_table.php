<?php

// Thesis study, see docs/study.md. The schema does not depend on STUDY_MODULE.

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
