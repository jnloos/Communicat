<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Onboarding\DemoDiscussionSeeder;
use Illuminate\Console\Command;
use Throwable;

class SeedDemoDiscussion extends Command
{
    protected $signature = 'discussion:seed-demo {--owner= : E-Mail des Besitzer-Nutzers (Standard: erster Admin/Nutzer)}';

    protected $description = 'Legt die geteilte, schreibgeschützte Onboarding-Demo-Diskussion an oder aktualisiert sie (ohne LLM-Anfragen).';

    public function handle(DemoDiscussionSeeder $seeder): int
    {
        $owner = null;
        if ($email = $this->option('owner')) {
            $owner = User::where('email', $email)->first();
            if ($owner === null) {
                $this->error("Kein Nutzer mit E-Mail {$email} gefunden.");

                return self::FAILURE;
            }
        }

        try {
            $project = $seeder->seed($owner);
        } catch (Throwable $e) {
            $this->error('Demo-Seeding fehlgeschlagen: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Demo-Diskussion bereit: #{$project->id} — {$project->title}");

        if (! empty($seeder->missingExperts())) {
            $this->warn('Fehlende Experten (zuerst `php artisan init:experts` ausführen): '.implode(', ', $seeder->missingExperts()));
        }

        return self::SUCCESS;
    }
}
