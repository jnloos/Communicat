<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('experts', function (Blueprint $table) {
            $table->text('profile')->nullable()->after('description');
            $table->text('core_beliefs')->nullable()->after('profile');
            $table->text('knowledge_limits')->nullable()->after('core_beliefs');
            $table->text('style')->nullable()->after('knowledge_limits');
            $table->dropColumn('prompt');
        });
    }

    public function down(): void
    {
        Schema::table('experts', function (Blueprint $table) {
            $table->text('prompt')->nullable();
            $table->dropColumn(['profile', 'core_beliefs', 'knowledge_limits', 'style']);
        });
    }
};