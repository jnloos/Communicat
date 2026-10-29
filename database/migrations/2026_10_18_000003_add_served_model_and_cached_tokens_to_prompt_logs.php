<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * model_served: the model id the response names (gateways may answer with a
     * dated snapshot or another model than asked for). tokens_cached: input tokens
     * read from a prompt cache. Both NULL = not reported, never 0.
     */
    public function up(): void
    {
        Schema::table('prompt_logs', function (Blueprint $table) {
            $table->string('model_served')->nullable()->after('checkpoint');
            $table->unsignedInteger('tokens_cached')->nullable()->after('tokens_reasoning');
        });
    }

    public function down(): void
    {
        Schema::table('prompt_logs', function (Blueprint $table) {
            $table->dropColumn(['model_served', 'tokens_cached']);
        });
    }
};
