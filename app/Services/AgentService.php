<?php

namespace App\Services;

use App\Models\Expert;
use App\Models\Project;

class AgentService
{
    public function __construct(
        protected Project $project,
        protected OpenAIClient $client,
        protected PromptBuilder $prompts,
    ) {}

    /**
     * PATH A — runs THINK for a single addressed agent.
     * Saves the GEDÄCHTNIS-UPDATE block to the expert's Summary and returns the raw response.
     */
    public function think(Expert $expert): string
    {
        $prompt   = $this->prompts->think($this->project, $expert);
        $response = $this->client->sendSlow($prompt);

        $memoryBlock = $this->extractMemoryUpdate($response);

        $summary          = $expert->thoughtsAbout($this->project);
        $summary->content = $memoryBlock;
        $summary->save();

        return $response;
    }

    /**
     * PATH B — runs THINK+PRIORITIZE for a single candidate agent.
     * Saves the GEDÄCHTNIS-UPDATE block from within the THINK section and returns the raw response.
     */
    public function thinkAndPrioritize(Expert $expert): string
    {
        $prompt   = $this->prompts->thinkAndPrioritize($this->project, $expert);
        $response = $this->client->sendSlow($prompt);

        $memoryBlock = $this->extractMemoryUpdate($response, 'PRIORITIZE:');

        $summary          = $expert->thoughtsAbout($this->project);
        $summary->content = $memoryBlock;
        $summary->save();

        return $response;
    }

    /**
     * SPEAK — generates the visible conversation turn for the winning agent.
     *
     * @return array{content: string, next_speaker: string, adjacency_pair_type: string, reason: string}
     */
    public function speak(Expert $expert, string $thinkOutput, string $moderationNote = ''): array
    {
        $prompt   = $this->prompts->speak($this->project, $expert, $thinkOutput, $moderationNote);
        $response = $this->client->sendFast($prompt);

        // Split off the [METADATEN block so we can parse metadata separately
        $metadataPos = strpos($response, '[METADATEN');
        if ($metadataPos !== false) {
            $content      = trim(substr($response, 0, $metadataPos));
            $metadataBlock = substr($response, $metadataPos);
        } else {
            $content      = trim($response);
            $metadataBlock = '';
        }

        $nextSpeaker       = $this->parseMetaField($metadataBlock, 'NEXT_SPEAKER');
        $adjacencyPairType = $this->parseMetaField($metadataBlock, 'ADJACENCY_PAIR_TYPE');
        $reason            = $this->parseMetaField($metadataBlock, 'REASON');

        return [
            'content'             => $content,
            'next_speaker'        => $nextSpeaker,
            'adjacency_pair_type' => $adjacencyPairType,
            'reason'              => $reason,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extract the content after "GEDÄCHTNIS-UPDATE:", stopping at an optional stop marker.
     * Pass $stopAt = 'PRIORITIZE:' when parsing THINK+PRIORITIZE output to avoid
     * including the priority score in the saved Gedächtnis.
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
     * Extract the value of a single-line metadata field (e.g. "NEXT_SPEAKER: Alice").
     */
    protected function parseMetaField(string $block, string $field): string
    {
        if (preg_match('/^' . preg_quote($field, '/') . ':\s*(.+)$/m', $block, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }
}
