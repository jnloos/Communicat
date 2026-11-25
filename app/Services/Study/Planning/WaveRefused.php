<?php

namespace App\Services\Study\Planning;

use RuntimeException;

/**
 * Thesis study, see docs/study.md.
 *
 * Writing a wave would not produce what the manifest describes: an attribute
 * template differs, a planned name belongs to another persona, or a persona was
 * edited by hand. Thrown before the first write wherever it can be known then.
 */
class WaveRefused extends RuntimeException
{
    /** @param  list<string>  $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct("Wave refused:\n- ".implode("\n- ", $problems));
    }
}
