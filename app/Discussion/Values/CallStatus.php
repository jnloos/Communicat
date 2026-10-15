<?php

namespace App\Discussion\Values;

/** How one model call ended. Values are stored in prompt_logs.status; a refused or empty answer is Failed. */
enum CallStatus: string
{
    case Ok = 'ok';
    case Failed = 'failed';
}
