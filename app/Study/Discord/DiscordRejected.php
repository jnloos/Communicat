<?php

namespace App\Study\Discord;

use RuntimeException;

/** 401 or 403: a wrong token or a bot that is not in the channel. Retrying cannot help. */
class DiscordRejected extends RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct("Discord rejected the request ({$status})");
    }
}
