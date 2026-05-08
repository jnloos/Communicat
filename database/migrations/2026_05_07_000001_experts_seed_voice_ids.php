<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $mapping = [
            'Michael Bauer' => 'nPczCjzI2devNBz1zQrb',
            'Sophie Wagner' => 'EXAVITQu4vr4xnSDxMaL',
            'Lena Fischer' => 'XrExE9yKIg1WjnnlVkGX',
            'Jan Lehmann' => 'TX3LPaxmHKxFdv7VOQHJ',
            'Nina Keller' => 'XB0fDUnXU5powFXDhCwa',
            'Marie Hoffmann' => 'pFZP5JQG7iQjIQuC4Bku',
            'Thomas Weber' => 'JBFqnCBsd6RMkjVDRZzb',
            'Clara Schmidt' => 'Xb7hH8MSUJpSbSDYk0k2',
            'Felix Brandt' => 'bIHbv24MWmeRgasZH58o',
            'Anna Richter' => 'cgSgspJ2msm6clMCkdW9',
            'Paul Neumann' => 'cjVigY5qzO86Huf0OWal',
            'David Kaufmann' => 'pqHfZKP75CvOlQylNhV4',
            'Julia Berger' => '9BWtsMINqrJLrRacOk9x',
            'Marco Lang' => 'onwK4e9ZLuTAKqWW03F9',
            'Sarah Vogel' => 'ThT5KcBeYPX3keUQqHPh',
            'Katharina Wolf' => 'oWAxZDx7w5VEj9dCyTzz',
            'Stefan Maier' => 'iP95p4xoKVk53GoZ742B',
            'Lisa Graf' => 'LcfcDJNUP1GQjkzn1xUU',
            'Tim Hofmann' => 'pNInz6obpgDQGcFmaJgB',
        ];

        foreach ($mapping as $name => $voiceId) {
            DB::table('experts')
                ->where('name', $name)
                ->update(['voice_id' => $voiceId]);
        }
    }

    public function down(): void
    {
        DB::table('experts')->update(['voice_id' => null]);
    }
};
