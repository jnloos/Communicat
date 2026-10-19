<?php

namespace Tests\Feature\Discussion;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Agents\SummarizeAgent;
use App\Discussion\Agents\ThinkAgent;
use App\Discussion\Support\PersonaLines;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\TurnRunner;
use App\Discussion\Values\AttributeVisibility;
use App\Discussion\Values\HasDefault;
use App\Discussion\Values\MemoryView;
use App\Events\JobLogged;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class PersonaAttributesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $alice;

    private Expert $bob;

    private PersonaAttribute $gender;

    private PersonaAttribute $origin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create([
            'description' => 'Should the school ban phones?',
            'pipeline' => 'RoundRobinPipeline',
            'seed' => 1,
        ]);
        $this->alice = Expert::factory()->create(['name' => 'Alice', 'role' => 'Student', 'description' => 'Curious.']);
        $this->bob = Expert::factory()->create(['name' => 'Bob', 'role' => 'Teacher', 'description' => 'Strict.']);
        $this->project->addContributingExpert($this->alice);
        $this->project->addContributingExpert($this->bob);

        // Created out of position order on purpose: output must follow `position`.
        $this->origin = PersonaAttribute::factory()->create([
            'name' => 'origin', 'self_template' => 'You grew up in :value.', 'roster_template' => 'from :value', 'position' => 2,
        ]);
        $this->gender = PersonaAttribute::factory()->create([
            'name' => 'gender', 'self_template' => 'You are a :value.', 'roster_template' => ':value', 'position' => 1,
        ]);
        $this->alice->setAttributeValue($this->origin, 'Owerri, Nigeria');
        $this->alice->setAttributeValue($this->gender, 'woman');
    }

    private function persona(): string
    {
        return app(PromptRenderer::class)->render('prompts.partials.persona', ['expert' => $this->alice, 'project' => $this->project->fresh()]);
    }

    private function roster(): string
    {
        $project = $this->project->fresh();

        return app(PromptRenderer::class)->render('prompts.partials.participants', ['experts' => $project->contributingExperts(), 'project' => $project]);
    }

    /** @return array<string, string> every non-blind prompt that carries the persona or participants partial */
    private function prompts(Project $project): array
    {
        $renderer = app(PromptRenderer::class);
        $project = $project->fresh();
        $experts = $project->contributingExperts();
        $memory = new MemoryView([], '', '');
        $common = ['expert' => $this->alice, 'project' => $project, 'experts' => $experts, 'memory' => $memory];

        return [
            'speak' => $renderer->render('prompts.speak', $common + ['proposal' => 'A draft.']),
            'think.speaker' => $renderer->render('prompts.think.speaker', $common),
            'think.prioritize' => $renderer->render('prompts.think.prioritize', $common + ['lowest' => 1, 'highest' => 5]),
            'think.propose' => $renderer->render('prompts.think.propose', $common),
            'orchestrator' => $renderer->render('prompts.select.orchestrator', $common),
            'judge' => $renderer->render('prompts.select.judge', $common + ['drafts' => ['E1' => ['name' => 'Alice', 'draft' => 'x']]]),
        ];
    }

    public function test_none_renders_exactly_the_text_from_before_the_feature(): void
    {
        $this->assertSame(AttributeVisibility::None, $this->project->fresh()->attribute_visibility);

        $this->assertSame(
            "=== ABOUT YOU ===\nYou are Alice (your token: {$this->alice->promptId}).\nYour role is Student.\n\nCurious.",
            $this->persona(),
        );
        $this->assertSame(
            "=== PARTICIPANTS (reference tokens) ===\n- Alice [{$this->alice->promptId}] (Student)\n- Bob [{$this->bob->promptId}] (Teacher)",
            $this->roster(),
        );
    }

    public function test_none_equals_a_project_whose_experts_have_no_attributes(): void
    {
        $bare = Project::factory()->create(['description' => $this->project->description]);
        $this->alice->personaAttributes()->detach();
        $this->alice->unsetRelation('personaAttributes');
        $bare->addContributingExpert($this->alice);
        $bare->addContributingExpert($this->bob);
        $withoutAttributes = $this->prompts($bare);

        $this->alice->setAttributeValue($this->origin, 'Owerri, Nigeria');
        $this->alice->setAttributeValue($this->gender, 'woman');

        $this->assertSame($withoutAttributes, $this->prompts($this->project));
    }

    public function test_own_shows_self_lines_in_position_order_and_nothing_in_the_roster(): void
    {
        $this->project->update(['attribute_visibility' => AttributeVisibility::Own]);

        $this->assertSame(
            "=== ABOUT YOU ===\nYou are Alice (your token: {$this->alice->promptId}).\nYour role is Student.\nYou are a woman.\nYou grew up in Owerri, Nigeria.\n\nCurious.",
            $this->persona(),
        );
        $this->assertStringNotContainsString('Nigeria', $this->roster());
        $this->assertStringContainsString('(Student)', $this->roster());
    }

    public function test_all_adds_the_roster_text_behind_the_role(): void
    {
        $this->project->update(['attribute_visibility' => AttributeVisibility::All]);

        $this->assertStringContainsString('(Student, woman, from Owerri, Nigeria)', $this->roster());
        $this->assertStringContainsString("- Bob [{$this->bob->promptId}] (Teacher)", $this->roster(), 'no value, no suffix');
        $this->assertStringContainsString('You are a woman.', $this->persona());
    }

    public function test_every_prompt_carries_the_attribute_only_as_far_as_the_visibility_reveals_it(): void
    {
        $this->project->update(['attribute_visibility' => AttributeVisibility::Own]);
        foreach ($this->prompts($this->project) as $name => $prompt) {
            if (in_array($name, ['orchestrator', 'judge'], true)) {
                $this->assertStringNotContainsString('Nigeria', $prompt, $name);
            } else {
                $this->assertStringContainsString('You grew up in Owerri, Nigeria.', $prompt, $name);
                $this->assertStringNotContainsString('from Owerri', $prompt, $name);
            }
        }

        $this->project->update(['attribute_visibility' => AttributeVisibility::All]);
        foreach ($this->prompts($this->project) as $name => $prompt) {
            $this->assertStringContainsString('from Owerri, Nigeria', $prompt, $name);
        }
    }

    public function test_the_blind_judge_never_sees_an_attribute(): void
    {
        $this->project->update(['attribute_visibility' => AttributeVisibility::All]);

        $prompt = app(PromptRenderer::class)->render('prompts.select.blind-judge', [
            'project' => $this->project->fresh(),
            'memory' => new MemoryView([['token' => 'E1', 'name' => 'Alice', 'content' => 'Hello.']], '', 'Summary.'),
            'drafts' => ['A' => 'A draft.', 'B' => 'Another draft.'],
        ]);

        $this->assertStringNotContainsString('Nigeria', $prompt);
        $this->assertStringNotContainsString('woman', $prompt);
        $this->assertStringNotContainsString('PARTICIPANTS', $prompt);
    }

    public function test_the_first_turn_freezes_visibility_and_the_rendered_lines(): void
    {
        Event::fake([PipelineStageChanged::class, JobLogged::class]);
        $this->fakeModels();
        $this->project->update(['attribute_visibility' => AttributeVisibility::All]);

        app(TurnRunner::class)->run($this->project);

        $config = $this->project->fresh()->run_config;
        $this->assertSame('all', $config['attribute_visibility']);
        $this->assertSame([
            ['attribute' => 'gender', 'value' => 'woman', 'self_line' => 'You are a woman.', 'roster' => 'woman'],
            ['attribute' => 'origin', 'value' => 'Owerri, Nigeria', 'self_line' => 'You grew up in Owerri, Nigeria.', 'roster' => 'from Owerri, Nigeria'],
        ], $config['cast'][0]['attributes']);
        $this->assertSame([], $config['cast'][1]['attributes']);
    }

    public function test_the_snapshot_records_the_lines_even_when_nothing_is_revealed(): void
    {
        Event::fake([PipelineStageChanged::class, JobLogged::class]);
        $this->fakeModels();

        app(TurnRunner::class)->run($this->project);

        $config = $this->project->fresh()->run_config;
        $this->assertSame('none', $config['attribute_visibility']);
        $this->assertCount(2, $config['cast'][0]['attributes']);
    }

    public function test_frozen_lines_beat_an_edited_template_and_an_edited_value(): void
    {
        Event::fake([PipelineStageChanged::class, JobLogged::class]);
        $this->fakeModels();
        $this->project->update(['attribute_visibility' => AttributeVisibility::All]);
        app(TurnRunner::class)->run($this->project);

        $this->gender->update(['self_template' => 'Edited: :value.', 'roster_template' => 'edited :value']);
        $this->alice->setAttributeValue($this->origin, 'Lagos');
        $this->alice->unsetRelation('personaAttributes');

        $lines = PersonaLines::for($this->project->fresh(), $this->alice->fresh());

        $this->assertSame(['You are a woman.', 'You grew up in Owerri, Nigeria.'], $lines->selfLines());
        $this->assertSame('woman, from Owerri, Nigeria', $lines->rosterText());
    }

    public function test_an_expert_missing_from_the_snapshot_reads_the_live_values(): void
    {
        Event::fake([PipelineStageChanged::class, JobLogged::class]);
        $this->fakeModels();
        $this->project->update(['attribute_visibility' => AttributeVisibility::All]);
        app(TurnRunner::class)->run($this->project);

        $late = Expert::factory()->create();
        $late->setAttributeValue($this->gender, 'man');
        $project = $this->project->fresh();
        $project->addContributingExpert($late);

        $lines = PersonaLines::for($project->fresh(), $late);

        $this->assertSame(['You are a man.'], $lines->selfLines());
        $this->assertSame('man', $lines->rosterText());
    }

    public function test_lines_are_empty_when_the_visibility_is_too_weak_or_there_is_no_value(): void
    {
        $none = PersonaLines::for($this->project->fresh(), $this->alice);
        $this->assertSame([], $none->selfLines());
        $this->assertNull($none->rosterText());

        $this->project->update(['attribute_visibility' => AttributeVisibility::Own]);
        $own = PersonaLines::for($this->project->fresh(), $this->alice);
        $this->assertCount(2, $own->selfLines());
        $this->assertNull($own->rosterText());

        $this->project->update(['attribute_visibility' => AttributeVisibility::All]);
        $this->assertNull(PersonaLines::for($this->project->fresh(), $this->bob)->rosterText());
    }

    public function test_a_template_is_plain_text_and_runs_no_code(): void
    {
        $this->origin->update(['self_template' => '{{ 1 + 1 }} <?php echo 7; ?> :value']);
        $this->project->update(['attribute_visibility' => AttributeVisibility::Own]);

        $this->assertSame('{{ 1 + 1 }} <?php echo 7; ?> Owerri, Nigeria', $this->origin->selfLine('Owerri, Nigeria'));
        $this->assertStringContainsString('{{ 1 + 1 }}', PersonaLines::for($this->project->fresh(), $this->alice->fresh())->selfLines()[1]);
    }

    public function test_an_unknown_frozen_visibility_reads_as_none_and_does_not_throw(): void
    {
        $this->project->update(['attribute_visibility' => AttributeVisibility::All, 'run_config' => ['pipeline' => 'RoundRobinPipeline']]);

        foreach (['bogus', '', 7, ['all'], true] as $bad) {
            $this->project->update(['run_config' => ['pipeline' => 'RoundRobinPipeline', 'attribute_visibility' => $bad]]);
            $project = $this->project->fresh();

            $this->assertSame(AttributeVisibility::None, PersonaLines::visibilityOf($project), json_encode($bad));
            $this->assertSame([], PersonaLines::for($project, $this->alice)->selfLines());
            $this->assertSame('', PersonaLines::for($project, $this->alice)->rosterSuffix());
        }

        // Without a frozen value the project's own column decides.
        $this->project->update(['run_config' => ['pipeline' => 'RoundRobinPipeline']]);
        $this->assertSame(AttributeVisibility::All, PersonaLines::visibilityOf($this->project->fresh()));
    }

    public function test_visibility_is_a_defaulting_enum(): void
    {
        $this->assertInstanceOf(HasDefault::class, AttributeVisibility::None);
        $this->assertSame(AttributeVisibility::None, AttributeVisibility::default());
        $this->assertFalse(AttributeVisibility::None->showsOwnLines());
        $this->assertTrue(AttributeVisibility::Own->showsOwnLines());
        $this->assertFalse(AttributeVisibility::Own->showsRoster());
        $this->assertTrue(AttributeVisibility::All->showsOwnLines());
        $this->assertTrue(AttributeVisibility::All->showsRoster());
    }

    public function test_a_new_project_defaults_to_none(): void
    {
        $this->assertSame(AttributeVisibility::None, (new Project)->attribute_visibility);
        $this->assertSame('none', DB::table('projects')->value('attribute_visibility'));
    }

    public function test_setting_a_blank_value_removes_the_row_and_sync_sets_several(): void
    {
        $this->alice->setAttributeValue($this->gender, '  ');
        $this->assertDatabaseMissing('expert_persona_attribute', ['expert_id' => $this->alice->id, 'persona_attribute_id' => $this->gender->id]);

        $this->alice->syncAttributeValues([$this->gender->id => 'nonbinary person', $this->origin->id => null, 9999 => 'ignored']);

        $this->assertSame(
            ['gender' => 'nonbinary person'],
            $this->alice->fresh()->personaAttributes->pluck('pivot.value', 'name')->all(),
        );
    }

    public function test_deleting_an_expert_or_an_attribute_removes_the_pivot_rows(): void
    {
        $this->assertSame(2, DB::table('expert_persona_attribute')->count());

        $this->gender->delete();
        $this->assertSame(1, DB::table('expert_persona_attribute')->count());

        $this->alice->delete();
        $this->assertSame(0, DB::table('expert_persona_attribute')->count());
    }

    public function test_name_uniqueness_ignores_case_and_surrounding_space(): void
    {
        $this->assertTrue(PersonaAttribute::nameTaken('  GENDER '));
        $this->assertFalse(PersonaAttribute::nameTaken('gender', $this->gender->id));
        $this->assertFalse(PersonaAttribute::nameTaken('age'));
    }

    public function test_new_attributes_join_the_end_of_the_order(): void
    {
        $this->assertSame(3, PersonaAttribute::factory()->create()->position);
    }

    public function test_special_characters_in_a_value_reach_the_prompt_as_typed(): void
    {
        $this->project->update(['attribute_visibility' => AttributeVisibility::All]);
        $this->alice->setAttributeValue($this->origin, 'Zürich & "Bern" <b>5 > 3</b>');

        $persona = $this->persona();
        $roster = $this->roster();

        // Blade escapes, PromptRenderer decodes once: the text is what was typed.
        $this->assertStringContainsString('You grew up in Zürich & "Bern" <b>5 > 3</b>.', $persona);
        $this->assertStringContainsString('from Zürich & "Bern" <b>5 > 3</b>)', $roster);
        $this->assertStringNotContainsString('&quot;', $persona);
    }

    public function test_an_entity_typed_in_a_value_is_not_decoded_a_second_time(): void
    {
        $this->project->update(['attribute_visibility' => AttributeVisibility::Own]);
        $this->alice->setAttributeValue($this->origin, 'R&amp;D &lt;lab&gt;');

        // Blade double-encodes the ampersand, the renderer decodes once: the literal text survives.
        $this->assertStringContainsString('You grew up in R&amp;D &lt;lab&gt;.', $this->persona());
    }

    public function test_a_value_that_contains_the_placeholder_is_not_substituted_again(): void
    {
        $this->origin->update(['self_template' => ':value / :value / again :value']);
        $this->project->update(['attribute_visibility' => AttributeVisibility::Own]);
        $this->alice->setAttributeValue($this->origin, 'the :value token');

        $this->assertStringContainsString('the :value token / the :value token / again the :value token', $this->persona());
    }

    public function test_a_template_with_several_placeholders_fills_each(): void
    {
        $this->gender->update(['roster_template' => ':value (really :value)']);
        $this->project->update(['attribute_visibility' => AttributeVisibility::All]);

        $this->assertStringContainsString('(Student, woman (really woman), from Owerri, Nigeria)', $this->roster());
    }

    public function test_an_attribute_deleted_after_the_first_turn_still_reaches_the_prompts_of_that_run(): void
    {
        Event::fake([PipelineStageChanged::class, JobLogged::class]);
        $this->fakeModels();
        $this->project->update(['attribute_visibility' => AttributeVisibility::All]);
        app(TurnRunner::class)->run($this->project);

        $this->gender->delete();

        $project = $this->project->fresh();
        $this->assertSame(['You are a woman.', 'You grew up in Owerri, Nigeria.'], PersonaLines::for($project, $this->alice->fresh())->selfLines());
        $this->assertStringContainsString('You are a woman.', $this->persona());
        $this->assertStringContainsString('(Student, woman, from Owerri, Nigeria)', $this->roster());
    }

    public function test_a_run_from_before_the_feature_has_no_attributes_key_and_reads_the_live_values(): void
    {
        $this->project->update([
            'attribute_visibility' => AttributeVisibility::All,
            'run_config' => ['pipeline' => 'RoundRobinPipeline', 'cast' => [
                ['seat' => 1, 'expert_id' => $this->alice->id, 'name' => 'Alice', 'role' => 'Student'],
            ]],
        ]);

        $lines = PersonaLines::for($this->project->fresh(), $this->alice);

        $this->assertSame(['You are a woman.', 'You grew up in Owerri, Nigeria.'], $lines->selfLines());
        $this->assertSame(', woman, from Owerri, Nigeria', $lines->rosterSuffix());
    }

    public function test_the_model_accepts_a_value_of_exactly_the_limit_and_trims_before_counting(): void
    {
        $limit = str_repeat('ä', PersonaAttribute::VALUE_MAX);

        $this->alice->setAttributeValue($this->origin, "  {$limit}\n");

        $this->assertSame($limit, $this->alice->fresh()->personaAttributes->firstWhere('name', 'origin')->pivot->value);
    }

    public function test_the_model_refuses_a_value_over_the_limit_or_with_a_line_break(): void
    {
        foreach ([
            str_repeat('x', PersonaAttribute::VALUE_MAX + 1) => 'at most',
            "two\nlines" => 'line break',
            "two\rlines" => 'line break',
        ] as $value => $message) {
            try {
                $this->alice->setAttributeValue($this->origin, (string) $value);
                $this->fail('accepted '.json_encode($value));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->assertSame('Owerri, Nigeria', $this->alice->fresh()->personaAttributes->firstWhere('name', 'origin')->pivot->value, 'the old value stays');
    }

    public function test_syncing_a_form_with_one_bad_value_throws_before_writing_any(): void
    {
        try {
            $this->alice->syncAttributeValues([$this->gender->id => 'man', $this->origin->id => str_repeat('x', PersonaAttribute::VALUE_MAX + 1)]);
            $this->fail('accepted a value over the limit');
        } catch (InvalidArgumentException) {
            $this->assertSame('woman', $this->alice->fresh()->personaAttributes->firstWhere('name', 'gender')->pivot->value);
        }
    }

    private function fakeModels(): void
    {
        FakeAgents::always(ThinkAgent::class, ['thought' => 'A thought.']);
        FakeAgents::always(SpeakAgent::class, ['contribution' => 'A contribution.', 'addressee' => null]);
        FakeAgents::always(SummarizeAgent::class, ['summary' => 'A summary.']);
    }
}
