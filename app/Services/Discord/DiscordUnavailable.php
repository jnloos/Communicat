<?php

namespace App\Services\Discord;

use RuntimeException;

/** Discord did not take the request (rate limit, server error); the next minute checks again anyway. */
class DiscordUnavailable extends RuntimeException {}
