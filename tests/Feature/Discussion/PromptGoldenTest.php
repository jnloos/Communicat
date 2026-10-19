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
 * The prompts of the study must not move when attributes are off. The files in
 * tests/Fixtures/prompts are the output of the views of the commit before the
 * attributes feature (rendered from `git show`, for the fixed project of
 * PromptFixtures); this renders the current views and compares byte for byte.
 */
class PromptGoldenTest extends TestCase
{
    use PromptFixtures, RefreshDatabase;

    private function fixture(string $name): string
    {
        return File::get(base_path("tests/Fixtures/prompts/{$name}.txt"));
    }

    public function test_with_visibility_none_every_prompt_equals_the_pre_feature_text(): void
    {
        $rendered = $this->renderFixturePrompts($this->fixtureProject());

        $this->assertSame(['speak', 'think.speaker', 'think.prioritize', 'think.propose', 'select.orchestrator', 'select.judge'], array_keys($rendered));
        foreach ($rendered as $name => $text) {
            $this->assertSame($this->fixture($name), $text, $name);
        }
    }

    public function test_with_attributes_defined_and_valued_but_visibility_none_the_prompts_stay_the_same(): void
    {
        $project = $this->fixtureProject();
        $gender = PersonaAttribute::factory()->create(['name' => 'gender', 'self_template' => 'You are a :value.', 'roster_template' => ':value']);
        $origin = PersonaAttribute::factory()->create(['name' => 'origin', 'self_template' => 'You grew up in :value.', 'roster_template' => 'from :value']);
        foreach ($project->contributingExperts() as $expert) {
            $expert->setAttributeValue($gender, 'woman');
            $expert->setAttributeValue($origin, 'Owerri, Nigeria');
        }

        $this->assertSame(AttributeVisibility::None, $project->attribute_visibility);
        foreach ($this->renderFixturePrompts($project->fresh()) as $name => $text) {
            $this->assertSame($this->fixture($name), $text, $name);
        }
    }

    /**
     * Found by file search, so a template added later that includes the persona or
     * participants partial is covered without touching this test.
     */
    public function test_every_template_with_a_persona_or_participants_partial_shows_the_attribute_at_all(): void
    {
        $project = $this->fixtureProject();
        $project->update(['attribute_visibility' => AttributeVisibility::All]);
        $project = $project->fresh();
        $alice = Expert::findOrFail(11);
        $alice->setAttributeValue(PersonaAttribute::factory()->create(['name' => 'origin', 'self_template' => 'SELF-MARK :value.', 'roster_template' => 'ROSTER-MARK :value']), 'Owerri');
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

        // The two partials themselves hold no include; the six prompts of the study do.
        $this->assertEqualsCanonicalizing(['speak', 'think.speaker', 'think.prioritize', 'think.propose', 'select.orchestrator', 'select.judge'], $checked);
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
