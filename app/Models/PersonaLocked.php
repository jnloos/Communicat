<?php

namespace App\Models;

use RuntimeException;

/**
 * A persona a started run has read (Expert::isLocked()): changing its name,
 * description or attribute values, or deleting it, would make the run's prompts
 * disagree with its run_config. Whoever wants a changed persona creates a new one.
 */
class PersonaLocked extends RuntimeException
{
    public static function for(Expert $expert): self
    {
        return new self("Expert {$expert->id} ({$expert->name}) is used by a started run and cannot be changed or deleted; create a new persona instead.");
    }
}
