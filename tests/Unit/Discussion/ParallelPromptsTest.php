<?php

namespace Tests\Unit\Discussion;

use App\Discussion\AbortsRun;
use App\Discussion\Jev\JevRejected;
use App\Discussion\PromptTaskAborted;
use App\Discussion\PromptTaskFailed;
use App\Discussion\Support\ParallelPrompts;
use App\Discussion\TransientFailure;
use App\Discussion\Values\ChildFailure;
use App\Discussion\Values\Completion;
use Closure;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Log;
use Laravel\SerializableClosure\SerializableClosure;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

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
     * A run must not die because the kernel refuses another process. That is
     * what ended one pilot cell at turn 39: the serialised closure carries the
     * rendered prompt, and once it grew past what posix_spawn() accepts, all
     * 32 remaining turns failed.
     */
    public function test_it_runs_the_prompts_itself_when_no_child_process_starts(): void
    {
        Log::spy();
        Concurrency::shouldReceive('run')->once()->andThrow(
            new ProcessStartFailedException(new Process(['true']), 'Argument list too long')
        );

        $result = app(ParallelPrompts::class)->run([
            7 => static fn () => self::completion('sieben'),
            9 => static fn () => self::completion('neun'),
        ]);

        $this->assertSame([7, 9], array_keys($result));
        $this->assertSame(['sieben', 'neun'], [$result[7]->text, $result[9]->text]);
        Log::shouldHaveReceived('warning')->once();
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

    private static function httpError(int $status): RequestException
    {
        return new RequestException(new HttpResponse(new Psr7Response($status)));
    }

    /** @return Throwable what run() threw */
    private function failureOf(array $tasks): Throwable
    {
        try {
            app(ParallelPrompts::class)->run($tasks);
        } catch (Throwable $e) {
            return $e;
        }

        $this->fail('run() must throw for a failed task.');
    }

    /**
     * The same failure must arrive the same way however the tasks ran: one task
     * directly, several under the suite's sync driver, or one after another.
     *
     * @return array<string, array{Closure(array): array}>
     */
    public static function paths(): array
    {
        return [
            'single task' => [fn (array $tasks) => [reset($tasks)]],
            'several tasks' => [fn (array $tasks) => $tasks + ['other' => static fn () => self::completion('ok')]],
        ];
    }

    #[DataProvider('paths')]
    public function test_a_server_error_in_a_task_arrives_as_a_transient_failure_with_its_class_and_status(Closure $path): void
    {
        $e = $this->failureOf($path([static fn () => throw self::httpError(500)]));

        $this->assertInstanceOf(PromptTaskFailed::class, $e);
        $this->assertNotInstanceOf(AbortsRun::class, $e);
        $this->assertSame(RequestException::class, $e->failure->class);
        $this->assertSame(500, $e->failure->status);
        $this->assertTrue($e->failure->transient);
        $this->assertTrue(TransientFailure::is($e));
        $this->assertStringContainsString('RequestException (HTTP 500)', $e->getMessage());
    }

    #[DataProvider('paths')]
    public function test_a_failure_names_where_it_was_thrown_and_a_non_transient_one_is_reported(Closure $path): void
    {
        $reported = [];
        $this->app->make(ExceptionHandler::class)
            ->reportable(function (Throwable $e) use (&$reported) {
                $reported[] = $e;

                return false;
            });

        $e = $this->failureOf($path([static fn () => throw new \LogicException('boom')]));

        $this->assertMatchesRegularExpression('/\[at ParallelPromptsTest\.php:\d+\]$/', $e->getMessage());
        $this->assertCount(1, $reported);
        $this->assertInstanceOf(\LogicException::class, $reported[0]);

        $reported = [];
        $this->failureOf($path([static fn () => throw self::httpError(500)]));
        $this->assertSame([], $reported, 'a transient failure is not reported');
    }

    #[DataProvider('paths')]
    public function test_a_client_error_in_a_task_is_not_transient(Closure $path): void
    {
        $e = $this->failureOf($path([static fn () => throw self::httpError(400)]));

        $this->assertInstanceOf(PromptTaskFailed::class, $e);
        $this->assertSame(400, $e->failure->status);
        $this->assertFalse($e->failure->transient);
        $this->assertFalse(TransientFailure::is($e));
    }

    #[DataProvider('paths')]
    public function test_an_error_that_aborts_the_run_still_aborts_it(Closure $path): void
    {
        $e = $this->failureOf($path([static fn () => throw new JevRejected(401, 'Invalid API key')]));

        $this->assertInstanceOf(PromptTaskAborted::class, $e);
        $this->assertInstanceOf(AbortsRun::class, $e);
        $this->assertFalse(TransientFailure::is($e));
        $this->assertStringContainsString('JevRejected', $e->getMessage());
    }

    public function test_a_failure_survives_the_fallback_without_child_processes(): void
    {
        Log::spy();
        Concurrency::shouldReceive('run')->once()->andThrow(
            new ProcessStartFailedException(new Process(['true']), 'Argument list too long')
        );

        $e = $this->failureOf([static fn () => self::completion('ok'), static fn () => throw self::httpError(503)]);

        $this->assertInstanceOf(PromptTaskFailed::class, $e);
        $this->assertSame(503, $e->failure->status);
    }

    /**
     * The guard wraps the caller's task in a closure of its own, and that wrapper
     * is what ProcessDriver serialises. It must survive the trip and report a
     * failure as a value: the driver rebuilds a thrown exception from class and
     * message only, which turns a RequestException into a TypeError.
     */
    public function test_the_guarded_task_survives_serialisation_and_reports_failure_as_a_value(): void
    {
        $guarded = ParallelPrompts::guarded(static fn () => throw self::httpError(502));

        $this->assertNull((new \ReflectionFunction($guarded))->getClosureThis());

        $revived = unserialize(serialize(new SerializableClosure($guarded)))->getClosure();
        $result = unserialize(serialize($revived()));

        $this->assertInstanceOf(ChildFailure::class, $result);
        $this->assertSame(502, $result->status);
        $this->assertTrue($result->transient);
    }
}
