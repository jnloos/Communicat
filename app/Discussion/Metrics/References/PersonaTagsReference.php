<?php

namespace App\Discussion\Metrics\References;

use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\TagFilter;
use App\Models\Expert;
use App\Models\Project;
use App\Models\TagValue;

/**
 * The seated agents whose persona carries the chosen tags (AND across
 * categories, OR within one). A filter without any existing value matches
 * nobody — it must never quietly widen to "everyone".
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
        $existing = TagValue::whereKey($this->tags->valueIds())->pluck('id')->all();

        if ($existing === []) {
            return [new AgentGroup('', [])];
        }

        $ids = Expert::whereKey(array_map(fn (SpeakerShare $s) => $s->expertId, $report->speakers))
            ->whereTagged($existing)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [new AgentGroup('', $ids)];
    }
}
