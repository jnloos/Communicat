<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BulkCreateUsers extends Command
{
    protected $signature = 'auth:create-users {count : Number of users to create}';
    protected $description = 'Create multiple users in bulk and output credentials.';

    public function handle(): int {
        $count = (int) $this->argument('count');

        if ($count < 1) {
            $this->error('Count must be >= 1.');
            return self::FAILURE;
        }

        $domain = parse_url(config('app.url'), PHP_URL_HOST);

        $users = [];
        $output = [];

        for ($i = 1; $i <= $count; $i++) {
            $password = Str::password(8);
            $email = "demouser-$i@$domain";

            $users[] = [
                'name' => "Demo User $i",
                'email' => $email,
                'password' => Hash::make($password)
            ];

            $output[] = [
                'email' => $email,
                'password' => $password,
            ];
        }

        User::insert($users);

        $this->newLine();
        $this->info('CREATED USERS:');
        foreach ($output as $row) {
            $this->line($row['email'] . ' | ' . $row['password']);
        }

        return self::SUCCESS;
    }
}
