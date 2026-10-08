<?php

namespace App\Discussion\Metrics\References;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\TagFilter;
use App\Models\Expert;
use App\Models\Project;

/**
 * The seated agents whose persona carries the chosen tags (AND across
 * categories, OR within one). An empty filter, or a category none of whose
 * values exists any more, matches nobody — it must never quietly widen to
 * "everyone".
 */
final class PersonaTagsReference implements AgentReference, NamesTagValues
{
    public const TYPE = 'persona_tags';

    public function __construct(public readonly TagFilter $tags = new TagFilter) {}

    public static function fromArray(array $data): static
    {
        return new self(TagFilter::fromArray($data['tags'] ?? []));
    }

    public function toArray(): array
    {
        return ['type' => self::TYPE, 'tags' => $this->tags->toArray()];
    }

    public function tagValueIds(): array
    {
        return $this->tags->valueIds();
    }

    public function withoutTagValues(array $ids): static
    {
        return new self($this->tags->without($ids));
    }

    public function pick(Project $project, ShareReport $report, Metric $metric): array
    {
        if ($this->tags->isEmpty()) {
            return [new AgentGroup('', [])];
        }

        $ids = Expert::whereKey(array_map(fn (SpeakerShare $s) => $s->expertId, $report->speakers))
            ->whereTagFilter($this->tags)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [new AgentGroup('', $ids)];
    }
}
