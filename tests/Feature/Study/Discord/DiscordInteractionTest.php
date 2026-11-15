<?php

namespace Tests\Feature\Study\Discord;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Study\BuildsStudyCells;
use Tests\Feature\Study\UsesTemporaryPauseFile;
use Tests\TestCase;

class DiscordInteractionTest extends TestCase
{
    use BuildsStudyCells;
    use RefreshDatabase;
    use SignsInteractions;
    use UsesTemporaryPauseFile;

    protected function setUp(): void
    {
        $this->enableInteractions();
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->disableInteractions();
    }

    public function test_status_in_the_study_channel_answers_with_the_snapshot(): void
    {
        $this->cell(spoken: 1);
        $this->cell(spoken: 2);

        $response = $this->interact(['type' => 2, 'channel_id' => 'study-channel', 'data' => ['name' => 'status']])->assertOk();

        $this->assertSame(4, $response->json('type'));
        $this->assertStringContainsString('1/2', $response->json('data.content'));
        $this->assertSame(['parse' => []], $response->json('data.allowed_mentions'));
        $this->assertNull($response->json('data.flags'));
    }

    public function test_status_in_another_channel_is_refused_privately_without_numbers(): void
    {
        $this->cell(spoken: 2);

        $response = $this->interact(['type' => 2, 'channel_id' => 'elsewhere', 'data' => ['name' => 'status']])->assertOk();

        $this->assertSame(64, $response->json('data.flags'));
        $this->assertStringNotContainsString('1/1', $response->json('data.content'));
        $this->assertSame(__('study::watch.discord.wrong_channel'), $response->json('data.content'));
    }

    public function test_an_unknown_command_is_a_bad_request(): void
    {
        $this->interact(['type' => 2, 'channel_id' => 'study-channel', 'data' => ['name' => 'pause']])->assertStatus(400);
        $this->interact(['type' => 99])->assertStatus(400);
    }

    public function test_the_route_does_not_exist_without_a_public_key(): void
    {
        $this->disableInteractions();
        $this->refreshApplication();

        $this->postJson('/discord/interactions', ['type' => 1])->assertNotFound();
    }
}
