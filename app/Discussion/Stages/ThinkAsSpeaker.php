<?php

namespace App\Discussion\Stages;

use App\Discussion\Support\Thinking;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Thought;
use App\Events\PipelineStageChanged;
use App\Llm\LlmException;
use Closure;

/** Only the already selected speaker thinks. Needs SelectSpeaker before it. */
class ThinkAsSpeaker
{
    public const MARKER_THOUGHT = 'GEDANKE:';

    public function __construct(private readonly Thinking $thinking) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $speaker = $payload->selection()->speaker;

        PipelineStageChanged::announce($payload->project->id, 'thinking', [$speaker]);

        $answers = $this->thinking->ask($payload, [$speaker], 'prompts.think.speaker', [
            'marker_thought' => self::MARKER_THOUGHT,
        ]);

        $thought = Thinking::section($answers[$speaker->id], self::MARKER_THOUGHT);

        if ($thought === '') {
            throw new LlmException(
                "ThinkAsSpeaker: marker '".self::MARKER_THOUGHT."' missing in the answer of expert {$speaker->id}.",
                LlmException::KIND_PARSE,
            );
        }

        $this->thinking->remember($payload->project, $speaker, $thought);
        $payload->addThought(new Thought($speaker->id, $thought));

        return $next($payload);
    }
}
