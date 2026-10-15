<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BulkCreateUsers extends Command
{
    protected $signature = 'auth:create-users {count : Number of users to create}';

    protected $description = 'Create multiple users in bulk and output credentials.';

    public function handle(): int
    {
        $count = (int) $this->argument('count');

        if ($count < 1) {
            $this->error('Count must be >= 1.');

            return self::FAILURE;
        }

        $credentials = DB::transaction(fn () => $this->createUsers($count));

        $this->newLine();
        $this->info('CREATED USERS:');

        foreach ($credentials as $email => $password) {
            $this->line("{$email} | {$password}");
        }

        return self::SUCCESS;
    }

    /**
     * Numbers on from the highest demo user already there, so a second run
     * adds users instead of failing on demouser-1. Created one by one, so the
     * timestamps are set and the 'hashed' cast hashes the password.
     *
     * @return array<string, string> email => plain password
     */
    private function createUsers(int $count): array
    {
        $domain = parse_url(config('app.url'), PHP_URL_HOST);
        $first = $this->highestDemoNumber($domain) + 1;
        $credentials = [];

        foreach (range($first, $first + $count - 1) as $number) {
            $password = Str::password(8);
            $email = "demouser-{$number}@{$domain}";

            User::create(['name' => "Demo User {$number}", 'email' => $email, 'password' => $password]);
            $credentials[$email] = $password;
        }

        return $credentials;
    }

    private function highestDemoNumber(?string $domain): int
    {
        return User::where('email', 'like', "demouser-%@{$domain}")
            ->pluck('email')
            ->map(fn (string $email) => (int) Str::between($email, 'demouser-', '@'))
            ->max() ?? 0;
    }
}
