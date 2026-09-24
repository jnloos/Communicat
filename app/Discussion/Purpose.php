<?php

namespace App\Discussion;

/** The four kinds of model call. Values are stored in prompt_logs.purpose. */
enum Purpose: string
{
    case Think = 'think';
    case Select = 'select';
    case Speak = 'speak';
    case Summarize = 'summarize';
}
