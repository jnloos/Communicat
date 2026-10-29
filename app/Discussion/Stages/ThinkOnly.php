<?php

namespace App\Discussion\Stages;

use App\Discussion\ParseFailure;
use App\Discussion\Schemas\ThoughtSchema;
use App\Discussion\Support\Thinking;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Thought;
use App\Events\PipelineStageChanged;
use Closure;

/**
 * Everyone thinks, nobody bids: the thinking half of ThinkAndPrioritize. Every
 * contributing expert writes its rolling thought in one parallel round and the
 * thought carries no priority (null), because somebody else gives the bid
 * (JevThinkScorePipeline: JevBids). Belongs before the stage that does.
 */
class ThinkOnly
{
    public function __construct(private readonly Thinking $thinking) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $experts = $payload->project->contributingExperts();

        PipelineStageChanged::announce($payload->project->id, 'thinking', $experts->all());

        $answers = $this->thinking->ask($payload, $experts, 'prompts.think.thought', ThoughtSchema::class);

        foreach ($experts as $expert) {
            $thought = $answers[$expert->id]->field('thought');

            if ($thought === '') {
                throw new ParseFailure("ThinkOnly: no 'thought' in the answer of expert {$expert->id}.");
            }

            $this->thinking->remember($payload->project, $expert, $thought);
            $payload->addThought(new Thought($expert->id, $thought));
        }

        return $next($payload);
    }
}
