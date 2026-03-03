<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CreateUser extends Command
{
    protected $signature = 'auth:create-user';
    protected $description = 'Create a new user for authentication';

    public function handle(): int {
        // Ask for user details
        $name = $this->ask('Enter the name');
        $email = $this->ask('Enter the email');
        $pass = $this->secret('Enter the password');
        $passConf = $this->secret('Confirm the password');

        // Check if passwords match
        if ($pass !== $passConf) {
            $this->error('Passwords do not match.');
            return 1; // exit with an error code
        }

        // Checks if email is in use
        if (User::where('email', $email)->exists()) {
            $this->error('A user with this email already exists.');
            return 1;
        }

        // Create the new user record
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($pass),
        ]);

        $this->info("User {$user->name} ({$user->email}) created successfully!");

        return 0;
    }
}
