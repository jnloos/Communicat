<?php

namespace App\Discussion\Values;

/** One agent's bid in a Jev Score turn: what the agent bid itself, and what Jev gave instead. */
final readonly class JevAgentBid
{
    public function __construct(
        public string $token,
        /** The team model's own bid, null when it gave none. It is recorded, not used. */
        public ?int $own,
        public JevBid $jev,
    ) {}
}
