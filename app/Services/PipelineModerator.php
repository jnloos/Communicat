<?php

namespace App\Services;

use App\Models\Expert;
use App\Models\Project;
use Illuminate\Support\Facades\Concurrency;

class PipelineModerator
{
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Run one full pipeline turn:
     *   1. Check moderation triggers
     *   2. Route (PATH A = direct address, PATH B = competitive selection)
     *   3. Think → Speak (PATH A) or ThinkAndPrioritize in parallel → SelectWinner → Speak (PATH B)
     *   4. Persist the message + metadata
     *   5. Update moderator state
     *   6. Maybe compress old messages
     */
    public function run(): void
    {
        $client     = app(OpenAIClient::class);
        $prompts    = app(PromptBuilder::class);
        $moderator  = new ModeratorService($this->project, $client, $prompts);
        $agent      = new AgentService($this->project, $client, $prompts);
        $summarizer = new Summarizer($this->project, $client, $prompts);

        $modNote = $moderator->checkTriggers();
        $route   = $moderator->route($modNote);

        if ($route['path'] === 'A' && !empty($route['addressed_agent'])) {
            // ----------------------------------------------------------------
            // PATH A — single addressed agent
            // ----------------------------------------------------------------
            $winner      = Expert::findByName($route['addressed_agent']);
            $thinkOutput = $agent->think($winner);
            $result      = $agent->speak($winner, $thinkOutput, $modNote);
        } else {
            // ----------------------------------------------------------------
            // PATH B — competitive: all (or selected) agents think+prioritize,
            //          moderator picks the winner
            // ----------------------------------------------------------------
            $selectedNames = $route['selected_agents'] ?? [];

            $selected = !empty($selectedNames)
                ? Expert::findManyByName($selectedNames)
                : $this->project->contributingExperts();

            // Fallback to all contributing experts if selected is empty
            if ($selected->isEmpty()) {
                $selected = $this->project->contributingExperts();
            }

            // Run thinkAndPrioritize in parallel for all selected agents
            $tasks = $selected->map(
                fn(Expert $e) => fn() => [$e->name => $agent->thinkAndPrioritize($e)]
            )->all();

            $tpOutputs = Concurrency::run($tasks);

            // Flatten: array of ['AgentName' => rawString] → one merged map
            $merged = collect($tpOutputs)->collapse()->all();

            $winnerName  = $moderator->selectWinner($merged);
            $winner      = Expert::findByName($winnerName);
            $thinkOutput = $merged[$winnerName];
            $result      = $agent->speak($winner, $thinkOutput, $modNote);
        }

        // Store the message
        $message = $this->project->addMessage($result['content'], $winner);
        $message->adjacency_pair_type = $result['adjacency_pair_type'];
        $message->next_speaker        = $result['next_speaker'];
        $message->save();

        $moderator->updateState($winner, $result['adjacency_pair_type']);
        $summarizer->maybeRun();
    }
}
