<?php

namespace Tests\Feature\Discussion;

use App\Discussion\Support\PromptRenderer;
use App\Discussion\Values\AttributeVisibility;
use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\View\ViewException;
use Tests\Concerns\PromptFixtures;
use Tests\TestCase;
use Throwable;

/**
 * The prompts of the study must not move. The files in tests/Fixtures/prompts are
 * the output of the views of the commit before the attributes feature, when the
 * role was a fixed line (rendered from `git show`, for the fixed project of
 * PromptFixtures). The role is now the Role attribute, so the same text appears
 * with visibility `all` and only Role defined; with `own` the role is a self line
 * only, with `none` it is gone.
 */
class PromptGoldenTest extends TestCase
{
    use PromptFixtures, RefreshDatabase;

    private function fixture(string $name): string
    {
        return File::get(base_path("tests/Fixtures/prompts/{$name}.txt"));
    }

    public function test_with_visibility_all_and_only_the_role_every_prompt_equals_the_pre_feature_text(): void
    {
        $rendered = $this->renderFixturePrompts($this->fixtureProject());

        $this->assertSame(['speak', 'think.speaker', 'think.prioritize', 'think.propose', 'select.orchestrator', 'select.judge'], array_keys($rendered));
        foreach ($rendered as $name => $text) {
            $this->assertSame($this->fixture($name), $text, $name);
        }
    }

    public function test_with_visibility_none_the_role_appears_in_no_prompt(): void
    {
        $rendered = $this->renderFixturePrompts($this->fixtureProject(AttributeVisibility::None));

        foreach ($rendered as $name => $text) {
            foreach (['Student', 'Teacher', 'Parent', 'Your role is'] as $role) {
                $this->assertStringNotContainsString($role, $text, "{$name}: {$role}");
            }
        }
    }

    public function test_with_visibility_own_the_role_is_a_self_line_and_not_in_the_roster(): void
    {
        $rendered = $this->renderFixturePrompts($this->fixtureProject(AttributeVisibility::Own));

        $this->assertStringContainsString("Your role is Student.\n", $rendered['speak']);
        $this->assertStringContainsString('Your role is Student.', $rendered['think.speaker']);
        foreach ($rendered as $name => $text) {
            foreach (['(Student)', '(Teacher)', '(Parent)'] as $roster) {
                $this->assertStringNotContainsString($roster, $text, "{$name}: {$roster}");
            }
        }
        foreach (['select.orchestrator', 'select.judge'] as $name) {
            $this->assertStringNotContainsString('Teacher', $rendered[$name], $name);
        }
    }

    public function test_with_attributes_defined_and_valued_but_visibility_none_the_other_attributes_stay_out(): void
    {
        $project = $this->fixtureProject(AttributeVisibility::None);
        $before = $this->renderFixturePrompts($project);
        $gender = PersonaAttribute::factory()->create(['name' => 'gender', 'self_template' => 'You are a :value.', 'roster_template' => ':value']);
        foreach ($project->contributingExperts() as $expert) {
            $expert->setAttributeValue($gender, 'woman');
        }

        $this->assertSame($before, $this->renderFixturePrompts($project->fresh()));
    }

    /**
     * Found by file search, so a template added later that includes the persona or
     * participants partial is covered without touching this test.
     */
    public function test_every_template_with_a_persona_or_participants_partial_shows_the_attribute_at_all(): void
    {
        $project = $this->fixtureProject();
        $alice = Expert::findOrFail(11);
        $alice->setAttributeValue(PersonaAttribute::factory()->create(['name' => 'origin', 'self_template' => 'SELF-MARK :value.', 'roster_template' => 'ROSTER-MARK :value']), 'Owerri');
        $this->revealAll($project, AttributeVisibility::All);
        $project = $project->fresh();
        $data = ['expert' => $alice->fresh()] + $this->promptData($project);

        $root = resource_path('views/prompts');
        $checked = [];
        foreach (File::allFiles($root) as $file) {
            $relative = str_replace(['/', '.blade.php'], ['.', ''], $file->getRelativePathname());
            $source = $file->getContents();
            $persona = str_contains($source, 'prompts.partials.persona');
            $roster = str_contains($source, 'prompts.partials.participants');

            if (! $persona && ! $roster) {
                continue;
            }

            $text = app(PromptRenderer::class)->render('prompts.'.$relative, $data);
            $persona && $this->assertStringContainsString('SELF-MARK Owerri.', $text, $relative);
            $roster && $this->assertStringContainsString('(Student, ROSTER-MARK Owerri)', $text, $relative);
            $checked[] = $relative;
        }

        // The two partials themselves hold no include. The prompts of the study reach them either directly or
        // through a shared state view (think/prioritize-state, select/orchestrator-state, select/judge-state),
        // which the team prompts and the states Jev reads both include; the blind judge has neither, by design.
        $this->assertEqualsCanonicalizing(['speak', 'think.speaker', 'think.propose', 'think.prioritize-state', 'select.orchestrator-state', 'select.judge-state'], $checked);

        // ...so everything built on those states shows the attribute as well, Jev's views included.
        foreach (['think.prioritize', 'select.orchestrator', 'select.judge', 'jev.bid', 'jev.orchestrator', 'jev.judge'] as $view) {
            $text = app(PromptRenderer::class)->render('prompts.'.$view, $data);
            $this->assertStringContainsString('(Student, ROSTER-MARK Owerri)', $text, $view);
        }
    }

    public function test_a_partial_rendered_without_its_project_fails_instead_of_dropping_the_attributes(): void
    {
        $expert = Expert::factory()->create();

        foreach (['persona' => ['expert' => $expert], 'participants' => ['experts' => collect([$expert])]] as $partial => $data) {
            $failure = null;
            try {
                app(PromptRenderer::class)->render("prompts.partials.{$partial}", $data);
            } catch (Throwable $e) {
                $failure = $e;
            }

            $this->assertNotNull($failure, "{$partial} rendered without a project");
            $this->assertStringContainsString('$project', $failure->getMessage());
        }
    }

    public function test_a_null_project_is_refused_too(): void
    {
        $this->expectException(ViewException::class); // wraps the TypeError of PersonaLines::for()

        app(PromptRenderer::class)->render('prompts.partials.persona', ['expert' => Expert::factory()->create(), 'project' => null]);
    }
}
