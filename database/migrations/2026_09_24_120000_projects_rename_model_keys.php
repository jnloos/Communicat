<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The model registry used to key its entries by provider ("openai"); it now keys
 * them by provider and model ("openai-gpt-5"), so the dropdown can offer the
 * provider first and its models second.
 *
 * projects.model holds one of those keys, and ModelConfig::fromConfig() throws on
 * an unknown one — without this mapping every existing project would break on its
 * next turn. run_config snapshots are deliberately left alone: they record what
 * ran at the time and must stay historically true.
 */
return new class extends Migration
{
    private const MAP = [
        'openai' => 'openai-gpt-5',
        'anthropic' => 'anthropic-opus-5',
        'gemini' => 'gemini-2.5-pro',
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('projects')->where('model', $old)->update(['model' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('projects')->where('model', $new)->update(['model' => $old]);
        }
    }
};
