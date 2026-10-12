<?php

namespace App\Discussion\Values;

/**
 * Whose share a question measures: everyone, the agents whose persona carries
 * the tags (TagFilter rules), the agents of one name, or both narrowed
 * together. A run in which the filter matches nobody drops out of the answer;
 * the filter never widens to everyone.
 */
final readonly class PeopleFilter
{
    public function __construct(
        public TagFilter $tags = new TagFilter,
        public ?string $expertName = null,
    ) {}

    public static function fromArray(mixed $data): self
    {
        $data = is_array($data) ? $data : [];
        $name = trim((string) ($data['expert_name'] ?? ''));

        return new self(TagFilter::fromArray($data['tags'] ?? []), $name === '' ? null : $name);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['tags' => $this->tags->toArray(), 'expert_name' => $this->expertName];
    }

    public function isEveryone(): bool
    {
        return $this->tags->isEmpty() && $this->expertName === null;
    }
}
