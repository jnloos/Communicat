<?php

namespace App\Services\PromptingPipeline\Stages;

use App\Models\Message;
use App\Services\PromptingPipeline\Data\TurnContext;
use App\Services\PromptingPipeline\Support\ModeratorService;
use Closure;

/**
 * First stage: gather all advisory signals the moderator needs — trigger note,
 * a pending (unanswered) user message, the deterministically detected open
 * adjacency pair, and the current agenda phase.
 */
class ResolveContext
{
    public function handle(TurnContext $ctx, Closure $next)
    {
        $moderator = app(ModeratorService::class, ['project' => $ctx->project]);

        $ctx->latestMessage = $ctx->project->latestParticipantMessage();

        $note = $moderator->checkTriggers();

        // If the user just spoke and no expert has answered yet, the next turn
        // MUST address the user directly — this outranks other triggers.
        $pendingUser = $this->pendingUserMessage($ctx);
        $pendingExcerpt = null;
        if ($pendingUser !== null) {
            $pendingExcerpt = mb_substr(trim($pendingUser->content), 0, 240);
            $userNote = "Die zuletzt eingegangene Nachricht stammt vom Nutzer und ist noch unbeantwortet: \""
                . $pendingExcerpt
                . "\". Der nächste Experten-Beitrag MUSS direkt darauf eingehen — als Antwort auf die Nutzeräußerung, nicht als Fortsetzung der vorherigen Experten-Diskussion.";
            $note = $note === '' ? $userNote : $userNote . ' ' . $note;
        }

        $openPair = $this->detectOpenAdjacencyPair($ctx);

        // Record the detected addressee on the addressing message so the chat can
        // draw a "speaks to" arrow (user→expert and expert→expert). The
        // expert→user hand-back is recorded separately as next_speaker_user_id
        // by PersistMessage. Don't clobber an existing hand-off.
        if ($openPair !== null
            && $ctx->latestMessage !== null
            && empty($ctx->latestMessage->next_speaker_expert_id)
            && empty($ctx->latestMessage->next_speaker_user_id)
        ) {
            $ctx->latestMessage->next_speaker_expert_id = $openPair['addressee_id'];
            $ctx->latestMessage->saveQuietly();
        }

        $ctx->moderationNote    = $note;
        $ctx->moderationContext = [
            'open_adjacency_pair' => $openPair,
            'agenda_phase'        => $moderator->agendaPhase(),
            'pending_user'        => $pendingExcerpt,
        ];

        return $next($ctx);
    }

    /**
     * The latest message if it was sent by a user (no expert has spoken since).
     */
    protected function pendingUserMessage(TurnContext $ctx): ?Message
    {
        $latest = $ctx->latestMessage;

        if ($latest === null || $latest->user_id === null) {
            return null;
        }

        return $latest;
    }

    /**
     * Detect an open adjacency pair from the latest message (advisory only).
     *
     * @return array{addressee_id: int, addressee: string, pair_type: string, from: string, source: string}|null
     */
    protected function detectOpenAdjacencyPair(TurnContext $ctx): ?array
    {
        $latest = $ctx->latestMessage;
        if ($latest === null) {
            return null;
        }

        $contributors = $ctx->project->contributingExperts();

        if (empty($latest->content)) {
            return null;
        }

        $hasQuestion    = str_contains($latest->content, '?');
        $senderExpertId = $latest->expert_id;
        $senderName     = $latest->expert?->name ?? $latest->user?->name ?? 'Nutzer';

        foreach ($contributors as $expert) {
            if ($senderExpertId !== null && $expert->id === $senderExpertId) {
                continue; // skip self-mentions
            }
            if (!preg_match('/\b' . preg_quote($expert->name, '/') . '\b/iu', $latest->content)) {
                continue;
            }
            // Expert-to-expert needs a question mark; a plain user mention suffices.
            if ($senderExpertId !== null && !$hasQuestion) {
                continue;
            }

            $source = match (true) {
                $senderExpertId !== null => 'expert_question',
                $hasQuestion             => 'user_question',
                default                  => 'user_mention',
            };

            return [
                'addressee_id' => $expert->id,
                'addressee'    => $expert->name, // display only; logic uses addressee_id
                'pair_type'    => $hasQuestion ? Message::PAIR_FRAGE_ANTWORT : Message::PAIR_ANSPRACHE_REAKTION,
                'from'         => $senderName,
                'source'       => $source,
            ];
        }

        return null;
    }
}
