<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\References\AgentReferences;
use App\Discussion\Metrics\References\EachReference;
use App\Discussion\Metrics\References\ExpertNameReference;
use App\Discussion\Metrics\References\PersonaTagsReference;
use App\Discussion\Metrics\References\TopReference;
use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\TagFilter;
use App\Models\Expert;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentReferenceTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<array{0: int, 1: string, 2: ?int, 3: int, 4: int}>  $rows  id, name, seat, turns, words */
    private function report(array $rows): ShareReport
    {
        $turns = array_sum(array_column($rows, 3));
        $words = array_sum(array_column($rows, 4));

        return new ShareReport(
            speakers: array_map(fn (array $r) => new SpeakerShare($r[0], $r[1], $r[2], $r[3], $r[4], $turns === 0 ? 0.0 : $r[3] / $turns, $words === 0 ? 0.0 : $r[4] / $words), $rows),
            spokenTurns: $turns, failedTurns: 0, totalWords: $words, turnGini: 0.0, wordGini: 0.0, giniCeiling: 0.75,
        );
    }

    /** @param  list<AgentGroup>  $groups */
    private function ids(array $groups): array
    {
        return array_map(fn (AgentGroup $g) => $g->expertIds, $groups);
    }

    public function test_expert_name_matches_every_seat_with_that_name(): void
    {
        $report = $this->report([[11, 'Ada', 1, 3, 30], [12, 'Bo', 2, 1, 10]]);

        $this->assertSame([[11]], $this->ids((new ExpertNameReference('Ada'))->pick(new Project, $report, Metric::TurnShare)));
    }

    public function test_top_takes_the_highest_value_and_the_lower_seat_on_a_tie(): void
    {
        $tied = $this->report([[11, 'Ada', 1, 2, 10], [12, 'Bo', 2, 2, 50]]);

        $this->assertSame([[11]], $this->ids((new TopReference)->pick(new Project, $tied, Metric::TurnShare)));
        $this->assertSame([[12]], $this->ids((new TopReference)->pick(new Project, $tied, Metric::WordShare)));
    }

    public function test_each_gives_one_group_per_agent_named_by_the_agent(): void
    {
        $groups = (new EachReference)->pick(new Project, $this->report([[11, 'Ada', 1, 1, 1], [12, 'Bo', null, 1, 1]]), Metric::TurnShare);

        $this->assertSame([[11], [12]], $this->ids($groups));
        $this->assertSame(['Ada', 'Bo'], array_map(fn (AgentGroup $g) => $g->name, $groups));
        $this->assertNotContains('', array_map(fn (AgentGroup $g) => $g->key, $groups));
    }

    public function test_persona_tags_pick_the_seated_agents_that_carry_them(): void
    {
        $title = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);
        $yes = $title->values()->create(['name' => 'true']);
        [$ceo, $peer] = Expert::factory()->count(2)->create();
        $ceo->setTag($title, $yes);

        $report = $this->report([[$ceo->id, 'Ceo', 1, 1, 1], [$peer->id, 'Peer', 2, 1, 1]]);
        $reference = new PersonaTagsReference(new TagFilter([$title->id => [$yes->id]]));

        $this->assertSame([[$ceo->id]], $this->ids($reference->pick(new Project, $report, Metric::TurnShare)));
        $this->assertSame([[]], $this->ids((new PersonaTagsReference)->pick(new Project, $report, Metric::TurnShare)));
    }

    public function test_the_combined_value_of_a_group_is_a_sum_and_words_per_turn_a_ratio(): void
    {
        $report = $this->report([[11, 'Ada', 1, 1, 10], [12, 'Bo', 2, 2, 80], [13, 'Cy', 3, 1, 10]]);
        $group = [$report->speakers[0], $report->speakers[1]];

        $this->assertSame(0.75, Metric::TurnShare->ofAgents($group));
        $this->assertSame(0.9, Metric::WordShare->ofAgents($group));
        $this->assertSame(30.0, Metric::WordsPerTurn->ofAgents($group));
    }

    public function test_saved_forms_round_trip_and_unknown_types_fall_back_to_each(): void
    {
        foreach ([
            ['type' => 'expert_name', 'name' => 'Ada'],
            ['type' => 'top'],
            ['type' => 'each'],
            ['type' => 'persona_tags', 'tags' => [4 => [7, 9]]],
        ] as $saved) {
            $this->assertSame($saved, AgentReferences::fromArray($saved)->toArray());
        }

        $this->assertInstanceOf(EachReference::class, AgentReferences::fromArray(['type' => 'nope']));
        // The seat reference is gone; comparisons saved with it read as `each`.
        $this->assertSame(['type' => 'each'], AgentReferences::fromArray(['type' => 'seat', 'seat' => 1])->toArray());
    }

    public function test_tag_filter_normalises_form_input(): void
    {
        $filter = TagFilter::fromArray(['3' => ['5', '5', ''], '4' => [], 'x' => ['1']]);

        $this->assertSame([3 => [5]], $filter->toArray());
        // Emptied, the category would stop filtering and widen to everything; it keeps its dead id.
        $this->assertSame([3 => [5]], $filter->without([5])->toArray());
    }
}
