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

        $this->comment('Creating demo discussion projects...');

        $projects = [
            [
                'title'       => 'Tempolimit auf Autobahnen',
                'description' => 'Sollte Deutschland ein generelles Tempolimit von 130 km/h auf Autobahnen einführen? Diskutiert konkret das CO₂-Einsparpotenzial, Unfall- und Todeszahlen, die Folgen für Pendler und Logistik sowie das Freiheitsargument — und ringt um eine gemeinsame, begründete Empfehlung.',
                'experts'     => ['Lisa Graf', 'David Kaufmann', 'Tim Hofmann', 'Paul Neumann'],
            ],
            [
                'title'       => 'KI-Werkzeuge an Schulen',
                'description' => 'Sollen Schülerinnen und Schüler KI-Werkzeuge wie ChatGPT im Unterricht und bei Hausaufgaben nutzen dürfen? Klärt konkret: Welche Aufgaben bleiben bewusst KI-frei, wie wird Leistung fair bewertet, welche Datenschutzregeln gelten für Minderjährige, und wie verändert sich die Rolle der Lehrkraft?',
                'experts'     => ['Stefan Maier', 'Nina Keller', 'Katharina Wolf', 'Paul Neumann'],
            ],
            [
                'title'       => 'EU-Chatkontrolle',
                'description' => 'Sollen Messenger-Dienste verpflichtet werden, auch Ende-zu-Ende-verschlüsselte Nachrichten automatisiert auf Missbrauchsdarstellungen zu durchsuchen (Client-Side-Scanning)? Wägt Kinderschutz, IT-Sicherheit, Grundrechte und technische Umsetzbarkeit gegeneinander ab.',
                'experts'     => ['Sarah Vogel', 'Katharina Wolf', 'Tim Hofmann', 'Paul Neumann'],
            ],
            [
                'title'       => 'Rückkehr ins Büro oder Remote-First',
                'description' => 'Soll unser Unternehmen eine verbindliche Büro-Anwesenheit von drei Tagen pro Woche einführen oder remote-first bleiben? Diskutiert konkret Produktivität, Teamzusammenhalt, Bürokosten, Fairness gegenüber Eltern und Pendlern sowie die Wirkung auf das Recruiting.',
                'experts'     => ['Marie Hoffmann', 'Clara Schmidt', 'David Kaufmann', 'Paul Neumann'],
            ],
            [
                'title'       => 'Vier-Tage-Woche bei vollem Lohn',
                'description' => 'Sollte die Vier-Tage-Woche bei vollem Lohnausgleich (32 Stunden, 100 % Gehalt) politisch gefördert werden? Klärt konkret die Auswirkungen auf Produktivität, Lohnkosten, Fachkräftemangel, Gesundheit und internationale Wettbewerbsfähigkeit.',
                'experts'     => ['David Kaufmann', 'Lisa Graf', 'Marie Hoffmann', 'Paul Neumann'],
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
        $project = new Project();
        $project->user_id    = $admin->id;
        $project->title       = $title;
        $project->description = $description;
        $project->settings    = ['summary_frequency' => 10];
        $project->save();
        $project->users()->syncWithoutDetaching($admin->id);

        $experts = Expert::whereIn('name', $expertNames)->get();
        foreach ($experts as $expert) {
            $project->addContributingExpert($expert);
        }

        $this->info("  • {$title} — {$experts->count()} Experten");
    }
}
