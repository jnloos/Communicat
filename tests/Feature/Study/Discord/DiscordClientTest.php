<?php

namespace Tests\Feature\Study\Discord;

use App\Study\Discord\DiscordClient;
use App\Study\Discord\DiscordRejected;
use App\Study\Discord\DiscordUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class DiscordClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
        config(['study.discord' => ['token' => 'tok', 'channel_id' => '42', 'application_id' => 'app', 'guild_id' => null, 'public_key' => null]]);
    }

    public function test_post_sends_bot_auth_channel_path_and_no_mentions(): void
    {
        Http::fake();

        app(DiscordClient::class)->post('hello @everyone');

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://discord.com/api/v10/channels/42/messages'
            && $request->hasHeader('Authorization', 'Bot tok')
            && str_starts_with($request->header('User-Agent')[0], 'DiscordBot (')
            && $request['content'] === 'hello @everyone'
            && $request['allowed_mentions'] === ['parse' => []]);
    }

    public function test_long_content_is_cut_to_two_thousand_characters(): void
    {
        Http::fake();

        app(DiscordClient::class)->post(str_repeat('ä', 2500));

        Http::assertSent(fn (Request $request) => mb_strlen($request['content']) === 2000);
    }

    public function test_a_short_rate_limit_waits_and_retries_once(): void
    {
        Http::fake(['*' => Http::sequence()->push(['retry_after' => 0.5], 429)->push([], 200)]);

        app(DiscordClient::class)->post('x');

        Http::assertSentCount(2);
        Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds === 500.0, 1);
    }

    public function test_a_long_rate_limit_is_not_waited_for(): void
    {
        Http::fake(['*' => Http::response(['retry_after' => 30], 429)]);

        $this->expectException(DiscordUnavailable::class);
        try {
            app(DiscordClient::class)->post('x');
        } finally {
            Http::assertSentCount(1);
            Sleep::assertNeverSlept();
        }
    }

    public function test_a_second_rate_limit_gives_up(): void
    {
        Http::fake(['*' => Http::response(['retry_after' => 0.1], 429)]);

        $this->expectException(DiscordUnavailable::class);
        try {
            app(DiscordClient::class)->post('x');
        } finally {
            Http::assertSentCount(2);
        }
    }

    public function test_a_wrong_token_is_rejected_with_the_status(): void
    {
        Http::fake(['*' => Http::response([], 401)]);

        try {
            app(DiscordClient::class)->post('x');
            $this->fail('expected DiscordRejected');
        } catch (DiscordRejected $e) {
            $this->assertSame(401, $e->status);
        }
    }

    public function test_a_server_error_is_unavailable(): void
    {
        Http::fake(['*' => Http::response([], 503)]);

        $this->expectException(DiscordUnavailable::class);

        app(DiscordClient::class)->post('x');
    }

    public function test_commands_register_on_the_guild_when_there_is_one(): void
    {
        config(['study.discord.guild_id' => 'g1']);
        Http::fake();

        app(DiscordClient::class)->registerCommands();

        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request->url() === 'https://discord.com/api/v10/applications/app/guilds/g1/commands'
            && $request->data()[0]['name'] === 'status');
    }

    public function test_commands_register_globally_without_a_guild(): void
    {
        Http::fake();

        app(DiscordClient::class)->registerCommands();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://discord.com/api/v10/applications/app/commands');
    }
}
