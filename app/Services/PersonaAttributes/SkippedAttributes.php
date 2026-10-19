<?php

namespace App\Services\PersonaAttributes;

/**
 * What AttributesByName::assign() left out, by cause. A value that is empty is
 * not a problem and appears nowhere: "no value" is a legitimate entry.
 */
final class SkippedAttributes
{
    /** @var list<string> "attribute: value" for a definition that does not exist here (or a value that is not a text) */
    public array $unknown = [];

    /** @var list<string> */
    public array $tooLong = [];

    /** @var list<string> */
    public array $lineBreak = [];

    public function isEmpty(): bool
    {
        return $this->unknown === [] && $this->tooLong === [] && $this->lineBreak === [];
    }

    public function merge(self $other): void
    {
        $this->unknown = [...$this->unknown, ...$other->unknown];
        $this->tooLong = [...$this->tooLong, ...$other->tooLong];
        $this->lineBreak = [...$this->lineBreak, ...$other->lineBreak];
    }

    public function addInvalid(ValueProblem $problem, string $pair): void
    {
        match ($problem) {
            ValueProblem::TooLong => $this->tooLong[] = $pair,
            ValueProblem::LineBreak => $this->lineBreak[] = $pair,
        };
    }
}
