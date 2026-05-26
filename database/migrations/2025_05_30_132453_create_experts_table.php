<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('experts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('avatar_url')->nullable();
            $table->string('job');
            $table->text('description');
            // Structured persona fields. core_beliefs/knowledge_limits hold JSON
            // arrays (cast in the model); profile/style are free text.
            $table->text('profile')->nullable();
            $table->text('core_beliefs')->nullable();
            $table->text('knowledge_limits')->nullable();
            $table->text('style')->nullable();
            $table->string('voice_id', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('experts');
    }
};
