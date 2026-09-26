<?php

namespace App\Discussion\Support;

use App\Discussion\Values\Completion;
use Closure;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessStartFailedException;

/**
 * Runs several prompts at once. The SDK has no batch API — prompt() is
 * synchronous — so this stays our own code, built the way the provider adapters
 * did it before: each task builds its own agent inside the child process. The
 * payload that actually crosses the process boundary is the caller's task
 * closure itself (`ProcessDriver::run()` serialises it), so a task must stay a
 * `static` closure capturing only scalars — never `$this`-bound — or the whole
 * bound object gets dragged along and can fail to serialise.
 *
 * Tests must run the sync concurrency driver (see phpunit.xml): a child process
 * sees neither the in-memory SQLite nor the faked agents.
 */
class ParallelPrompts
{
    /**
     * @param  array<array-key, Closure(): Completion>  $tasks
     * @return array<array-key, Completion>
     */
    public function run(array $tasks): array
    {
        if (count($tasks) <= 1) {
            return array_map(fn (Closure $task) => $task(), $tasks);
        }

        $keys = array_keys($tasks);
        $ordered = array_values($tasks);

        try {
            // Concurrency::run returns a list; restore the caller's keys.
            $results = array_values(Concurrency::run($ordered));
        } catch (ProcessStartFailedException $e) {
            $results = $this->oneAfterAnother($ordered, $e);
        }

        return array_combine($keys, $results);
    }

    /**
     * The same work, in this process, when no child process can be started.
     *
     * ProcessDriver hands the serialised closure to the child as a command-line
     * argument, and that closure carries the rendered prompt. Once history and
     * long-term memory have grown far enough the argument exceeds what the
     * kernel will spawn -- "posix_spawn() failed: Argument list too long" --
     * and from that turn on every turn fails, 32 in a row in one pilot cell.
     *
     * Falling back costs wall-clock time and nothing else. The tasks are
     * independent, every prompt is rendered before any call goes out, and a
     * task returns the same Completion whichever process runs it. A study run
     * that slows down is worth having; one that dies at turn 39 is not.
     *
     * @param  list<Closure(): Completion>  $tasks
     * @return list<Completion>
     */
    private function oneAfterAnother(array $tasks, ProcessStartFailedException $reason): array
    {
        Log::warning('ParallelPrompts could not start a child process and ran the prompts one after another.', [
            'tasks' => count($tasks),
            'reason' => $reason->getMessage(),
        ]);

        return array_map(fn (Closure $task) => $task(), $tasks);
    }
}
