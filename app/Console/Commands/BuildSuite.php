<?php

namespace App\Console\Commands;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class BuildSuite extends Command
{
    protected $signature = 'dev:build-suite';

    protected $description = 'Builds a development suite with default settings.';

    public function handle(): int
    {
        $this->warn('This command will destroy the database and fill it with test entries.');
        if (! $this->confirm('Do you really want to do this?')) {
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
        $admin = new User;
        $admin->id = 1;
        $admin->name = 'admin';
        $admin->email = 'admin@localhost';
        $admin->password = Hash::make('admin');
        $admin->is_admin = true;
        $admin->save();
        $this->info("Default admin created: $admin->name ($admin->email)");

        $this->comment('Creating test user...');
        $test = new User;
        $test->name = 'test';
        $test->email = 'test@localhost';
        $test->password = Hash::make('test');
        $test->save();
        $this->info("Test user created: $test->name ($test->email)");

        $this->comment('Creating demo discussion projects...');

        $projects = [
            [
                'title' => 'Speed limit on motorways',
                'description' => 'Should Germany introduce a general speed limit of 130 km/h on motorways? Discuss specifically the CO₂ savings potential, accident and fatality figures, the consequences for commuters and logistics, and the freedom argument — and work toward a shared, reasoned recommendation.',
                'experts' => ['Lisa Graf', 'David Kaufmann', 'Tim Hofmann', 'Paul Neumann'],
            ],
            [
                'title' => 'AI tools in schools',
                'description' => 'Should students be allowed to use AI tools like ChatGPT in class and for homework? Clarify specifically: which tasks deliberately stay AI-free, how is performance assessed fairly, what data protection rules apply to minors, and how does the teacher\'s role change?',
                'experts' => ['Stefan Maier', 'Nina Keller', 'Katharina Wolf', 'Paul Neumann'],
            ],
            [
                'title' => 'EU chat control',
                'description' => 'Should messenger services be required to automatically scan even end-to-end encrypted messages for depictions of abuse (client-side scanning)? Weigh child protection, IT security, fundamental rights and technical feasibility against each other.',
                'experts' => ['Sarah Vogel', 'Katharina Wolf', 'Tim Hofmann', 'Paul Neumann'],
            ],
            [
                'title' => 'Return to the office or remote-first',
                'description' => 'Should our company introduce mandatory office attendance of three days a week, or stay remote-first? Discuss specifically productivity, team cohesion, office costs, fairness toward parents and commuters, and the effect on recruiting.',
                'experts' => ['Marie Hoffmann', 'Clara Schmidt', 'David Kaufmann', 'Paul Neumann'],
            ],
            [
                'title' => 'Four-day week with full pay',
                'description' => 'Should the four-day week with full wage compensation (32 hours, 100% pay) be promoted politically? Clarify specifically the effects on productivity, labor costs, skilled-worker shortage, health and international competitiveness.',
                'experts' => ['David Kaufmann', 'Lisa Graf', 'Marie Hoffmann', 'Paul Neumann'],
            ],
        ];

        foreach ($projects as $spec) {
            $this->createDiscussionProject($admin, $spec['title'], $spec['description'], $spec['experts']);
        }

        return 0;
    }

    /**
     * Create a demo project owned by $admin and attach the named contributing
     * experts (looked up from the seeded catalog) so the discussion pipeline has
     * candidates to work with.
     *
     * @param  string[]  $expertNames
     */
    private function createDiscussionProject(User $admin, string $title, string $description, array $expertNames): void
    {
        $project = new Project;
        $project->user_id = $admin->id;
        $project->title = $title;
        $project->description = $description;
        $project->save();
        $project->users()->syncWithoutDetaching($admin->id);

        $experts = Expert::whereIn('name', $expertNames)->get();
        foreach ($experts as $expert) {
            $project->addContributingExpert($expert);
        }

        $project->addMessage(view('components.projects.welcome-message', ['project' => $project])->render());

        $this->info("  • {$title} — {$experts->count()} Experten");
    }
}
