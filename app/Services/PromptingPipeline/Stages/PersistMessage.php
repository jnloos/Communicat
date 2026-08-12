<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Models\Expert;
use App\Models\Message;
use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\OpenQuestionMemory;
use App\Services\PromptingPipeline\Support\SpeakTrailerResolver;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Persist the winner's turn with its adjacency metadata.
 *
 * Two sources claim to know who holds the floor next, and in practice they
 * disagree: the moderator's `handBackToUser` intent, and what the agent's
 * visible text actually does. This stage reconciles them under one rule:
 *
 *     THE VISIBLE TEXT WINS.
 *
 * The reader only ever sees the prose, so metadata contradicting it is simply
 * wrong. Concretely, a turn ending in a question to a named expert is an expert
 * pair even when the moderator wanted to hand back — previously the expert token
 * was discarded, the round stopped, and the addressed expert never answered
 * (6 of 18 hand-offs in the logged production run did exactly this).
 *
 *   text addresses an expert → expert pair, round continues
 *   text asks the user       → user pair, round stops and waits
 *   text addresses nobody    → plenum; stops only if the moderator asked to
 */
class PersistMessage
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        $message = $ctx->project->addMessage($ctx->speakResult['content'], $ctx->winner);

        $wantsHandoff = $ctx->directive?->handBackToUser ?? false;
        $speakToken = $ctx->speakResult['adjacency_partner_token'] ?? null;
        $addressedExpert = $ctx->project->contributorByPromptId($speakToken);

        // A hand-off only lands when the text actually poses a question to the
        // human. "Over to you" without a question is a dead end the user cannot
        // act on, so the round keeps running instead.
        $handsOff = $wantsHandoff
            && $addressedExpert === null
            && ($ctx->speakResult['ends_with_question'] ?? false);

        if ($handsOff) {
            // Resolve the concrete user (pending message author, else owner) so
            // only they are prompted.
            $partner = $ctx->project->handoffUser($ctx->latestMessage);
            $message->adjacency_pair_type = Message::PAIR_ABSCHLUSS_NUTZER;
        } else {
            $partner = $addressedExpert;
            $message->adjacency_pair_type = $ctx->speakResult['adjacency_pair_type'] ?? Message::PAIR_BEITRAG_DISKUSSION;
        }

        if ($partner !== null) {
            $message->adjacencyPartner()->associate($partner);
        }

        // The incoming half of the pair. Deterministic: the winner was the
        // addressee of an open pair, so this turn closes it — regardless of
        // whether the text also opens a new one with somebody else. Both ends
        // are stored, so "answers Sophie, asks Lena" stays readable.
        $message->answers_message_id = $ctx->openPair?->id;

        $message->job_log_id = $ctx->jobLogId;
        $message->save();

        $this->rollOpenQuestions($ctx, $message, $addressedExpert);
        $this->logReconciliation($ctx, $message, $wantsHandoff, $handsOff, $addressedExpert !== null);

        $ctx->message = $message;
        $ctx->stop = $handsOff;
        $ctx->reason = $handsOff ? 'user_addressed' : null;

        return $next($ctx);
    }

    /**
     * Move open questions between the two sides of the pair.
     *
     * Only a turn that actually closed a pair clears the speaker's obligations —
     * merely taking the floor is not an answer. If they in turn asked a named
     * expert a question, write it into *that* expert's memory, so the obligation
     * to reply lives with the person who owes the reply, not only with the asker.
     */
    protected function rollOpenQuestions(TurnContext $ctx, Message $message, ?object $addressedExpert): void
    {
        $questions = app(OpenQuestionMemory::class);

        if ($message->answers_message_id !== null) {
            $questions->clear($ctx->project, $ctx->winner);
        }

        if ($addressedExpert instanceof Expert
            && in_array($message->adjacency_pair_type, [Message::PAIR_FRAGE_ANTWORT, Message::PAIR_ANSPRACHE_REAKTION], true)
        ) {
            $questions->record($ctx->project, $addressedExpert, $ctx->winner->name, $message->content);
        }
    }

    /**
     * Record how the addressee was determined and whether the moderator's intent
     * had to be overruled. Replaces the throwaway file-based debugging used to
     * diagnose the original defect: the same signals now go through the app log
     * alongside the JobLog trail, so the rate stays countable after the fact.
     */
    protected function logReconciliation(
        TurnContext $ctx,
        Message $message,
        bool $wantsHandoff,
        bool $handsOff,
        bool $addressedExpert,
    ): void {
        $source = $ctx->speakResult['resolver_source'] ?? SpeakTrailerResolver::SOURCE_NONE;
        $overruled = $wantsHandoff && ! $handsOff;

        if (! $overruled && $source !== SpeakTrailerResolver::SOURCE_PROSE) {
            return;
        }

        Log::info('SPEAK addressing reconciled', [
            'project_id' => $ctx->project->id,
            'message_id' => $message->id,
            'job_log_id' => $ctx->jobLogId,
            'resolver_source' => $source,
            'moderator_wanted_handoff' => $wantsHandoff,
            'handed_off' => $handsOff,
            // Moderator asked to pause, but the text kept the floor with the
            // experts — the failure mode that stalled addressed experts.
            'handoff_overruled' => $overruled,
            'addressed_expert' => $addressedExpert,
            'pair_type' => $message->adjacency_pair_type,
        ]);
    }
}
