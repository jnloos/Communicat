<?php

namespace Tests\Feature\Statistics;

use App\Livewire\Statistics\StatisticsPage;
use App\Models\Comparison;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        Livewire::test(StatisticsPage::class)->call('create')->assertSet('comparisonId', Comparison::sole()->id);

        $this->assertNull(Comparison::sole()->name);
        $this->assertFalse(Comparison::sole()->is_template);
    }

    public function test_a_fix_question_opens_to_view_without_a_copy(): void
    {
        $fix = User::factory()->create()->comparisons()->create(['name' => 'Status effect', 'question' => ['metric' => 'word_share'], 'is_template' => true]);
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(StatisticsPage::class)
            ->assertSee('Templates')
            ->assertSee('Questions')
            ->assertSee('Status effect')
            ->call('open', $fix->id)
            ->assertSet('comparisonId', $fix->id)
            ->assertSee('Copy to adjust');

        $this->assertSame(0, $user->comparisons()->count());
        $this->assertSame(1, Comparison::count());
    }

    public function test_copying_a_fix_question_makes_an_own_question_and_opens_it(): void
    {
        $fix = User::factory()->create()->comparisons()->create(['name' => 'Status effect', 'question' => ['metric' => 'word_share'], 'is_template' => true]);
        $user = User::factory()->create();
        $this->actingAs($user);

        $page = Livewire::test(StatisticsPage::class)->call('copyFixQuestion', $fix->id);

        $copy = $user->comparisons()->sole();
        $page->assertSet('comparisonId', $copy->id);
        $this->assertFalse($copy->is_template);
        $this->assertSame('Status effect', $copy->name);
        $this->assertSame('word_share', $copy->question['metric']);
        $this->assertTrue($fix->fresh()->is_template);
    }

    public function test_the_copy_keeps_the_form_being_viewed(): void
    {
        $fix = User::factory()->create()->comparisons()->create(['name' => 'F', 'question' => ['metric' => 'word_share'], 'is_template' => true]);
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(StatisticsPage::class)->call('copyFixQuestion', $fix->id, 'trajectory', 'rolling');

        $this->assertSame('trajectory', $user->comparisons()->sole()->question['form']);
        $this->assertSame('rolling', $user->comparisons()->sole()->question['curve']);
    }

    public function test_a_fix_question_opens_by_url_parameter(): void
    {
        $fix = User::factory()->create()->comparisons()->create(['name' => 'F', 'is_template' => true]);
        $this->actingAs(User::factory()->create());

        Livewire::withQueryParams(['c' => $fix->id])->test(StatisticsPage::class)->assertSet('comparisonId', $fix->id);
    }

    public function test_saving_as_fix_question_keeps_the_question_and_names_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->comparisons()->create(['question' => ['metric' => 'word_share']]);

        Livewire::test(StatisticsPage::class)->call('saveAsTemplate', $question->id);

        $template = Comparison::templates()->sole();
        $this->assertSame('Word share · everyone', $template->name);
        $this->assertFalse($question->fresh()->is_template);
    }

    public function test_renaming_to_nothing_leaves_the_question_untitled(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->comparisons()->create(['name' => 'Mine']);

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
        $user->comparisons()->create(['name' => 'Mine', 'question' => ['metric' => 'word_share']]);
        $user->comparisons()->create(['name' => 'Shared', 'question' => ['metric' => 'gini'], 'is_template' => true]);

        Livewire::test(StatisticsPage::class)
            ->assertSee('Mine')
            ->assertSee('Word share · everyone')
            ->assertSee('Shared')
            ->assertDontSee('Untitled question');
    }

    public function test_an_unnamed_entry_shows_untitled_and_its_sentence(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $user->comparisons()->create(['question' => ['metric' => 'word_share']]);

        Livewire::test(StatisticsPage::class)
            ->assertSee('Untitled question')
            ->assertSee('Word share · everyone');
    }

    public function test_renaming_to_empty_lists_the_question_as_untitled(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->comparisons()->create(['name' => 'Mine']);

        Livewire::test(StatisticsPage::class)
            ->assertDontSee('Untitled question')
            ->call('startRenaming', $question->id)
            ->set('name', '')
            ->call('saveName')
            ->assertSee('Untitled question');
    }

    public function test_a_template_cannot_be_renamed_to_nothing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $template = $user->comparisons()->create(['name' => 'Shared', 'is_template' => true]);

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
        $template = $owner->comparisons()->create(['name' => 'T', 'is_template' => true]);
        $this->actingAs(User::factory()->create());

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(StatisticsPage::class)->call('delete', $template->id);
    }

    public function test_someone_elses_question_does_not_exist_here(): void
    {
        $foreign = User::factory()->create()->comparisons()->create([]);
        $this->actingAs(User::factory()->create());

        Livewire::withQueryParams(['c' => $foreign->id])->test(StatisticsPage::class)->assertSet('comparisonId', null);
    }
}
