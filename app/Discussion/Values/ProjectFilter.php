<?php

namespace App\Discussion\Values;

/**
 * Which runs a question reads: project tags (TagFilter rules), any of the
 * pipelines, any of the models, one turn budget. An empty part filters
 * nothing; an empty filter means every run the user may read, and the
 * sentence says so.
 */
final readonly class ProjectFilter
{
    /**
     * @param  list<string>  $pipelines  short class names, OR
     * @param  list<string>  $models  model keys, OR
     */
    public function __construct(
        public TagFilter $tags = new TagFilter,
        public array $pipelines = [],
        public array $models = [],
        public ?int $turnBudget = null,
    ) {}

    public static function fromArray(mixed $data): self
    {
        $data = is_array($data) ? $data : [];
        $budget = $data['turn_budget'] ?? null;

        return new self(
            TagFilter::fromArray($data['tags'] ?? []),
            self::names($data['pipelines'] ?? []),
            self::names($data['models'] ?? []),
            is_numeric($budget) && (int) $budget > 0 ? (int) $budget : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tags' => $this->tags->toArray(),
            'pipelines' => $this->pipelines,
            'models' => $this->models,
            'turn_budget' => $this->turnBudget,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->tags->isEmpty() && $this->pipelines === [] && $this->models === [] && $this->turnBudget === null;
    }

    /** @return list<string> */
    private static function names(mixed $values): array
    {
        return array_values(array_unique(array_filter((array) $values, fn ($value) => is_string($value) && $value !== '')));
    }
}
