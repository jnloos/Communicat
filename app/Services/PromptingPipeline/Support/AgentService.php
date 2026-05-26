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
        $prompt   = $this->thinkPrompt($expert);
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
     * @return array{memory: string, beitragsabsicht: string}
     */
    public function consumeThink(Expert $expert, string $response, string $context = 'think'): array
    {
        $memoryBlock = $this->extractMemoryUpdate($response, 'BEITRAGSABSICHT:');
        $this->persistMemoryBlock($expert, $memoryBlock, $response, "{$context}:{$expert->id}");

        return [
            'memory'          => $memoryBlock,
            'beitragsabsicht' => $this->extractBeitragsabsicht($response),
        ];
    }

    /**
     * Save the extracted GEDÄCHTNIS block, but never overwrite an existing memory
     * with an empty value — that happens when the LLM refuses or returns a
     * malformed response without the GEDÄCHTNIS-UPDATE marker.
     */
    protected function persistMemoryBlock(Expert $expert, string $memoryBlock, string $rawResponse, string $context): void
    {
        if ($memoryBlock === '') {
            Log::warning('GEDÄCHTNIS-UPDATE marker missing in LLM response', [
                'context'        => $context,
                'project_id'     => $this->project->id,
                'expert_id'      => $expert->id,
                'response_first' => mb_substr($rawResponse, 0, 200),
            ]);
            return;
        }

        $summary = $expert->thoughtsAbout($this->project);
        $summary->content = $memoryBlock;
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
     * @param  array{memory: string, beitragsabsicht: string} $thinkOutput
     * @return array{content: string, adjacency_pair_type: ?string, adjacency_partner_token: ?string}
     */
    public function speak(Expert $expert, array $thinkOutput, Directive $directive): array
    {
        $prompt   = $this->prompts->speak($this->project, $expert, $thinkOutput, $directive);
        $response = $this->client->sendFast($prompt, "speak:{$expert->id}");

        return $this->consumeSpeak($response);
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
        $pos    = mb_strpos($response, $marker);

        $content = $pos === false ? $response : mb_substr($response, 0, $pos);
        $trailer = $pos === false ? ''        : mb_substr($response, $pos + mb_strlen($marker));

        return [
            'content'                 => trim($content),
            'adjacency_pair_type'     => $this->parsePairType($trailer),
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
        if (!preg_match('/PAARTYP:\s*(.+)/u', $trailer, $m)) {
            return null;
        }

        $value   = trim($m[1]);
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
        if (!preg_match('/ADRESSAT:\s*(\S+)/u', $trailer, $m)) {
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
        $pos    = strpos($text, $marker);

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
     * Extract the contribution intent following the "BEITRAGSABSICHT:" marker.
     */
    protected function extractBeitragsabsicht(string $text): string
    {
        $marker = 'BEITRAGSABSICHT:';
        $pos    = strpos($text, $marker);

        if ($pos === false) {
            return '';
        }

        return trim(substr($text, $pos + strlen($marker)));
    }
}
