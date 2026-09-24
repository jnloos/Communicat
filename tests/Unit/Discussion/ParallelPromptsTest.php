<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Support\ParallelPrompts;
use App\Discussion\Values\Completion;
use Laravel\SerializableClosure\SerializableClosure;
use Tests\TestCase;

class ParallelPromptsTest extends TestCase
{
    private static function completion(string $text): Completion
    {
        return new Completion($text, '', 1, 1, null);
    }

    public function test_keys_survive_the_round_trip(): void
    {
        $result = app(ParallelPrompts::class)->run([
            7 => static fn () => self::completion('sieben'),
            9 => static fn () => self::completion('neun'),
        ]);

        $this->assertSame([7, 9], array_keys($result));
        $this->assertSame('sieben', $result[7]->text);
        $this->assertSame('neun', $result[9]->text);
    }

    public function test_a_single_task_takes_the_direct_path(): void
    {
        $result = app(ParallelPrompts::class)->run([3 => static fn () => self::completion('drei')]);

        $this->assertSame('drei', $result[3]->text);
    }

    public function test_an_empty_list_is_no_work(): void
    {
        $this->assertSame([], app(ParallelPrompts::class)->run([]));
    }

    /**
     * This is the property ProcessDriver::run() actually depends on: it wraps
     * each task in serialize(new SerializableClosure($task)) before handing it
     * to the child process. A non-static closure written inside a method
     * implicitly binds $this — even one that never mentions $this in its body
     * still carries it (PHP binds it at closure-creation time), so dropping
     * `static` here would silently start carrying this test case instance
     * along. A static closure never binds $this at all. This test holds
     * regardless of the configured concurrency driver, so it also catches the
     * regression under the suite's sync driver.
     */
    public function test_a_task_closure_survives_serialisation(): void
    {
        $task = static fn () => self::completion('serialisiert');

        $this->assertNull(
            (new \ReflectionFunction($task))->getClosureThis(),
            'a task closure must be static — ProcessDriver::run() serialises it for a child process and may not carry a bound $this.'
        );

        $wrapper = new SerializableClosure($task);
        $revived = unserialize(serialize($wrapper))->getClosure();

        $this->assertSame('serialisiert', $revived()->text);
    }
}
