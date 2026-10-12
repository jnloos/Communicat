<?php

namespace App\Discussion\Values;

/**
 * A question to the study's runs, read as one sentence: [metric] of [people]
 * ([together | per head]) in [runs] over [turns], compared by [dimension], split by
 * [dimension]. fromArray() turns any form input into a question that can be
 * answered, so neither the editor nor the evaluator meets a contradiction:
 *
 * - Gini describes a whole run: it counts everyone and cannot compare agents
 *   within a run.
 * - The aggregate only applies to shares; words per turn is a ratio already.
 * - Panels sort runs, so splitBy is never a persona tag, nor the dimension
 *   compareBy already uses.
 * - A form the question cannot show falls back to points.
 */
final readonly class QuestionDefinition
{
    public function __construct(
        public Metric $metric = Metric::TurnShare,
        public PeopleFilter $people = new PeopleFilter,
        public Aggregate $aggregate = Aggregate::Together,
        public ProjectFilter $projects = new ProjectFilter,
        /** The window inside every run; QuestionEvaluator caps its upper bound at the shortest run read. */
        public TurnRange $turns = new TurnRange,
        public ?Dimension $compareBy = null,
        public ?Dimension $splitBy = null,
        public ResultForm $form = ResultForm::Points,
        /** 'cumulative' or 'rolling', as Metric::series() reads it. */
        public string $curve = 'cumulative',
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $metric = Metric::tryFrom((string) ($data['metric'] ?? '')) ?? Metric::TurnShare;
        $compareBy = Dimension::fromKey($data['compare_by'] ?? null);
        $splitBy = Dimension::fromKey($data['split_by'] ?? null);

        if ($metric->isRunLevel() && $compareBy?->isPersonaLevel()) {
            $compareBy = null;
        }

        if ($splitBy?->isPersonaLevel() || ($splitBy !== null && $splitBy == $compareBy)) {
            $splitBy = null;
        }

        $question = new self(
            metric: $metric,
            people: $metric->isRunLevel() ? new PeopleFilter : PeopleFilter::fromArray($data['people'] ?? []),
            aggregate: $metric->isShare() ? (Aggregate::tryFrom((string) ($data['aggregate'] ?? '')) ?? Aggregate::Together) : Aggregate::Together,
            projects: ProjectFilter::fromArray($data['projects'] ?? []),
            turns: TurnRange::fromArray($data['turns'] ?? []),
            compareBy: $compareBy,
            splitBy: $splitBy,
            form: ResultForm::tryFrom((string) ($data['form'] ?? '')) ?? ResultForm::Points,
            curve: ($data['curve'] ?? null) === 'rolling' ? 'rolling' : 'cumulative',
        );

        return $question->supports($question->form) ? $question : $question->withForm(ResultForm::Points);
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
            'split_by' => $this->splitBy?->key() ?? '',
            'form' => $this->form->value,
            'curve' => $this->curve,
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

    /** The same question without what only its owner has: the group filter and a group dimension. */
    public function withoutGroups(): self
    {
        $isGroup = fn (?Dimension $dimension) => $dimension?->type === DimensionType::Group;

        return new self($this->metric, $this->people, $this->aggregate, $this->projects->withoutGroups(), $this->turns,
            $isGroup($this->compareBy) ? null : $this->compareBy, $isGroup($this->splitBy) ? null : $this->splitBy, $this->form, $this->curve);
    }

    public function withForm(ResultForm $form): self
    {
        return new self($this->metric, $this->people, $this->aggregate, $this->projects, $this->turns, $this->compareBy, $this->splitBy, $form, $this->curve);
    }
}
