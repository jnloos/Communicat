<?php

namespace App\Discussion\Stages;

use App\Discussion\ParseFailure;
use App\Discussion\Support\Thinking;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Thought;
use App\Events\PipelineStageChanged;
use Closure;

/** Only the already selected speaker thinks. Needs SelectSpeaker before it. */
class ThinkAsSpeaker
{
    public function __construct(private readonly Thinking $thinking) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $speaker = $payload->selection()->speaker;

        PipelineStageChanged::announce($payload->project->id, 'thinking', [$speaker]);

        $answers = $this->thinking->ask($payload, [$speaker], 'prompts.think.speaker', [
            'marker_thought' => Thinking::MARKER_THOUGHT,
        ]);

        $thought = Thinking::section($answers[$speaker->id], Thinking::MARKER_THOUGHT);

        if ($thought === '') {
            throw new ParseFailure(
                "ThinkAsSpeaker: marker '".Thinking::MARKER_THOUGHT."' missing in the answer of expert {$speaker->id}."
            );
        }

        $this->thinking->remember($payload->project, $speaker, $thought);
        $payload->addThought(new Thought($speaker->id, $thought));

        return $next($payload);
    }
}
