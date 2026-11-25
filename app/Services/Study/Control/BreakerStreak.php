<?php

namespace App\Services\Study\Control;

use Illuminate\Support\Facades\Cache;

/**
 * Turns in a row, over all runs, that produced no public contribution. Lives in the
 * cache on purpose: losing it only resets it to 0, which is harmless. The TTL keeps a
 * leftover from the evening before from tripping the breaker the next morning.
 */
class BreakerStreak
{
    private const KEY = 'study:breaker:streak';

    private const TTL_SECONDS = 6 * 3600;

    public function increment(): int
    {
        Cache::add(self::KEY, 0, self::TTL_SECONDS);

        return (int) Cache::increment(self::KEY);
    }

    public function reset(): void
    {
        Cache::forget(self::KEY);
    }

    public function count(): int
    {
        return (int) Cache::get(self::KEY, 0);
    }
}
