<?php

namespace Tests\Feature\Transfer;

use App\Models\Expert;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Services\Transfer\ExpertTransfer;
use App\Services\Transfer\ImportReport;
use App\Services\Transfer\TagTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TagAndExpertTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_tags_are_exported_by_name_and_missing_ones_are_created_on_import(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);
        $category->values()->create(['name' => 'true']);
        $category->values()->create(['name' => 'false']);
        $exported = (new TagTransfer)->export();

        $this->assertSame([['scope' => 'persona', 'name' => 'title-holder', 'values' => ['true', 'false']]], $exported);

        $exported[] = ['scope' => 'project', 'name' => 'status condition', 'values' => ['status']];
        $report = new ImportReport;
        (new TagTransfer)->import($exported, $report);

        $this->assertSame(2, TagCategory::count());
        $this->assertSame(['status'], TagCategory::where('name', 'status condition')->sole()->values()->pluck('name')->all());
        $this->assertSame(1, $report->count(ImportReport::TAGS, ImportReport::CREATED));
        $this->assertSame(1, $report->count(ImportReport::TAGS, ImportReport::SKIPPED));
    }

    public function test_an_expert_round_trips_with_its_tags_and_keeps_a_free_id(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);
        $value = $category->values()->create(['name' => 'true']);
        $expert = Expert::factory()->create(['name' => 'Alex Brandt', 'role' => 'CEO']);
        $expert->setTag($category, $value);
        $data = (new ExpertTransfer)->export($expert);
        $expert->delete();

        $report = new ImportReport;
        (new ExpertTransfer)->import($data, $report);

        $copy = Expert::where('uuid', $data['uuid'])->sole();
        $this->assertSame($data['id'], $copy->id);
        $this->assertSame('CEO', $copy->role);
        $this->assertSame(['title-holder' => 'true'], $data['tags']);
        $this->assertTrue($copy->tagValues()->whereKey($value->id)->exists());
        $this->assertSame(1, $report->count(ImportReport::EXPERTS, ImportReport::CREATED));
    }

    public function test_an_existing_expert_is_skipped_and_a_taken_id_gets_a_new_one_with_a_note(): void
    {
        $expert = Expert::factory()->create();
        $data = (new ExpertTransfer)->export($expert);
        $report = new ImportReport;

        (new ExpertTransfer)->import($data, $report);
        $this->assertSame(1, $report->count(ImportReport::EXPERTS, ImportReport::SKIPPED));

        $data['uuid'] = '0b9f2d4c-1a3e-4f5b-8c6d-7e8f9a0b1c2d';
        (new ExpertTransfer)->import($data, $report);
        $copy = Expert::where('uuid', $data['uuid'])->sole();

        $this->assertNotSame($expert->id, $copy->id);
        $this->assertNotNull($report->entries(ImportReport::EXPERTS)[1]['note']);
    }

    public function test_skipping_an_existing_expert_leaves_it_untouched(): void
    {
        $expert = Expert::factory()->create(['name' => 'Alex Brandt', 'role' => 'CEO']);
        $data = (new ExpertTransfer)->export($expert);
        $data['name'] = 'Changed';
        $data['role'] = 'Intern';
        $report = new ImportReport;

        (new ExpertTransfer)->import($data, $report);

        $this->assertSame(1, $report->count(ImportReport::EXPERTS, ImportReport::SKIPPED));
        $expert->refresh();
        $this->assertSame('Alex Brandt', $expert->name);
        $this->assertSame('CEO', $expert->role);
    }

    public function test_tag_names_match_ignoring_case_and_surrounding_space(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);
        $category->values()->create(['name' => 'true']);
        $report = new ImportReport;

        (new TagTransfer)->import([['scope' => 'persona', 'name' => ' Title-Holder ', 'values' => ['TRUE', 'false']]], $report);

        $this->assertSame(1, TagCategory::count());
        $this->assertSame(['true', 'false'], $category->values()->orderBy('id')->pluck('name')->all());
        $this->assertSame(1, $report->count(ImportReport::TAGS, ImportReport::SKIPPED));
        $this->assertStringContainsString('false', $report->entries(ImportReport::TAGS)[0]['note']);
    }

    public function test_the_new_id_note_names_both_ids(): void
    {
        $expert = Expert::factory()->create();
        $data = (new ExpertTransfer)->export($expert);
        $data['uuid'] = '0b9f2d4c-1a3e-4f5b-8c6d-7e8f9a0b1c2d';
        $report = new ImportReport;

        (new ExpertTransfer)->import($data, $report);

        $copy = Expert::where('uuid', $data['uuid'])->sole();
        $this->assertSame(
            __('transfer.notes.new_expert_id', ['old' => $expert->id, 'new' => $copy->id]),
            $report->entries(ImportReport::EXPERTS)[0]['note'],
        );
    }

    public function test_the_id_sequence_stays_sane_after_a_kept_id(): void
    {
        $data = (new ExpertTransfer)->export(Expert::factory()->create());
        Expert::query()->delete();
        $data['id'] = 500;
        $data['uuid'] = '0b9f2d4c-1a3e-4f5b-8c6d-7e8f9a0b1c2d';

        (new ExpertTransfer)->import($data, new ImportReport);
        $next = Expert::factory()->create();

        $this->assertSame(500, Expert::where('uuid', $data['uuid'])->sole()->id);
        $this->assertNotSame(500, $next->id);
    }

    public function test_a_round_trip_keeps_the_plain_fields(): void
    {
        $expert = Expert::factory()->create([
            'name' => 'Alex Brandt',
            'description' => 'Decides fast.',
            'avatar_url' => 'https://example.test/a.png',
            'created_at' => '2026-03-04 05:06:07',
        ]);
        $data = (new ExpertTransfer)->export($expert);
        $expert->delete();

        (new ExpertTransfer)->import($data, new ImportReport);

        $copy = Expert::where('uuid', $data['uuid'])->sole();
        $this->assertSame('Alex Brandt', $copy->name);
        $this->assertSame('Decides fast.', $copy->description);
        $this->assertSame('https://example.test/a.png', $copy->avatar_url);
        $this->assertSame($expert->created_at->timestamp, $copy->created_at->timestamp);
    }

    public function test_a_taken_id_renumbers_only_its_own_expert_not_the_later_ones(): void
    {
        $exported = collect([1, 2, 3])->map(fn (int $id) => [
            'uuid' => (string) Str::uuid(), 'id' => $id, 'name' => "Expert {$id}", 'role' => 'r', 'description' => 'd',
        ])->all();
        Expert::factory()->create(['id' => 1]);
        $report = new ImportReport;

        (new ExpertTransfer)->importAll($exported, $report);

        $this->assertSame(2, Expert::where('uuid', $exported[1]['uuid'])->sole()->id);
        $this->assertSame(3, Expert::where('uuid', $exported[2]['uuid'])->sole()->id);
        $this->assertNotSame(1, Expert::where('uuid', $exported[0]['uuid'])->sole()->id);
        $notes = array_values(array_filter(array_column($report->entries(ImportReport::EXPERTS), 'note')));
        $this->assertCount(1, $notes);
        $this->assertSame(3, $report->count(ImportReport::EXPERTS, ImportReport::CREATED));
    }

    public function test_an_entry_without_a_valid_uuid_is_not_imported(): void
    {
        $report = new ImportReport;

        (new ExpertTransfer)->importAll([['uuid' => 'not-a-uuid', 'id' => 7, 'name' => 'Alex', 'role' => 'CEO']], $report);

        $this->assertSame(0, Expert::count());
        $this->assertSame(1, $report->count(ImportReport::EXPERTS, ImportReport::FAILED));
        $this->assertSame(__('transfer.notes.bad_uuid'), $report->entries(ImportReport::EXPERTS)[0]['note']);
    }
}
