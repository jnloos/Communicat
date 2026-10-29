<?php

namespace Tests\Feature\Discussion;

use App\Discussion\Agents\SelectorAgent;
use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Agents\SummarizeAgent;
use App\Discussion\Agents\ThinkAgent;
use App\Discussion\Jev\JevNotConfigured;
use App\Discussion\Jev\JevRejected;
use App\Discussion\Pipelines\BlindQualityPipeline;
use App\Discussion\Pipelines\DeclaresDecisionModel;
use App\Discussion\Pipelines\JevBlindQualityPipeline;
use App\Discussion\Pipelines\JevOrchestratorPipeline;
use App\Discussion\Pipelines\JevQualityPipeline;
use App\Discussion\Pipelines\JevScorePipeline;
use App\Discussion\Pipelines\OrchestratorPipeline;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Pipelines\QualityPipeline;
use App\Discussion\Pipelines\RoundRobinPipeline;
use App\Discussion\Pipelines\ScorePipeline;
use App\Discussion\Pipelines\TurnPipeline;
use App\Discussion\Selectors\JevBidSelector;
use App\Discussion\Selectors\JevBlindJudgeSelector;
use App\Discussion\Selectors\JevJudgeSelector;
use App\Discussion\Selectors\JevSpeakerSelector;
use App\Discussion\Stages\JevBids;
use App\Discussion\Stages\ThinkAndPrioritize;
use App\Discussion\Stages\ThinkOnly;
use App\Discussion\TurnRunner;
use App\Discussion\Values\SelectionRecord;
use App\Events\JobLogged;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\PromptLog;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Classification;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fakes\FakeAgents;
use Tests\Fakes\FakeJev;
use Tests\TestCase;

class JevPipelinesTest extends TestCase
{
    use RefreshDatabase;

    private const SELECT = 'App\Discussion\Stages\SelectSpeaker:';

    /** The stage lists of the five pipelines that ran before the Jev variants existed, byte for byte. */
    public static function baseStages(): array
    {
        $s = 'App\Discussion\Stages\\';
        $k = 'App\Discussion\Selectors\\';
        $tail = [$s.'Speak', $s.'PersistMessage', $s.'Summarize'];

        return [
            'RoundRobin' => [RoundRobinPipeline::class, [self::SELECT.$k.'RoundRobinSelector', $s.'ThinkAsSpeaker', ...$tail]],
            'Score' => [ScorePipeline::class, [$s.'ThinkAndPrioritize', self::SELECT.$k.'HighestBidSelector', ...$tail]],
            'Orchestrator' => [OrchestratorPipeline::class, [self::SELECT.$k.'OrchestratorSelector', $s.'ThinkAsSpeaker', ...$tail]],
            'Quality' => [QualityPipeline::class, [$s.'ThinkAndPropose', self::SELECT.$k.'JudgeSelector', ...$tail]],
            'BlindQuality' => [BlindQualityPipeline::class, [$s.'ThinkAndPropose', self::SELECT.$k.'BlindJudgeSelector', ...$tail]],
        ];
    }

    #[DataProvider('baseStages')]
    public function test_the_base_pipelines_stage_lists_are_unchanged(string $pipeline, array $stages): void
    {
        $this->assertSame($stages, (new $pipeline)->stages());
    }

    public static function variants(): array
    {
        return [
            'orchestrator' => [JevOrchestratorPipeline::class, OrchestratorPipeline::class, 0, self::SELECT.JevSpeakerSelector::class],
            'quality' => [JevQualityPipeline::class, QualityPipeline::class, 1, self::SELECT.JevJudgeSelector::class],
            'blind quality' => [JevBlindQualityPipeline::class, BlindQualityPipeline::class, 1, self::SELECT.JevBlindJudgeSelector::class],
        ];
    }

    /** @param  class-string<TurnPipeline>  $variant */
    #[DataProvider('variants')]
    public function test_a_selector_variant_differs_from_its_parent_in_exactly_one_stage(string $variant, string $parent, int $position, string $stage): void
    {
        $expected = (new $parent)->stages();
        $expected[$position] = $stage;

        $this->assertSame($expected, (new $variant)->stages());
        $this->assertInstanceOf($parent, new $variant);
        $this->assertInstanceOf(DeclaresDecisionModel::class, new $variant);
    }

