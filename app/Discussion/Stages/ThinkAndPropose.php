<?php

namespace App\Discussion\Stages;

use App\Discussion\ParseFailure;
use App\Discussion\Schemas\ThoughtWithDraftSchema;
use App\Discussion\Support\Thinking;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Thought;
use App\Events\PipelineStageChanged;
use Closure;

/** Everyone thinks and drafts a contribution. Belongs before SelectSpeaker(Judge). */
class ThinkAndPropose
{
    public function __construct(private readonly Thinking $thinking) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $experts = $payload->project->contributingExperts();

        PipelineStageChanged::announce($payload->project->id, 'thinking', $experts->all());

        $answers = $this->thinking->ask($payload, $experts, 'prompts.think.propose', ThoughtWithDraftSchema::class);

        foreach ($experts as $expert) {
            $answer = $answers[$expert->id];
            $thought = trim($answer['thought'] ?? '');

            if ($thought === '') {
                throw new ParseFailure(
                    "ThinkAndPropose: no 'thought' in the answer of expert {$expert->id}."
                );
            }

            // A missing draft is left null: JudgeSelector scores what it was
            // given and records who drafted nothing in its fallbacks.
            $draft = trim($answer['draft'] ?? '');

            $this->thinking->remember($payload->project, $expert, $thought);
            $payload->addThought(new Thought($expert->id, $thought, proposal: $draft === '' ? null : $draft));
        }

        return $next($payload);
    }
}
