<?php

namespace App\Study\Discord;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/** Discord over plain REST: no gateway, no library, the bot is never "online". */
class DiscordClient
{
    private const BASE_URL = 'https://discord.com/api/v10';

    private const MAX_CONTENT = 2000;

    private const MAX_WAIT_SECONDS = 10;

    public function configured(): bool
    {
        return (bool) (config('study.discord.token') && config('study.discord.channel_id'));
    }

    public function post(string $content): void
    {
        $this->send('POST', '/channels/'.config('study.discord.channel_id').'/messages', [
            'content' => mb_substr($content, 0, self::MAX_CONTENT),
            'allowed_mentions' => ['parse' => []],
        ]);
    }

    /** PUT replaces the whole list, so this is idempotent. */
    public function registerCommands(): void
    {
        $application = config('study.discord.application_id');
        $guild = config('study.discord.guild_id');

        $this->send('PUT', $guild ? "/applications/{$application}/guilds/{$guild}/commands" : "/applications/{$application}/commands", [
            ['name' => 'status', 'type' => 1, 'description' => __('study::watch.discord.command_description')],
        ]);
    }

    /** One retry after a short rate limit; a long one or a second one is not worth waiting for. */
    private function send(string $method, string $path, array $body): void
    {
        $response = $this->request($method, $path, $body);

        if ($response->status() === 429) {
            $wait = (float) $response->json('retry_after', self::MAX_WAIT_SECONDS + 1);

            if ($wait > self::MAX_WAIT_SECONDS) {
                throw new DiscordUnavailable("Discord rate limit: retry after {$wait} s");
            }

            Sleep::for($wait)->seconds();
            $response = $this->request($method, $path, $body);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new DiscordRejected($response->status());
        }

        if ($response->failed()) {
            throw new DiscordUnavailable("Discord answered {$response->status()}");
        }
    }

    private function request(string $method, string $path, array $body): Response
    {
        return Http::withToken(config('study.discord.token'), 'Bot')
            ->withUserAgent('DiscordBot (https://communicat.example, 1.0)')
            ->timeout(5)->connectTimeout(3)
            ->send($method, self::BASE_URL.$path, ['json' => $body]);
    }
}
