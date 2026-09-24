<?php

namespace App\Discussion\Stages;

use App\Discussion\ParseFailure;
use App\Discussion\Support\Thinking;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Thought;
use App\Events\PipelineStageChanged;
use Closure;

/** Everyone thinks and drafts a contribution. Belongs before SelectSpeaker(Judge). */
class ThinkAndPropose
{
    public const MARKER_DRAFT = 'ENTWURF:';

    public function __construct(private readonly Thinking $thinking) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $experts = $payload->project->contributingExperts();

        PipelineStageChanged::announce($payload->project->id, 'thinking', $experts->all());

        $answers = $this->thinking->ask($payload, $experts, 'prompts.think.propose', [
            'marker_thought' => Thinking::MARKER_THOUGHT,
            'marker_draft' => self::MARKER_DRAFT,
        ]);

        foreach ($experts as $expert) {
            $answer = $answers[$expert->id];
            $thought = Thinking::section($answer, Thinking::MARKER_THOUGHT, self::MARKER_DRAFT);

            if ($thought === '') {
                throw new ParseFailure(
                    "ThinkAndPropose: marker '".Thinking::MARKER_THOUGHT."' missing in the answer of expert {$expert->id}."
                );
            }

            $draft = Thinking::section($answer, self::MARKER_DRAFT);

            $this->thinking->remember($payload->project, $expert, $thought);
            $payload->addThought(new Thought($expert->id, $thought, proposal: $draft === '' ? null : $draft));
        }

        return $next($payload);
    }
}
