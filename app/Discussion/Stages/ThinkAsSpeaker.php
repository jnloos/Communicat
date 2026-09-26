<?php

namespace App\Discussion\Stages;

use App\Discussion\ParseFailure;
use App\Discussion\Schemas\ThoughtSchema;
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

        $answers = $this->thinking->ask($payload, [$speaker], 'prompts.think.speaker', ThoughtSchema::class);

        $thought = trim($answers[$speaker->id]['thought'] ?? '');

        // The schema declares the field required, so an empty one means the
        // provider did not honour the contract -- not that the model had nothing
        // to say. Either way the turn has no short-term memory to write.
        if ($thought === '') {
            throw new ParseFailure(
                "ThinkAsSpeaker: no 'thought' in the answer of expert {$speaker->id}."
            );
        }

        $this->thinking->remember($payload->project, $speaker, $thought);
        $payload->addThought(new Thought($speaker->id, $thought));

        return $next($payload);
    }
}
