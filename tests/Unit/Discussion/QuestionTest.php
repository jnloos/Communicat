<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\Aggregate;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ProjectFilter;
use App\Discussion\Values\Question;
use App\Discussion\Values\ResultForm;
use Tests\TestCase;

/** Question normalises form input into a question that can be answered. */
class QuestionTest extends TestCase
{
    public function test_an_empty_question_asks_for_everyones_turn_share_in_every_run(): void
    {
        $question = Question::fromArray([]);

        $this->assertSame(Metric::TurnShare, $question->metric);
        $this->assertTrue($question->people->isEveryone());
        $this->assertSame(Aggregate::Together, $question->aggregate);
        $this->assertTrue($question->projects->isEmpty());
        $this->assertNull($question->compareBy);
        $this->assertNull($question->splitBy);
        $this->assertSame(ResultForm::Points, $question->form);
    }

    public function test_a_saved_question_reads_back_unchanged(): void
    {
        $saved = [
            'metric' => 'word_share',
            'people' => ['tags' => [3 => [7]], 'expert_name' => 'Alex Brandt'],
            'aggregate' => 'per_head',
            'projects' => ['tags' => [1 => [1, 2]], 'pipelines' => ['ScorePipeline'], 'models' => ['openai-gpt-5'], 'turn_budget' => 60],
            'compare_by' => 'project_tag:1',
            'split_by' => 'pipeline',
            'form' => 'trajectory',
            'curve' => 'rolling',
        ];

        $this->assertSame($saved, Question::fromArray($saved)->toArray());
    }

    public function test_dimension_keys(): void
    {
        $this->assertEquals(new Dimension(DimensionType::PersonaTag, 3), Dimension::fromKey('persona_tag:3'));
        $this->assertEquals(new Dimension(DimensionType::Pipeline), Dimension::fromKey('pipeline'));
        $this->assertNull(Dimension::fromKey(''));
        $this->assertNull(Dimension::fromKey('persona_tag'));
        $this->assertNull(Dimension::fromKey('persona_tag:x'));
        $this->assertNull(Dimension::fromKey('colour'));
        $this->assertNull(Dimension::fromKey(null));
        $this->assertSame('project_tag:1', Dimension::fromKey('project_tag:1')->key());
    }

    public function test_gini_counts_everyone_and_cannot_compare_within_a_run(): void
    {
        $question = Question::fromArray([
            'metric' => 'turn_gini',
            'people' => ['expert_name' => 'Alex Brandt'],
            'aggregate' => 'per_head',
            'compare_by' => 'persona_tag:3',
        ]);

        $this->assertTrue($question->people->isEveryone());
        $this->assertSame(Aggregate::Together, $question->aggregate);
        $this->assertNull($question->compareBy);
    }

    public function test_panels_never_split_agents_nor_repeat_the_comparison(): void
    {
        $this->assertNull(Question::fromArray(['split_by' => 'persona_tag:3'])->splitBy);
        $this->assertNull(Question::fromArray(['compare_by' => 'pipeline', 'split_by' => 'pipeline'])->splitBy);
        $this->assertSame('model', Question::fromArray(['compare_by' => 'pipeline', 'split_by' => 'model'])->splitBy->key());
    }

    public function test_shares_only_appear_when_the_groups_add_up_to_everyone(): void
    {
        $composition = ['compare_by' => 'persona_tag:3', 'form' => 'composition'];

        $this->assertSame(ResultForm::Composition, Question::fromArray($composition)->form);
        $this->assertSame(ResultForm::Points, Question::fromArray([...$composition, 'aggregate' => 'per_head'])->form);
        $this->assertSame(ResultForm::Points, Question::fromArray([...$composition, 'people' => ['expert_name' => 'A']])->form);
        $this->assertSame(ResultForm::Points, Question::fromArray([...$composition, 'compare_by' => 'project_tag:1'])->form);
        $this->assertSame(ResultForm::Points, Question::fromArray([...$composition, 'metric' => 'words_per_turn'])->form);
    }

    public function test_a_curve_exists_for_shares_only(): void
    {
        $this->assertSame(ResultForm::Trajectory, Question::fromArray(['form' => 'trajectory'])->form);
        $this->assertSame(ResultForm::Points, Question::fromArray(['metric' => 'turn_gini', 'form' => 'trajectory'])->form);
    }

    public function test_project_filter_drops_blank_input(): void
    {
        $filter = ProjectFilter::fromArray(['tags' => [1 => ['']], 'pipelines' => ['', 'ScorePipeline', 'ScorePipeline'], 'models' => [null], 'turn_budget' => '0']);

        $this->assertSame(['ScorePipeline'], $filter->pipelines);
        $this->assertSame([], $filter->models);
        $this->assertNull($filter->turnBudget);
        $this->assertTrue($filter->tags->isEmpty());
    }

    public function test_aggregate_only_applies_to_shares(): void
    {
        $this->assertSame(Aggregate::Together, Question::fromArray(['metric' => 'words_per_turn', 'aggregate' => 'per_head'])->aggregate);
    }
}
