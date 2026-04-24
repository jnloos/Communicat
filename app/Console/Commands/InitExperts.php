<?php

namespace App\Console\Commands;

use App\Models\Expert;
use App\Models\Tag;
use Illuminate\Console\Command;

class InitExperts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'init:experts {--file=database/experts.json}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize or update the experts table with data from a JSON file (idempotent).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $file = $this->option('file');

        if (! file_exists($file)) {
            $this->error("File $file not found.");

            return 1;
        }

        $json = file_get_contents($file);
        $experts = json_decode($json, true);

        if ($experts === null) {
            $this->error('Failed to decode JSON.');

            return 1;
        }

        $created = 0;
        $updated = 0;

        foreach ($experts as $expert) {
            $avatarUrl = ! empty($expert['avatar_url']) ? asset($expert['avatar_url']) : null;

            $model = Expert::updateOrCreate(
                ['name' => $expert['name']],
                [
                    'description' => $expert['description'],
                    'job' => $expert['job'],
                    'prompt' => $expert['prompt'],
                    'avatar_url' => $avatarUrl,
                ]
            );

            $tagIds = collect($expert['tags'] ?? [])
                ->filter(fn ($name) => is_string($name) && trim($name) !== '')
                ->map(fn (string $name) => Tag::firstOrCreateByName($name)->id)
                ->unique()
                ->values()
                ->all();

            $model->tags()->sync($tagIds);

            $model->wasRecentlyCreated ? $created++ : $updated++;
        }

        $this->info("Experts initialized: {$created} created, {$updated} updated.");

        return 0;
    }
}
