<?php

namespace App\Console\Commands;

use App\Facades\Specification;
use App\Jobs\MessageGenerator;
use App\Models\Project;
use Illuminate\Console\Command;

class GenMessage extends Command
{
    protected $signature = 'spec:gen-message {projectId? : The ID of the project}';

    protected $description = 'Test the MessageGenerator job in the console';

    public function handle(): int
    {
        $projectId = $this->argument('projectId');

        // If no project ID provided, show available projects
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

        // Verify project exists
        $project = Project::find($projectId);
        if (!$project) {
            $this->error("Project with ID {$projectId} not found.");
            return 1;
        }

        $this->info("Testing MessageGenerator for project: {$project->title}");
        $this->line('');

        try {
            // Execute job synchronously
            Specification::forProject($project)->generateNextMessage();

            $this->info('✓ Job executed successfully!');
            $this->line('');

            // Show the latest message
            $latestMessage = $project->messages()->latest()->first();
            if ($latestMessage) {
                $this->info('Latest message created:');
                $this->line($latestMessage->content);
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('✗ Job failed with error:');
            $this->error($e->getMessage());
            $this->line('');
            $this->line('Stack trace:');
            $this->line($e->getTraceAsString());

            return 1;
        }
    }
}
