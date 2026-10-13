<?php

namespace Tests\Feature\Transfer;

use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\Question;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use App\Services\Transfer\DefinitionNames;
use App\Services\Transfer\GroupTransfer;
use App\Services\Transfer\ImportReport;
use App\Services\Transfer\QuestionTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupAndQuestionTransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_definition_travels_by_names_and_comes_back_as_this_databases_ids(): void
    {
        $user = User::factory()->create();
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $noStatus = $condition->values()->create(['name' => 'no-status']);
        $focus = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona']);
        $true = $focus->values()->create(['name' => 'true']);
        $group = ProjectGroup::create(['user_id' => $user->id, 'name' => 'Quality', 'position' => 1]);
        $definition = [
            'metric' => 'turn_share',
            'people' => ['tags' => [$focus->id => [$true->id]]],
            'projects' => ['groups' => [$group->id]],
            'compare_by' => "project_tag:{$condition->id}",
            'baseline' => (string) $noStatus->id,
            'split_by' => 'pipeline',
        ];

        $named = DefinitionNames::toNames($definition, $user);

        $this->assertSame(['focus persona' => ['true']], $named['people']['tags']);
        $this->assertSame(['Quality'], $named['projects']['groups']);
        $this->assertSame('project_tag:status condition', $named['compare_by']);
        $this->assertSame('no-status', $named['baseline']);
        $this->assertSame($definition['compare_by'], DefinitionNames::toIds($named, $user)['compare_by']);
        $this->assertSame($definition['baseline'], DefinitionNames::toIds($named, $user)['baseline']);
        $this->assertSame([$focus->id => [$true->id]], DefinitionNames::toIds($named, $user)['people']['tags']);
        $this->assertSame([$group->id], DefinitionNames::toIds($named, $user)['projects']['groups']);
    }

    public function test_groups_are_recreated_for_the_importing_user_and_file_its_projects(): void
    {
        $source = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $source->id]);
        $project->addContributingUser($source);
        $group = ProjectGroup::create(['user_id' => $source->id, 'name' => 'Quality', 'position' => 1]);
        $project->fileInGroupFor($source, $group->id);
        $exported = (new GroupTransfer)->export($source);

        $reader = User::factory()->create();
        $project->addContributingUser($reader);
        $report = new ImportReport;
        (new GroupTransfer)->import($exported, $reader, $report);

        $copy = ProjectGroup::where('user_id', $reader->id)->sole();
        $this->assertSame('Quality', $copy->name);
        $this->assertSame($copy->id, $project->groupIdFor($reader));
        $this->assertSame(1, $report->count(ImportReport::GROUPS, ImportReport::CREATED));
    }

    public function test_questions_come_back_as_own_questions_and_templates_only_for_admins(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $admin->questions()->create(['name' => 'Mine', 'notes' => 'n', 'definition' => ['metric' => 'word_share']]);
        $admin->questions()->create(['name' => 'Template', 'definition' => [], 'is_template' => true]);
        $exported = (new QuestionTransfer)->export($admin);
        Question::query()->delete();

        $reader = User::factory()->create();
        $report = new ImportReport;
        (new QuestionTransfer)->import($exported, $reader, $report);

        $this->assertSame(['Mine', 'Template'], $reader->questions()->orderBy('position')->pluck('name')->all());
        $this->assertSame(0, Question::templates()->count());
        $this->assertSame('word_share', $reader->questions()->where('name', 'Mine')->sole()->definition()->metric->value);

        (new QuestionTransfer)->import($exported, $reader, $report);
        $this->assertSame(2, $reader->questions()->count());
        $this->assertSame(2, $report->count(ImportReport::QUESTIONS, ImportReport::SKIPPED));
    }

    public function test_a_definition_naming_a_deleted_category_converts_without_throwing(): void
    {
        $user = User::factory()->create();
        $category = TagCategory::create(['scope' => TagScope::Project, 'name' => 'gone']);
        $value = $category->values()->create(['name' => 'x']);
        $definition = ['metric' => 'turn_share', 'compare_by' => "project_tag:{$category->id}", 'baseline' => (string) $value->id];
        $category->delete();

        $named = DefinitionNames::toNames($definition, $user);

        $this->assertSame('project_tag:', $named['compare_by']);
        $this->assertNull($named['baseline']);
    }

    public function test_an_admin_importer_keeps_a_template_as_a_template(): void
    {
        $exported = [['name' => 'T', 'notes' => null, 'is_template' => true, 'definition' => ['metric' => 'word_share']]];
        $admin = User::factory()->create(['is_admin' => true]);
        $report = new ImportReport;

        (new QuestionTransfer)->import($exported, $admin, $report);

        $this->assertTrue((bool) $admin->questions()->sole()->is_template);
        $this->assertNull($report->entries(ImportReport::QUESTIONS)[0]['note']);
    }

    public function test_a_project_the_importer_does_not_contribute_to_is_not_filed(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $mine = Project::factory()->create(['user_id' => $owner->id]);
        $mine->addContributingUser($owner);
        $mine->addContributingUser($reader);
        $foreign = Project::factory()->create(['user_id' => $owner->id]);
        $foreign->addContributingUser($owner);
        $exported = [['name' => 'G', 'projects' => [$mine->uuid, $foreign->uuid]]];
        $report = new ImportReport;

        (new GroupTransfer)->import($exported, $reader, $report);

        $group = ProjectGroup::where('user_id', $reader->id)->sole();
        $this->assertSame($group->id, $mine->groupIdFor($reader));
        $this->assertNull($foreign->groupIdFor($reader));
        $this->assertSame(trans_choice('transfer.notes.projects_filed', 1, ['count' => 1]), $report->entries(ImportReport::GROUPS)[0]['note']);
    }

    public function test_an_existing_group_of_the_same_name_is_reused_and_its_projects_filed(): void
    {
        $reader = User::factory()->create();
        $existing = ProjectGroup::create(['user_id' => $reader->id, 'name' => 'Quality', 'position' => 1]);
        $project = Project::factory()->create(['user_id' => $reader->id]);
        $project->addContributingUser($reader);
        $report = new ImportReport;

        (new GroupTransfer)->import([['name' => 'Quality', 'projects' => [$project->uuid]]], $reader, $report);

        $this->assertSame(1, ProjectGroup::where('user_id', $reader->id)->count());
        $this->assertSame($existing->id, $project->groupIdFor($reader));
        $this->assertSame(1, $report->count(ImportReport::GROUPS, ImportReport::SKIPPED));
    }

    public function test_names_resolve_to_the_new_ids_after_every_id_changed(): void
    {
        $user = User::factory()->create();
        $make = function (bool $reverse) use ($user): array {
            $projectCat = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
            $personaCat = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona']);
            foreach ($reverse ? ['b', 'a'] : ['a', 'b'] as $name) {
                $projectCat->values()->create(['name' => $name]);
            }
            $personaCat->values()->create(['name' => 'true']);
            $group = ProjectGroup::create(['user_id' => $user->id, 'name' => 'Quality', 'position' => 1]);

            return [$projectCat, $personaCat, $group];
        };
        [$projectCat, $personaCat, $group] = $make(false);
        $a = $projectCat->values()->where('name', 'a')->value('id');
        $definition = [
            'metric' => 'turn_share',
            'people' => ['tags' => [$personaCat->id => [$personaCat->values()->value('id')]]],
            'projects' => ['tags' => [$projectCat->id => [$a]], 'groups' => [$group->id]],
            'compare_by' => "project_tag:{$projectCat->id}",
            'baseline' => (string) $a,
        ];
        $named = DefinitionNames::toNames($definition, $user);

        TagCategory::all()->each->delete();
        ProjectGroup::query()->delete();
        [$newProject, $newPersona, $newGroup] = $make(true);
        $newA = $newProject->values()->where('name', 'a')->value('id');
        $ids = DefinitionNames::toIds($named, $user);

        $this->assertNotSame($projectCat->id, $newProject->id);
        $this->assertSame([$newPersona->id => [$newPersona->values()->value('id')]], $ids['people']['tags']);
        $this->assertSame([$newProject->id => [$newA]], $ids['projects']['tags']);
        $this->assertSame([$newGroup->id], $ids['projects']['groups']);
        $this->assertSame("project_tag:{$newProject->id}", $ids['compare_by']);
        $this->assertSame((string) $newA, $ids['baseline']);
    }

    public function test_a_persona_and_a_project_category_of_the_same_name_stay_apart(): void
    {
        $user = User::factory()->create();
        $project = TagCategory::create(['scope' => TagScope::Project, 'name' => 'kind']);
        $persona = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'kind']);
        $pv = $project->values()->create(['name' => 'x']);
        $ev = $persona->values()->create(['name' => 'x']);
        $named = [
            'people' => ['tags' => ['kind' => ['x']]],
            'projects' => ['tags' => ['kind' => ['x']]],
            'compare_by' => 'project_tag:kind',
        ];

        $ids = DefinitionNames::toIds($named, $user);

        $this->assertSame([$persona->id => [$ev->id]], $ids['people']['tags']);
        $this->assertSame([$project->id => [$pv->id]], $ids['projects']['tags']);
        $this->assertSame("project_tag:{$project->id}", $ids['compare_by']);
    }

    public function test_split_by_and_the_other_baseline_kinds_round_trip(): void
    {
        $user = User::factory()->create();
        $persona = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona']);
        $project = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $project->values()->create(['name' => 'a']);
        $group = ProjectGroup::create(['user_id' => $user->id, 'name' => 'Quality', 'position' => 1]);

        $tagged = DefinitionNames::toNames(['split_by' => "persona_tag:{$persona->id}", 'compare_by' => "project_tag:{$project->id}", 'baseline' => ''], $user);
        $this->assertSame('persona_tag:focus persona', $tagged['split_by']);
        $this->assertSame('', $tagged['baseline']);
        $back = DefinitionNames::toIds($tagged, $user);
        $this->assertSame("persona_tag:{$persona->id}", $back['split_by']);
        $this->assertSame('', $back['baseline']);

        $pipeline = DefinitionNames::toNames(['compare_by' => 'pipeline', 'baseline' => 'QualityPipeline'], $user);
        $this->assertSame('QualityPipeline', $pipeline['baseline']);
        $this->assertSame('QualityPipeline', DefinitionNames::toIds($pipeline, $user)['baseline']);

        $grouped = DefinitionNames::toNames(['compare_by' => 'group', 'baseline' => (string) $group->id], $user);
        $this->assertSame('Quality', $grouped['baseline']);
        $group->delete();
        $newGroup = ProjectGroup::create(['user_id' => $user->id, 'name' => 'Quality', 'position' => 1]);
        $this->assertSame((string) $newGroup->id, DefinitionNames::toIds($grouped, $user)['baseline']);
    }

    public function test_imported_questions_join_the_end_of_the_importers_list(): void
    {
        $reader = User::factory()->create();
        $reader->questions()->create(['name' => 'First', 'definition' => []]);
        $reader->questions()->create(['name' => 'Second', 'definition' => []]);
        $exported = [
            ['name' => 'Imported A', 'notes' => null, 'is_template' => false, 'definition' => ['metric' => 'word_share']],
            ['name' => 'Imported B', 'notes' => null, 'is_template' => false, 'definition' => ['metric' => 'turn_share']],
        ];

        (new QuestionTransfer)->import($exported, $reader, new ImportReport);

        $this->assertSame(['First', 'Second', 'Imported A', 'Imported B'], $reader->questions()->notTemplates()->pluck('name')->all());
        $this->assertSame([1, 2, 3, 4], $reader->questions()->notTemplates()->pluck('position')->map(fn ($p) => (int) $p)->all());
    }

    public function test_an_admins_imported_template_joins_the_end_of_the_templates(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $admin->questions()->create(['name' => 'Existing', 'definition' => [], 'is_template' => true]);

        (new QuestionTransfer)->import([['name' => 'Imported', 'notes' => null, 'is_template' => true, 'definition' => []]], $admin, new ImportReport);

        $this->assertSame(['Existing', 'Imported'], Question::templates()->pluck('name')->all());
        $this->assertSame(2, (int) Question::templates()->where('name', 'Imported')->sole()->position);
    }
}
