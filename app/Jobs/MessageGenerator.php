<?php
namespace App\Jobs;

use App\Facades\Specification;
use App\Jobs\Dependencies\ProjectJob;
use App\Models\Project;
use App\Services\Dependencies\PromptingStrategy;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Log;

class MessageGenerator extends ProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(int $projectId) {
        $this->setProject($projectId);
    }

    public function handle(): void {
        $this->withProjectLock(function (Project $project) {
            try {
                /** @var class-string<PromptingStrategy> $strategy */
                $strategy = $project->prompting_strategy::forProject($project);
                $strategy->genNextMessage();
            }
            catch (Exception $e) {
                Log::error(sprintf("%s: %s", $e->getMessage(), $e->getTraceAsString()));
            }
        });
    }
}
