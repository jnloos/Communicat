<?php

namespace App\Discussion\Values;

/**
 * What a question compares or splits by: a tag category, the pipeline or the
 * model. Its key ("persona_tag:3", "pipeline") is what a select binds and what
 * a saved question stores, so the form needs no translation step.
 */
final readonly class Dimension
{
    public function __construct(
        public DimensionType $type,
        public ?int $categoryId = null,
    ) {}

    /** Null for an empty or malformed key, and for a tag type without its category. */
    public static function fromKey(mixed $key): ?self
    {
        if (! is_string($key) || $key === '') {
            return null;
        }

        [$type, $category] = array_pad(explode(':', $key, 2), 2, null);
        $type = DimensionType::tryFrom($type);

        return match (true) {
            $type === null => null,
            ! $type->isTag() => new self($type),
            is_numeric($category) => new self($type, (int) $category),
            default => null,
        };
    }

    public function key(): string
    {
        return $this->type->isTag() ? "{$this->type->value}:{$this->categoryId}" : $this->type->value;
    }

    /** A persona tag sorts the agents of one run; every other dimension sorts runs. */
    public function isPersonaLevel(): bool
    {
        return $this->type === DimensionType::PersonaTag;
    }
}
