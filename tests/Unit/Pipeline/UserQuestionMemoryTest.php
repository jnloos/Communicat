<?php

namespace Tests\Unit\Pipeline;

use App\Services\PromptingPipeline\Support\UserQuestionMemory;
use Tests\TestCase;

class UserQuestionMemoryTest extends TestCase
{
    public function test_upsert_prepends_question_block_and_keeps_existing_memory(): void
    {
        $memory = "[U3]\nBob will Klarheit.\n[STAND]\nOffen.";

        $out = UserQuestionMemory::upsert($memory, 'Wie skaliert man das System?');

        $this->assertStringStartsWith('[AKTUELLE_NUTZERFRAGE]', $out);
        $this->assertStringContainsString('Wie skaliert man das System?', $out);
        $this->assertStringContainsString('[U3]', $out);
        $this->assertStringContainsString('[STAND]', $out);
    }

    public function test_upsert_replaces_existing_block_without_duplicating(): void
    {
        $memory = "[U3]\nBob will Klarheit.";

        $first = UserQuestionMemory::upsert($memory, 'Erste Frage?');
        $second = UserQuestionMemory::upsert($first, 'Zweite Frage?');

        $this->assertSame(1, substr_count($second, UserQuestionMemory::MARKER));
        $this->assertStringContainsString('Zweite Frage?', $second);
        $this->assertStringNotContainsString('Erste Frage?', $second);
        $this->assertStringContainsString('[U3]', $second);
    }

    public function test_strip_removes_block_and_leaves_rest(): void
    {
        $memory = "[AKTUELLE_NUTZERFRAGE]\nAlte Frage\n[U3]\nBob-Notiz.";

        $stripped = UserQuestionMemory::strip($memory);

        $this->assertStringNotContainsString('[AKTUELLE_NUTZERFRAGE]', $stripped);
        $this->assertStringNotContainsString('Alte Frage', $stripped);
        $this->assertStringContainsString('[U3]', $stripped);
    }

    public function test_empty_question_strips_the_block(): void
    {
        $memory = UserQuestionMemory::upsert("[U3]\nBob.", 'Frage?');

        $this->assertFalse(UserQuestionMemory::contains(UserQuestionMemory::upsert($memory, '')));
    }
}
