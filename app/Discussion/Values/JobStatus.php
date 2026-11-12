<?php

namespace App\Discussion\Values;

/**
 * Where a turn stands. Values are stored in job_logs.status. A failed turn may
 * still have spoken: whether a turn counts is `words IS NOT NULL`, never Success.
 * Interrupted is an infrastructure error (TransientFailure), kept apart from
 * Failed so it never enters the study's failure rate.
 */
enum JobStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
    case Interrupted = 'interrupted';
}
