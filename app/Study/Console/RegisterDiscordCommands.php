<?php

namespace App\Study\Console;

use App\Study\Discord\DiscordClient;
use Illuminate\Console\Command;
use Throwable;

/** One-off setup: announces /status to Discord and prints the URL for the Developer Portal. */
class RegisterDiscordCommands extends Command
{
    protected $signature = 'study:discord-register';

    protected $description = 'Register the /status slash command with Discord (idempotent).';

    public function handle(DiscordClient $discord): int
    {
        foreach (['DISCORD_APPLICATION_ID' => 'application_id', 'DISCORD_BOT_TOKEN' => 'token'] as $env => $key) {
            if (! config("study.discord.{$key}")) {
                $this->error("{$env} fehlt.");

                return self::FAILURE;
            }
        }

        try {
            $discord->registerCommands();
        } catch (Throwable $e) {
            $this->error('Discord: '.$e->getMessage());

            return self::FAILURE;
        }

        $scope = config('study.discord.guild_id') ? 'on the server' : 'globally (can take up to an hour)';
        $this->info("/status registered {$scope}.");
        $this->line('Interactions Endpoint URL for the Developer Portal: '.url('/study/discord/interactions'));

        return self::SUCCESS;
    }
}
