<?php

namespace App\Discussion\Support;

use App\Discussion\Values\Completion;
use Closure;
use Illuminate\Support\Facades\Concurrency;

/**
 * Runs several prompts at once. The SDK has no batch API — prompt() is
 * synchronous — so this stays our own code, built the way the provider adapters
 * did it before: nothing but value objects crosses the process boundary, and
 * each task builds its own agent inside the child process.
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

        // Concurrency::run returns a list; restore the caller's keys.
        return array_combine($keys, array_values(Concurrency::run(array_values($tasks))));
    }
}
