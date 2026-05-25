<?php

namespace Tests\Unit\Pipeline;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use App\Models\User;
use App\Services\PromptingPipeline\DiscussionPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class PipelineModeratorMentionTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;
    private Expert $sophie;
    private Expert $lena;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::withoutEvents(fn() => Project::create([
            'title'       => 'Test',
            'description' => 'Test',
            'settings'    => [],
            'user_id'     => $this->user->id,
        ]));
        $this->sophie = Expert::factory()->create(['name' => 'Sophie Wagner']);
        $this->lena   = Expert::factory()->create(['name' => 'Lena Fischer']);
        $this->project->addContributingExpert($this->sophie);
        $this->project->addContributingExpert($this->lena);
    }

    private function makeUserMessage(string $content): Message
    {
        $message = new Message();
        $message->project_id = $this->project->id;
        $message->user_id    = $this->user->id;
        $message->content    = $content;
        $message->save();

        return $message;
    }

    private function callExtract(?Message $msg): ?Expert
    {
        $pipeline = new DiscussionPipeline($this->project);
        $method   = new ReflectionMethod($pipeline, 'extractUserMention');
        $method->setAccessible(true);

        return $method->invoke(
            $pipeline,
            $msg,
            $this->project->contributingExperts(),
        );
    }

    public function test_resolves_full_name_mention(): void
    {
        $msg = $this->makeUserMessage('Was meinst du @Sophie Wagner zu dem Vorschlag?');

        $result = $this->callExtract($msg);

        $this->assertNotNull($result);
        $this->assertSame('Sophie Wagner', $result->name);
    }

    public function test_resolves_mention_at_start_of_message(): void
    {
        $msg = $this->makeUserMessage('@Lena Fischer kannst du dazu etwas sagen?');

        $result = $this->callExtract($msg);

        $this->assertNotNull($result);
        $this->assertSame('Lena Fischer', $result->name);
    }

    public function test_returns_null_when_no_mention_present(): void
    {
        $msg = $this->makeUserMessage('Eine ganz normale Nachricht ohne Erwähnung.');

        $this->assertNull($this->callExtract($msg));
    }

    public function test_returns_null_when_mention_does_not_match_contributor(): void
    {
        $msg = $this->makeUserMessage('Was sagt @Unbekannt dazu?');

        $this->assertNull($this->callExtract($msg));
    }

    public function test_ignores_at_inside_email_address(): void
    {
        $msg = $this->makeUserMessage('Schick es an sophie@example.com bitte.');

        $this->assertNull($this->callExtract($msg));
    }

    public function test_returns_null_for_expert_authored_message(): void
    {
        $message = new Message();
        $message->project_id = $this->project->id;
        $message->expert_id  = $this->sophie->id;
        $message->content    = '@Lena Fischer übernimmst du?';
        $message->save();

        $this->assertNull($this->callExtract($message));
    }

    public function test_returns_null_for_null_message(): void
    {
        $this->assertNull($this->callExtract(null));
    }

    public function test_prefers_longest_matching_contributor_name(): void
    {
        $sophie = Expert::factory()->create(['name' => 'Sophie']);
        $this->project->addContributingExpert($sophie);

        $msg = $this->makeUserMessage('Hi @Sophie Wagner, was meinst du?');

        $result = $this->callExtract($msg);

        $this->assertNotNull($result);
        $this->assertSame('Sophie Wagner', $result->name);
    }

    public function test_resolves_case_insensitively(): void
    {
        $msg = $this->makeUserMessage('Frage an @sophie wagner zur Architektur.');

        $result = $this->callExtract($msg);

        $this->assertNotNull($result);
        $this->assertSame('Sophie Wagner', $result->name);
    }
}
