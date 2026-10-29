<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Jev\JevClient;
use App\Discussion\Schemas\ContributionSchema;
use App\Discussion\Values\JevDecides;
use App\Discussion\Values\JevDecisionModel;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\Purpose;
use App\Models\Expert;
use App\Models\PromptLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Classification\Choice;
use Tests\Fakes\FakeAgents;
use Tests\Fakes\FakeJev;
use Tests\TestCase;

/**
 * Jev's calls bypass RecordPromptLog, so RecordJevCall is a second writer of
 * prompt_logs. A column one of them learns to write and the other does not would
 * leave Jev rows (or agent rows) silently thinner; this keeps the two in step.
 */
class RecordJevCallTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> the columns a writer sets on a new prompt_logs row, sorted */
    private function columnsWrittenBy(callable $call): array
    {
        $written = [];
        PromptLog::creating(function (PromptLog $row) use (&$written) {
            $written = array_keys($row->getAttributes());
        });

        $call();

        sort($written);

        return $written;
    }

    public function test_a_jev_call_writes_the_same_columns_as_a_successful_agent_call(): void
    {
        FakeAgents::always(SpeakAgent::class, ['contribution' => 'Ein Beitrag.', 'addressee' => null]);
        FakeJev::randomly();

        $agent = $this->columnsWrittenBy(fn () => (new SpeakAgent(ModelConfig::fromConfig(config('ai.default_model')), null, null, ContributionSchema::class))->ask('Prompt'));
        $jev = $this->columnsWrittenBy(fn () => app(JevClient::class)->ask(
            JevDecisionModel::fromConfig(JevDecides::Selector),
            'State',
            ['speaker' => new Choice('Wer?', ['E1' => 'a', 'E2' => 'b'])],
            Purpose::Select,
            null,
        ));

        $this->assertNotEmpty($agent);
        $this->assertSame($agent, $jev);
    }

    public function test_what_the_api_does_not_report_is_null_and_never_zero(): void
    {
        FakeJev::randomly();

        app(JevClient::class)->ask(JevDecisionModel::fromConfig(JevDecides::Selector), 'State', ['speaker' => new Choice('Wer?', ['E1' => 'a', 'E2' => 'b'])], Purpose::Select, null);

        $row = PromptLog::sole();

        foreach (['reasoning', 'tokens_reasoning', 'tokens_cached', 'model_served', 'tokens_in', 'tokens_out'] as $column) {
            $this->assertNull($row->{$column}, $column);
        }
        $this->assertSame('typesafe', $row->provider);
        $this->assertSame('jev-1.13.0', $row->model);
    }

    public function test_a_jev_call_is_labelled_like_an_agent_call_with_a_jev_prefix(): void
    {
        FakeJev::randomly();
        $model = JevDecisionModel::fromConfig(JevDecides::Selector);
        $question = ['speaker' => new Choice('Wer?', ['E1' => 'a', 'E2' => 'b'])];

        app(JevClient::class)->ask($model, 'State', $question, Purpose::Select, null);
        $expert = Expert::factory()->create();
        app(JevClient::class)->ask($model, 'State', $question, Purpose::Think, null, $expert->id);

        $this->assertSame(['jev:select', "jev:think:{$expert->id}"], PromptLog::orderBy('id')->pluck('label')->all());
    }
}
