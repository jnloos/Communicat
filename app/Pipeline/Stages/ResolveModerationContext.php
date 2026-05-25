<?php

namespace App\Pipeline\Stages;

use App\Models\Message;
use App\Pipeline\TurnContext;
use App\Services\ModeratorService;
use Closure;

/**
 * First stage: gather all advisory signals the moderator needs — trigger note,
 * a pending (unanswered) user message, the deterministically detected open
 * adjacency pair, and the current agenda phase.
 */
class ResolveModerationContext
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

        $ctx->moderationNote    = $note;
        $ctx->moderationContext = [
            'open_adjacency_pair' => $this->detectOpenAdjacencyPair($ctx),
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
     * @return array{addressee: string, pair_type: string, from: string, source: string}|null
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
                'addressee' => $expert->name,
                'pair_type' => $hasQuestion ? Message::PAIR_FRAGE_ANTWORT : Message::PAIR_ANSPRACHE_REAKTION,
                'from'      => $senderName,
                'source'    => $source,
            ];
        }

        return null;
    }
}
