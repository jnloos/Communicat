<?php

namespace App\Discussion\Values;

use App\Discussion\Metrics\References\AgentReference;
use App\Discussion\Metrics\References\AgentReferences;
use App\Discussion\Metrics\References\EachReference;
use App\Discussion\Metrics\References\NamesTagValues;

/**
 * One series of a comparison: which runs (project filter) and which of their
 * agents (reference). Built from form input or saved JSON alike; fromArray()
 * normalises both, so a broken entry degrades to a harmless default.
 */
final readonly class SeriesDefinition
{
    public function __construct(
        public string $label,
        public TagFilter $projectTags = new TagFilter,
        public ?string $pipeline = null,
        public ?string $model = null,
        public AgentReference $reference = new EachReference,
    ) {}

    public static function fromArray(array $data): self
    {
        $projects = is_array($data['projects'] ?? null) ? $data['projects'] : [];

        return new self(
            label: trim((string) ($data['label'] ?? '')),
            projectTags: TagFilter::fromArray($projects['tags'] ?? []),
            pipeline: self::optional($projects['pipeline'] ?? null),
            model: self::optional($projects['model'] ?? null),
            reference: AgentReferences::fromArray(is_array($data['reference'] ?? null) ? $data['reference'] : []),
        );
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'projects' => ['tags' => $this->projectTags->toArray(), 'pipeline' => $this->pipeline, 'model' => $this->model],
            'reference' => $this->reference->toArray(),
        ];
    }

    /** Every tag value this series names, in the project filter and the reference. @return list<int> */
    public function tagValueIds(): array
    {
        $ids = $this->projectTags->valueIds();

        if ($this->reference instanceof NamesTagValues) {
            $ids = [...$ids, ...$this->reference->tagValueIds()];
        }

        return array_values(array_unique($ids));
    }

    /** @param  list<int>  $ids  values that no longer exist */
    public function withoutTagValues(array $ids): self
    {
        $reference = $this->reference instanceof NamesTagValues ? $this->reference->withoutTagValues($ids) : $this->reference;

        return new self($this->label, $this->projectTags->without($ids), $this->pipeline, $this->model, $reference);
    }

    public function withLabel(string $label): self
    {
        return new self($label, $this->projectTags, $this->pipeline, $this->model, $this->reference);
    }

    private static function optional(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
