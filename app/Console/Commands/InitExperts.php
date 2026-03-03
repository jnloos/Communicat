<?php

namespace App\Console\Commands;

use App\Models\Expert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Storage;

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
    protected $description = 'Initialize the experts table with data from a JSON file';

    /**
     * Execute the console command.
     */
    public function handle(): int {
        $file = $this->option('file');

        if (!file_exists($file)) {
            $this->error("File $file not found.");
            return 1;
        }

        $json = file_get_contents($file);
        $experts = json_decode($json, true);

        if ($experts === null) {
            $this->error("Failed to decode JSON.");
            return 1;
        }

        foreach ($experts as $expert) {
            $model = new Expert();

            $model->name = $expert['name'];
            $model->description = $expert['description'];
            $model->job = $expert['job'];
            $model->prompt = $expert['prompt'];

            $url = asset($expert['avatar_url']);
            $model->avatar_url = $url;

            $model->save();
        }

        $this->info("Experts have been successfully initialized.");
        return 0;
    }
}
