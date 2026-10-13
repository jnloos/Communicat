<?php

namespace Tests\Feature\Statistics;

use App\Livewire\Statistics\StatisticsPage;
use App\Models\Question;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class StatisticsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get(route('statistics'))->assertRedirect(route('login'));
    }

    public function test_every_signed_in_user_reaches_the_page(): void
    {
        $this->actingAs(User::factory()->create())->get(route('statistics'))->assertOk()->assertSee(__('questions.heading'));
    }

    public function test_a_new_question_opens_at_once_without_a_name(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(StatisticsPage::class)->call('create')->assertSet('questionId', Question::sole()->id);

        $this->assertNull(Question::sole()->name);
        $this->assertFalse(Question::sole()->is_template);
    }

    public function test_a_template_opens_to_view_without_a_copy(): void
    {
        $template = User::factory()->create()->questions()->create(['name' => 'Status effect', 'definition' => ['metric' => 'word_share'], 'is_template' => true]);
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(StatisticsPage::class)
            ->assertSee('Templates')
            ->assertSee('Questions')
            ->assertSee('Status effect')
            ->call('open', $template->id)
            ->assertSet('questionId', $template->id)
            ->assertSee('Copy to adjust');

        $this->assertSame(0, $user->questions()->count());
        $this->assertSame(1, Question::count());
    }

    public function test_copying_a_template_makes_an_own_question_and_opens_it(): void
    {
        $template = User::factory()->create()->questions()->create(['name' => 'Status effect', 'definition' => ['metric' => 'word_share'], 'is_template' => true]);
        $user = User::factory()->create();
        $this->actingAs($user);

        $page = Livewire::test(StatisticsPage::class)->call('copyTemplate', $template->id);

        $copy = $user->questions()->sole();
        $page->assertSet('questionId', $copy->id);
        $this->assertFalse($copy->is_template);
        $this->assertSame('Copy of Status effect', $copy->name);
        $this->assertSame('word_share', $copy->definition['metric']);
        $this->assertTrue($template->fresh()->is_template);
    }

    public function test_the_copy_keeps_the_form_being_viewed(): void
    {
        $template = User::factory()->create()->questions()->create(['name' => 'F', 'definition' => ['metric' => 'word_share'], 'is_template' => true]);
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(StatisticsPage::class)->call('copyTemplate', $template->id, 'trajectory', 'rolling');

        $this->assertSame('trajectory', $user->questions()->sole()->definition['form']);
        $this->assertSame('rolling', $user->questions()->sole()->definition['curve']);
    }

    public function test_a_template_opens_by_url_parameter(): void
    {
        $template = User::factory()->create()->questions()->create(['name' => 'F', 'is_template' => true]);
        $this->actingAs(User::factory()->create());

        Livewire::withQueryParams(['q' => $template->id])->test(StatisticsPage::class)->assertSet('questionId', $template->id);
    }

    public function test_saving_as_template_keeps_the_question_and_names_it(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        $question = $user->questions()->create(['definition' => ['metric' => 'word_share']]);

        Livewire::test(StatisticsPage::class)->call('saveAsTemplate', $question->id);

        $template = Question::templates()->sole();
        $this->assertSame('Word share of everyone', $template->name);
        $this->assertFalse($question->fresh()->is_template);
    }

    public function test_renaming_to_nothing_leaves_the_question_untitled(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->questions()->create(['name' => 'Mine']);

        Livewire::test(StatisticsPage::class)
            ->call('startRenaming', $question->id)
            ->set('name', '   ')
            ->call('saveName')
            ->assertHasNoErrors();

        $this->assertNull($question->fresh()->name);
    }

    public function test_the_list_shows_title_and_sentence_of_a_named_entry(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->questions()->create(['name' => 'Mine', 'definition' => ['metric' => 'word_share']]);
        $user->questions()->create(['name' => 'Shared', 'definition' => ['metric' => 'gini'], 'is_template' => true]);

        Livewire::test(StatisticsPage::class)
            ->assertSee('Mine')
            ->assertSee('Word share of everyone')
            ->assertSee('Shared')
            ->assertDontSee('Untitled question');
    }

    public function test_an_unnamed_entry_shows_untitled_and_its_sentence(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->questions()->create(['definition' => ['metric' => 'word_share']]);

        Livewire::test(StatisticsPage::class)
            ->assertSee('Untitled question')
            ->assertSee('Word share of everyone');
    }

    public function test_renaming_to_empty_lists_the_question_as_untitled(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->questions()->create(['name' => 'Mine']);

        Livewire::test(StatisticsPage::class)
            ->assertDontSee('Untitled question')
            ->call('startRenaming', $question->id)
            ->set('name', '')
            ->call('saveName')
            ->assertSee('Untitled question');
    }

    public function test_a_template_cannot_be_renamed_to_nothing(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        $template = $user->questions()->create(['name' => 'Shared', 'is_template' => true]);

        Livewire::test(StatisticsPage::class)
            ->call('startRenaming', $template->id)
            ->set('name', '   ')
            ->call('saveName')
            ->assertHasErrors(['name' => 'required']);

        $this->assertSame('Shared', $template->fresh()->name);
    }

    public function test_only_the_owner_deletes_a_template(): void
    {
        $owner = User::factory()->create();
        $template = $owner->questions()->create(['name' => 'T', 'is_template' => true]);
        $this->actingAs(User::factory()->create());

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(StatisticsPage::class)->call('delete', $template->id);
    }

    public function test_someone_elses_question_does_not_exist_here(): void
    {
        $foreign = User::factory()->create()->questions()->create([]);
        $this->actingAs(User::factory()->create());

        Livewire::withQueryParams(['q' => $foreign->id])->test(StatisticsPage::class)->assertSet('questionId', null);
    }

    public function test_saving_as_a_template_drops_the_groups_and_says_so(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        $group = $user->projectGroups()->create(['name' => 'Score', 'position' => 0]);
        $question = $user->questions()->create(['definition' => ['compare_by' => 'group', 'split_by' => 'pipeline', 'projects' => ['groups' => [$group->id], 'pipelines' => ['ScorePipeline']]]]);

        Livewire::test(StatisticsPage::class)
            ->call('saveAsTemplate', $question->id)
            ->assertSee('Groups are personal, so the template does not use them.');

        $template = Question::templates()->sole()->definition();
        $this->assertSame([], $template->projects->groups);
        $this->assertSame(['ScorePipeline'], $template->projects->pipelines);
        $this->assertNull($template->compareBy);
        $this->assertSame('pipeline', $template->splitBy->key());
        $this->assertSame([$group->id], $question->fresh()->definition()->projects->groups);
    }

    public function test_a_template_without_groups_gives_no_notice(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        $question = $user->questions()->create(['definition' => ['metric' => 'word_share']]);

        Livewire::test(StatisticsPage::class)
            ->call('saveAsTemplate', $question->id)
            ->assertDontSee('Groups are personal');
    }

    public function test_the_list_shows_the_first_line_of_the_notes_else_the_sentence(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->questions()->create(['name' => 'With notes', 'notes' => "First line of notes\nSecond line"]);
        $user->questions()->create(['name' => 'Without notes']);

        Livewire::test(StatisticsPage::class)
            ->assertSee('First line of notes')
            ->assertDontSee('Second line of')
            ->assertSee('Turn share');
    }

    public function test_copying_duplicating_and_saving_as_template_keep_the_notes(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);
        $template = User::factory()->create()->questions()->create(['name' => 'Fix', 'notes' => 'From the template', 'is_template' => true]);
        $own = $user->questions()->create(['name' => 'Own', 'notes' => 'My conclusion']);

        $page = Livewire::test(StatisticsPage::class);
        $page->call('copyTemplate', $template->id);
        $page->call('duplicate', $own->id);
        $page->call('saveAsTemplate', $own->id);

        $this->assertSame('From the template', $user->questions()->notTemplates()->where('name', 'Copy of Fix')->sole()->notes);
        $this->assertSame('My conclusion', $user->questions()->notTemplates()->where('name', 'Copy of Own')->sole()->notes);
        $this->assertSame('My conclusion', $user->questions()->where('is_template', true)->sole()->notes);
    }

    public function test_the_list_reads_the_names_its_sentences_use_once_not_per_entry(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $category = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $status = $category->values()->create(['name' => 'status']);
        $group = $user->projectGroups()->create(['name' => 'Score', 'position' => 0]);
        $definition = ['projects' => ['tags' => [$category->id => [$status->id]], 'groups' => [$group->id]], 'compare_by' => "project_tag:{$category->id}"];
        $user->questions()->create(['definition' => $definition]);

        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(StatisticsPage::class)->assertSee('Status condition: status');
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $one = $queries();

        foreach (range(1, 7) as $more) {
            $user->questions()->create(['definition' => $definition]);
        }

        $this->assertSame($one, $queries());
    }

    public function test_only_an_admin_saves_a_template_and_sees_the_action(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->questions()->create(['name' => 'Mine']);

        Livewire::test(StatisticsPage::class)
            ->assertDontSee('Save as template')
            ->call('saveAsTemplate', $question->id)
            ->assertForbidden();

        $this->assertSame(0, Question::templates()->count());

        $user->update(['is_admin' => true]);
        Livewire::test(StatisticsPage::class)->assertSee('Save as template');
    }

    public function test_a_template_owner_who_is_no_admin_cannot_rename_or_delete_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $template = $user->questions()->create(['name' => 'Shared', 'is_template' => true]);

        Livewire::test(StatisticsPage::class)->call('startRenaming', $template->id)->assertForbidden();
        Livewire::test(StatisticsPage::class)->call('delete', $template->id)->assertForbidden();

        $this->assertSame('Shared', $template->fresh()?->name);
    }

    public function test_an_owner_who_is_no_admin_still_renames_and_deletes_own_questions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->questions()->create(['name' => 'Mine']);

        Livewire::test(StatisticsPage::class)
            ->call('startRenaming', $question->id)
            ->set('name', 'Renamed')
            ->call('saveName')
            ->assertHasNoErrors()
            ->call('delete', $question->id);

        $this->assertNull($question->fresh());
    }

    public function test_a_list_title_takes_one_line_and_its_note_two(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->questions()->create(['name' => 'Status effect on turn share by pipeline, a long title', 'notes' => 'A note']);

        $html = Livewire::test(StatisticsPage::class)->html();

        $this->assertMatchesRegularExpression('/<span class="[^"]*truncate[^"]*">Status effect on turn share/', $html);
        $this->assertMatchesRegularExpression('/<span class="[^"]*line-clamp-2[^"]*">A note/', $html);
    }

    public function test_the_rename_dialog_hint_says_only_how_to_clear_the_title(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(StatisticsPage::class)
            ->assertSee('Leave empty for no title.')
            ->assertDontSee('The sentence is always shown beside it');
    }

    public function test_the_empty_templates_text_is_neutral_and_only_admins_get_the_call_to_action(): void
    {
        app()->setLocale('en');
        $this->actingAs(User::factory()->create());
        Livewire::test(StatisticsPage::class)->assertSee('No template yet.')->assertDontSee('Save a question as a template to share it.');

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Livewire::test(StatisticsPage::class)->assertSee('No template yet.')->assertSee('Save a question as a template to share it.');
    }

    public function test_an_owner_reorders_own_questions_by_drag_and_by_menu(): void
    {
        $user = User::factory()->create();
        foreach (['One', 'Two', 'Three'] as $name) {
            $user->questions()->create(['name' => $name]);
        }
        $this->actingAs($user);
        $id = fn (string $name) => Question::where('name', $name)->sole()->id;

        Livewire::test(StatisticsPage::class)
            ->call('sortQuestion', $id('Three'), 0)
            ->assertSeeInOrder(['Three', 'One', 'Two'])
            ->call('moveQuestion', $id('Three'), 1)
            ->assertSeeInOrder(['One', 'Three', 'Two']);
    }

    public function test_nobody_reorders_someone_elses_question(): void
    {
        $foreign = User::factory()->create()->questions()->create(['name' => 'Theirs']);
        $this->actingAs(User::factory()->create());

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(StatisticsPage::class)->call('sortQuestion', $foreign->id, 0);
    }

    public function test_only_admins_reorder_the_templates(): void
    {
        $owner = User::factory()->create(['is_admin' => true]);
        $first = $owner->questions()->create(['name' => 'First template', 'is_template' => true]);
        $second = $owner->questions()->create(['name' => 'Second template', 'is_template' => true]);

        $this->actingAs(User::factory()->create());
        Livewire::test(StatisticsPage::class)->call('sortTemplate', $second->id, 0)->assertForbidden();
        Livewire::test(StatisticsPage::class)->call('moveTemplate', $second->id, -1)->assertForbidden();
        $this->assertSame([$first->id, $second->id], Question::templates()->pluck('id')->all());

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Livewire::test(StatisticsPage::class)->call('moveTemplate', $second->id, -1)->assertSeeInOrder(['Second template', 'First template']);
    }
}
