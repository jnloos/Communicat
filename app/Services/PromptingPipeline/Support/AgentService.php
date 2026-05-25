<?php

namespace App\Services\PromptingPipeline\Support;

use App\Models\Expert;
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
        $response = $this->client->sendSlow($prompt, "think:{$expert->name}");

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
        $this->persistMemoryBlock($expert, $memoryBlock, $response, "{$context}:{$expert->name}");

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
     * Floor authority lives with the moderator: SPEAK output is ONLY the visible
     * contribution text. The agent no longer names its successor — next speaker
     * and adjacency-pair type are derived downstream from the Directive/detection
     * (see PersistMessage), never parsed from this output.
     *
     * @param  array{memory: string, beitragsabsicht: string} $thinkOutput
     * @return array{content: string}
     */
    public function speak(Expert $expert, array $thinkOutput, Directive $directive): array
    {
        $prompt   = $this->prompts->speak($this->project, $expert, $thinkOutput, $directive);
        $response = $this->client->sendFast($prompt, "speak:{$expert->name}");

        return ['content' => trim($response)];
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
