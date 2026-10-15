<?php

namespace Tests\Feature\Transfer;

use App\Models\Expert;
use App\Services\Transfer\ProjectImportCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjectImportCheckTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, string>  $uuids  place => uuid */
    private function file(array $uuids): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'pipeline' => 'ScorePipeline',
            'model' => 'openai-gpt-5-mini',
            'expert_ids' => ['1' => $uuids['expert_ids']],
            'seats' => [['expert' => $uuids['seat'], 'seat' => 1]],
            'messages' => [
                ['key' => 1, 'expert' => $uuids['message'], 'addressee' => $uuids['addressee']],
                ['key' => 2, 'expert' => null, 'is_user' => true],
            ],
            'summaries' => [['expert' => $uuids['summary']]],
            'jobs' => [
                ['key' => 1, 'expert' => $uuids['job'], 'prompts' => [['expert' => $uuids['prompt']], ['expert' => null], []]],
                ['key' => 2, 'expert' => null],
                ['key' => 3, 'prompts' => [['expert' => $uuids['second_prompt']]]],
            ],
        ];
    }

    /** @return array<string, string> */
    private function uuids(): array
    {
        $places = ['expert_ids', 'seat', 'message', 'addressee', 'summary', 'job', 'prompt', 'second_prompt'];

        return array_combine($places, array_map(fn () => (string) Str::uuid(), $places));
    }

    public function test_every_place_a_file_names_an_expert_counts_and_nulls_or_gaps_do_not(): void
    {
        $uuids = $this->uuids();

        $reason = (new ProjectImportCheck)->reasonToSkip($this->file($uuids));

        $this->assertSame(__('transfer.notes.missing_experts', ['experts' => implode(', ', $uuids)]), $reason);
    }

    public function test_an_expert_named_in_several_places_is_listed_once(): void
    {
        $uuids = array_fill_keys(array_keys($this->uuids()), (string) Str::uuid());

        $reason = (new ProjectImportCheck)->reasonToSkip($this->file($uuids));

        $this->assertSame(__('transfer.notes.missing_experts', ['experts' => $uuids['seat']]), $reason);
    }

    public function test_found_experts_maps_every_named_uuid_to_its_id_here(): void
    {
        $uuids = $this->uuids();
        $experts = collect($uuids)->map(fn (string $uuid) => Expert::factory()->create(['uuid' => $uuid])->id);
        $check = new ProjectImportCheck;

        $this->assertNull($check->reasonToSkip($this->file($uuids)));
        $this->assertEqualsCanonicalizing($experts->values()->all(), array_values($check->foundExperts($this->file($uuids))));
    }

    public function test_a_model_key_with_dots_is_known_and_an_unknown_one_skips(): void
    {
        config(['ai.models' => ['gemini-2.5-pro' => ['provider' => 'gemini', 'model' => 'gemini-2.5-pro']]]);
        $file = ['model' => 'gemini-2.5-pro'] + $this->file($this->uuids());

        $this->assertStringNotContainsString('gemini-2.5-pro', (string) (new ProjectImportCheck)->reasonToSkip($file));
        $this->assertSame(__('transfer.notes.unknown_model', ['model' => 'gemini-2']), (new ProjectImportCheck)->reasonToSkip(['model' => 'gemini-2'] + $file));
    }
}
