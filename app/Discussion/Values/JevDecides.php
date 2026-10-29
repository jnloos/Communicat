<?php

namespace App\Discussion\Values;

/** What Jev stands in for in a pipeline: the speaker selector, or the agents' bids. */
enum JevDecides: string
{
    case Selector = 'selector';
    case Bidder = 'bidder';
}
