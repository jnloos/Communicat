<?php

namespace App\Services\Tags;

use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use Illuminate\Support\Facades\DB;

/**
 * Tags the study cells that ran before tags existed: the condition from the
 * title, and the persona on seat 1 of every status cell as the title holder —
 * unless that persona also sits in a control cell, where it held no title, in
 * which case it is reported instead of tagged.
 */
class StudyTagSeeder
{
    public const CONDITION_CATEGORY = 'bedingung';

    public const STATUS = 'status';

    public const CONTROL = 'kontrolle';

    public const TITLE_CATEGORY = 'titeltraeger';

    public const TITLE_YES = 'ja';

    /** "without" first: "with status" is not a substring of it, but the order states the intent. */
    public function conditionFor(string $title): ?string
    {
        if (preg_match('/\bwithout status\b/i', $title)) {
            return self::CONTROL;
        }

        if (preg_match('/\bwith status\b/i', $title)) {
            return self::STATUS;
        }

        return null;
    }

    public function plan(): StudyTagPlan
    {
        $projects = Project::with('experts')->orderBy('id')->get();
        $cells = [];
        $seatedInControl = [];
        $titled = [];
        $withoutSeatOne = [];

        foreach ($projects as $project) {
            $condition = $this->conditionFor($project->title);

            if ($condition === null) {
                continue;
            }

            $cells[] = ['project' => $project, 'condition' => $condition];

            if ($condition === self::CONTROL) {
                foreach ($project->experts as $expert) {
                    $seatedInControl[$expert->id][] = $project->id;
                }

                continue;
            }

            $holder = $project->experts->first(fn (Expert $expert) => (int) $expert->pivot->seat === 1);

            if ($holder === null) {
                $withoutSeatOne[] = $project;

                continue;
            }

            $titled[$holder->id]['expert'] = $holder;
            $titled[$holder->id]['projects'][] = $project->id;
        }

        $titleHolders = [];
        $conflicts = [];

        foreach ($titled as $expertId => $row) {
            if (isset($seatedInControl[$expertId])) {
                $conflicts[] = ['expert' => $row['expert'], 'status' => $row['projects'], 'control' => $seatedInControl[$expertId]];
            } else {
                $titleHolders[] = $row;
            }
        }

        return new StudyTagPlan($cells, $titleHolders, $conflicts, $withoutSeatOne, $projects->count() - count($cells));
    }

    /** Idempotent: categories and values are found or created, and setTag replaces. */
    public function apply(StudyTagPlan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            $condition = $this->category(TagScope::Project, self::CONDITION_CATEGORY);
            $values = [
                self::STATUS => $this->value($condition, self::STATUS),
                self::CONTROL => $this->value($condition, self::CONTROL),
            ];

            foreach ($plan->projects as $row) {
                $row['project']->setTag($condition, $values[$row['condition']]);
            }

            $titleCategory = $this->category(TagScope::Persona, self::TITLE_CATEGORY);
            $yes = $this->value($titleCategory, self::TITLE_YES);

            foreach ($plan->titleHolders as $row) {
                $row['expert']->setTag($titleCategory, $yes);
            }
        });
    }

    private function category(TagScope $scope, string $name): TagCategory
    {
        return TagCategory::firstOrCreate(['scope' => $scope->value, 'name' => $name]);
    }

    private function value(TagCategory $category, string $name): TagValue
    {
        return $category->values()->firstOrCreate(['name' => $name]);
    }
}
