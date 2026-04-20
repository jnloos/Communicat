<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('adjacency_pair_type', 50)->nullable()->after('content');
            $table->string('next_speaker', 100)->nullable()->after('adjacency_pair_type');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['adjacency_pair_type', 'next_speaker']);
        });
    }
};
