<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Models\User;
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

        $this->comment('Destroying the database...');
        $this->call('db:wipe', ['--force' => true]);
        $this->info('Database wiped successfully.');

        $this->comment('Running migrations...');
        $this->call('migrate', ['--force' => true]);
        $this->info('Migrations completed successfully.');

        $this->comment('Load default experts...');
        $this->call('init:experts');

        $this->comment('Creating a admin user...');
        $admin = new User();
        $admin->id = 1;
        $admin->name = 'admin';
        $admin->email = 'admin@localhost';
        $admin->password = Hash::make('admin');
        $admin->is_admin = true;
        $admin->save();
        $this->info("Default admin created: $admin->name ($admin->email)");

        $this->comment('Creating test user...');
        $test = new User();
        $test->name = 'test';
        $test->email = 'test@localhost';
        $test->password = Hash::make('test');
        $test->save();
        $this->info("Test user created: $test->name ($test->email)");

        $this->comment('Creating demo project...');
        $project = new Project();
        $project->user_id = $admin->id;
        $project->title = 'World Conqueror';
        $project->description = 'Strategic discussion on global domination: Which country should we attack first, and who should we ally with to get started?';
        $project->settings = ['summary_frequency' => 10];
        $project->save();
        $project->users()->syncWithoutDetaching($admin->id);
        $this->info("Demo project created: {$project->title}");

        return 0;
    }
}
