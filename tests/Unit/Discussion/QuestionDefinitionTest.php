<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Values\Aggregate;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\DimensionType;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ProjectFilter;
use App\Discussion\Values\QuestionDefinition;
use App\Discussion\Values\ResultForm;
use App\Discussion\Values\TurnRange;
use Tests\TestCase;

/** QuestionDefinition normalises form input into a question that can be answered. */
class QuestionDefinitionTest extends TestCase
{
    public function test_an_empty_question_asks_for_everyones_turn_share_in_every_run(): void
    {
        $question = QuestionDefinition::fromArray([]);

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
            'projects' => ['tags' => [1 => [1, 2]], 'pipelines' => ['ScorePipeline'], 'models' => ['openai-gpt-5'], 'groups' => [4]],
            'turns' => ['from' => 5, 'to' => 60],
            'compare_by' => 'project_tag:1',
            'split_by' => 'pipeline',
            'form' => 'trajectory',
            'curve' => 'rolling',
        ];

        $this->assertSame($saved, QuestionDefinition::fromArray($saved)->toArray());
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
        $question = QuestionDefinition::fromArray([
            'metric' => 'turn_gini',
            'people' => ['expert_name' => 'Alex Brandt'],
            'aggregate' => 'per_head',
            'compare_by' => 'persona_tag:3',
        ]);

        $this->assertTrue($question->people->isEveryone());
        $this->assertSame(Aggregate::Together, $question->aggregate);
        $this->assertNull($question->compareBy);
    }

    public function test_panels_never_split_agents_nor_repeat_the_compare_dimension(): void
    {
        $this->assertNull(QuestionDefinition::fromArray(['split_by' => 'persona_tag:3'])->splitBy);
        $this->assertNull(QuestionDefinition::fromArray(['compare_by' => 'pipeline', 'split_by' => 'pipeline'])->splitBy);
        $this->assertSame('model', QuestionDefinition::fromArray(['compare_by' => 'pipeline', 'split_by' => 'model'])->splitBy->key());
    }

    public function test_shares_only_appear_when_the_groups_add_up_to_everyone(): void
    {
        $composition = ['compare_by' => 'persona_tag:3', 'form' => 'composition'];

        $this->assertSame(ResultForm::Composition, QuestionDefinition::fromArray($composition)->form);
        $this->assertSame(ResultForm::Points, QuestionDefinition::fromArray([...$composition, 'aggregate' => 'per_head'])->form);
        $this->assertSame(ResultForm::Points, QuestionDefinition::fromArray([...$composition, 'people' => ['expert_name' => 'A']])->form);
        $this->assertSame(ResultForm::Points, QuestionDefinition::fromArray([...$composition, 'compare_by' => 'project_tag:1'])->form);
        $this->assertSame(ResultForm::Points, QuestionDefinition::fromArray([...$composition, 'metric' => 'words_per_turn'])->form);
    }

    public function test_a_curve_exists_for_shares_only(): void
    {
        $this->assertSame(ResultForm::Trajectory, QuestionDefinition::fromArray(['form' => 'trajectory'])->form);
        $this->assertSame(ResultForm::Points, QuestionDefinition::fromArray(['metric' => 'turn_gini', 'form' => 'trajectory'])->form);
    }

    public function test_project_filter_drops_blank_input(): void
    {
        $filter = ProjectFilter::fromArray(['tags' => [1 => ['']], 'pipelines' => ['', 'ScorePipeline', 'ScorePipeline'], 'models' => [null], 'turn_budget' => '60']);

        $this->assertSame(['ScorePipeline'], $filter->pipelines);
        $this->assertSame([], $filter->models);
        $this->assertSame(['tags', 'pipelines', 'models', 'groups'], array_keys($filter->toArray()), 'the old turn budget key is ignored and no longer written');
        $this->assertTrue($filter->tags->isEmpty());
    }

    public function test_aggregate_only_applies_to_shares(): void
    {
        $this->assertSame(Aggregate::Together, QuestionDefinition::fromArray(['metric' => 'words_per_turn', 'aggregate' => 'per_head'])->aggregate);
    }

    public function test_the_turn_window_is_normalised_like_every_other_input(): void
    {
        $this->assertEquals(new TurnRange, QuestionDefinition::fromArray([])->turns);
        $this->assertEquals(new TurnRange(2, 2), QuestionDefinition::fromArray(['turns' => ['from' => '2', 'to' => '1']])->turns);
    }

    public function test_a_group_is_a_dimension_to_compare_or_split_by_and_a_filter(): void
    {
        $this->assertEquals(new Dimension(DimensionType::Group), Dimension::fromKey('group'));
        $this->assertSame('group', Dimension::fromKey('group')->key());

        $question = QuestionDefinition::fromArray(['compare_by' => 'group', 'split_by' => 'pipeline', 'projects' => ['groups' => ['3', 3, 'x', 0, 7]]]);

        $this->assertSame('group', $question->compareBy->key());
        $this->assertSame([3, 7], $question->projects->groups);
        $this->assertSame([3, 7], $question->toArray()['projects']['groups']);
        $this->assertSame('group', QuestionDefinition::fromArray(['split_by' => 'group'])->splitBy->key());
        $this->assertNull(QuestionDefinition::fromArray(['compare_by' => 'group', 'split_by' => 'group'])->splitBy);
        $this->assertFalse(QuestionDefinition::fromArray(['projects' => ['groups' => [3]]])->projects->isEmpty());
    }
}
