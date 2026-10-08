<?php

namespace App\Discussion\Values;

use App\Discussion\Metrics\References\AgentReference;
use App\Discussion\Metrics\References\AgentReferences;
use App\Discussion\Metrics\References\EachReference;
use App\Discussion\Metrics\References\NamesTagValues;
use App\Models\TagCategory;

/**
 * One series of a comparison: which runs (project filter) and which of their
 * agents (reference). Built from form input or saved JSON alike; fromArray()
 * normalises both, so a broken entry degrades to a harmless default.
 *
 * `unresolvedTags` are tag names a template asked for that this database
 * does not know. They have no id, so they cannot sit in a filter; instead,
 * while any is left, the series matches no run (SeriesProjects) — it never
 * widens to every run. The user removes them one by one in the editor.
 */
final readonly class SeriesDefinition
{
    /** @param  list<array{category: string, value: string}>  $unresolvedTags */
    public function __construct(
        public string $label,
        public TagFilter $projectTags = new TagFilter,
        public ?string $pipeline = null,
        public ?string $model = null,
        public AgentReference $reference = new EachReference,
        public array $unresolvedTags = [],
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
            unresolvedTags: self::unresolved($data['unresolved_tags'] ?? []),
        );
    }

    /**
     * Stored or form rows as definitions; a row that is not an array degrades
     * to an empty series rather than failing.
     *
     * @return list<self>
     */
    public static function listFrom(array $rows): array
    {
        return array_values(array_map(fn (mixed $row) => self::fromArray(is_array($row) ? $row : []), $rows));
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'projects' => ['tags' => $this->projectTags->toArray(), 'pipeline' => $this->pipeline, 'model' => $this->model],
            'reference' => $this->reference->toArray(),
            'unresolved_tags' => $this->unresolvedTags,
        ];
    }

    public function hasUnresolvedTags(): bool
    {
        return $this->unresolvedTags !== [];
    }

    /** "Condition: status" per unresolved tag, as the interface names any tag. @return list<string> */
    public function unresolvedLabels(): array
    {
        return array_map(fn (array $tag) => TagCategory::labelFor($tag['category']).': '.$tag['value'], $this->unresolvedTags);
    }

    public function withoutUnresolvedTag(int $index): self
    {
        $tags = $this->unresolvedTags;
        unset($tags[$index]);

        return new self($this->label, $this->projectTags, $this->pipeline, $this->model, $this->reference, array_values($tags));
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

        return new self($this->label, $this->projectTags->without($ids), $this->pipeline, $this->model, $reference, $this->unresolvedTags);
    }

    public function withLabel(string $label): self
    {
        return new self($label, $this->projectTags, $this->pipeline, $this->model, $this->reference, $this->unresolvedTags);
    }

    /** @return list<array{category: string, value: string}> */
    private static function unresolved(mixed $rows): array
    {
        $tags = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $category = is_array($row) && is_string($row['category'] ?? null) ? trim($row['category']) : '';
            $value = is_array($row) && is_string($row['value'] ?? null) ? trim($row['value']) : '';

            if ($category !== '' && $value !== '') {
                $tags[] = ['category' => $category, 'value' => $value];
            }
        }

        return $tags;
    }

    private static function optional(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
