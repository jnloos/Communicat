<?php

namespace App\Discussion\Jev;

use App\Discussion\AbortsRun;
use RuntimeException;

/**
 * Jev refused a request in a way that retrying or drawing a speaker does not
 * fix: a wrong or revoked key (401, 403), a request it will not accept (400,
 * 422), exhausted credit (402). Like a missing key it is a configuration or
 * format error, not a model answer, so it is neither retried nor turned into a
 * fallback: a cell of random speakers would look like data. It ends the run.
 *
 * The message carries the HTTP status and a short, single-line excerpt of what
 * the API said in its answer. It never carries the request (the state is the
 * discussion, and the key travels in a header), and the constructor takes only
 * the two scalars below because a child process of ParallelPrompts rebuilds the
 * exception from its public constructor properties.
 */
final class JevRejected extends RuntimeException implements AbortsRun
{
    /** How much of the API's own message is kept. */
    public const DETAIL_LIMIT = 200;

    public function __construct(
        public readonly int $status,
        public readonly string $detail,
    ) {
        parent::__construct($detail === ''
            ? "Jev rejected the request (HTTP {$status})."
            : "Jev rejected the request (HTTP {$status}): {$detail}");
    }
}
