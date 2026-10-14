<?php

namespace App\Discussion\Values;

/**
 * What a question asks the study's runs, read as one sentence: [metric] of
 * [people] ([together | per head]) in [runs] over [turns], compared by
 * [dimension], split by [up to two dimensions]. fromArray() turns any form input into a
 * question that can be answered, so neither the editor nor the evaluator meets
 * a contradiction:
 *
 * - Gini describes a whole run: it counts everyone and cannot compare agents
 *   within a run.
 * - The aggregate only applies to shares; words per turn is a ratio already.
 * - Panels sort runs, so a split dimension is never a persona tag, nor the
 *   dimension compareBy already uses, nor named twice; at most MAX_SPLITS. A
 *   question saved before splits were lists names one key, which still reads.
 * - A baseline exists only with a compare dimension. Whether its key is one of
 *   that dimension's values is the evaluator's call: it takes the first group
 *   in dimension order when the key names none.
 * - A form the question cannot show falls back to points.
 */
final readonly class QuestionDefinition
{
    /** At most this many dimensions split an answer: the rows and the columns of its panels. */
    public const MAX_SPLITS = 2;

    public function __construct(
        public Metric $metric = Metric::TurnShare,
        public PeopleFilter $people = new PeopleFilter,
        public Aggregate $aggregate = Aggregate::Together,
        public ProjectFilter $projects = new ProjectFilter,
        /** The window inside every run; QuestionEvaluator caps its upper bound at the shortest run read. */
        public TurnRange $turns = new TurnRange,
        public ?Dimension $compareBy = null,
        /** @var list<Dimension> the panels' dimensions: the first gives the rows, the second the columns */
        public array $splitBy = [],
        public ResultForm $form = ResultForm::Points,
        public CurveMode $curve = CurveMode::Cumulative,
        /**
         * The group the others are measured against: a key of a compareBy value (a tag value id,
         * DimensionValues::NONE, a pipeline name, a model key, a group id). The delta is the other
         * group minus this one, whatever order the dimension lists its values in.
         */
        public ?string $baseline = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $metric = Metric::tryFrom((string) ($data['metric'] ?? '')) ?? Metric::TurnShare;
        $compareBy = Dimension::fromKey($data['compare_by'] ?? null);

        if ($metric->isRunLevel() && $compareBy?->isPersonaLevel()) {
            $compareBy = null;
        }

        $splitBy = self::splits($data['split_by'] ?? [], $compareBy);

        $baseline = $data['baseline'] ?? null;

        $question = new self(
            metric: $metric,
            people: $metric->isRunLevel() ? new PeopleFilter : PeopleFilter::fromArray($data['people'] ?? []),
            aggregate: $metric->isShare() ? (Aggregate::tryFrom((string) ($data['aggregate'] ?? '')) ?? Aggregate::Together) : Aggregate::Together,
            projects: ProjectFilter::fromArray($data['projects'] ?? []),
            turns: TurnRange::fromArray($data['turns'] ?? []),
            compareBy: $compareBy,
            splitBy: $splitBy,
            form: ResultForm::tryFrom((string) ($data['form'] ?? '')) ?? ResultForm::Points,
            curve: CurveMode::tryFrom((string) ($data['curve'] ?? '')) ?? CurveMode::Cumulative,
            baseline: $compareBy !== null && is_scalar($baseline) ? (string) $baseline : null,
        );

        return $question->supports($question->form) ? $question : $question->withForm(ResultForm::Points);
    }

    /**
     * The split dimensions as asked: a list of keys, or one key as saved
     * before splits were lists.
     *
     * @return list<Dimension>
     */
    private static function splits(mixed $keys, ?Dimension $compareBy): array
    {
        $splits = [];

        foreach (is_array($keys) ? $keys : [$keys] as $key) {
            $dimension = Dimension::fromKey($key);

            if ($dimension !== null && ! $dimension->isPersonaLevel() && $dimension != $compareBy && ! in_array($dimension, $splits)) {
                $splits[] = $dimension;
            }
        }

        return array_slice($splits, 0, self::MAX_SPLITS);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric->value,
            'people' => $this->people->toArray(),
            'aggregate' => $this->aggregate->value,
            'projects' => $this->projects->toArray(),
            'turns' => $this->turns->toArray(),
            'compare_by' => $this->compareBy?->key() ?? '',
            'split_by' => array_map(fn (Dimension $dimension) => $dimension->key(), $this->splitBy),
            'baseline' => $this->baseline,
            'form' => $this->form->value,
            'curve' => $this->curve->value,
        ];
    }

    /**
     * Points always work. A curve exists for shares only. Shares of a whole
     * need groups that add up to it: a share, compared by a persona tag, over
     * everyone, counted together.
     */
    public function supports(ResultForm $form): bool
    {
        return match ($form) {
            ResultForm::Points => true,
            ResultForm::Trajectory => $this->metric->isShare(),
            ResultForm::Composition => $this->metric->isShare()
                && $this->compareBy?->isPersonaLevel() === true
                && $this->people->isEveryone()
                && $this->aggregate === Aggregate::Together,
        };
    }

    /** The same question without what only its owner has: the group filter and a group dimension (with its baseline). */
    public function withoutGroups(): self
    {
        $comparesGroups = $this->compareBy?->type === DimensionType::Group;

        return new self(
            metric: $this->metric,
            people: $this->people,
            aggregate: $this->aggregate,
            projects: $this->projects->withoutGroups(),
            turns: $this->turns,
            compareBy: $comparesGroups ? null : $this->compareBy,
            splitBy: array_values(array_filter($this->splitBy, fn (Dimension $dimension) => $dimension->type !== DimensionType::Group)),
            form: $this->form,
            curve: $this->curve,
            baseline: $comparesGroups ? null : $this->baseline,
        );
    }

    public function withBaseline(?string $baseline): self
    {
        return new self($this->metric, $this->people, $this->aggregate, $this->projects, $this->turns,
            $this->compareBy, $this->splitBy, $this->form, $this->curve, $this->compareBy === null ? null : $baseline);
    }

    public function withForm(ResultForm $form): self
    {
        return new self($this->metric, $this->people, $this->aggregate, $this->projects, $this->turns,
            $this->compareBy, $this->splitBy, $form, $this->curve, $this->baseline);
    }

    public function withTurns(TurnRange $turns): self
    {
        return new self($this->metric, $this->people, $this->aggregate, $this->projects, $turns,
            $this->compareBy, $this->splitBy, $this->form, $this->curve, $this->baseline);
    }
}
