<?php

namespace App\Discussion\Stages;

use App\Discussion\ParseFailure;
use App\Discussion\Schemas\ThoughtWithPrioritySchema;
use App\Discussion\Support\Thinking;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Thought;
use App\Events\PipelineStageChanged;
use Closure;

/** Everyone thinks and bids for the floor. Belongs before SelectSpeaker(HighestBid). */
class ThinkAndPrioritize
{
    public function __construct(private readonly Thinking $thinking) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $experts = $payload->project->contributingExperts();

        PipelineStageChanged::announce($payload->project->id, 'thinking', $experts->all());

        $answers = $this->thinking->ask($payload, $experts, 'prompts.think.prioritize', ThoughtWithPrioritySchema::class, [
            'lowest' => ThoughtWithPrioritySchema::LOWEST,
            'highest' => ThoughtWithPrioritySchema::HIGHEST,
        ]);

        foreach ($experts as $expert) {
            $answer = $answers[$expert->id];
            $thought = trim($answer['thought'] ?? '');

            if ($thought === '') {
                throw new ParseFailure(
                    "ThinkAndPrioritize: no 'thought' in the answer of expert {$expert->id}."
                );
            }

            $this->thinking->remember($payload->project, $expert, $thought);
            $payload->addThought(new Thought($expert->id, $thought, priority: $this->priority($answer)));
        }

        return $next($payload);
    }

    /**
     * null when the bid is missing or out of range. The stage substitutes
     * nothing: HighestBidSelector owns the fallback and records it in the
     * signals, so the study can tell a real bid from a replaced one. The schema
     * constrains the range, but a provider that answers through a tool is asked,
     * not forced, so the check stays.
     *
     * @param  array<string, mixed>  $answer
     */
    private function priority(array $answer): ?int
    {
        $raw = $answer['priority'] ?? null;

        if (! is_int($raw) && ! (is_string($raw) && ctype_digit($raw))) {
            return null;
        }

        $value = (int) $raw;

        return $value >= ThoughtWithPrioritySchema::LOWEST && $value <= ThoughtWithPrioritySchema::HIGHEST
            ? $value
            : null;
    }
}
