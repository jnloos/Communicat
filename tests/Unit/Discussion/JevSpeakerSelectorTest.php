<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Jev\JevNotConfigured;
use App\Discussion\Memory\Memory;
use App\Discussion\Selectors\JevSpeakerSelector;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Support\TieBreaker;
use App\Discussion\TurnPayload;
use App\Discussion\Values\AttributeVisibility;
use App\Discussion\Values\CallStatus;
use App\Discussion\Values\JobStatus;
use App\Discussion\Values\Purpose;
use App\Discussion\Values\Selection;
use App\Discussion\Values\SelectionRecord;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\PersonaAttribute;
use App\Models\Project;
use App\Models\PromptLog;
use App\Models\Summary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Tests\Fakes\FakeJev;
use Tests\TestCase;

class JevSpeakerSelectorTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var list<Expert> */
    private array $experts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['seed' => 4711]);
        $this->experts = Expert::factory()->withRole('Student')->count(3)->create()->all();

        foreach ($this->experts as $expert) {
            $this->project->addContributingExpert($expert);
        }

        $this->project->setAttributeVisibilities(PersonaAttribute::pluck('id')->mapWithKeys(fn (int $id) => [$id => AttributeVisibility::All])->all());
    }

    private function token(int $index): string
    {
        return $this->experts[$index]->promptId;
    }

    private function select(?int $jobLogId = null, int $turnIndex = 1): Selection
    {
        return app(JevSpeakerSelector::class)->select(new TurnPayload($this->project->fresh(), $turnIndex, $jobLogId));
    }

    public function test_the_expert_with_the_highest_probability_gets_the_floor(): void
    {
        FakeJev::choosing([$this->token(0) => 0.1, $this->token(1) => 0.7, $this->token(2) => 0.2]);

        $selection = $this->select();

        $this->assertSame($this->experts[1]->id, $selection->speaker->id);
        $this->assertSame('JevSpeakerSelector', $selection->selector);
        $this->assertFalse($selection->tieBroken);
        $this->assertSame($this->token(1), $selection->signals['token']);
        $this->assertFalse($selection->signals['fallback']);
        $this->assertSame('pick', $selection->signals['jev_kind']);
        $this->assertTrue($selection->signals['jev_asked']);
        $this->assertSame([$this->token(0) => 0.1, $this->token(1) => 0.7, $this->token(2) => 0.2], $selection->signals['jev_probabilities']);
        $this->assertSame(0.9, $selection->signals['jev_confidence']);
        $this->assertSame('jev-1.13.0', $selection->signals['jev_model']);
        $this->assertIsInt($selection->signals['jev_latency_ms']);
        $this->assertSame('', $selection->reasoning);
    }

    public function test_a_tie_in_jevs_probabilities_goes_to_the_tie_breaker_reproducibly(): void
    {
        FakeJev::choosing([$this->token(0) => 0.4, $this->token(1) => 0.4, $this->token(2) => 0.2]);

        $first = $this->select(turnIndex: 5);
        $again = $this->select(turnIndex: 5);
        $expected = app(TieBreaker::class)->pick([$this->experts[0], $this->experts[1]], $this->project, 5);

        $this->assertSame($expected->id, $first->speaker->id);
        $this->assertSame($first->speaker->id, $again->speaker->id);
        $this->assertTrue($first->tieBroken);
        $this->assertFalse($first->signals['fallback']);
        $this->assertSame($first->speaker->promptId, $first->signals['token']);
    }

    public function test_the_same_expert_may_win_twice_in_a_row(): void
    {
        FakeJev::choosing([$this->token(0) => 0.8, $this->token(1) => 0.1, $this->token(2) => 0.1]);

        $this->assertSame($this->experts[0]->id, $this->select(turnIndex: 1)->speaker->id);
        $this->assertSame($this->experts[0]->id, $this->select(turnIndex: 2)->speaker->id);
    }

    public function test_the_options_are_exactly_the_contributing_experts_described_by_name_and_roster_label(): void
    {
        FakeJev::randomly();
        $outsider = Expert::factory()->create();

        $this->select();

        $prompt = FakeJev::prompt();
        $question = $prompt->questions['speaker'];

        $this->assertCount(1, $prompt->questions);
        $this->assertInstanceOf(Choice::class, $question);
        $this->assertSame(array_map(fn (Expert $e) => $e->promptId, $this->experts), array_keys($question->options));
        $this->assertSame($this->experts[0]->name.' (Student)', $question->options[$this->token(0)]);
        $this->assertArrayNotHasKey($outsider->promptId, $question->options);
    }

    public function test_the_roster_label_follows_the_projects_attribute_visibility(): void
    {
        $this->project->setAttributeVisibilities(PersonaAttribute::pluck('id')->mapWithKeys(fn (int $id) => [$id => AttributeVisibility::None])->all());
        FakeJev::randomly();

        $this->select();

        $prompt = FakeJev::prompt();

        $this->assertSame($this->experts[0]->name, $prompt->questions['speaker']->options[$this->token(0)]);
        $this->assertStringNotContainsString('Student', $prompt->state);
    }

    public function test_the_state_carries_the_history_but_never_a_private_thought(): void
    {
        $this->project->addMessage('Ein Beitrag von Alice.', $this->experts[0]);
        Summary::create(['project_id' => $this->project->id, 'expert_id' => $this->experts[0]->id, 'content' => 'GEHEIMER GEDANKE']);
        FakeJev::randomly();

        $this->select();

        $state = FakeJev::prompt()->state;

        $this->assertStringContainsString('Ein Beitrag von Alice.', $state);
        $this->assertStringNotContainsString('GEHEIMER GEDANKE', $state);
    }

    /** The team model's orchestrator and Jev read the same text; only the sentence that asks differs. */
    public function test_the_state_is_what_the_orchestrator_reads_and_the_decision_is_jevs_own_sentence(): void
    {
        $this->project->addMessage('Ein Beitrag von Alice.', $this->experts[0]);
        FakeJev::randomly();

        $this->select();

        $prompt = FakeJev::prompt();
        $project = $this->project->fresh();
        $orchestrator = app(PromptRenderer::class)->render('prompts.select.orchestrator', [
            'project' => $project,
            'memory' => app(Memory::class)->viewFor($project),
        ] + app(PromptRenderer::class)->participants($project));

        $this->assertSame(JevSpeakerSelector::DECISION, $prompt->questions['speaker']->instructions);
        $this->assertStringStartsWith($prompt->state."\n\n=== DECISION ===\n", $orchestrator);
    }

    public function test_the_question_asks_who_speaks_next_and_not_who_contributes_the_most(): void
    {
        $this->assertStringContainsString('most likely to speak next', JevSpeakerSelector::DECISION);
        $this->assertStringNotContainsString('contributes the most', JevSpeakerSelector::DECISION);
    }

    public function test_a_failed_request_falls_back_to_the_tie_breaker_and_is_recorded_without_ending_the_run(): void
    {
        FakeJev::fails(RateLimitedException::forProvider('typesafe'));
        $job = $this->job();

        $selection = $this->select($job->id);

        $this->assertTrue($selection->signals['fallback']);
        $this->assertTrue($selection->tieBroken);
        $this->assertNull($selection->signals['token']);
        $this->assertSame([], $selection->signals['jev_probabilities']);
        $this->assertTrue($selection->signals['jev_asked']);
        $this->assertContains($selection->speaker->id, array_map(fn (Expert $e) => $e->id, $this->experts));

        $log = PromptLog::sole();
        $this->assertSame(CallStatus::Failed, $log->status);
        $this->assertStringContainsString('RateLimitedException', $log->error);
        $this->assertSame(3, $log->config['attempts'], 'the request was sent again twice before it gave up');
        $this->assertSame($job->id, $log->job_log_id);
    }

    public function test_a_failover_event_still_reaches_the_failover_guard(): void
    {
        Log::spy();
        FakeJev::fails(RateLimitedException::forProvider('typesafe'));

        $this->select();

        // The SDK fires the event after every failed attempt (the request is sent three times), also with one provider.
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'failover') && str_contains($message, 'typesafe') && str_contains($message, 'RateLimitedException'))
            ->times(3);
    }

    public function test_an_answer_without_an_offered_option_falls_back_but_is_recorded_as_answered(): void
    {
        FakeJev::answering(fn () => ['speaker' => new ChoiceAnswer('E999', ['E999' => 1.0])]);

        $selection = $this->select($this->job()->id);

        $this->assertTrue($selection->signals['fallback']);
        $this->assertSame(CallStatus::Ok, PromptLog::sole()->status);
    }

    public function test_an_answer_of_the_wrong_kind_falls_back(): void
    {
        FakeJev::answering(fn () => ['speaker' => new BooleanAnswer(0.5)]);

        $this->assertTrue($this->select()->signals['fallback']);
    }

    public function test_every_request_is_one_prompt_log_row_of_the_judge_free_select_purpose(): void
    {
        FakeJev::choosing([$this->token(0) => 0.6, $this->token(1) => 0.3, $this->token(2) => 0.1]);
        $job = $this->job();

        $this->select($job->id);

        $log = PromptLog::sole();
        $this->assertSame($job->id, $log->job_log_id);
        $this->assertSame(Purpose::Select, $log->purpose);
        $this->assertSame('typesafe', $log->provider);
        $this->assertSame('jev-1.13.0', $log->model);
        $this->assertSame(CallStatus::Ok, $log->status);
        $this->assertNull($log->expert_id);
        $this->assertNotNull($log->latency_ms);
        $this->assertSame(['provider' => 'typesafe', 'model' => 'jev-1.13.0', 'attempts' => 1], $log->config);

        $request = json_decode($log->prompt, true);
        $this->assertSame('jev-1.13.0', $request['model']);
        $this->assertStringContainsString('=== PARTICIPANTS', $request['state']);
        $this->assertSame('choice', $request['questions']['speaker']['type']);
        $this->assertSame($this->token(0), array_key_first($request['questions']['speaker']['options']));

        $answers = json_decode($log->response, true);
        $this->assertSame($this->token(0), $answers['speaker']['choice']);
    }

    /** One provider, the pinned model: nothing for the SDK to fail over to. */
    public function test_exactly_one_provider_and_the_pinned_version_are_passed(): void
    {
        FakeJev::randomly();

        $this->select();

        $prompt = FakeJev::prompt();

        $this->assertSame('typesafe', $prompt->provider->name());
        $this->assertSame('jev-1.13.0', $prompt->model);
        $this->assertSame(60, $prompt->timeout);
        $this->assertSame(['typesafe' => 'jev-1.13.0'], Provider::formatProviderAndModelList('typesafe', 'jev-1.13.0'));
        $this->assertSame('typesafe', config('ai.jev.provider'));
        $this->assertSame('typesafe', config('ai.providers.typesafe.driver'));
    }

    public function test_a_missing_key_is_a_configuration_error_and_not_a_fallback(): void
    {
        Classification::fake();
        config(['ai.providers.typesafe.key' => '']);

        $this->expectException(JevNotConfigured::class);

        try {
            $this->select();
        } finally {
            Classification::assertNothingClassified();
            $this->assertSame(0, PromptLog::count());
        }
    }

    public function test_a_single_expert_decides_nothing_asks_nothing_and_records_no_probability(): void
    {
        $project = Project::factory()->create();
        $project->addContributingExpert($alone = Expert::factory()->create());
        FakeJev::randomly();

        $selection = app(JevSpeakerSelector::class)->select(new TurnPayload($project, 1));

        $this->assertSame($alone->id, $selection->speaker->id);
        $this->assertFalse($selection->signals['fallback'], 'nothing went wrong');
        $this->assertFalse($selection->signals['jev_asked']);
        $this->assertSame([], $selection->signals['jev_probabilities']);
        $this->assertSame($alone->promptId, $selection->signals['token']);
        $this->assertNull(SelectionRecord::fromArray($selection->toArray())->speakerProbability($alone->id));
        $this->assertSame([], FakeJev::prompts());
    }

    private function job(): JobLog
    {
        return JobLog::create(['job_class' => 'x', 'project_id' => $this->project->id, 'status' => JobStatus::Running, 'started_at' => now(), 'turn_index' => 1]);
    }
}
