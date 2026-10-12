<?php

namespace App\Discussion\Values;

/**
 * Whose share a question measures: everyone, the agents whose persona carries
 * the tags (TagFilter rules), the agents of any of several names, or both narrowed
 * together. A run in which the filter matches nobody drops out of the answer;
 * the filter never widens to everyone.
 */
final readonly class PeopleFilter
{
    /** @param  list<string>  $expertNames  an agent matches when its name is any of them; empty names no one in particular */
    public function __construct(
        public TagFilter $tags = new TagFilter,
        public array $expertNames = [],
    ) {}

    public static function fromArray(mixed $data): self
    {
        $data = is_array($data) ? $data : [];

        // Questions saved before the list held one name under `expert_name`.
        $names = $data['expert_names'] ?? $data['expert_name'] ?? [];
        $names = array_unique(array_filter(array_map(fn ($name) => trim((string) $name), is_array($names) ? $names : [$names]), fn (string $name) => $name !== ''));

        return new self(TagFilter::fromArray($data['tags'] ?? []), array_values($names));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['tags' => $this->tags->toArray(), 'expert_names' => $this->expertNames];
    }

    public function isEveryone(): bool
    {
        return $this->tags->isEmpty() && $this->expertNames === [];
    }
}
