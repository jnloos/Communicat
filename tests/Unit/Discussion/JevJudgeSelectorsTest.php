<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\JudgeAgent;
use App\Discussion\Memory\Memory;
use App\Discussion\Selectors\BlindJudgeSelector;
use App\Discussion\Selectors\JevBlindJudgeSelector;
use App\Discussion\Selectors\JevJudgeSelector;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Support\TieBreaker;
use App\Discussion\TurnPayload;
use App\Discussion\Values\AttributeVisibility;
use App\Discussion\Values\Purpose;
use App\Discussion\Values\Selection;
use App\Discussion\Values\SelectionRecord;
use App\Discussion\Values\Thought;
use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Tests\Fakes\FakeAgents;
use Tests\Fakes\FakeJev;
use Tests\TestCase;

class JevJudgeSelectorsTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var list<Expert> */
    private array $experts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['seed' => 4711]);
        $this->experts = Expert::factory()->withRole('Chief Executive')->count(4)->create(['description' => 'Beschreibung'])->all();

        foreach ($this->experts as $expert) {
            $this->project->addContributingExpert($expert);
        }

        $this->project->setAttributeVisibilities(PersonaAttribute::pluck('id')->mapWithKeys(fn (int $id) => [$id => AttributeVisibility::All])->all());
    }

    /** @param  list<int>|null  $drafting  indexes of the experts who handed in a draft; null means all */
    private function payload(?array $drafting = null, int $turnIndex = 1): TurnPayload
    {
        $payload = new TurnPayload($this->project->fresh(), $turnIndex);

        foreach ($this->experts as $index => $expert) {
            $drafted = $drafting === null || in_array($index, $drafting, true);
            $payload->addThought(new Thought($expert->id, 'GEDANKE-GEHEIM', proposal: $drafted ? "Entwurf {$index} ".str_repeat('x', 300) : null));
        }

        return $payload;
    }

    private function judge(?array $drafting = null, int $turnIndex = 1): Selection
    {
        return app(JevJudgeSelector::class)->select($this->payload($drafting, $turnIndex));
    }

    private function blind(?array $drafting = null, int $turnIndex = 1): Selection
    {
        return app(JevBlindJudgeSelector::class)->select($this->payload($drafting, $turnIndex));
    }

    private function token(int $index): string
    {
        return $this->experts[$index]->promptId;
    }

    public function test_the_judge_picks_the_draft_with_the_highest_probability(): void
    {
        FakeJev::choosing([$this->token(0) => 0.1, $this->token(1) => 0.2, $this->token(2) => 0.6, $this->token(3) => 0.1]);

        $selection = $this->judge();

        $this->assertSame($this->experts[2]->id, $selection->speaker->id);
        $this->assertSame('JevJudgeSelector', $selection->selector);
        $this->assertSame($this->token(2), $selection->signals['token']);
        $this->assertFalse($selection->signals['fallback']);
        $this->assertFalse($selection->tieBroken);
    }

    public function test_the_judges_options_are_exactly_the_drafters_and_name_only_who_they_are(): void
    {
        FakeJev::randomly();

        $this->judge([1, 3]);

        $prompt = FakeJev::prompt();
        $question = $prompt->questions['speaker'];
        $description = $question->options[$this->token(1)];

        $this->assertInstanceOf(Choice::class, $question);
        $this->assertSame([$this->token(1), $this->token(3)], array_keys($question->options));
        // Who it is, like the orchestrator's options; the draft itself is not repeated there.
        $this->assertStringStartsWith($this->experts[1]->name, $description);
        $this->assertStringNotContainsString('Entwurf 1', $description);
        $this->assertLessThanOrEqual(60, mb_strlen($description));
        // The whole draft is in the state.
        $this->assertStringContainsString("[{$this->token(1)}] {$this->experts[1]->name}:\nEntwurf 1 ".str_repeat('x', 300), $prompt->state);
    }

    public function test_the_judge_sees_the_participant_list_with_roles_like_the_team_models_judge(): void
    {
        FakeJev::randomly();

        $this->judge();

        $this->assertStringContainsString("{$this->experts[0]->name} [{$this->token(0)}] (Chief Executive)", FakeJev::prompt()->state);
    }

    public function test_the_judges_state_and_decision_are_what_the_judge_reads(): void
    {
        FakeJev::randomly();
        $payload = $this->payload();

        app(JevJudgeSelector::class)->select($payload);

        $prompt = FakeJev::prompt();
        $project = $payload->project;
        $drafters = $project->contributingExperts()->keyBy(fn (Expert $e) => $e->promptId);
        $judge = app(PromptRenderer::class)->render('prompts.select.judge', [
            'project' => $project,
            'memory' => app(Memory::class)->viewFor($project),
            'drafts' => $drafters->map(fn (Expert $e) => ['name' => $e->name, 'draft' => $payload->thoughtOf($e)->proposal])->all(),
        ] + app(PromptRenderer::class)->participants($project));

        $this->assertSame(JevJudgeSelector::DECISION, $prompt->questions['speaker']->instructions);
        $this->assertSame($judge, $prompt->state."\n\n=== DECISION ===\n".JevJudgeSelector::DECISION);
    }

    public function test_a_judge_tie_goes_to_the_tie_breaker(): void
    {
        FakeJev::choosing([$this->token(0) => 0.25, $this->token(1) => 0.25, $this->token(2) => 0.25, $this->token(3) => 0.25]);

        $selection = $this->judge(turnIndex: 3);

        $this->assertTrue($selection->tieBroken);
        $this->assertFalse($selection->signals['fallback']);
        $this->assertSame(app(TieBreaker::class)->pick($this->experts, $this->project, 3)->id, $selection->speaker->id);
    }

    public function test_a_failed_judge_request_falls_back_among_the_drafters_and_is_recorded_as_a_judge_call(): void
    {
        FakeJev::fails(ProviderOverloadedException::forProvider('typesafe'));

        $selection = $this->judge([1, 2]);

        $this->assertTrue($selection->signals['fallback']);
        $this->assertTrue($selection->tieBroken);
        $this->assertContains($selection->speaker->id, [$this->experts[1]->id, $this->experts[2]->id]);
        $this->assertSame(Purpose::Judge, PromptLog::sole()->purpose);
    }

    public function test_without_a_draft_nothing_is_asked_and_the_floor_is_drawn(): void
    {
        FakeJev::randomly();

        $selection = $this->judge([]);

        $this->assertTrue($selection->signals['fallback']);
        $this->assertFalse($selection->signals['jev_asked'], 'a draw for lack of drafts says nothing about Jev');
        $this->assertTrue($selection->tieBroken);
        $this->assertSame([], FakeJev::prompts());
    }

    public function test_a_single_draft_wins_without_a_question_and_without_a_probability(): void
    {
        FakeJev::randomly();

        $selection = $this->judge([2]);

        $this->assertSame($this->experts[2]->id, $selection->speaker->id);
        $this->assertFalse($selection->signals['fallback']);
        $this->assertFalse($selection->signals['jev_asked']);
        $this->assertSame([], $selection->signals['jev_probabilities']);
        $this->assertSame([], FakeJev::prompts());
    }

    public function test_the_blind_judge_offers_letters_and_leaks_no_name_token_or_role(): void
    {
        $this->project->addMessage('Ein Beitrag.', $this->experts[0]);
        FakeJev::randomly();

        $this->blind();

        $prompt = FakeJev::prompt();
        $question = $prompt->questions['speaker'];
        $everything = $prompt->state.json_encode($question->toArray());

        $this->assertSame(['A', 'B', 'C', 'D'], array_keys($question->options));
        $this->assertSame(['Draft A', 'Draft B', 'Draft C', 'Draft D'], array_values($question->options));

        foreach ($this->experts as $expert) {
            $this->assertStringNotContainsString($expert->name, $everything);
            $this->assertStringNotContainsString($expert->promptId.']', $everything);
            $this->assertStringNotContainsString("[{$expert->promptId}", $everything);
            $this->assertStringNotContainsString(' '.$expert->promptId, $everything);
        }

        $this->assertStringNotContainsString('Chief Executive', $everything);
        $this->assertStringNotContainsString('PARTICIPANTS', $everything);
        $this->assertStringNotContainsString('GEDANKE-GEHEIM', $everything);
        $this->assertStringContainsString('[A]', $prompt->state);
    }

    public function test_the_blind_state_and_decision_are_what_the_blind_judge_reads(): void
    {
        FakeJev::randomly();
        FakeAgents::always(JudgeAgent::class, ['label' => 'A', 'reasoning' => '-']);

        $this->blind();
        $this->blindTeamJudge();

        $prompt = FakeJev::prompt();

        $this->assertSame(JevBlindJudgeSelector::DECISION, $prompt->questions['speaker']->instructions);
        JudgeAgent::assertPrompted(fn ($team) => $team->prompt === $prompt->state."\n\n=== DECISION ===\n".JevBlindJudgeSelector::DECISION);
    }

    public function test_the_letters_are_the_ones_the_team_models_blind_judge_would_use(): void
    {
        FakeJev::randomly();
        FakeAgents::always(JudgeAgent::class, ['label' => 'A', 'reasoning' => '-']);

        $jev = $this->blind(turnIndex: 7)->signals['order'];
        $team = app(BlindJudgeSelector::class)->select($this->payload(turnIndex: 7))->signals['order'];

        $this->assertSame($team, $jev);
    }

    public function test_the_chosen_letter_maps_back_to_its_author_and_keeps_its_probability(): void
    {
        FakeJev::randomly();
        $order = $this->blind()->signals['order'];

        FakeJev::choosing(['A' => 0.1, 'B' => 0.2, 'C' => 0.6, 'D' => 0.1]);
        $selection = $this->blind();

        $this->assertSame($order['C'], $selection->speaker->id);
        $this->assertSame('C', $selection->signals['label']);
        $this->assertSame($order, $selection->signals['order']);
        $this->assertSame(['A' => 0.1, 'B' => 0.2, 'C' => 0.6, 'D' => 0.1], $selection->signals['jev_probabilities']);
        $this->assertSame(0.6, SelectionRecord::fromArray($selection->toArray())->speakerProbability($order['C']));
    }

    public function test_a_blind_tie_is_drawn_and_a_failure_falls_back_with_the_order_recorded(): void
    {
        FakeJev::choosing(['A' => 0.5, 'B' => 0.5, 'C' => 0.0, 'D' => 0.0]);
        $tied = $this->blind(turnIndex: 4);

        $this->assertTrue($tied->tieBroken);
        $this->assertFalse($tied->signals['fallback']);
        $this->assertContains($tied->signals['label'], ['A', 'B']);

        FakeJev::fails(ProviderOverloadedException::forProvider('typesafe'));
        $failed = $this->blind(turnIndex: 4);

        $this->assertTrue($failed->signals['fallback']);
        $this->assertNull($failed->signals['label']);
        $this->assertCount(4, $failed->signals['order']);
    }

    private function blindTeamJudge(): void
    {
        app(BlindJudgeSelector::class)->select($this->payload());
    }
}
