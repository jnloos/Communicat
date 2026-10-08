<?php

namespace App\Discussion\Values;

/** One run's value in a series line. */
final readonly class ProjectValue
{
    public function __construct(
        public string $title,
        public float $value,
    ) {}
}
