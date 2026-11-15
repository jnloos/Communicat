<?php

namespace Tests\Feature\Study\Planning;

use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use App\Study\Models\StudyWave;
use App\Study\Planning\Manifest;
use App\Study\Planning\Planner;
use App\Study\Planning\WaveRefused;
use App\Study\Planning\WaveWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WaveWriterTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['is_admin' => true]);
    }

    public function test_it_opens_every_planned_run_with_its_personas_tags_and_seats(): void
    {
        $hidden = PersonaAttribute::create(['name' => 'Hobby', 'self_template' => 'You like :value.', 'roster_template' => ':value']);
        $manifest = self::tiny();
        $wave = $this->write($manifest, 1, 1, 1);

        $this->assertSame(64, Project::count());
        $this->assertSame(16 * 6, Expert::count());

        $run = (new Planner)->plan($manifest, 1, 1)[5];
        $project = Project::where('uuid', $run->uuid)->firstOrFail();

        $this->assertSame([$run->title(), $run->topic, 'openai-gpt-5-mini', 'ScorePipeline', 30, $run->seed],
            [$project->title, $project->description, $project->model, $project->pipeline, $project->turn_budget, $project->seed]);
        $this->assertSame(array_map(fn ($persona) => $persona->uuid, $run->seating()), $project->experts->pluck('uuid')->all());
        $this->assertSame([1, 2, 3], $project->experts->pluck('pivot.seat')->all());

        $this->assertSame([
            'study' => 'tiny', 'scenario' => 'S01', 'block' => 'B02', 'focus profile' => $run->focusProfile(),
            'duo profile' => $run->duoProfile(), 'focus seat' => (string) $run->seatOf('focus'), 'wave' => '1',
        ], $this->tagsOf($project, ['study', 'scenario', 'block', 'focus profile', 'duo profile', 'focus seat', 'wave']));

        $levels = collect($project->attributeVisibilities())->map->value->all();
        $this->assertSame(['all', 'all', 'all', 'none'], array_values(array_map(fn ($id) => $levels[$id], [
            PersonaAttribute::findRole()->id, PersonaAttribute::where('name', 'Gender')->value('id'), PersonaAttribute::where('name', 'Origin')->value('id'), $hidden->id,
        ])));

        $focus = $project->experts->firstWhere('uuid', $run->personas['focus']->uuid);
        $this->assertSame($run->personas['focus']->name, $focus->name);
        $this->assertSame($run->personas['focus']->attributes, $focus->personaAttributes->pluck('pivot.value', 'name')->only(['Role', 'Gender', 'Origin'])->all());
        $this->assertSame(['study' => 'tiny', 'first name' => $run->personas['focus']->firstNameId, 'subgroup' => $run->personas['focus']->subgroup],
            $this->tagsOf($focus, ['study', 'first name', 'subgroup']));

        $this->assertTrue($wave->group->projects()->whereKey($project->id)->exists());
        $this->assertSame('Studie tiny · Welle 1', $wave->group->name);
        $this->assertSame(64, $wave->group->projects()->count());
    }

    public function test_the_profile_values_are_created_in_canonical_order(): void
    {
        $this->write(self::tiny(), 1, 1, 1);

        $names = TagCategory::ofScope(TagScope::Project)->where('name', 'focus profile')->firstOrFail()->values->pluck('name')->all();

        $this->assertSame(['S+G+E+', 'S−G+E+', 'S+G+E−', 'S−G+E−', 'S+G−E+', 'S−G−E+', 'S+G−E−', 'S−G−E−'], $names);
    }

    public function test_writing_again_changes_nothing(): void
    {
        $wave = $this->write(self::tiny(), 1, 1, 1);
        $before = [$this->snapshot(), $this->counts()];

        Carbon::setTestNow(now()->addHour());
        app(WaveWriter::class)->write($wave, $this->owner);

        $this->assertSame($before, [$this->snapshot(), $this->counts()]);
    }

    public function test_a_deleted_run_is_opened_again_and_only_that_one(): void
    {
        $wave = $this->write(self::tiny(), 1, 1, 1);
        $gone = Project::orderBy('id')->skip(7)->firstOrFail();
        $uuid = $gone->uuid;
        $gone->delete();

        app(WaveWriter::class)->write($wave, $this->owner);

        $this->assertSame(64, Project::count());
        $this->assertSame(1, Project::where('uuid', $uuid)->count());
        $this->assertSame(16 * 6, Expert::count());
    }

    public function test_a_gender_definition_with_other_templates_refuses_before_anything_is_written(): void
    {
        PersonaAttribute::create(['name' => 'Gender', 'self_template' => 'You are a :value person.', 'roster_template' => ':value']);

        $this->assertRefused(fn () => $this->write(self::tiny(), 1, 1, 1), 'Attribute Gender');
        $this->assertSame(0, Project::count());
        $this->assertSame(0, Expert::count());
        $this->assertSame(0, TagCategory::count());
    }

    public function test_a_planned_name_held_by_another_persona_refuses(): void
    {
        $name = (new Planner)->plan(self::tiny(), 1, 1)[40]->personas['duo2']->name;
        Expert::factory()->create(['name' => $name]);

        $this->assertRefused(fn () => $this->write(self::tiny(), 1, 1, 1), $name);
        $this->assertSame(0, Project::count());
    }

    public function test_wave_2_on_an_appended_manifest_leaves_wave_1_untouched(): void
    {
        $this->write(self::tiny(), 1, 1, 1)->update(['applied_at' => now()]);
        $before = $this->snapshot();

        Carbon::setTestNow(now()->addHour());
        $this->write(self::tiny(appended: true), 2, 2, 2, 'One scenario more.');

        $this->assertSame(128, Project::count());
        $firstWave = array_intersect_key($this->snapshot(), $before);
        $this->assertSame($before, $firstWave);
        $this->assertSame(64, Project::whereHas('tagValues', fn ($q) => $q->where('name', '2')->whereHas('category', fn ($c) => $c->where('name', 'wave')))->count());
    }

    /** The tiny manifest; appended: a second scenario after the first, same core. */
    public static function tiny(bool $appended = false): Manifest
    {
        $data = json_decode(ManifestTest::fixture('tiny-manifest.json'), true);

        if ($appended) {
            $data['scenarios'][] = ['id' => 2, 'topic' => 'Should the market move to the square?'] + array_slice($data['scenarios'][0], 2);
        }

        return Manifest::fromJson(json_encode($data));
    }

    private function write(Manifest $manifest, int $number, int $from, int $to): StudyWave
    {
        $wave = StudyWave::record($manifest, $number, $from, $to, $this->owner->id);
        app(WaveWriter::class)->write($wave, $this->owner);

        return $wave->refresh();
    }

    private function assertRefused(callable $write, string $expected): void
    {
        try {
            $write();
            $this->fail('The wave was written.');
        } catch (WaveRefused $exception) {
            $this->assertStringContainsString($expected, implode("\n", $exception->problems));
        }
    }

    /** @return array<string, string> category name => value name */
    private function tagsOf(Project|Expert $model, array $categories): array
    {
        $tags = $model->tagValues()->with('category')->get()->mapWithKeys(fn ($value) => [$value->category->name => $value->name]);

        return collect($categories)->mapWithKeys(fn ($name) => [$name => $tags[$name] ?? null])->all();
    }

    private function counts(): array
    {
        return array_map(fn (string $table) => DB::table($table)->count(), [
            'taggables', 'tag_values', 'project_attribute_visibility', 'project_contributors', 'expert_persona_attribute', 'messages', 'project_groups',
        ]);
    }

    /** Every project and expert with its update time and tags, keyed by uuid. */
    private function snapshot(): array
    {
        $rows = [];
        foreach ([Project::class, Expert::class] as $class) {
            foreach ($class::with('tagValues')->get() as $model) {
                $rows[$model->uuid] = [(string) $model->updated_at, $model->tagValues->pluck('id')->sort()->values()->all()];
            }
        }

        return $rows;
    }
}
