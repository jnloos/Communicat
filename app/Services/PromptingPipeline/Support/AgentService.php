<?php

namespace App\Services\PromptingPipeline\Support;

use App\Models\Expert;
use App\Models\Message;
use App\Models\Project;
use App\Services\Clients\OpenAIClient;
use App\Services\PromptingPipeline\Data\Directive;
use Illuminate\Support\Facades\Log;

class AgentService
{
    public function __construct(
        protected Project $project,
        protected OpenAIClient $client,
        protected PromptBuilder $prompts,
    ) {}

    /**
     * THINK — runs the single think step for one candidate: updates the expert's
     * memory and states a contribution intent. Persists the GEDÄCHTNIS-UPDATE
     * block and returns the parsed result.
     *
     * @return array{memory: string, beitragsabsicht: string}
     */
    public function think(Expert $expert): array
    {
        $prompt = $this->thinkPrompt($expert);
        $response = $this->client->sendSlow($prompt, "think:{$expert->id}");

        return $this->consumeThink($expert, $response);
    }

    /**
     * Build the THINK prompt for one expert without calling the LLM. Used to
     * batch many THINKs concurrently via OpenAIClient::sendManySlow().
     */
    public function thinkPrompt(Expert $expert): string
    {
        return $this->prompts->think($this->project, $expert);
    }

    /**
     * Post-process a raw THINK response: persist the GEDÄCHTNIS block for the
     * expert and return both the memory block and the parsed BEITRAGSABSICHT
     * (the contribution intent the moderator judges in SelectWinner).
     *
     * @return array{memory: string, beitragsabsicht: string, topic_done: bool}
     */
    public function consumeThink(Expert $expert, string $response, string $context = 'think'): array
    {
        $memoryBlock = $this->extractMemoryUpdate($response, 'BEITRAGSABSICHT:');
        $this->persistMemoryBlock($expert, $memoryBlock, $response, "{$context}:{$expert->id}");

        return [
            'memory' => $memoryBlock,
            'beitragsabsicht' => $this->extractBeitragsabsicht($response),
            // Persona's own verdict on whether the current point is exhausted.
            'topic_done' => $this->extractTopicDone($response),
        ];
    }

    /**
     * Fold the extracted GEDÄCHTNIS block into the expert's stored memory.
     *
     * Two properties matter here:
     *  - A missing block never clears an existing memory (the LLM refused or
     *    returned something malformed).
     *  - A partial block never deletes what it failed to repeat — MemoryMerger
     *    carries omitted sections over. This is what stops personas from
     *    silently forgetting participants mid-discussion.
     *
     * The current user question is then re-stamped from the single project-level
     * source, so every persona holds the same one.
     */
    protected function persistMemoryBlock(Expert $expert, string $memoryBlock, string $rawResponse, string $context): void
    {
        $summary = $expert->thoughtsAbout($this->project);

        if ($memoryBlock === '') {
            Log::warning('GEDÄCHTNIS-UPDATE marker missing in LLM response', [
                'context' => $context,
                'project_id' => $this->project->id,
                'expert_id' => $expert->id,
                'response_first' => mb_substr($rawResponse, 0, 200),
            ]);
        } else {
            $summary->content = app(MemoryMerger::class)->merge(
                $summary->content,
                $memoryBlock,
                $this->project->participantTokens(),
            );
        }

        $summary->content = UserQuestionMemory::upsert(
            $summary->content ?? '',
            $this->project->settings['current_user_question'] ?? null,
        );
        $summary->last_message_id = $this->project->messages()->max('id');
        $summary->save();
    }

    /**
     * SPEAK — generates the visible conversation turn for the winning agent,
     * executing the moderator's Directive in persona.
     *
     * The visible prose stays name-based and is never parsed. The agent appends a
     * STEUERUNG trailer naming the addressed peer (a prompt token, e.g. "E7") and
     * the adjacency-pair type; both are parsed off here and the trailer stripped
     * from the visible content. The user hand-back is NOT emitted here — it stays
     * moderator-driven (Directive->addressUser) and is resolved in PersistMessage.
     *
     * @param  array{memory: string, beitragsabsicht: string}  $thinkOutput
     * @return array{content: string, adjacency_pair_type: ?string, adjacency_partner_token: ?string}
     */
    public function speak(Expert $expert, array $thinkOutput, Directive $directive, ?Message $openPair = null): array
    {
        $prompt = $this->prompts->speak($this->project, $expert, $thinkOutput, $directive, $openPair);

        // A reaction turn gets a real ceiling, not just an instruction: across
        // the logged runs the hard brevity rule was present in 52 of 58 calls
        // and never once produced a turn under 200 characters.
        $isReaction = $directive->role === 'kurz_reagieren';
        $maxTokens = $isReaction
            ? max(32, (int) config('discussion.reaction_max_output_tokens', 160))
            : null;

        // The cap counts hidden reasoning tokens too, so the effort has to come
        // down with it — otherwise the model thinks through its whole budget and
        // returns no text at all.
        $response = $this->client->sendFast(
            $prompt,
            "speak:{$expert->id}",
            null,
            $maxTokens,
            $isReaction ? 'minimal' : null,
        );

        if ($isReaction && trim($response) === '') {
            // The ceiling swallowed the answer. A silent turn is worse than a
            // long one, so take one uncapped shot.
            Log::warning('Reaction turn returned nothing under the token cap; retrying uncapped', [
                'project_id' => $this->project->id,
                'expert_id' => $expert->id,
                'max_output_tokens' => $maxTokens,
            ]);

            $response = $this->client->sendFast($prompt, "speak:{$expert->id}");
            $maxTokens = null;
        }

        return $this->consumeSpeak($this->trimToSentence($response, $maxTokens !== null));
    }

