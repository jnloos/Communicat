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
}
