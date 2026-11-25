<?php

// Thesis study, see docs/study.md. The schema does not depend on STUDY_MODULE.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_waves', function (Blueprint $table) {
            $table->id();
            $table->string('study');
            $table->unsignedInteger('number');
            $table->unsignedInteger('first_scenario');
            $table->unsignedInteger('last_scenario');
            $table->longText('manifest');
            $table->string('manifest_sha256', 64);
            $table->string('core_sha256', 64);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_group_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['study', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_waves');
    }
};
