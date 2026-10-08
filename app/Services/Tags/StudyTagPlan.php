<?php

namespace App\Services\Tags;

use App\Models\Expert;
use App\Models\Project;

/** What tags:seed-study would write, worked out before anything is written. */
final readonly class StudyTagPlan
{
    /**
     * @param  list<array{project: Project, condition: string}>  $projects  cells whose title names a condition
     * @param  list<array{expert: Expert, projects: list<int>}>  $titleHolders  seat-1 personas of status cells
     * @param  list<array{expert: Expert, status: list<int>, control: list<int>}>  $conflicts  seat 1 in a status cell, seated in a control cell too
     * @param  list<Project>  $withoutSeatOne  status cells with nobody on seat 1
     * @param  int  $unmatched  projects whose title names no condition
     */
    public function __construct(
        public array $projects,
        public array $titleHolders,
        public array $conflicts,
        public array $withoutSeatOne,
        public int $unmatched,
    ) {}
}
