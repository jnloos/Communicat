<?php

namespace App\Discussion\Stages;

use App\Discussion\ParseFailure;
use App\Discussion\Support\Thinking;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Thought;
use App\Events\PipelineStageChanged;
use Closure;

/** Everyone thinks and bids for the floor. Belongs before SelectSpeaker(HighestBid). */
class ThinkAndPrioritize
{
    public const MARKER_PRIORITY = 'PRIORITÄT:';

    private const LOWEST = 1;

    private const HIGHEST = 5;

    public function __construct(private readonly Thinking $thinking) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $experts = $payload->project->contributingExperts();

        PipelineStageChanged::announce($payload->project->id, 'thinking', $experts->all());

        $answers = $this->thinking->ask($payload, $experts, 'prompts.think.prioritize', [
            'marker_thought' => Thinking::MARKER_THOUGHT,
            'marker_priority' => self::MARKER_PRIORITY,
            'lowest' => self::LOWEST,
            'highest' => self::HIGHEST,
        ]);

        foreach ($experts as $expert) {
            $answer = $answers[$expert->id];
            $thought = Thinking::section($answer, Thinking::MARKER_THOUGHT, self::MARKER_PRIORITY);

            if ($thought === '') {
                throw new ParseFailure(
                    "ThinkAndPrioritize: marker '".Thinking::MARKER_THOUGHT."' missing in the answer of expert {$expert->id}."
                );
            }

            $this->thinking->remember($payload->project, $expert, $thought);
            $payload->addThought(new Thought($expert->id, $thought, priority: $this->priority($answer)));
        }

        return $next($payload);
    }

    /**
     * null when the bid is missing or out of range. The stage substitutes nothing:
     * HighestBidSelector owns the fallback and records it in the signals, so the
     * study can tell a real bid from a replaced one.
     */
    private function priority(string $answer): ?int
    {
        $raw = trim(Thinking::section($answer, self::MARKER_PRIORITY));

        if (! preg_match('/^\d+/', $raw, $match)) {
            return null;
        }

        $value = (int) $match[0];

        return $value >= self::LOWEST && $value <= self::HIGHEST ? $value : null;
    }
}