    public function test_the_score_variant_lets_the_agents_only_think_and_gives_the_bid_to_jev(): void
    {
        $expected = (new ScorePipeline)->stages();
        $expected[0] = ThinkOnly::class;
        array_splice($expected, 1, 0, [JevBids::class]);

        $this->assertSame($expected, (new JevScorePipeline)->stages());
        $this->assertNotContains(ThinkAndPrioritize::class, (new JevScorePipeline)->stages());
        $this->assertInstanceOf(ScorePipeline::class, new JevScorePipeline);
        $this->assertInstanceOf(DeclaresDecisionModel::class, new JevScorePipeline);

        config(['ai.jev.bid_resolution' => 'unrounded']);

        $this->assertSame(self::SELECT.JevBidSelector::class, (new JevScorePipeline)->stages()[2]);
    }

    public function test_a_jev_score_turn_has_no_bid_of_the_team_model_to_keep(): void
    {
        FakeJev::randomly();

        $project = $this->runCell('JevScorePipeline');
        $job = JobLog::where('project_id', $project->id)->sole();
        $signals = SelectionRecord::fromArray($job->selection)->toArray()['signals'];

        $this->assertNotNull($job->words);
        $this->assertSame('bidder', $project->run_config['decision_model']['decides']);
        $this->assertCount(3, $signals['bids']);
        $this->assertSame([null, null, null], array_values($signals['jev_own_bids']), 'the team model is never asked for a priority');
        $this->assertCount(3, $signals['jev_scores']);
        $this->assertNotNull(SelectionRecord::fromArray($job->selection)->speakerJevScore($job->expert_id));
        $this->assertSame(3, PromptLog::where('job_log_id', $job->id)->where('provider', 'typesafe')->count());
    }

    public function test_the_score_variant_keeps_the_existing_selector_unless_the_bids_are_compared_unrounded(): void
    {
        $this->assertSame((new ScorePipeline)->stages()[1], (new JevScorePipeline)->stages()[2]);

        config(['ai.jev.bid_resolution' => 'unrounded']);

        $this->assertSame(self::SELECT.JevBidSelector::class, (new JevScorePipeline)->stages()[2]);
        $this->assertSame(self::SELECT.'App\Discussion\Selectors\HighestBidSelector', (new ScorePipeline)->stages()[1]);
    }

    public function test_the_registry_finds_the_subclasses_and_labels_them(): void
    {
        $registry = app(PipelineRegistry::class);

        foreach (['JevOrchestratorPipeline', 'JevScorePipeline', 'JevQualityPipeline', 'JevBlindQualityPipeline'] as $name) {
            $this->assertTrue($registry->has($name), $name);
            $this->assertInstanceOf(TurnPipeline::class, $registry->resolve($name));
        }

        $this->assertSame('Jev Orchestrator', $registry->label('JevOrchestratorPipeline'));
        $this->assertSame('Jev Score', $registry->label('JevScorePipeline'));
        $this->assertSame('Jev Quality', $registry->label('JevQualityPipeline'));
        $this->assertSame('Jev Blind Quality', $registry->label('JevBlindQualityPipeline'));
        $this->assertFalse($registry->has('JevRoundRobinPipeline'), 'round robin has no model decision to replace');
        $this->assertSame(array_keys($registry->all()), collect($registry->all())->keys()->sort()->values()->all());
    }

    private function runCell(string $pipeline, int $turns = 1): Project
    {
        Event::fake([PipelineStageChanged::class, JobLogged::class]);
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Ein Gedanke.', 'priority' => 5, 'draft' => 'Ein Entwurf.']);
        FakeAgents::always(SpeakAgent::class, ['contribution' => 'Ein Beitrag.', 'addressee' => null]);
        FakeAgents::always(SummarizeAgent::class, ['summary' => 'Zusammenfassung.']);

        $project = Project::factory()->create(['pipeline' => $pipeline, 'seed' => 3]);

        foreach (Expert::factory()->count(3)->create() as $expert) {
            $project->addContributingExpert($expert);
        }

        foreach (range(1, $turns) as $ignored) {
            app(TurnRunner::class)->run($project->fresh());
        }

        return $project->fresh();
    }

