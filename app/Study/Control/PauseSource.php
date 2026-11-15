<?php

namespace App\Study\Control;

/** Who paused the study: a person (study:pause, later the dashboard) or the circuit breaker. */
enum PauseSource: string
{
    case Manual = 'manual';
    case Breaker = 'breaker';
}
