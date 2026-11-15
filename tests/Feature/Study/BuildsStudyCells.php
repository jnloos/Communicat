<?php

namespace Tests\Feature\Study;

use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;

/** Study cells (tagged projects with a budget of 2 turns) for tests of the progress read model. */
trait BuildsStudyCells
{
    /** @var array<string, TagCategory> */
    private array $categories = [];

    private function value(string $category, string $name): TagValue
    {
        $this->categories[$category] ??= TagCategory::create(['scope' => TagScope::Project, 'name' => $category]);

        return $this->categories[$category]->values()->firstOrCreate(['name' => $name]);
    }

    private function cell(int $spoken = 0, string $pipeline = 'ScorePipeline'): Project
    {
        $project = Project::factory()->create(['pipeline' => $pipeline, 'turn_budget' => 2]);
        $focus = $this->value('focus profile', 'S+G+E+');
        $duo = $this->value('duo profile', 'S+G+E+');
        $project->setTag($this->categories['focus profile'], $focus);
        $project->setTag($this->categories['duo profile'], $duo);

        for ($turn = 1; $turn <= $spoken; $turn++) {
            $this->spokenTurn($project, $turn);
        }

        return $project;
    }

    private function spokenTurn(Project $project, int $turn): void
    {
        JobLog::create([
            'job_class' => 'App\Discussion\TurnRunner', 'project_id' => $project->id, 'status' => 'success',
            'started_at' => now(), 'finished_at' => now(), 'turn_index' => $turn, 'expert_id' => Expert::factory()->create()->id, 'words' => 10,
        ]);
    }
}
