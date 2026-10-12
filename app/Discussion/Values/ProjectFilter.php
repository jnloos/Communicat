<?php

namespace App\Discussion\Values;

/**
 * Which runs a question reads: project tags (TagFilter rules), any of the
 * pipelines, any of the models, any of the user's sidebar groups. An empty part filters
 * nothing; an empty filter means every run the user may read, and the
 * sentence says so.
 */
final readonly class ProjectFilter
{
    /**
     * @param  list<string>  $pipelines  short class names, OR
     * @param  list<string>  $models  model keys, OR
     * @param  list<int>  $groups  ids of the viewing user's sidebar groups, OR; never a template's
     */
    public function __construct(
        public TagFilter $tags = new TagFilter,
        public array $pipelines = [],
        public array $models = [],
        public array $groups = [],
    ) {}

    public static function fromArray(mixed $data): self
    {
        $data = is_array($data) ? $data : [];

        return new self(
            TagFilter::fromArray($data['tags'] ?? []),
            self::names($data['pipelines'] ?? []),
            self::names($data['models'] ?? []),
            self::ids($data['groups'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tags' => $this->tags->toArray(),
            'pipelines' => $this->pipelines,
            'models' => $this->models,
            'groups' => $this->groups,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->tags->isEmpty() && $this->pipelines === [] && $this->models === [] && $this->groups === [];
    }

    /** @return list<string> */
    private static function names(mixed $values): array
    {
        return array_values(array_unique(array_filter((array) $values, fn ($value) => is_string($value) && $value !== '')));
    }

    /** @return list<int> */
    private static function ids(mixed $values): array
    {
        $ids = array_map('intval', array_filter((array) $values, fn ($value) => is_int($value) || (is_string($value) && ctype_digit($value))));

        return array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
    }

    public function withoutGroups(): self
    {
        return new self($this->tags, $this->pipelines, $this->models);
    }
}