    public function test_a_jev_run_freezes_its_decision_maker_next_to_the_team_model(): void
    {
        FakeJev::randomly();

        $orchestrator = $this->runCell('JevOrchestratorPipeline');
        $score = $this->runCell('JevScorePipeline');

        $this->assertSame(['provider' => 'typesafe', 'model' => 'jev-1.13.0', 'decides' => 'selector', 'timeout' => 60], $orchestrator->run_config['decision_model']);
        $this->assertSame(['provider' => 'typesafe', 'model' => 'jev-1.13.0', 'decides' => 'bidder', 'timeout' => 60, 'bid_resolution' => 'rounded'], $score->run_config['decision_model']);
        $this->assertSame('openai', $orchestrator->run_config['model']['provider']);
        $this->assertSame('JevOrchestratorPipeline', $orchestrator->run_config['pipeline']);
    }

    public function test_later_turns_read_the_frozen_model_and_not_a_config_edited_in_the_middle_of_a_series(): void
    {
        FakeJev::randomly();
        $project = $this->runCell('JevOrchestratorPipeline');
        config(['ai.jev.model' => 'jev-9.9.9']);

        app(TurnRunner::class)->run($project->fresh());

        $this->assertSame(['jev-1.13.0', 'jev-1.13.0'], array_map(fn ($prompt) => $prompt->model, FakeJev::prompts()));
        $this->assertSame('jev-1.13.0', $project->fresh()->run_config['decision_model']['model']);
    }

    public function test_the_existing_pipelines_freeze_no_decision_maker(): void
    {
        foreach (['OrchestratorPipeline', 'ScorePipeline', 'RoundRobinPipeline'] as $name) {
            FakeAgents::always(SelectorAgent::class, ['speaker' => 'E1', 'reasoning' => '-']);

            $this->assertArrayNotHasKey('decision_model', $this->runCell($name)->run_config, $name);
        }
    }

    public function test_the_jev_orchestrator_turn_is_measured_logged_and_provenanced_like_any_turn(): void
    {
        FakeJev::randomly();

        $project = $this->runCell('JevOrchestratorPipeline');
        $job = JobLog::where('project_id', $project->id)->sole();
        $record = SelectionRecord::fromArray($job->selection);

        $this->assertSame('JevSpeakerSelector', $record->selector());
        $this->assertNotNull($job->words);
        $this->assertFalse($record->fallback());
        $this->assertSame(1, PromptLog::where('job_log_id', $job->id)->where('provider', 'typesafe')->count());
        $this->assertSame(0, PromptLog::where('job_log_id', $job->id)->where('purpose', 'select')->whereNull('provider')->count());
    }

    public function test_a_jev_quality_run_with_a_failing_decision_maker_falls_back_and_is_stopped_after_the_configured_turns(): void
    {
        FakeJev::fails(ProviderOverloadedException::forProvider('typesafe'));
        config(['ai.jev.max_fallback_turns' => 3]);

        $project = $this->runCell('JevQualityPipeline', 2);
        $jobs = JobLog::where('project_id', $project->id)->orderBy('turn_index')->get();

        $this->assertCount(2, $jobs);
        $this->assertSame(2, $jobs->whereNotNull('words')->count(), 'single fallbacks stay allowed and speak');
        foreach ($jobs as $job) {
            $this->assertTrue(SelectionRecord::fromArray($job->selection)->fallback());
        }

        $result = app(TurnRunner::class)->run($project->fresh());
        $third = JobLog::where('project_id', $project->id)->where('turn_index', 3)->sole();

        $this->assertTrue($result->stop);
        $this->assertSame('jev_outage', $result->reason);
        $this->assertNull($third->words, 'nobody speaks in the turn that stops the run');
        $this->assertTrue(SelectionRecord::fromArray($third->selection)->fallback(), 'the draw it made is on record');
    }

