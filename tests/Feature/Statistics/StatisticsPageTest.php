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

    public function test_anyone_copies_a_template_into_their_own_questions(): void
    {
        $template = User::factory()->create()->comparisons()->create(['name' => 'Status effect', 'question' => ['metric' => 'word_share'], 'is_template' => true]);
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(StatisticsPage::class)->assertSee('Status effect')->call('useTemplate', $template->id);

        $copy = $user->comparisons()->sole();
        $this->assertFalse($copy->is_template);
        $this->assertSame('Status effect', $copy->name);
        $this->assertSame('word_share', $copy->question['metric']);
    }

    public function test_saving_as_template_keeps_the_question_and_names_the_template(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $question = $user->comparisons()->create(['question' => ['metric' => 'word_share']]);

        Livewire::test(StatisticsPage::class)->call('saveAsTemplate', $question->id);

        $template = Comparison::templates()->sole();
        $this->assertSame('Word share · everyone', $template->name);
        $this->assertFalse($question->fresh()->is_template);
    }

    public function test_renaming_to_nothing_returns_to_the_sentence(): void
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
