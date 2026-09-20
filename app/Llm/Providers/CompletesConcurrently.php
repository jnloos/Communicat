<?php

namespace App\Llm\Providers;

use App\Llm\LlmFactory;
use App\Llm\LlmRequest;
use Illuminate\Support\Facades\Concurrency;

/**
 * Runs several requests at once through Laravel's Concurrency facade. Each task
 * rebuilds its adapter from the model key inside the child process, so nothing
 * but plain value objects crosses the process boundary.
 */
trait CompletesConcurrently
{
    public function completeMany(array $requests): array
    {
        if (count($requests) <= 1) {
            return array_map(fn (LlmRequest $request) => $this->complete($request), $requests);
        }

        $modelKey = $this->config()->key;
        $keys = array_keys($requests);

        $tasks = [];
        foreach ($requests as $request) {
            $tasks[] = static fn () => app(LlmFactory::class)->adapter($modelKey)->complete($request);
        }

        // Concurrency::run returns a list; restore the caller's keys.
        return array_combine($keys, array_values(Concurrency::run($tasks)));
    }
}
