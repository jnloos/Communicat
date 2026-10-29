<?php

namespace App\Discussion;

use Throwable;

/**
 * An error that no later turn of the same run can get past (a missing or wrong
 * key, a rejected request): TurnRunner records the failed turn and then lets the
 * exception out of run(), so the headless runner and the queue job stop instead
 * of failing the same way, and paying the team model, for every remaining turn.
 */
interface AbortsRun extends Throwable {}
