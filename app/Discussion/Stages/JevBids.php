<?php

namespace App\Discussion\Stages;

use App\Discussion\Jev\JevBidder;
use App\Discussion\Jev\JevClient;
use App\Discussion\Jev\JevOutageGuard;
use App\Discussion\Memory\Memory;
use App\Discussion\Support\ParallelPrompts;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\TurnPayload;
use App\Discussion\Values\BidResolution;
use App\Discussion\Values\JevAgentBid;
use App\Discussion\Values\JevBid;
use App\Discussion\Values\JevDecides;
use App\Discussion\Values\JevDecisionModel;
use App\Discussion\Values\SelectionRecord;
use Closure;

/**
 * Gives the bids of a Jev Score turn. Belongs directly after a think stage and
 * before SelectSpeaker (JevScorePipeline: ThinkOnly).
 *
 * The think stage has written the short-term memory and put every agent's thought
 * in the payload. Per agent, Jev is then asked how urgently THIS agent wants the
 * floor, from that agent's own view (prompts/jev/bid: persona block, topic,
 * participants, History and Long-Term, and the thought it has just written) --
 * one request per agent, asked in parallel like the thoughts. Jev's bid goes into
 * the thought exactly where an agent's own would land, so the selector reads it
 * as it reads any bid. A think stage that did ask for a priority (ThinkAndPrioritize)
 * has it kept in the selection signals (jev_own_bids); after ThinkOnly there is
 * none and jev_own_bids is null for everyone.
 *
 * The bid depends on the team model only through the thought Jev reads.
 *
 * An unreadable or failed Jev bid is a missing bid (null), which the selector
 * handles as it always did (lowest, listed in signals.fallbacks). When every Jev
 * bid of the turn is missing, for enough turns in a row, JevOutageGuard halts the
 * turn.
 */
class JevBids
{
    public function __construct(
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
        private readonly ParallelPrompts $parallel,
        private readonly JevClient $jev,
        private readonly JevOutageGuard $outage,
    ) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $model = JevDecisionModel::forProject($payload->project, JevDecides::Bidder);
        $this->jev->assertConfigured($model);

        $jev = $this->parallel->run($this->requests($payload, $model));
        $agentBids = [];

        foreach ($payload->project->contributingExperts() as $expert) {
            $thought = $payload->thoughtOf($expert);

            if ($thought === null) {
                continue;
            }

            /** @var JevBid $bid */
            $bid = $jev[$expert->id];
            $agentBids[] = new JevAgentBid($expert->promptId, $thought->priority, $bid);

            $payload->addThought($thought->withPriority($bid->level()));
        }

        $payload->addSelectionSignals(SelectionRecord::jevBidSignals($agentBids, $model->bidResolution ?? BidResolution::fromConfig()));
        $this->outage->haltIfDown($payload, $agentBids !== [] && array_filter($agentBids, fn (JevAgentBid $bid) => ! $bid->jev->unreadable()) === []);

        return $next($payload);
    }

    /**
     * One task per agent that has a thought. Only scalars and the (scalar) decision
     * model are captured: the bidder is rebuilt inside the child process, as the
     * agents are.
     *
     * @return array<int, Closure(): JevBid>
     */
    private function requests(TurnPayload $payload, JevDecisionModel $model): array
    {
        $project = $payload->project;
        $memory = $this->memory->viewFor($project);
        $jobLogId = $payload->jobLogId;
        $tasks = [];

        foreach ($project->contributingExperts() as $expert) {
            $thought = $payload->thoughtOf($expert);

            if ($thought === null) {
                continue;
            }

            $state = $this->prompts->render('prompts.jev.bid', [
                'expert' => $expert,
                'project' => $project,
                'memory' => $memory,
                'thought' => $thought->text,
            ] + $this->prompts->participants($project));

            $expertId = $expert->id;

            $tasks[$expertId] = static fn () => app(JevBidder::class)->bid($model, $state, $jobLogId, $expertId);
        }

        return $tasks;
    }
}
