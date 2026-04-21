<?php

namespace App\Services;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;

class PipelineModerator
{
    public function __construct(
        protected Project $project,
        protected ?int $jobLogId = null,
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
    /**
     * @return array{stop: bool, reason: ?string, next_speaker: ?string}
     */
    public function run(): array
    {
        $client     = app(OpenAIClient::class);
        $prompts    = app(PromptBuilder::class);
        $moderator  = new ModeratorService($this->project, $client, $prompts);
        $agent      = new AgentService($this->project, $client, $prompts);
        $summarizer = new Summarizer($this->project, $client, $prompts);

        $modNote = $moderator->checkTriggers();

        // Surface any deterministically detected direct addressee as a hint
        // to the moderator. The moderator still owns the final routing decision.
        $openPair   = $this->detectOpenAdjacencyPair();
        $directHint = $this->detectDirectAddressHint();
        $route      = $moderator->route($modNote, $directHint);

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

            // Build prompts on the main thread (closures must only capture primitives
            // so they survive serialization in Laravel's process concurrency driver).
            $promptMap = $selected->mapWithKeys(
                fn(Expert $e) => [$e->name => $agent->thinkAndPrioritizePrompt($e)]
            )->all();

            $responses = $client->sendManySlow($promptMap);

            // Persist per-expert GEDÄCHTNIS updates back on the main thread.
            $merged       = [];
            $expertByName = $selected->keyBy('name');
            foreach ($responses as $name => $response) {
                $merged[$name] = $agent->consumeThinkAndPrioritize($expertByName[$name], $response);
            }

            $winnerName  = $moderator->selectWinner($merged, $openPair);
            $winner      = Expert::findByName($winnerName);
            $thinkOutput = $merged[$winnerName];
            $result      = $agent->speak($winner, $thinkOutput, $modNote);
        }

        // Store the message
        $message = $this->project->addMessage($result['content'], $winner);
        $message->adjacency_pair_type = $result['adjacency_pair_type'];
        $message->next_speaker        = $result['next_speaker'];
        $message->job_log_id          = $this->jobLogId;
        $message->save();

        $moderator->updateState($winner, $result['adjacency_pair_type']);
        $summarizer->maybeRun();

        $stop = $this->isUserAddressed($result['next_speaker'] ?? '');

        return [
            'stop'         => $stop,
            'reason'       => $stop ? 'user_addressed' : null,
            'next_speaker' => $result['next_speaker'] ?? null,
        ];
    }

    protected function isUserAddressed(string $nextSpeaker): bool
    {
        $normalized = mb_strtolower(trim($nextSpeaker));
        return in_array($normalized, ['nutzer', 'user'], true);
    }

    /**
     * Build a hint string for the moderator about deterministically detected
     * direct addressees in the latest message. Derived from the structured
     * open-adjacency-pair detection so both signals stay consistent.
     */
    protected function detectDirectAddressHint(): ?string
    {
        $pair = $this->detectOpenAdjacencyPair();
        if ($pair === null) {
            return null;
        }

        return match ($pair['source'] ?? '') {
            'next_speaker'     => "Der vorherige Sprecher ({$pair['from']}) hat explizit an {$pair['addressee']} übergeben (NEXT_SPEAKER).",
            'expert_question'  => "Im letzten Turn hat {$pair['from']} eine Frage direkt an {$pair['addressee']} gestellt.",
            'user_question'    => "Die letzte Nutzernachricht richtet eine Frage an {$pair['addressee']}.",
            'user_mention'     => "Die letzte Nutzernachricht erwähnt {$pair['addressee']} namentlich.",
            default            => "Offenes Adjacency Pair adressiert {$pair['addressee']}.",
        };
    }

    /**
     * Detect an open adjacency pair from the latest message.
     *
     * Detection priority:
     *   1. Prior expert turn's NEXT_SPEAKER pointing at a contributing expert.
     *   2. Latest expert message containing a question plus a contributor's name
     *      (e.g. "Was denkst du, Jana?") — excludes the sender.
     *   3. Latest user message containing a question plus a contributor's name.
     *   4. Latest user message mentioning a contributor by name (no question mark).
     *
     * @return array{addressee: string, pair_type: string, from: string, source: string}|null
     */
    protected function detectOpenAdjacencyPair(): ?array
    {
        $latest = $this->project->messages()
            ->where(fn($q) => $q->whereNotNull('expert_id')->orWhereNotNull('user_id'))
            ->latest('id')
            ->first();

        if ($latest === null) {
            return null;
        }

        $contributors = $this->project->contributingExperts();

        // 1. Explicit NEXT_SPEAKER handoff from prior expert turn
        if ($latest->expert_id !== null && !empty($latest->next_speaker)) {
            $target = mb_strtolower(trim($latest->next_speaker));
            if (!in_array($target, ['nutzer', 'user'], true)) {
                $match = $contributors->first(fn(Expert $e) => mb_strtolower($e->name) === $target);
                if ($match !== null) {
                    return [
                        'addressee' => $match->name,
                        'pair_type' => (string) ($latest->adjacency_pair_type ?: 'Frage→Antwort'),
                        'from'      => (string) ($latest->expert?->name ?? ''),
                        'source'    => 'next_speaker',
                    ];
                }
            }
        }

        if (empty($latest->content)) {
            return null;
        }

        $hasQuestion = str_contains($latest->content, '?');
        $senderExpertId = $latest->expert_id;
        $senderName = $latest->expert?->name ?? $latest->user?->name ?? 'Nutzer';

        // 2./3. Name mention in latest message content
        foreach ($contributors as $expert) {
            // Skip self-mentions by the same expert
            if ($senderExpertId !== null && $expert->id === $senderExpertId) {
                continue;
            }
            if (!preg_match('/\b' . preg_quote($expert->name, '/') . '\b/iu', $latest->content)) {
                continue;
            }

            // For expert-to-expert: require a question mark to avoid false positives
            // from neutral references. For user messages: a plain mention is enough.
            if ($senderExpertId !== null && !$hasQuestion) {
                continue;
            }

            $source = match (true) {
                $senderExpertId !== null => 'expert_question',
                $hasQuestion             => 'user_question',
                default                  => 'user_mention',
            };

            return [
                'addressee' => $expert->name,
                'pair_type' => $hasQuestion ? 'Frage→Antwort' : 'Ansprache→Reaktion',
                'from'      => $senderName,
                'source'    => $source,
            ];
        }

        return null;
    }
}