    /**
     * Cut a token-capped response back to its last complete sentence, so a turn
     * that hit the ceiling never ends mid-word. The STEUERUNG trailer is left
     * untouched — it is parsed off afterwards.
     */
    protected function trimToSentence(string $response, bool $capped): string
    {
        if (! $capped) {
            return $response;
        }

        $marker = '---STEUERUNG---';
        $pos = mb_strpos($response, $marker);

        $body = rtrim($pos === false ? $response : mb_substr($response, 0, $pos));
        $trailer = $pos === false ? '' : mb_substr($response, $pos);

        if ($body === '' || preg_match('/[.!?…]["»\']?$/u', $body)) {
            return $response;
        }

        if (! preg_match('/^.*[.!?…]["»\']?/su', $body, $m)) {
            // Not a single complete sentence — keep what there is rather than
            // returning an empty contribution.
            return $response;
        }

        return trim($m[0])."\n\n".$trailer;
    }

    /**
     * Split a raw SPEAK response into the visible contribution and the parsed
     * control trailer. A missing trailer degrades gracefully: the whole text is
     * the contribution, with no partner and no pair type.
     *
     * @return array{content: string, adjacency_pair_type: ?string, adjacency_partner_token: ?string}
     */
    public function consumeSpeak(string $response): array
    {
        $marker = '---STEUERUNG---';
        $pos = mb_strpos($response, $marker);

        $content = $pos === false ? $response : mb_substr($response, 0, $pos);
        $trailer = $pos === false ? '' : mb_substr($response, $pos + mb_strlen($marker));

        return [
            'content' => trim($content),
            'adjacency_pair_type' => $this->parsePairType($trailer),
            'adjacency_partner_token' => $this->parsePartnerToken($trailer),
        ];
    }

    /**
     * Read the PAARTYP line and keep it only if it is one of the known pair-type
     * labels; anything else (incl. the user-hand-back type, which is not the
     * agent's to set) is dropped.
     */
    protected function parsePairType(string $trailer): ?string
    {
        if (! preg_match('/PAARTYP:\s*(.+)/u', $trailer, $m)) {
            return null;
        }

        $value = trim($m[1]);
        $allowed = [
            Message::PAIR_FRAGE_ANTWORT,
            Message::PAIR_ANSPRACHE_REAKTION,
            Message::PAIR_BEITRAG_DISKUSSION,
            Message::PAIR_SYNTHESE_DISKUSSION,
        ];

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * Read the ADRESSAT line and keep the token only if it resolves to an expert
     * contributor of this project. "none"/"niemand"/unknown → null (plenum).
     */
    protected function parsePartnerToken(string $trailer): ?string
    {
        if (! preg_match('/ADRESSAT:\s*(\S+)/u', $trailer, $m)) {
            return null;
        }

        $token = trim($m[1]);

        return $this->project->contributorByPromptId($token) instanceof Expert ? $token : null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extract the content after "GEDÄCHTNIS-UPDATE:", stopping at $stopAt
     * (e.g. 'BEITRAGSABSICHT:') so the intent isn't folded into saved memory.
     */
    protected function extractMemoryUpdate(string $text, ?string $stopAt = null): string
    {
        $marker = 'GEDÄCHTNIS-UPDATE:';
        $pos = strpos($text, $marker);

        if ($pos === false) {
            return '';
        }

        $content = substr($text, $pos + strlen($marker));

        if ($stopAt !== null) {
            $stopPos = strpos($content, $stopAt);
            if ($stopPos !== false) {
                $content = substr($content, 0, $stopPos);
            }
        }

        return trim($content);
    }

    /**
     * Extract the contribution intent following the "BEITRAGSABSICHT:" marker,
     * stopping at the "THEMA_STATUS:" trailer so the status isn't folded into it.
     */
    protected function extractBeitragsabsicht(string $text): string
    {
        $marker = 'BEITRAGSABSICHT:';
        $pos = strpos($text, $marker);

        if ($pos === false) {
            return '';
        }

        $content = substr($text, $pos + strlen($marker));

        $stopPos = strpos($content, 'THEMA_STATUS:');
        if ($stopPos !== false) {
            $content = substr($content, 0, $stopPos);
        }

        return trim($content);
    }

    /**
     * Read the persona's THEMA_STATUS trailer: true only when the expert declares
     * the current point exhausted ("abgeschlossen"). A missing/unknown value
     * degrades to false (topic still open) — silence never closes a topic.
     */
    protected function extractTopicDone(string $text): bool
    {
        if (! preg_match('/THEMA_STATUS:\s*(\p{L}+)/u', $text, $m)) {
            return false;
        }

        return mb_strtolower(trim($m[1])) === 'abgeschlossen';
    }
}
