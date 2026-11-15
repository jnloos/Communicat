<?php

namespace App\Discussion;

use App\Discussion\Values\ChildFailure;
use RuntimeException;

/**
 * A ParallelPrompts task failed. Carries the original class, HTTP status and
 * whether it was transient, so TransientFailure and job_logs.error see the real
 * cause in every pipeline, whether the task ran in a child process or not.
 */
class PromptFailedInChild extends RuntimeException
{
    public function __construct(public readonly ChildFailure $failure)
    {
        parent::__construct($failure->describe());
    }
}
