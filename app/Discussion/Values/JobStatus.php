<?php

namespace App\Discussion\Values;

/**
 * Where a turn stands. Values are stored in job_logs.status. A failed turn may
 * still have spoken: whether a turn counts is `words IS NOT NULL`, never Success.
 */
enum JobStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
}
