<?php

namespace Tests\Feature\Pipeline;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\PromptingPipeline\Data\Directive;
use App\Services\PromptingPipeline\Support\MentionResolver;
use App\Services\PromptingPipeline\Support\SpeakTrailerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SPEAK prompt forbids naming the user on an expert-round turn, and the
 * model ignores it anyway — a live verification run produced "admin, aus
 * architektonischer Sicht …" with the rule rendered right there in the prompt.
 * That is the reported symptom ("the user was addressed although nobody chose
 * them"), so it gets a deterministic net.
 */
class UserVocativeTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Expert $speaker;

    private SpeakTrailerResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create(['name' => 'admin']);
        $this->project = Project::withoutEvents(fn () => Project::create([
            'title' => 'Test', 'description' => 'Test', 'settings' => [], 'user_id' => $owner->id,
        ]));
        $this->speaker = Expert::factory()->create(['name' => 'Michael Bauer']);
        $this->project->addContributingExpert($this->speaker);
        $this->project->addContributingExpert(Expert::factory()->create(['name' => 'Sophie Wagner']));

        $this->resolver = new SpeakTrailerResolver(new MentionResolver);
    }

    private function directive(bool $handBack = false, ?string $pendingUser = null): Directive
    {
        return new Directive(
            role: 'vertiefen',
            agendaStep: 'divergenz',
            convergenceIntent: '',
            handBackToUser: $handBack,
            pendingUserName: $pendingUser,
        );
    }

    private function speak(string $content, ?Directive $directive): string
    {
        return $this->resolver->resolve(
            ['content' => $content, 'adjacency_partner_token' => null, 'adjacency_pair_type' => null],
            $this->project,
            $this->speaker,
            $directive,
        )['content'];
    }

    public function test_a_leading_user_vocative_is_removed(): void
    {
        $this->assertSame(
            'Aus architektonischer Sicht ist eine regelbasierte Vorgehensweise sinnvoll.',
            $this->speak('admin, Aus architektonischer Sicht ist eine regelbasierte Vorgehensweise sinnvoll.', $this->directive()),
        );
    }

    public function test_a_trailing_user_vocative_is_removed(): void
    {
        $this->assertSame(
            'Die Betreuung bleibt so planbar.',
            $this->speak('Die Betreuung bleibt so planbar, admin.', $this->directive()),
        );
    }

    /**
     * On a hand-off the persona is supposed to speak to the human, so the name
     * must survive.
     */
    public function test_the_name_survives_on_a_hand_off_turn(): void
    {
        $content = 'Welche Variante bevorzugst du, admin?';

        $this->assertSame($content, $this->speak($content, $this->directive(handBack: true)));
    }

    /**
     * Answering a pending user message legitimately references them.
     */
    public function test_the_name_survives_while_answering_the_user(): void
    {
        $content = 'Das lässt sich ohne Budget lösen, admin.';

        $this->assertSame($content, $this->speak($content, $this->directive(pendingUser: 'admin')));
    }

    /**
     * Only vocative positions are touched — quoting the human mid-sentence is
     * normal discussion behaviour.
     */
    public function test_a_mid_sentence_reference_is_left_alone(): void
    {
        $content = 'Der von admin genannte Engpass betrifft vor allem die Prüfungslast.';

        $this->assertSame($content, $this->speak($content, $this->directive()));
    }

    public function test_an_expert_name_is_never_stripped(): void
    {
        $content = 'Sophie Wagner, wie siehst du die Prüfungslast?';

        $this->assertSame($content, $this->speak($content, $this->directive()));
    }

    public function test_without_a_directive_nothing_is_touched(): void
    {
        $content = 'admin, das sehe ich anders.';

        $this->assertSame($content, $this->speak($content, null));
    }
}
