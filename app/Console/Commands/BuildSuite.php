<?php

namespace App\Console\Commands;

use App\Facades\Python;
use App\Models\User;
use App\Services\ExpertParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class BuildSuite extends Command
{
    protected $signature = 'dev:build-suite';

    protected $description = 'Builds a development suite with default settings.';

    public function handle(): int {
        $this->warn('This command will destroy the database and fill it with test entries.');
        if (!$this->confirm('Do you really want to do this?')) {
            $this->info('Operation cancelled.');
            return 0;
        }

        // Wipe the database tables
        $this->comment('Destroying the database...');
        $this->call('db:wipe', ['--force' => true]);
        $this->info('Database wiped successfully.');

        // Run all migrations to reset the database
        $this->comment('Running migrations...');
        $this->call('migrate', ['--force' => true]);
        $this->info('Migrations completed successfully.');

        $this->comment('Load default experts...');
        $this->call('init:experts');

        // Create a default user
        $this->comment('Creating a admin user...');
        $user = new User();
        $user->id = 1;
        $user->name = 'admin';
        $user->email = 'admin@localhost';
        $user->password = Hash::make('admin');
        $user->save();
        $this->info("Default admin created: $user->name ($user->email)");

        // Rebuild the Python environment
        $this->comment('Clearing Python environment...');
        Python::clear();
        $this->comment('Building Python environment...');
        Python::build();
        $this->info('Python environment successfully rebuilt.');

        return 0;
    }
}
