<?php

namespace App\Console\Commands;

use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Services\PersonaAttributes\AttributesByName;
use App\Services\PersonaAttributes\SkippedAttributes;
use App\Services\Tags\TagsByName;
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
    public function handle(TagsByName $tags, AttributesByName $attributes): int
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
        $skipped = [];
        $skippedAttributes = new SkippedAttributes;

        foreach ($experts as $expert) {
            $avatarUrl = ! empty($expert['avatar_url']) ? asset($expert['avatar_url']) : null;

            // One entry, one persona per role label it carries: the same knowledge
            // profile under the label it wears in the status condition and under the
            // one it wears in the equal condition. They are written from a single
            // source on purpose -- kept as two entries they could drift, and a run
            // would then differ in more than the title, which is the one thing the
            // study manipulates. A profile that wears the same label in both
            // conditions -- a rank-and-file seat in a graded scenario -- is one
            // persona, not two, which is why the labels are deduplicated.
            foreach (array_unique($expert['roles']) as $role) {
                $model = Expert::updateOrCreate(
                    ['name' => $expert['name'], 'role' => $role],
                    [
                        'description' => $expert['description'],
                        'avatar_url' => $avatarUrl,
                    ],
                );

                $model->wasRecentlyCreated ? $created++ : $updated++;

                // Optional {"category": "value"}; only existing persona categories
                // and values are used, and a value already set stays.
                $skipped = [...$skipped, ...$tags->assign($model, $expert['tags'] ?? [], overwrite: false)];

                // Optional {"attribute": "value"}, the same rule: only existing
                // definitions are used, and a value already set stays.
                $skippedAttributes->merge($attributes->assign($model, $expert['attributes'] ?? [], overwrite: false));
            }
        }

        $this->info("Experts initialized: {$created} created, {$updated} updated.");

        foreach (array_unique($skipped) as $pair) {
            $this->warn("Tag does not exist and was skipped: {$pair}");
        }

        foreach (array_unique($skippedAttributes->unknown) as $pair) {
            $this->warn("Attribute does not exist and was skipped: {$pair}");
        }

        foreach (array_unique($skippedAttributes->tooLong) as $pair) {
            $this->warn('Attribute value too long (over '.PersonaAttribute::VALUE_MAX." characters) and was skipped: {$pair}");
        }

        foreach (array_unique($skippedAttributes->lineBreak) as $pair) {
            $this->warn("Attribute value contains a line break and was skipped: {$pair}");
        }

        return 0;
    }
}