    public function test_a_single_answer_in_between_ends_the_streak(): void
    {
        FakeJev::steps([
            ProviderOverloadedException::forProvider('typesafe'),
            ProviderOverloadedException::forProvider('typesafe'),
            ProviderOverloadedException::forProvider('typesafe'),   // turn 1: three attempts, all failed
            ProviderOverloadedException::forProvider('typesafe'),
            ProviderOverloadedException::forProvider('typesafe'),
            ProviderOverloadedException::forProvider('typesafe'),   // turn 2: same
            ['speaker' => new ChoiceAnswer('E1', ['E1' => 0.5, 'E2' => 0.3, 'E3' => 0.2])],   // turn 3 answers
            ProviderOverloadedException::forProvider('typesafe'),
        ]);
        config(['ai.jev.max_fallback_turns' => 3]);

        $project = $this->runCell('JevQualityPipeline', 3);
        $result = app(TurnRunner::class)->run($project->fresh());

        $this->assertFalse($result->stop, 'an answer in turn 3 started the count again');
        $this->assertSame(4, JobLog::where('project_id', $project->id)->whereNotNull('words')->count());
    }

    public function test_the_guard_can_be_switched_off(): void
    {
        FakeJev::fails(ProviderOverloadedException::forProvider('typesafe'));
        config(['ai.jev.max_fallback_turns' => 0]);

        $project = $this->runCell('JevQualityPipeline', 5);

        $this->assertSame(5, JobLog::where('project_id', $project->id)->whereNotNull('words')->count());
    }

    public function test_a_jev_score_run_without_a_single_readable_bid_is_stopped_before_the_unreadable_turn_speaks(): void
    {
        FakeJev::fails(ProviderOverloadedException::forProvider('typesafe'));
        config(['ai.jev.max_fallback_turns' => 2]);

        $project = $this->runCell('JevScorePipeline', 2);
        $jobs = JobLog::where('project_id', $project->id)->orderBy('turn_index')->get();

        $this->assertNotNull($jobs[0]->words);
        $this->assertNull($jobs[1]->words);
        $this->assertSame('HighestBidSelector', SelectionRecord::fromArray($jobs[1]->selection)->selector());
    }

    public function test_a_run_without_a_key_stops_before_a_turn_is_logged_or_the_team_model_is_asked(): void
    {
        Classification::fake();
        config(['ai.providers.typesafe.key' => '']);
        Event::fake([PipelineStageChanged::class, JobLogged::class]);
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Ein Gedanke.', 'priority' => 5, 'draft' => 'Ein Entwurf.']);

        $project = Project::factory()->create(['pipeline' => 'JevScorePipeline', 'seed' => 3]);
        $project->addContributingExpert(Expert::factory()->create());

        try {
            app(TurnRunner::class)->run($project->fresh());
            $this->fail('A missing key must not be swallowed.');
        } catch (JevNotConfigured $e) {
            $this->assertStringContainsString('TYPESAFE_API_KEY', $e->getMessage());
        }

        $this->assertSame(0, JobLog::where('project_id', $project->id)->count(), 'no failed turn in the failure rate');
        $this->assertNull($project->fresh()->run_config, 'nothing frozen for a run that never started');
        ThinkAgent::assertNeverPrompted();
    }

    public function test_a_rejected_request_ends_the_run_and_leaves_the_failed_turn_on_record(): void
    {
        FakeJev::fails(new RequestException(new HttpResponse(new Psr7Response(401, [], '{"error":{"message":"Invalid API key"}}'))));
        Event::fake([PipelineStageChanged::class, JobLogged::class]);
        FakeAgents::always(SpeakAgent::class, ['contribution' => 'Ein Beitrag.', 'addressee' => null]);
        FakeAgents::always(SummarizeAgent::class, ['summary' => 'Zusammenfassung.']);
        FakeAgents::always(ThinkAgent::class, ['thought' => 'Ein Gedanke.', 'priority' => 5, 'draft' => 'Ein Entwurf.']);

        $project = Project::factory()->create(['pipeline' => 'JevOrchestratorPipeline', 'seed' => 3]);
        foreach (Expert::factory()->count(3)->create() as $expert) {
            $project->addContributingExpert($expert);
        }

        try {
            app(TurnRunner::class)->run($project->fresh());
            $this->fail('A rejected request must end the run.');
        } catch (JevRejected $e) {
            $this->assertSame(401, $e->status);
        }

        $job = JobLog::where('project_id', $project->id)->sole();
        $this->assertSame('failed', $job->status->value);
        $this->assertStringContainsString('JevRejected: Jev rejected the request (HTTP 401): Invalid API key', $job->error);
        $this->assertNull($job->words);
    }
}
