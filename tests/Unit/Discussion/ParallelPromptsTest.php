<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Support\ParallelPrompts;
use App\Discussion\Values\Completion;
use Tests\TestCase;

class ParallelPromptsTest extends TestCase
{
    private function completion(string $text): Completion
    {
        return new Completion($text, '', 1, 1, null);
    }

    public function test_keys_survive_the_round_trip(): void
    {
        $result = app(ParallelPrompts::class)->run([
            7 => fn () => $this->completion('sieben'),
            9 => fn () => $this->completion('neun'),
        ]);

        $this->assertSame([7, 9], array_keys($result));
        $this->assertSame('sieben', $result[7]->text);
        $this->assertSame('neun', $result[9]->text);
    }

    public function test_a_single_task_takes_the_direct_path(): void
    {
        $result = app(ParallelPrompts::class)->run([3 => fn () => $this->completion('drei')]);

        $this->assertSame('drei', $result[3]->text);
    }

    public function test_an_empty_list_is_no_work(): void
    {
        $this->assertSame([], app(ParallelPrompts::class)->run([]));
    }
}
