<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateUser extends Command
{
    protected $signature = 'auth:create-user';

    protected $description = 'Create a new user for authentication';

    public function handle(): int
    {
        $name = text('Enter the name', required: true, validate: ['name' => 'max:255']);
        $email = text('Enter the email', required: true, validate: ['email' => 'email|max:255|unique:users,email']);
        $password = password('Enter the password', required: true, validate: ['password' => 'min:8']);
        $confirmation = password('Confirm the password', required: true);

        if ($password !== $confirmation) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        // The model's 'hashed' cast hashes the password.
        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);

        $this->info("User {$user->name} ({$user->email}) created successfully!");

        return self::SUCCESS;
    }
}
