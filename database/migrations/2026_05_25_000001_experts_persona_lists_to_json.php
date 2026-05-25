<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('experts')->get()->each(function ($expert) {
            $updates = [];

            if (! empty($expert->core_beliefs)) {
                $decoded = json_decode($expert->core_beliefs, true);
                if (! is_array($decoded)) {
                    $lines = preg_split('/\n(?=\d+\.\s)/', $expert->core_beliefs);
                    $updates['core_beliefs'] = json_encode(
                        array_values(array_filter(
                            array_map(fn ($l) => trim(preg_replace('/^\d+\.\s+/', '', $l)), $lines),
                            fn ($l) => $l !== ''
                        )),
                        JSON_UNESCAPED_UNICODE
                    );
                }
            }

            if (! empty($expert->knowledge_limits)) {
                $decoded = json_decode($expert->knowledge_limits, true);
                if (! is_array($decoded)) {
                    $lines = preg_split('/\n(?=- )/', $expert->knowledge_limits);
                    $updates['knowledge_limits'] = json_encode(
                        array_values(array_filter(
                            array_map(fn ($l) => trim(preg_replace('/^-\s+/', '', $l)), $lines),
                            fn ($l) => $l !== ''
                        )),
                        JSON_UNESCAPED_UNICODE
                    );
                }
            }

            if (! empty($updates)) {
                DB::table('experts')->where('id', $expert->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        // Data migration — not reversible without original text format
    }
};
