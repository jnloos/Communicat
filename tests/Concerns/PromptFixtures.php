<?php

namespace Tests\Concerns;

use App\Discussion\Support\PromptRenderer;
use App\Discussion\Values\AttributeVisibility;
use App\Discussion\Values\MemoryView;
use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;

/**
 * One fixed project (fixed texts, ids, names, roles as the Role attribute, empty memory) and the
 * six prompts that carry the persona or participants partial, rendered through
 * whatever views are currently registered. The golden test compares the result
 * with the files in tests/Fixtures/prompts, which were rendered from the views
 * of the commit before the attributes feature, when the role was a fixed line:
 * the same text now needs the Role attribute valued and visibility `all`.
 */
trait PromptFixtures
{
    /** @var list<string> */
    private const PROMPTS = ['speak', 'think.speaker', 'think.prioritize', 'think.propose', 'select.orchestrator', 'select.judge'];

    private function fixtureProject(AttributeVisibility $visibility = AttributeVisibility::All): Project
    {
        $project = Project::factory()->create([
            'title' => 'Fixture cell',
            'description' => 'Should the school ban phones during lessons?',
            'pipeline' => 'RoundRobinPipeline',
            'seed' => 1,
        ]);

        foreach ([
            [11, 'Alice Fixture', 'Student', 'Curious and quick to ask why.'],
            [12, 'Bob Fixture', 'Teacher', 'Strict about the timetable.'],
            [13, 'Cleo Fixture', 'Parent', 'Worries about safety on the way home.'],
        ] as [$id, $name, $role, $description]) {
            $project->addContributingExpert(Expert::factory()->withRole($role)->create(['id' => $id, 'name' => $name, 'description' => $description]));
        }

        $this->revealAll($project, $visibility);

        return $project->fresh();
    }

    /** Sets the level of every attribute defined right now (so call it after the attributes exist). */
    private function revealAll(Project $project, AttributeVisibility $level): void
    {
        $project->setAttributeVisibilities(
            PersonaAttribute::pluck('id')->mapWithKeys(fn (int $id) => [$id => $level])->all(),
        );
    }

    /**
     * Every variable any prompt of the study reads, for the first expert as the
     * speaker. A superset on purpose: a view ignores what it does not use, and a
     * template found by file search needs no special case.
     *
     * @return array<string, mixed>
     */
    private function promptData(Project $project): array
    {
        $experts = $project->contributingExperts();

        return [
            'expert' => $experts->first(),
            'project' => $project,
            'experts' => $experts,
            'memory' => new MemoryView([], '', ''),
            'proposal' => 'A draft.',
            'thought' => 'A thought.',
            'lowest' => 1,
            'highest' => 5,
            'drafts' => ['E11' => ['name' => 'Alice Fixture', 'draft' => 'First draft.'], 'E12' => ['name' => 'Bob Fixture', 'draft' => 'Second draft.']],
        ];
    }

    /** @return array<string, string> prompt name => rendered text */
    private function renderFixturePrompts(Project $project): array
    {
        $renderer = app(PromptRenderer::class);
        $data = $this->promptData($project);

        $rendered = [];
        foreach (self::PROMPTS as $name) {
            $rendered[$name] = $renderer->render('prompts.'.$name, $data);
        }

        return $rendered;
    }
}
