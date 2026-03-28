<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Assistant;
use Illuminate\Console\Command;

class GenMessage extends Command
{
    protected $signature = 'spec:gen-message {projectId? : The ID of the project}';

    protected $description = 'Test the MessageGenerator job synchronously in the console';

    public function handle(): int
    {
        $projectId = $this->argument('projectId');

        if (!$projectId) {
            $projects = Project::all(['id', 'title']);

            if ($projects->isEmpty()) {
                $this->error('No projects found in the database.');
                return 1;
            }

            $this->info('Available projects:');
            foreach ($projects as $project) {
                $this->line("  [{$project->id}] {$project->title}");
            }

            $projectId = $this->ask('Enter the project ID to test');
        }

        $project = Project::find($projectId);
        if (!$project) {
            $this->error("Project with ID {$projectId} not found.");
            return 1;
        }

        $this->info("Running Assistant for project: {$project->title}");

        try {
            Assistant::forProject($project)->genNextMessage();

            $this->info('✓ Done!');

            $latestMessage = $project->messages()->latest()->first();
            if ($latestMessage) {
                $this->info('Latest message:');
                $this->line($latestMessage->content);
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('✗ Failed: ' . $e->getMessage());
            $this->line($e->getTraceAsString());
            return 1;
        }
    }
}
