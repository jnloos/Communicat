<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Metrics\SpokenTurns;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\QuestionDefinition;
use App\Discussion\Values\RunPlacement;
use App\Discussion\Values\SpeakerShare;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Places each run of an answer: its panel and which agents form which group,
 * judged over the whole run. Neither depends on the turn window, so the window
 * can be capped by the runs that take part before it is applied to any of them.
 */
final class RunPlacer
{
    public function __construct(
        private readonly SpeakingShares $shares,
        private readonly SpokenTurns $spoken,
    ) {}

    /**
     * A run takes no part (null) without a spoken turn, without a value in a
     * split or run-level compare dimension, or without anyone the people filter
     * accepts.
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, RunPlacement|null> project id => its placement
     */
    public function place(QuestionDefinition $question, Collection $projects, DimensionValues $values): array
    {
        $accepted = $this->acceptedPeople($question, $projects);

        return $projects->mapWithKeys(fn (Project $project) => [$project->id => $this->placeOf($question, $project, $values, $accepted)])->all();
    }

    /**
     * The experts the people filter accepts among the participants of these
     * runs (seated, or spoke and left), in one query; null when it accepts
     * everyone (or the metric describes whole runs and ignores it).
     *
     * @param  Collection<int, Project>  $projects
     * @return array<int, true>|null expert id => true
     */
    private function acceptedPeople(QuestionDefinition $question, Collection $projects): ?array
    {
        $filter = $question->people;

        if ($question->metric->isRunLevel() || $filter->isEveryone()) {
            return null;
        }

        return Expert::whereIn('id', $this->spoken->participantIdsIn($projects->modelKeys()))
            ->whereTagFilter($filter->tags)
            ->when($filter->expertNames !== [], fn ($query) => $query->whereIn('name', $filter->expertNames))
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /** @param  array<int, true>|null  $accepted */
    private function placeOf(QuestionDefinition $question, Project $project, DimensionValues $values, ?array $accepted): ?RunPlacement
    {
        $parts = $this->panelOf($question->splitBy, $project, $values);
        $report = $parts === null ? null : $this->shares->forProject($project);

        if ($report === null || $report->isEmpty()) {
            return null;
        }

        $people = $accepted === null
            ? $report->speakers
            : array_values(array_filter($report->speakers, fn (SpeakerShare $speaker) => isset($accepted[$speaker->expertId])));
        $groups = array_filter(
            $this->grouped($question->compareBy, $project, $values, $people),
            fn (array $speakers) => $question->metric->hasValueFor($speakers),
        );

        return $groups === [] ? null : new RunPlacement(
            $parts,
            array_map(fn (array $speakers) => array_map(fn (SpeakerShare $speaker) => $speaker->expertId, $speakers), $groups),
        );
    }

    /**
     * The run's value in every split dimension, in their order; empty without
     * a split. Null when the run lacks a value in one of them.
     *
     * @param  list<Dimension>  $splitBy
     * @return list<int|string>|null
     */
    private function panelOf(array $splitBy, Project $project, DimensionValues $values): ?array
    {
        $parts = [];

        foreach ($splitBy as $dimension) {
            $key = $values->ofProject($dimension, $project);

            if ($key === null) {
                return null;
            }

            $parts[] = $key;
        }

        return $parts;
    }

    /**
     * @param  list<SpeakerShare>  $people
     * @return array<int|string, list<SpeakerShare>> group key => the group's agents in this run
     */
    private function grouped(?Dimension $compareBy, Project $project, DimensionValues $values, array $people): array
    {
        if ($compareBy === null) {
            return ['' => $people];
        }

        if (! $compareBy->isPersonaLevel()) {
            $key = $values->ofProject($compareBy, $project);

            return $key === null ? [] : [$key => $people];
        }

        $keys = $values->ofExperts($compareBy, array_map(fn (SpeakerShare $speaker) => $speaker->expertId, $people));
        $groups = [];

        foreach ($people as $speaker) {
            $groups[$keys[$speaker->expertId]][] = $speaker;
        }

        return $groups;
    }
}
