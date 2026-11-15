<?php

namespace App\Discussion;

use RuntimeException;

/**
 * The response names another model than the one the run asked for (a gateway
 * combo, a fallback inside a gateway, a new Jev version). A run that mixes two
 * models is unusable in every environment, so this ends the run instead of
 * landing in the data; the call itself is already in prompt_logs. There is no
 * switch to turn it off. A date suffix is not drift (ModelIdentity).
 */
final class ModelDrifted extends RuntimeException implements AbortsRun
{
    public function __construct(
        public readonly string $requested,
        public readonly string $served,
    ) {
        parent::__construct("Asked for model [{$requested}], but [{$served}] answered.");
    }
}
