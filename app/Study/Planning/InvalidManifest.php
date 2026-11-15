<?php

namespace App\Study\Planning;

use RuntimeException;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * A manifest that cannot plan a study, with every problem found, so one upload
 * shows all of them instead of one per attempt.
 */
class InvalidManifest extends RuntimeException
{
    /** @param  list<string>  $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct("Invalid manifest:\n- ".implode("\n- ", $problems));
    }
}
